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

use local_universityai\ops;
use local_universityai\redaction\filter;
use local_universityai\summary\service;
use local_universityai\syllabus_repository;

/**
 * 每週為每門有已確認課綱的課程產生摘要（FR-SUM-01）。
 *
 * 進入點層：只負責游標、分批與故障隔離，單一課程的處理全在
 * \local_universityai\summary\service。
 *
 * **A-04：游標分批。** 一次只處理 batchsize 門課，游標存在外掛設定裡，
 * 下次自上次結束處續跑，一輪跑完歸零。代價是「每週摘要」不保證在同一分鐘內
 * 全部發出——摘要是週期性資訊，不是即時通知，這個代價可以接受。
 *
 * **NFR-REL-03：故障隔離。** 每門課各自包在 try/catch 內，失敗寫 _ops 後
 * 繼續下一門。這是 §4.4「不要自行實作重試迴圈」的例外情形嗎？不是——
 * 這裡沒有重跑任何東西，只是不讓一門課的例外中止整批。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class weekly_summary extends \core\task\scheduled_task {
    /** @var int 站台設定缺席時的單次處理課程數上限。 */
    public const DEFAULT_BATCH_SIZE = 50;

    /** @var string 游標的設定鍵。存的是上次處理到的課程 id。 */
    public const CURSOR_SETTING = 'summarycursor';

    /**
     * 排程頁面上顯示的名稱。
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:weeklysummary', 'local_universityai');
    }

    /**
     * 執行一批。
     *
     * @return void
     */
    public function execute() {
        $batchsize = (int) get_config('local_universityai', 'batchsize');
        if ($batchsize <= 0) {
            $batchsize = self::DEFAULT_BATCH_SIZE;
        }

        $cursor = (int) get_config('local_universityai', self::CURSOR_SETTING);
        $courseids = (new syllabus_repository())->get_courseids_with_confirmed($cursor, $batchsize);

        if ($courseids === []) {
            // 游標已到底。歸零讓下一次排程從頭再跑一輪。
            set_config(self::CURSOR_SETTING, 0, 'local_universityai');
            mtrace('local_universityai: 沒有待處理的課程，游標歸零。');

            return;
        }

        $service = new service();
        $tally = [];

        foreach ($courseids as $courseid) {
            try {
                $outcome = $service->generate_for_course($courseid);
                $tally[$outcome['result']] = ($tally[$outcome['result']] ?? 0) + 1;
                $this->trace_outcome($courseid, $outcome);
            } catch (\Throwable $e) {
                // 例外在此吸收而不是往上拋：往上拋會讓整批停在這門課，
                // 後面的課程要等到下一次排程才有機會（NFR-REL-03）。
                //
                // text_client 已經為「呼叫失敗」寫過 _ops，這裡記的是它之外的
                // 例外——資料庫錯誤、課綱結構壞掉之類。
                $tally['exception'] = ($tally['exception'] ?? 0) + 1;
                ops::record(
                    ops::OP_WEEKLY_SUMMARY,
                    ops::TARGET_COURSE,
                    (string) $courseid,
                    ops::STATUS_FAILED
                );
                mtrace('local_universityai: 課程 ' . $courseid . ' 摘要失敗：'
                    . $this->safe_message($e->getMessage()));
            }

            $cursor = $courseid;
        }

        // 這一批沒滿代表已經是最後一批，直接歸零而不是等下一次跑空。
        set_config(
            self::CURSOR_SETTING,
            count($courseids) < $batchsize ? 0 : $cursor,
            'local_universityai'
        );

        mtrace('local_universityai: 本批處理 ' . count($courseids) . ' 門課　'
            . $this->format_tally($tally));
    }

    /**
     * 輸出單一課程的結果。
     *
     * @param int $courseid 課程 id
     * @param array $outcome service::generate_for_course() 的回傳
     * @return void
     */
    private function trace_outcome(int $courseid, array $outcome): void {
        if ($outcome['result'] === service::RESULT_CREATED) {
            mtrace('local_universityai: 課程 ' . $courseid . ' 第 ' . $outcome['weekno'] . ' 週摘要已產生（'
                . $outcome['status'] . '　' . ($outcome['model'] ?: '未呼叫模型')
                . '　' . $outcome['durationms'] . ' ms）。');

            return;
        }

        if ($outcome['result'] === service::RESULT_FAILED) {
            mtrace('local_universityai: 課程 ' . $courseid . ' 摘要生成失敗：'
                . $this->safe_message($outcome['errormessage']));
        }
    }

    /**
     * 過濾要寫進記錄的訊息。
     *
     * mtrace 的內容會進 Moodle 的排程記錄並保存在資料庫裡，因此它是一條
     * **外送路徑的變體**（§6.3）：外部服務的錯誤訊息常把整段請求內容回貼
     * 回來，而排程記錄會被匯出、被截圖、被貼進問題回報。
     *
     * @param string $message 原始訊息
     * @return string
     */
    private function safe_message(string $message): string {
        return filter::create()->redact($message);
    }

    /**
     * 把各種結果的計數排成一行。
     *
     * @param array $tally 結果代號對應到出現次數
     * @return string
     */
    private function format_tally(array $tally): string {
        $parts = [];
        foreach ($tally as $result => $count) {
            $parts[] = $result . ' ' . $count;
        }

        return $parts ? implode('　', $parts) : '無結果';
    }
}
