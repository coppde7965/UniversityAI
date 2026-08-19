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

use local_universityai\syllabus_repository;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * 單一課程摘要流程的行為（FR-SUM-01、NFR-REL-04）。
 *
 * 語言模型以替身取代，所以這組測試不碰網路，但走的是完整的其餘路徑：
 * 查課綱 → 算事實 → 冪等判斷 → 保存。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(service::class)]
#[CoversClass(repository::class)]
#[CoversClass(syllabus_repository::class)]
final class summary_service_test extends \advanced_testcase {
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
     * 建立一門有課綱的課。
     *
     * @param string $status parsed 或 confirmed
     * @param string|null $payload 自訂 payload，null 表示用預設的六週課綱
     * @return \stdClass 課程
     */
    private function course_with_syllabus(
        string $status = syllabus_repository::STATUS_CONFIRMED,
        ?string $payload = null
    ): \stdClass {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['fullname' => '人工智慧導論']);

        $weeks = [];
        for ($i = 1; $i <= 6; $i++) {
            $weeks[] = [
                'week_no' => $i,
                'date' => $this->start->modify('+' . (($i - 1) * 7) . ' days')->format('Y-m-d'),
                'topic' => '第 ' . $i . ' 週主題',
                'note' => '',
            ];
        }

        $payload ??= json_encode([
            'course' => ['title' => '人工智慧導論', 'teacher' => '陳怡君'],
            'term' => ['start_date' => $this->start->format('Y-m-d'), 'total_weeks' => 6],
            'weeks' => $weeks,
            'events' => [],
        ], JSON_UNESCAPED_UNICODE);

        $DB->insert_record(syllabus_repository::TABLE, (object) [
            'courseid' => $course->id,
            'version' => 1,
            'status' => $status,
            'payload' => $payload,
            'contenthash' => sha1('test'),
            'model' => 'stub',
            'parsedat' => time(),
            'usermodified' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return $course;
    }

    /**
     * 建立一個永遠成功的生成器替身。
     *
     * @return generator
     */
    private function stub_generator(): generator {
        $generator = $this->createMock(generator::class);
        $generator->method('generate')->willReturn([
            'success' => true,
            'status' => generator::STATUS_GENERATED,
            'content' => '本週重點：第 3 週主題。',
            'model' => 'stub-model',
            'durationms' => 12,
            'errormessage' => '',
        ]);

        return $generator;
    }

    /**
     * 自學期起始日起算第幾天的上午九點。
     *
     * @param int $days 天數
     * @return int
     */
    private function day(int $days): int {
        return $this->start->modify('+' . $days . ' days')->setTime(9, 0)->getTimestamp();
    }

    /**
     * 正常情況下產生一則摘要，並存下版本、模型與課綱版本。
     */
    public function test_creates_a_summary_with_full_provenance(): void {
        global $DB;

        $course = $this->course_with_syllabus();
        $service = new service(null, null, $this->stub_generator(), null);

        $outcome = $service->generate_for_course($course->id, $this->day(15));

        $this->assertSame(service::RESULT_CREATED, $outcome['result']);
        $this->assertSame(3, $outcome['weekno']);

        $record = $DB->get_record(repository::TABLE, ['id' => $outcome['summaryid']], '*', MUST_EXIST);
        $this->assertSame('本週重點：第 3 週主題。', $record->content);
        $this->assertSame('stub-model', $record->model);
        $this->assertEquals(1, $record->syllabusversion);
        $this->assertEquals(1, $record->version);
        $this->assertEquals(3, $record->weekno);
        $this->assertGreaterThan(0, (int) $record->generatedat);

        // NFR-MNT-03 的可回溯性不只要模型，還要「當時的輸入」。
        $this->assertNotEmpty($record->facts);
        $this->assertIsArray(json_decode($record->facts, true));
    }

    /**
     * 同一教學週重跑不會產生第二則摘要（NFR-REL-04）。
     */
    public function test_rerunning_the_same_week_is_idempotent(): void {
        global $DB;

        $course = $this->course_with_syllabus();
        $service = new service(null, null, $this->stub_generator(), null);

        $this->assertSame(
            service::RESULT_CREATED,
            $service->generate_for_course($course->id, $this->day(15))['result']
        );
        $this->assertSame(
            service::RESULT_EXISTS,
            $service->generate_for_course($course->id, $this->day(17))['result']
        );

        $this->assertSame(1, $DB->count_records(repository::TABLE, ['courseid' => $course->id]));
    }

    /**
     * 下一個教學週會產生新的一則。
     */
    public function test_the_next_teaching_week_gets_its_own_summary(): void {
        global $DB;

        $course = $this->course_with_syllabus();
        $service = new service(null, null, $this->stub_generator(), null);

        $service->generate_for_course($course->id, $this->day(15));
        $outcome = $service->generate_for_course($course->id, $this->day(22));

        $this->assertSame(service::RESULT_CREATED, $outcome['result']);
        $this->assertSame(4, $outcome['weekno']);
        $this->assertSame(2, $DB->count_records(repository::TABLE, ['courseid' => $course->id]));
    }

    /**
     * 強制重新產生時保留舊版，不覆蓋。
     *
     * 這是 FR-SUM-01「保留版本」的實質內容：改了提示詞想重跑一次，舊的那則
     * 仍然查得到，才有辦法比較兩者。
     */
    public function test_forcing_a_regeneration_keeps_the_previous_version(): void {
        global $DB;

        $course = $this->course_with_syllabus();
        $service = new service(null, null, $this->stub_generator(), null);

        $service->generate_for_course($course->id, $this->day(15));
        $outcome = $service->generate_for_course($course->id, $this->day(15), true);

        $this->assertSame(service::RESULT_CREATED, $outcome['result']);
        $this->assertSame(2, $DB->count_records(repository::TABLE, ['courseid' => $course->id]));

        $versions = $DB->get_fieldset_select(repository::TABLE, 'version', 'courseid = ?', [$course->id]);
        sort($versions);
        $this->assertSame([1, 2], array_map('intval', $versions));

        // 最新版本優先：頁面上不該同時列出兩則同一週的摘要。
        $recent = (new repository())->get_recent($course->id, 10);
        $this->assertCount(1, $recent);
        $this->assertEquals(2, $recent[0]->version);
    }

    /**
     * 尚未確認的課綱不產生摘要。
     *
     * 驗收條件寫的是「僅根據**已確認**課綱」，這條測試是那三個字的落點。
     */
    public function test_parsed_but_unconfirmed_syllabus_is_ignored(): void {
        $course = $this->course_with_syllabus(syllabus_repository::STATUS_PARSED);
        $service = new service(null, null, $this->stub_generator(), null);

        $this->assertSame(
            service::RESULT_NOSYLLABUS,
            $service->generate_for_course($course->id, $this->day(15))['result']
        );
    }

    /**
     * 學期外、課程不存在、payload 壞掉，各有各的狀態碼。
     *
     * 三者都不是失敗。混成一個布林值的話，排程輸出就無法區分「不需要做」
     * 與「做壞了」。
     */
    public function test_each_non_failure_reason_has_its_own_result_code(): void {
        $service = new service(null, null, $this->stub_generator(), null);

        $running = $this->course_with_syllabus();
        $this->assertSame(
            service::RESULT_NOTRUNNING,
            $service->generate_for_course($running->id, $this->day(60))['result']
        );

        $this->assertSame(
            service::RESULT_NOCOURSE,
            $service->generate_for_course(-1, $this->day(15))['result']
        );

        $broken = $this->course_with_syllabus(syllabus_repository::STATUS_CONFIRMED, 'not json at all');
        $this->assertSame(
            service::RESULT_BADPAYLOAD,
            $service->generate_for_course($broken->id, $this->day(15))['result']
        );
    }

    /**
     * 分批查詢只回傳有已確認課綱的課程，且每門課只出現一次。
     */
    public function test_batch_query_returns_each_confirmed_course_once(): void {
        global $DB;

        $confirmed = $this->course_with_syllabus();
        $parsedonly = $this->course_with_syllabus(syllabus_repository::STATUS_PARSED);
        $this->getDataGenerator()->create_course();

        // 同一門課的第二個已確認版本，不該讓它在清單中出現兩次。
        $DB->insert_record(syllabus_repository::TABLE, (object) [
            'courseid' => $confirmed->id,
            'version' => 2,
            'status' => syllabus_repository::STATUS_CONFIRMED,
            'payload' => '{}',
            'contenthash' => sha1('v2'),
            'model' => 'stub',
            'parsedat' => time(),
            'usermodified' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $ids = (new syllabus_repository())->get_courseids_with_confirmed(0, 50);

        $this->assertSame([(int) $confirmed->id], $ids);
        $this->assertNotContains((int) $parsedonly->id, $ids);
    }

    /**
     * 最新的已確認版本以 version 判定，不是 confirmedat。
     *
     * version 的語意是第幾次上傳，單調遞增且不受時鐘影響。老師先確認第 2 版、
     * 事後又補確認第 1 版時，時間戳會給出錯的答案。
     */
    public function test_latest_confirmed_is_chosen_by_version(): void {
        global $DB;

        $course = $this->course_with_syllabus();
        $DB->set_field(syllabus_repository::TABLE, 'confirmedat', time(), ['courseid' => $course->id]);

        $DB->insert_record(syllabus_repository::TABLE, (object) [
            'courseid' => $course->id,
            'version' => 2,
            'status' => syllabus_repository::STATUS_CONFIRMED,
            'payload' => '{"marker":"v2"}',
            'contenthash' => sha1('v2'),
            'model' => 'stub',
            'parsedat' => time(),
            'confirmedat' => time() - DAYSECS,
            'usermodified' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $latest = (new syllabus_repository())->get_latest_confirmed((int) $course->id);

        $this->assertNotNull($latest);
        $this->assertEquals(2, $latest->version);
    }

    /**
     * 生成失敗時不保存任何東西。
     *
     * 半套的摘要比沒有摘要糟：頁面上會出現一則空白內容，而冪等判斷會認為
     * 這一週已經做過了，於是永遠不會重試。
     */
    public function test_a_failed_generation_saves_nothing(): void {
        global $DB;

        $generator = $this->createMock(generator::class);
        $generator->method('generate')->willReturn([
            'success' => false,
            'status' => generator::STATUS_GENERATED,
            'content' => '',
            'model' => '',
            'durationms' => 3,
            'errormessage' => '供應商回報錯誤',
        ]);

        $course = $this->course_with_syllabus();
        $outcome = (new service(null, null, $generator, null))
            ->generate_for_course($course->id, $this->day(15));

        $this->assertSame(service::RESULT_FAILED, $outcome['result']);
        $this->assertSame(0, $DB->count_records(repository::TABLE, ['courseid' => $course->id]));
    }
}
