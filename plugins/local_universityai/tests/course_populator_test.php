<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_universityai;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * 依課綱填入課程、章節與事件（FR-CRS-01、FR-CRS-02、FR-CAL-01）。
 *
 * 冪等性是這組測試的重點。`_evtmap` 那張表從第一階段就建好了，唯一鍵是
 * 整個設計裡「重複執行不產生重複活動」的實作方式，但在這之前從來沒有任何
 * 東西寫進去過——也就是說那個設計從未被驗證。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(course_populator::class)]
final class course_populator_test extends \advanced_testcase {
    /**
     * 每個測試都在交易中執行並於結束時回捲。
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * 一份三週、兩個事件的已確認課綱。
     *
     * @return array
     */
    private function payload(): array {
        return [
            'syllabus_id' => '1',
            'course_ref' => 0,
            'version' => 1,
            'status' => syllabus_repository::STATUS_CONFIRMED,
            'course' => [
                'title' => '人工智慧導論',
                'code' => 'CS3025',
                'teacher' => null,
                'semester' => '114-1',
                'credits' => 3,
            ],
            'term' => ['start_date' => '2025-09-09', 'total_weeks' => 3, 'weekday' => 'TUE'],
            'grading' => [
                ['item' => '作業', 'weight' => 40, 'note' => ''],
                ['item' => '期末考', 'weight' => 60, 'note' => ''],
            ],
            'weeks' => [
                ['week_no' => 1, 'date' => '2025-09-09', 'topic' => '課程介紹', 'note' => '請先閱讀第一章',
                    'confidence' => 1, 'source_span' => 'x', 'overridden' => false],
                ['week_no' => 2, 'date' => '2025-09-16', 'topic' => '搜尋演算法', 'note' => '',
                    'confidence' => 1, 'source_span' => 'x', 'overridden' => false],
                ['week_no' => 3, 'date' => '2025-09-23', 'topic' => '知識表示', 'note' => '',
                    'confidence' => 1, 'source_span' => 'x', 'overridden' => false],
            ],
            'events' => [
                ['event_id' => 'aaa111', 'type' => 'assignment', 'title' => '作業一',
                    'due_date' => '2025-09-23', 'due_time' => '23:59', 'week_no' => 3,
                    'confidence' => 1, 'source_span' => 'x', 'overridden' => false],
                ['event_id' => 'bbb222', 'type' => 'exam', 'title' => '期末考',
                    'due_date' => '2025-09-30', 'due_time' => null, 'week_no' => null,
                    'confidence' => 1, 'source_span' => 'x', 'overridden' => false],
            ],
            'parse_meta' => ['unresolved' => []],
        ];
    }

    /**
     * 建立課程與課綱。
     *
     * @param string $status 課綱狀態
     * @param array|null $payload 自訂 payload
     * @param array $courseoptions 課程的建立選項
     * @return array{course: \stdClass, syllabusid: int}
     */
    private function scenario(
        string $status = syllabus_repository::STATUS_CONFIRMED,
        ?array $payload = null,
        array $courseoptions = []
    ): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course($courseoptions + ['format' => 'topics']);

        $syllabusid = $DB->insert_record(syllabus_repository::TABLE, (object) [
            'courseid' => $course->id,
            'version' => 1,
            'status' => $status,
            'payload' => json_encode($payload ?? $this->payload(), JSON_UNESCAPED_UNICODE),
            'contenthash' => sha1('t'),
            'model' => 'stub',
            'parsedat' => time(),
            'usermodified' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return ['course' => $course, 'syllabusid' => $syllabusid];
    }

    /**
     * 課程欄位、章節與事件都依課綱寫入（FR-CRS-01、FR-CRS-02、FR-CAL-01）。
     */
    public function test_course_sections_and_events_are_written(): void {
        global $DB;

        $scenario = $this->scenario();
        (new course_populator())->apply($scenario['syllabusid'], 2);

        $course = $DB->get_record('course', ['id' => $scenario['course']->id], '*', MUST_EXIST);
        $this->assertSame('人工智慧導論', $course->fullname);
        $this->assertStringContainsString('CS3025', $course->summary);
        $this->assertStringContainsString('40%', $course->summary);
        $this->assertGreaterThan(0, (int) $course->startdate);

        // 結束日是最後一週結束之後，也就是起始日加上總週數。
        $this->assertSame(
            (int) $course->startdate + (3 * 7 * DAYSECS),
            (int) $course->enddate
        );

        // FR-CRS-02：第 N 個章節的名稱對應 weeks[N-1].topic。
        foreach ([1 => '課程介紹', 2 => '搜尋演算法', 3 => '知識表示'] as $number => $topic) {
            $section = $DB->get_record(
                'course_sections',
                ['course' => $course->id, 'section' => $number],
                '*',
                MUST_EXIST
            );
            $this->assertSame($topic, $section->name);
        }
        $this->assertSame('請先閱讀第一章', $DB->get_field(
            'course_sections',
            'summary',
            ['course' => $course->id, 'section' => 1]
        ));

        // FR-CAL-01：每個事件產生一個行事曆事件，截止日一致。
        $this->assertSame(2, $DB->count_records('event', ['courseid' => $course->id]));
        $this->assertSame(2, $DB->count_records(
            course_populator::EVTMAP_TABLE,
            ['courseid' => $course->id]
        ));
    }

    /**
     * 重複執行不產生重複的事件或章節（NFR-REL-04、FR-CAL-01）。
     *
     * 這是 `_evtmap` 唯一鍵存在的理由。在這組測試之前，那張表從未被寫入過，
     * 也就是說整個冪等設計從來沒有被驗證。
     */
    public function test_applying_twice_is_idempotent(): void {
        global $DB;

        $scenario = $this->scenario();
        $populator = new course_populator();

        $populator->apply($scenario['syllabusid'], 2);
        $events = $DB->count_records('event', ['courseid' => $scenario['course']->id]);
        $sections = $DB->count_records('course_sections', ['course' => $scenario['course']->id]);

        $second = $populator->apply($scenario['syllabusid'], 2);

        $this->assertSame($events, $DB->count_records('event', ['courseid' => $scenario['course']->id]));
        $this->assertSame($sections, $DB->count_records(
            'course_sections',
            ['course' => $scenario['course']->id]
        ));

        // 第二次沒有任何欄位、章節或事件需要變更——除了事件，那是「更新既有」
        // 而不是「新增」，所以計數仍然是 2。
        $this->assertSame(0, $second['fields']);
        $this->assertSame(0, $second['sections']);
    }

    /**
     * 日期改了之後重新填入，是更新既有事件而不是新增一個。
     *
     * 這正是 §9.6 那個「event_id 不含日期」的設計要換到的行為：同一場考試
     * 改期，行事曆上仍然只有一筆。
     */
    public function test_a_date_change_updates_the_existing_event(): void {
        global $DB;

        $scenario = $this->scenario();
        $populator = new course_populator();
        $populator->apply($scenario['syllabusid'], 2);

        $before = $DB->get_record(
            course_populator::EVTMAP_TABLE,
            ['courseid' => $scenario['course']->id, 'eventkey' => 'bbb222'],
            '*',
            MUST_EXIST
        );

        // 只改日期，event_id 不變（那是 §9.6 的規則）。
        $payload = $this->payload();
        $payload['events'][1]['due_date'] = '2025-10-07';
        $DB->set_field(
            syllabus_repository::TABLE,
            'payload',
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            ['id' => $scenario['syllabusid']]
        );

        $populator->apply($scenario['syllabusid'], 2);

        $this->assertSame(2, $DB->count_records('event', ['courseid' => $scenario['course']->id]));

        $after = $DB->get_record(
            course_populator::EVTMAP_TABLE,
            ['courseid' => $scenario['course']->id, 'eventkey' => 'bbb222'],
            '*',
            MUST_EXIST
        );
        $this->assertSame($before->moodleeventid, $after->moodleeventid, '應該更新同一個事件。');

        // 比對時間戳而不是格式化後的字串：userdate() 的 %d 在不同平台上
        // 補不補前導零並不一致，用它斷言會讓測試在別人的機器上莫名其妙地紅。
        $expected = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            '2025-10-07',
            \core_date::get_server_timezone_object()
        )->getTimestamp();

        $event = $DB->get_record('event', ['id' => $after->moodleeventid], '*', MUST_EXIST);
        $this->assertSame($expected, (int) $event->timestart);
    }

    /**
     * 沒有日期的事件不建立。
     *
     * 行事曆事件一定要有時間點，而課綱沒寫日期時那個時間點只能用猜的。
     * 這類項目應該留在 unresolved 裡讓老師補上，而不是被放進行事曆一個
     * 編出來的日子——那比沒有更糟，因為學生會相信它。
     */
    public function test_events_without_a_date_are_skipped(): void {
        global $DB;

        $payload = $this->payload();
        $payload['events'][1]['due_date'] = null;

        $scenario = $this->scenario(syllabus_repository::STATUS_CONFIRMED, $payload);
        (new course_populator())->apply($scenario['syllabusid'], 2);

        $this->assertSame(1, $DB->count_records('event', ['courseid' => $scenario['course']->id]));
    }

    /**
     * 只有已確認的課綱能填入（FR-CRS-01、FR-SYL-04）。
     *
     * FR-SYL-04 的驗收條件寫的是「解析完成後狀態為待確認，此時呼叫課程建立
     * **應被拒絕**」——這條測試就是那句話的落點。
     */
    public function test_only_a_confirmed_syllabus_can_be_applied(): void {
        foreach (
            [
            syllabus_repository::STATUS_UPLOADED,
            syllabus_repository::STATUS_PARSED,
            syllabus_repository::STATUS_FAILED,
            ] as $status
        ) {
            $scenario = $this->scenario($status);

            try {
                (new course_populator())->apply($scenario['syllabusid'], 2);
                $this->fail("狀態 {$status} 不該能填入課程。");
            } catch (\moodle_exception $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    /**
     * 預覽標出哪些既有欄位會被覆寫（FR-CRS-01）。
     *
     * 「會被覆寫」的定義是**既有欄位已有內容且與課綱不同**。空欄位被填上
     * 不算——那是這個功能存在的目的，把它也標成警告會讓真正的警告被淹沒。
     */
    public function test_preview_flags_only_real_overwrites(): void {
        $scenario = $this->scenario(
            syllabus_repository::STATUS_CONFIRMED,
            null,
            ['fullname' => '老師自己取的課名', 'summary' => '']
        );

        $preview = (new course_populator())->preview($scenario['syllabusid']);
        $byname = array_column($preview['fields'], null, 'name');

        $this->assertTrue($byname['fullname']['overwrites'], '課名原本有內容且不同，應標示為覆寫。');
        $this->assertSame('老師自己取的課名', $byname['fullname']['current']);
        $this->assertSame('人工智慧導論', $byname['fullname']['proposed']);

        $this->assertFalse($byname['summary']['overwrites'], '簡述原本是空的，填上不算覆寫。');

        $this->assertSame(3, $preview['weeks']);
        $this->assertSame(2, $preview['events']);
    }

    /**
     * 相同的值不算覆寫，也不會被重複寫入。
     */
    public function test_identical_values_are_reported_as_unchanged(): void {
        $scenario = $this->scenario(
            syllabus_repository::STATUS_CONFIRMED,
            null,
            ['fullname' => '人工智慧導論']
        );

        $preview = (new course_populator())->preview($scenario['syllabusid']);
        $byname = array_column($preview['fields'], null, 'name');

        $this->assertTrue($byname['fullname']['unchanged']);
        $this->assertFalse($byname['fullname']['overwrites']);
    }

    /**
     * 成功時寫下營運紀錄（FR-CRS-01 的最後一條驗收條件）。
     */
    public function test_a_successful_run_is_recorded(): void {
        global $DB;

        $scenario = $this->scenario();
        (new course_populator())->apply($scenario['syllabusid'], 2);

        $record = $DB->get_record(ops::TABLE, [
            'operation' => ops::OP_COURSE_POPULATE,
            'targetid' => (string) $scenario['syllabusid'],
        ], '*', MUST_EXIST);

        $this->assertSame(ops::STATUS_SUCCESS, $record->status);
    }
}
