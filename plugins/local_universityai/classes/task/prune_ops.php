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

/**
 * 清除超過保留期的營運狀態紀錄。
 *
 * 進入點層（docs/architecture.md §2.2）：只負責讀設定、呼叫 Model、回報，
 * 不含業務邏輯——刪除條件與分批策略都在 \local_universityai\ops::prune()。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prune_ops extends \core\task\scheduled_task {
    /** @var int 站台設定缺席時的保留天數。 */
    public const DEFAULT_RETENTION_DAYS = 90;

    /** @var int 站台設定缺席時的單次刪除上限。 */
    public const DEFAULT_BATCH_SIZE = 50;

    /**
     * 排程頁面上顯示的名稱。
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:pruneops', 'local_universityai');
    }

    /**
     * 執行清理。
     *
     * @return void
     */
    public function execute() {
        $retentiondays = (int) get_config('local_universityai', 'opsretentiondays');
        $batchsize = (int) get_config('local_universityai', 'batchsize');

        // 設定尚未寫入（例如剛安裝完、管理員還沒進過設定頁）時回到預設值。
        // get_config() 在此情況回傳 false，轉成 int 是 0，而 0 對 prune() 的
        // 語意是「不要清理」——會安靜地什麼都不做，正是最難察覺的那種失效。
        if ($retentiondays <= 0) {
            $retentiondays = self::DEFAULT_RETENTION_DAYS;
        }
        if ($batchsize <= 0) {
            $batchsize = self::DEFAULT_BATCH_SIZE;
        }

        $deleted = ops::prune($retentiondays, $batchsize);

        mtrace("local_universityai: 清除 {$deleted} 筆逾期營運紀錄"
            . "（保留 {$retentiondays} 天，單次上限 {$batchsize} 筆）。");
    }
}
