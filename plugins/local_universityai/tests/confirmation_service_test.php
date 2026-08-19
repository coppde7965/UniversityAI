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
 * 老師的逐項修正與確認（FR-SYL-03、FR-SYL-04、FR-DIF-02）。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(confirmation_service::class)]
final class confirmation_service_test extends \advanced_testcase {
    /**
     * 每個測試都在交易中執行並於結束時回捲。
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * 一份已解析的課綱。
     *
     * 第 2 週的信心刻意壓低，用來驗低信心標示。
     *
     * @return array
     */
    private function payload(): array {
        return [
            'course' => ['title' => '人工智慧導論', 'code' => null, 'teacher' => null,
                'semester' => null, 'credits' => null],
            'term' => ['start_date' => '2025-09-09', 'total_weeks' => 2, 'weekday' => 'TUE'],
            'grading' => [['item' => '作業', 'weight' => 100, 'note' => '']],
            'weeks' => [
                ['week_no' => 1, 'date' => '2025-09-09', 'topic' => '課程介紹', 'note' => '',
                    'confidence' => 0.95, 'source_span' => '第 1 週 課程介紹', 'overridden' => false],
                ['week_no' => 2, 'date' => '2025-09-16', 'topic' => '搜尋演算法', 'note' => '',
                    'confidence' => 0.35, 'source_span' => '第 2 週 搜尋演算法', 'overridden' => false],
            ],
            'events' => [
                ['event_id' => 'abc123', 'type' => 'assignment', 'title' => '作業一',
                    'due_date' => '2025-09-16', 'due_time' => '23:59', 'week_no' => 2,
                    'confidence' => 0.9, 'source_span' => '作業一', 'overridden' => false],
            ],
            'parse_meta' => ['unresolved' => ['期中考日期未定']],
        ];
    }

    /**
     * 建立一門課與一份指定狀態的課綱。
     *
     * @param string $status 課綱狀態
     * @return array{course: \stdClass, syllabusid: int}
     */
    private function scenario(string $status = syllabus_repository::STATUS_PARSED): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $syllabusid = $DB->insert_record(syllabus_repository::TABLE, (object) [
            'courseid' => $course->id,
            'version' => 1,
            'status' => $status,
            'payload' => json_encode($this->payload(), JSON_UNESCAPED_UNICODE),
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
     * 低信心的項目會被標示出來（FR-SYL-03）。
     */
    public function test_low_confidence_items_are_flagged(): void {
        set_config('confidencethreshold', 0.6, 'local_universityai');

        $annotated = (new confirmation_service())->annotate($this->payload());

        $this->assertFalse($annotated['weeks'][0]['islowconfidence']);
        $this->assertTrue($annotated['weeks'][1]['islowconfidence']);
        $this->assertFalse($annotated['events'][0]['islowconfidence']);
        $this->assertSame(1, $annotated['lowcount']);
        $this->assertSame(['期中考日期未定'], $annotated['unresolved']);
    }

    /**
     * 門檻設定缺席時退回預設值，而不是變成 0。
     *
     * 0 的語意是「什麼都不標示」——那會讓 FR-SYL-03 這條驗收條件安靜地失效，
     * 而畫面上看起來一切正常。prune_ops 撞過同一個坑。
     */
    public function test_missing_threshold_falls_back_to_the_default(): void {
        unset_config('confidencethreshold', 'local_universityai');

        $service = new confirmation_service();

        $this->assertGreaterThan(0, $service->threshold());
        $this->assertSame(1, $service->annotate($this->payload())['lowcount']);
    }

    /**
     * 沒有改動的確認不標記任何覆寫。
     *
     * 老師看過一遍覺得都對就按確認，那是最常見的路徑。若它也把每一項標成
     * 人工覆寫，FR-DIF-02 的保護就變成「永遠保護全部」，等於沒有保護。
     */
    public function test_confirming_without_edits_marks_nothing(): void {
        global $DB;

        $scenario = $this->scenario();
        $service = new confirmation_service();
        $submitted = $this->submission($this->payload());

        $overrides = $service->confirm(
            $scenario['syllabusid'],
            $submitted,
            2,
            \context_course::instance($scenario['course']->id)
        );

        $this->assertSame(0, $overrides);

        $stored = json_decode(
            $DB->get_field(syllabus_repository::TABLE, 'payload', ['id' => $scenario['syllabusid']]),
            true
        );
        foreach ($stored['weeks'] as $week) {
            $this->assertFalse($week['overridden']);
        }
    }

    /**
     * 改過的項目被標記為人工覆寫，沒改的不受影響（FR-DIF-02）。
     */
    public function test_edited_items_are_marked_as_overridden(): void {
        global $DB;

        $scenario = $this->scenario();
        $submitted = $this->submission($this->payload());
        $submitted->week_1_topic = '搜尋演算法：BFS 與 DFS';
        $submitted->event_0_duedate = '2025-09-23';

        $overrides = (new confirmation_service())->confirm(
            $scenario['syllabusid'],
            $submitted,
            2,
            \context_course::instance($scenario['course']->id)
        );

        $this->assertSame(2, $overrides);

        $stored = json_decode(
            $DB->get_field(syllabus_repository::TABLE, 'payload', ['id' => $scenario['syllabusid']]),
            true
        );

        $this->assertFalse($stored['weeks'][0]['overridden'], '沒改的週次不該被標記。');
        $this->assertTrue($stored['weeks'][1]['overridden']);
        $this->assertSame('搜尋演算法：BFS 與 DFS', $stored['weeks'][1]['topic']);
        $this->assertTrue($stored['events'][0]['overridden']);
        $this->assertSame('2025-09-23', $stored['events'][0]['due_date']);
    }

    /**
     * 清空一個欄位存成 null 而不是空字串。
     *
     * DM-SYLLABUS 的可空欄位用 null 表示「沒有這個值」；存 '' 會讓下游分不清
     * 「老師清空了」與「模型抽出了一個空字串」。
     */
    public function test_clearing_a_field_stores_null(): void {
        global $DB;

        $scenario = $this->scenario();
        $submitted = $this->submission($this->payload());
        $submitted->event_0_duetime = '';

        (new confirmation_service())->confirm(
            $scenario['syllabusid'],
            $submitted,
            2,
            \context_course::instance($scenario['course']->id)
        );

        $stored = json_decode(
            $DB->get_field(syllabus_repository::TABLE, 'payload', ['id' => $scenario['syllabusid']]),
            true
        );

        $this->assertNull($stored['events'][0]['due_time']);
    }

    /**
     * 確認記錄確認者與時間，狀態轉為 confirmed（FR-SYL-04）。
     */
    public function test_confirmation_records_who_and_when(): void {
        global $DB;

        $scenario = $this->scenario();
        $before = time();

        (new confirmation_service())->confirm(
            $scenario['syllabusid'],
            $this->submission($this->payload()),
            7,
            \context_course::instance($scenario['course']->id)
        );

        $record = $DB->get_record(syllabus_repository::TABLE, ['id' => $scenario['syllabusid']], '*', MUST_EXIST);

        $this->assertSame(syllabus_repository::STATUS_CONFIRMED, $record->status);
        $this->assertEquals(7, $record->confirmedby);
        $this->assertGreaterThanOrEqual($before, (int) $record->confirmedat);

        // 版本不變：修改寫回同一版（§3.3）。
        $this->assertEquals(1, $record->version);
    }

    /**
     * 只有 parsed 狀態能被確認。
     *
     * 三種擋下的狀態各有理由：uploaded 還沒解析完、confirmed 是重複送出、
     * failed 沒有內容可確認。安靜地成功會讓下游拿到一份不該存在的已確認課綱。
     */
    public function test_only_a_parsed_syllabus_can_be_confirmed(): void {
        foreach (
            [
            syllabus_repository::STATUS_UPLOADED,
            syllabus_repository::STATUS_CONFIRMED,
            syllabus_repository::STATUS_FAILED,
            ] as $status
        ) {
            $scenario = $this->scenario($status);

            try {
                (new confirmation_service())->confirm(
                    $scenario['syllabusid'],
                    $this->submission($this->payload()),
                    2,
                    \context_course::instance($scenario['course']->id)
                );
                $this->fail("狀態 {$status} 不該能被確認。");
            } catch (\moodle_exception $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    /**
     * 確認會觸發事件，並帶上覆寫項目數。
     *
     * 事件是 NFR-SEC-04 的操作紀錄——資料表的 confirmedby 記的是當前狀態，
     * 會被下一次確認覆寫；事件記的是發生過的事，不會。
     */
    public function test_confirmation_triggers_an_event(): void {
        $scenario = $this->scenario();
        $submitted = $this->submission($this->payload());
        $submitted->week_0_note = '教室改在 E301';

        $sink = $this->redirectEvents();
        (new confirmation_service())->confirm(
            $scenario['syllabusid'],
            $submitted,
            2,
            \context_course::instance($scenario['course']->id)
        );
        $events = $sink->get_events();
        $sink->close();

        $confirmed = array_values(array_filter(
            $events,
            static fn($e) => $e instanceof event\syllabus_confirmed
        ));

        $this->assertCount(1, $confirmed);
        $this->assertEquals($scenario['syllabusid'], $confirmed[0]->objectid);
        $this->assertSame(1, $confirmed[0]->other['overrides']);
    }

    /**
     * 由 payload 組出一份「什麼都沒改」的表單提交值。
     *
     * 欄位名由 confirmation_service::field_name() 產生而不是手寫——手寫的話
     * 這組測試會在名稱規則改變時仍然通過，而正式流程已經壞掉了。
     *
     * @param array $payload DM-SYLLABUS
     * @return \stdClass
     */
    private function submission(array $payload): \stdClass {
        $submitted = new \stdClass();

        foreach ($payload['weeks'] as $index => $week) {
            foreach (confirmation_service::WEEK_FIELDS as $field) {
                $name = confirmation_service::field_name('week', $index, $field);
                $submitted->$name = (string) ($week[$field] ?? '');
            }
        }
        foreach ($payload['events'] as $index => $event) {
            foreach (confirmation_service::EVENT_FIELDS as $field) {
                $name = confirmation_service::field_name('event', $index, $field);
                $submitted->$name = (string) ($event[$field] ?? '');
            }
        }

        return $submitted;
    }
}
