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

namespace local_universityai\task;

use local_universityai\syllabus_repository;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * 每週摘要排程任務的游標與分批（A-04）。
 *
 * 這組測試刻意讓所有課程的學期都**已經結束**，所以每一門都會走到
 * notrunning 而不呼叫語言模型。要測的是游標與分批，不是生成——把兩件事
 * 綁在一起會讓這組測試需要網路，那它就不會有人跑。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(weekly_summary::class)]
final class weekly_summary_task_test extends \advanced_testcase {
    /**
     * 每個測試都在交易中執行並於結束時回捲。
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * 建立 n 門有已確認課綱的課，學期都在很久以前結束。
     *
     * @param int $count 課程數
     * @return int[] 由小到大的課程 id
     */
    private function courses_with_confirmed_syllabus(int $count): array {
        global $DB;

        $payload = json_encode([
            'course' => ['title' => 'x'],
            'term' => ['start_date' => '2020-01-06', 'total_weeks' => 6],
            'weeks' => [],
            'events' => [],
        ]);

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $course = $this->getDataGenerator()->create_course();
            $ids[] = (int) $course->id;

            $DB->insert_record(syllabus_repository::TABLE, (object) [
                'courseid' => $course->id,
                'version' => 1,
                'status' => syllabus_repository::STATUS_CONFIRMED,
                'payload' => $payload,
                'contenthash' => sha1('t' . $i),
                'model' => 'stub',
                'parsedat' => time(),
                'usermodified' => 0,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        sort($ids);

        return $ids;
    }

    /**
     * 執行任務並丟掉 mtrace 的輸出。
     *
     * @return void
     */
    private function run_task(): void {
        ob_start();
        (new weekly_summary())->execute();
        ob_end_clean();
    }

    /**
     * 單次處理量受 batchsize 限制，游標停在最後一門課。
     */
    public function test_batch_size_limits_one_run(): void {
        $ids = $this->courses_with_confirmed_syllabus(5);
        set_config('batchsize', 2, 'local_universityai');

        $this->run_task();

        $this->assertEquals(
            $ids[1],
            get_config('local_universityai', weekly_summary::CURSOR_SETTING),
            '游標應停在本批的最後一門課。'
        );
    }

    /**
     * 一輪跑完後游標歸零，下一次重新從頭開始。
     */
    public function test_cursor_resets_after_a_full_pass(): void {
        $this->courses_with_confirmed_syllabus(3);
        set_config('batchsize', 2, 'local_universityai');

        $this->run_task();
        $this->run_task();

        $this->assertEquals(
            0,
            get_config('local_universityai', weekly_summary::CURSOR_SETTING),
            '最後一批不滿 batchsize 時就該歸零，不必等下一次跑空。'
        );
    }

    /**
     * 沒有任何課程時直接歸零並結束。
     */
    public function test_no_courses_resets_the_cursor(): void {
        set_config(weekly_summary::CURSOR_SETTING, 999, 'local_universityai');

        $this->run_task();

        $this->assertEquals(0, get_config('local_universityai', weekly_summary::CURSOR_SETTING));
    }

    /**
     * batchsize 設定缺席時退回預設值，而不是變成 0。
     *
     * get_config() 在設定未寫入時回傳 false，轉成 int 是 0——而 0 對分批查詢
     * 的語意是「一門都不處理」，那會安靜地什麼都不做。這是第一階段
     * prune_ops 撞過的同一個坑，在這裡再釘一次。
     */
    public function test_missing_batch_size_falls_back_to_the_default(): void {
        $ids = $this->courses_with_confirmed_syllabus(3);
        unset_config('batchsize', 'local_universityai');

        $this->run_task();

        // 預設值遠大於 3，所以一次就跑完整輪，游標歸零。
        $this->assertGreaterThan(3, weekly_summary::DEFAULT_BATCH_SIZE);
        $this->assertEquals(0, get_config('local_universityai', weekly_summary::CURSOR_SETTING));
        $this->assertNotEmpty($ids);
    }

    /**
     * 單一課程的壞資料不影響同一批的其他課程（NFR-REL-03）。
     *
     * 用一份解不開的 payload 製造問題。**這條測試走的是回傳狀態碼那條路，
     * 不是 try/catch 那條**——service 對壞 payload 的處理刻意是回傳
     * badpayload 而不是拋例外，理由見 syllabus_repository::decode()。
     * 例外路徑目前沒有自動化涵蓋，需要能注入失敗的相依才測得到。
     */
    public function test_one_broken_course_does_not_stop_the_batch(): void {
        global $DB;

        $ids = $this->courses_with_confirmed_syllabus(2);
        $DB->set_field(syllabus_repository::TABLE, 'payload', '{ not json', ['courseid' => $ids[0]]);
        set_config('batchsize', 2, 'local_universityai');

        $this->run_task();

        // 游標走到了第二門課，代表第一門的問題沒有中止整批。
        $this->assertEquals($ids[1], get_config('local_universityai', weekly_summary::CURSOR_SETTING));
    }
}
