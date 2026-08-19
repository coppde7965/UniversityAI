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

/**
 * 營運狀態紀錄（DM-OPSTATUS）。
 *
 * Model 層，遵守 docs/architecture.md §2.2 的硬規則：不碰任何 output、
 * renderer、moodle_url 或 html_writer，也不回傳含 HTML 的字串。
 *
 * 這張表有兩個用途，看起來像但其實不同：
 *   1. FR-DSH-02 的營運面板——管理者看最近發生什麼。
 *   2. FR-NTF-02 的通知去重——查「有沒有對這個對象發過這種通知」。
 * 第 2 點是 (targettype, targetid) 索引存在的第二個理由。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ops {
    /** @var string 資料表名。 */
    public const TABLE = 'local_universityai_ops';

    /** @var string 課綱解析（FR-SYL-02）。 */
    public const OP_SYLLABUS_PARSE = 'syllabus_parse';

    /** @var string 依課綱填入課程資訊（FR-CRS-01）。 */
    public const OP_COURSE_POPULATE = 'course_populate';

    /** @var string 建立課程事件（FR-CAL-01）。 */
    public const OP_EVENT_CREATE = 'event_create';

    /** @var string 推送至外部行事曆（FR-CAL-03）。 */
    public const OP_CALENDAR_PUSH = 'calendar_push';

    /** @var string 每週摘要（FR-SUM-01）。 */
    public const OP_WEEKLY_SUMMARY = 'weekly_summary';

    /** @var string 發出通知（FR-NTF-01）。 */
    public const OP_NOTIFY = 'notify';

    /** @var string 對象為課綱版本。 */
    public const TARGET_SYLLABUS = 'syllabus';

    /** @var string 對象為課程。 */
    public const TARGET_COURSE = 'course';

    /** @var string 對象為事件。 */
    public const TARGET_EVENT = 'event';

    /** @var string 對象為使用者。 */
    public const TARGET_USER = 'user';

    /** @var string 成功。 */
    public const STATUS_SUCCESS = 'success';

    /** @var string 已失敗且不再重試。 */
    public const STATUS_FAILED = 'failed';

    /** @var string 失敗但仍會重試。 */
    public const STATUS_RETRYING = 'retrying';

    /**
     * 允許的作業類型。
     *
     * @return string[]
     */
    public static function operations(): array {
        return [
            self::OP_SYLLABUS_PARSE,
            self::OP_COURSE_POPULATE,
            self::OP_EVENT_CREATE,
            self::OP_CALENDAR_PUSH,
            self::OP_WEEKLY_SUMMARY,
            self::OP_NOTIFY,
        ];
    }

    /**
     * 允許的對象類型。
     *
     * @return string[]
     */
    public static function targettypes(): array {
        return [
            self::TARGET_SYLLABUS,
            self::TARGET_COURSE,
            self::TARGET_EVENT,
            self::TARGET_USER,
        ];
    }

    /**
     * 允許的狀態。
     *
     * @return string[]
     */
    public static function statuses(): array {
        return [
            self::STATUS_SUCCESS,
            self::STATUS_FAILED,
            self::STATUS_RETRYING,
        ];
    }

    /**
     * 寫入一筆營運狀態。
     *
     * 刻意**不提供 error 參數**。DM-OPSTATUS 的 error 欄位在資料表中存在，
     * 但寫入前必須經過 docs/architecture.md §6 的外送資料過濾——例外訊息
     * 常把整段請求內容夾帶進來，其中可能有課綱原文與老師的聯絡方式，直接
     * 寫進資料庫等於繞過 NFR-SEC-06。過濾元件在第三階段才建立，在那之前
     * 不開放這條寫入路徑，比留一個「記得之後要加過濾」的註解可靠。
     *
     * @param string $operation 見 self::operations()
     * @param string $targettype 見 self::targettypes()
     * @param string $targetid 對象識別，最長 64 字元
     * @param string $status 見 self::statuses()
     * @param int $attempt 第幾次嘗試，自 1 起算
     * @return int 新紀錄的 id
     * @throws \coding_exception 傳入未定義的列舉值或過長的 targetid 時
     */
    public static function record(
        string $operation,
        string $targettype,
        string $targetid,
        string $status,
        int $attempt = 1
    ): int {
        global $DB;

        if (!in_array($operation, self::operations(), true)) {
            throw new \coding_exception('未定義的作業類型：' . $operation);
        }
        if (!in_array($targettype, self::targettypes(), true)) {
            throw new \coding_exception('未定義的對象類型：' . $targettype);
        }
        if (!in_array($status, self::statuses(), true)) {
            throw new \coding_exception('未定義的狀態：' . $status);
        }
        if ($targetid === '' || \core_text::strlen($targetid) > 64) {
            throw new \coding_exception('targetid 必須為 1 至 64 字元。');
        }
        if ($attempt < 1) {
            throw new \coding_exception('attempt 自 1 起算。');
        }

        return $DB->insert_record(self::TABLE, (object) [
            'operation' => $operation,
            'targettype' => $targettype,
            'targetid' => $targetid,
            'status' => $status,
            'attempt' => $attempt,
            'error' => null,
            'occurredat' => time(),
        ]);
    }

    /**
     * 刪除超過保留期的紀錄。
     *
     * 分批刪除而非一次 DELETE：跑久了這張表會很大，單次刪除數十萬列會讓
     * 交易與鎖持續整個 cron 週期。單次上限交由呼叫端給，理由同 A-04。
     *
     * @param int $retentiondays 保留天數，0 或負數表示不清理
     * @param int $limit 單次最多刪除幾列
     * @return int 實際刪除的列數
     */
    public static function prune(int $retentiondays, int $limit): int {
        global $DB;

        if ($retentiondays <= 0 || $limit <= 0) {
            return 0;
        }

        $cutoff = time() - ($retentiondays * DAYSECS);

        $ids = $DB->get_records_select(
            self::TABLE,
            'occurredat < :cutoff',
            ['cutoff' => $cutoff],
            'occurredat ASC',
            'id',
            0,
            $limit
        );
        if (!$ids) {
            return 0;
        }

        $DB->delete_records_list(self::TABLE, 'id', array_keys($ids));

        return count($ids);
    }
}
