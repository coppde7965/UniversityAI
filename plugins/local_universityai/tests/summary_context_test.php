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

namespace local_universityai\summary;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * 摘要事實蒐集的行為（FR-SUM-01）。
 *
 * 時間相關的斷言一律以「相對於學期起始日」表達，不寫死任何時間戳——
 * 寫死的話這組測試會在時區設定不同的機器上紅，而那與被測的邏輯無關。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(context_builder::class)]
final class summary_context_test extends \advanced_testcase {
    /** @var \DateTimeImmutable 學期起始日。 */
    private \DateTimeImmutable $start;

    /**
     * 每個測試都在交易中執行並於結束時回捲。
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->start = new \DateTimeImmutable(
            '2025-09-01 00:00:00',
            \core_date::get_server_timezone_object()
        );
    }

    /**
     * 產生一份六週的課綱。
     *
     * @param array $events events[] 的內容
     * @return array DM-SYLLABUS
     */
    private function payload(array $events = []): array {
        $weeks = [];
        for ($i = 1; $i <= 6; $i++) {
            $weeks[] = [
                'week_no' => $i,
                'date' => $this->start->modify('+' . (($i - 1) * 7) . ' days')->format('Y-m-d'),
                'topic' => '第 ' . $i . ' 週主題',
                'note' => $i === 3 ? '本週需繳交作業一' : '',
            ];
        }

        return [
            'course' => ['title' => '人工智慧導論', 'teacher' => '陳怡君'],
            'term' => ['start_date' => $this->start->format('Y-m-d'), 'total_weeks' => 6],
            'weeks' => $weeks,
            'events' => $events,
        ];
    }

    /**
     * 自學期起始日起算第幾天，回傳當天上午九點的時間戳。
     *
     * @param int $days 天數
     * @return int
     */
    private function day(int $days): int {
        return $this->start->modify('+' . $days . ' days')->setTime(9, 0)->getTimestamp();
    }

    /**
     * 週次由 term.start_date 推算，不看站台的日曆週設定。
     */
    public function test_week_number_comes_from_the_term_start_date(): void {
        $builder = new context_builder();

        // 第 15 天落在第 3 週（第 15 至 21 天）。
        $facts = $builder->build(1, '課程', $this->payload(), 1, $this->day(15));

        $this->assertTrue($facts['running']);
        $this->assertSame(3, $facts['weekno']);
        $this->assertSame('第 3 週主題', $facts['thisweek']['topic']);
        $this->assertSame('本週需繳交作業一', $facts['thisweek']['note']);
        $this->assertSame('第 4 週主題', $facts['nextweek']['topic']);
        $this->assertSame($this->start->modify('+14 days')->getTimestamp(), $facts['weekstart']);
    }

    /**
     * 每一週的邊界都對，包含第一天與最後一天。
     */
    public function test_week_boundaries(): void {
        $builder = new context_builder();

        $this->assertSame(1, $builder->build(1, 'c', $this->payload(), 1, $this->day(0))['weekno']);
        $this->assertSame(1, $builder->build(1, 'c', $this->payload(), 1, $this->day(6))['weekno']);
        $this->assertSame(2, $builder->build(1, 'c', $this->payload(), 1, $this->day(7))['weekno']);
        $this->assertSame(6, $builder->build(1, 'c', $this->payload(), 1, $this->day(41))['weekno']);
    }

    /**
     * 學期尚未開始或已結束時不算開課中。
     *
     * 這決定了 service 會回傳 notrunning 而不是產生一則「本週無安排」。
     * 一門三年前結束的課不該每週繼續累積摘要。
     */
    public function test_term_outside_range_is_not_running(): void {
        $builder = new context_builder();

        $this->assertFalse($builder->build(1, 'c', $this->payload(), 1, $this->day(-1))['running']);
        $this->assertFalse($builder->build(1, 'c', $this->payload(), 1, $this->day(42))['running']);
    }

    /**
     * 課綱不完整時安靜地當成未開課，不拋例外。
     *
     * payload 是語言模型產出的內容，缺欄位是可預期的情況；在這裡拋例外會讓
     * 一門課的壞資料中止整批排程（NFR-REL-03）。
     */
    public function test_missing_term_information_is_tolerated(): void {
        $builder = new context_builder();

        $this->assertFalse($builder->build(1, 'c', [], 1, $this->day(1))['running']);
        $this->assertFalse($builder->build(1, 'c', ['term' => ['start_date' => 'x']], 1, $this->day(1))['running']);
    }

    /**
     * 只有七日內到期的事件會進來。
     */
    public function test_only_events_within_seven_days_are_collected(): void {
        $events = [
            [
                'event_id' => 'a',
                'type' => 'assignment',
                'title' => '作業一',
                'due_date' => $this->start->modify('+17 days')->format('Y-m-d'),
                'due_time' => '23:59',
            ],
            [
                'event_id' => 'b',
                'type' => 'exam',
                'title' => '期中考',
                'due_date' => $this->start->modify('+30 days')->format('Y-m-d'),
                'due_time' => null,
            ],
            [
                'event_id' => 'c',
                'type' => 'other',
                'title' => '已經過去的事',
                'due_date' => $this->start->modify('+2 days')->format('Y-m-d'),
                'due_time' => null,
            ],
        ];

        $facts = (new context_builder())->build(1, 'c', $this->payload($events), 1, $this->day(15));

        $this->assertCount(1, $facts['dueevents']);
        $this->assertSame('作業一', $facts['dueevents'][0]['title']);
        $this->assertSame(context_builder::SOURCE_SYLLABUS, $facts['dueevents'][0]['source']);
    }

    /**
     * 已對應到 Moodle 事件的課綱事件不重複計入。
     *
     * 這是 _evtmap 在摘要這條路徑上的第二個用途：老師可能事後在 Moodle 端
     * 改過日期，而課綱那一份不會跟著動，此時以 Moodle 端為準。
     */
    public function test_events_already_mapped_defer_to_the_calendar(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $due = $this->start->modify('+17 days')->setTime(23, 59);

        $DB->insert_record('local_universityai_evtmap', (object) [
            'courseid' => $course->id,
            'eventkey' => 'a',
            'moodleeventid' => 12345,
            'targettype' => 'event',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $DB->insert_record('event', (object) [
            'name' => '作業一（老師改過日期）',
            // description 在 mdl_event 上是 NOT NULL 且沒有預設值。直接省略
            // 會得到一個看起來與本測試無關的資料庫錯誤。
            'description' => '',
            'format' => 1,
            'courseid' => $course->id,
            'groupid' => 0,
            'userid' => 0,
            'eventtype' => 'course',
            'timestart' => $due->getTimestamp(),
            'timeduration' => 0,
            'visible' => 1,
            'timemodified' => time(),
        ]);

        $events = [[
            'event_id' => 'a',
            'type' => 'assignment',
            'title' => '作業一',
            'due_date' => $due->format('Y-m-d'),
            'due_time' => '23:59',
        ]];

        $facts = (new context_builder())->build(
            $course->id,
            'c',
            $this->payload($events),
            1,
            $this->day(15)
        );

        $this->assertCount(1, $facts['dueevents']);
        $this->assertSame('作業一（老師改過日期）', $facts['dueevents'][0]['title']);
        $this->assertSame(context_builder::SOURCE_CALENDAR, $facts['dueevents'][0]['source']);
    }

    /**
     * 三個區塊都沒有內容時 hascontent 為 false。
     *
     * 這個旗標決定 generator 走不走語言模型，是「本週無安排」那條驗收條件
     * 在程式中的落點。
     */
    public function test_hascontent_is_false_when_all_three_sections_are_empty(): void {
        $payload = $this->payload();
        $payload['weeks'] = [];

        $facts = (new context_builder())->build(1, 'c', $payload, 1, $this->day(15));

        $this->assertTrue($facts['running']);
        $this->assertNull($facts['thisweek']);
        $this->assertNull($facts['nextweek']);
        $this->assertSame([], $facts['dueevents']);
        $this->assertFalse($facts['hascontent']);
    }

    /**
     * 主題與備註都空白的週次視同不存在。
     *
     * DM-SYLLABUS 要求 weeks 的長度等於 total_weeks，所以停課週仍然會有一列，
     * 只是內容是空的。把它當成「有當週資料」會讓模型收到一個空主題——而模型
     * 面對空字串一定會編。
     */
    public function test_a_blank_week_counts_as_no_data(): void {
        $payload = $this->payload();
        $payload['weeks'][2]['topic'] = '';
        $payload['weeks'][2]['note'] = '';

        $facts = (new context_builder())->build(1, 'c', $payload, 1, $this->day(15));

        $this->assertTrue($facts['running']);
        $this->assertSame(3, $facts['weekno']);
        $this->assertNull($facts['thisweek']);
        $this->assertNotNull($facts['nextweek']);

        // 下週還有資料，所以整體仍算有內容——「本週無安排」是摘要裡的一個
        // 段落，不是整份摘要都不做。
        $this->assertTrue($facts['hascontent']);
    }

    /**
     * 教師姓名會被列為敏感字面值交給過濾器。
     */
    public function test_teacher_name_is_reported_as_a_sensitive_literal(): void {
        $facts = (new context_builder())->build(1, 'c', $this->payload(), 1, $this->day(15));

        $this->assertSame(['陳怡君'], $facts['literals']);
    }
}
