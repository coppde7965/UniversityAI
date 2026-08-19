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
 * 課綱版本查詢。
 *
 * Model 層。A-02 決定了整份 DM-SYLLABUS 以 JSON 存在單一欄位，因此這裡的
 * 每個查詢都是「整列讀出、在 PHP 端解碼」——沒有「用 SQL 查 JSON 內部」
 * 這條路，XMLDB 沒有 JSON 型別（§3.1）。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class syllabus_repository {
    /** @var string 資料表名。 */
    public const TABLE = 'local_universityai_syllabus';

    /** @var string 檔案已收下，尚未解析（FR-SYL-01）。 */
    public const STATUS_UPLOADED = 'uploaded';

    /** @var string 已解析、尚未經老師確認。 */
    public const STATUS_PARSED = 'parsed';

    /** @var string 已由老師確認（FR-SYL-04）。 */
    public const STATUS_CONFIRMED = 'confirmed';

    /** @var string 解析失敗，保留紀錄供查核。 */
    public const STATUS_FAILED = 'failed';

    /** @var string Moodle 檔案 API 的元件名。 */
    public const FILE_COMPONENT = 'local_universityai';

    /** @var string 原始課綱檔案的檔案區。 */
    public const FILE_AREA = 'syllabus';

    /**
     * 取得某門課最新的已確認課綱。
     *
     * 「最新」以 version 判定而不是 confirmedat：version 的語意是第幾次上傳
     * （SRS §1.6），單調遞增且不受時鐘影響。老師先確認第 2 版、事後又補確認
     * 第 1 版時，時間戳會給出錯的答案。
     *
     * @param int $courseid 課程 id
     * @return \stdClass|null 找不到時回傳 null
     */
    public function get_latest_confirmed(int $courseid): ?\stdClass {
        global $DB;

        $records = $DB->get_records(
            self::TABLE,
            ['courseid' => $courseid, 'status' => self::STATUS_CONFIRMED],
            'version DESC',
            '*',
            0,
            1
        );

        return $records ? reset($records) : null;
    }

    /**
     * 取得有已確認課綱的課程 id，供排程任務以游標分批處理（A-04）。
     *
     * 回傳的是課程 id 而不是課綱列，因為呼叫端要的是「有哪些課要處理」；
     * 一門課有多個已確認版本時，這裡只該出現一次。
     *
     * @param int $aftercourseid 游標：只取大於此值的課程 id
     * @param int $limit 單次上限
     * @return int[] 由小到大排序的課程 id
     */
    public function get_courseids_with_confirmed(int $aftercourseid, int $limit): array {
        global $DB;

        $sql = "SELECT courseid, MAX(version) AS latestversion
                  FROM {" . self::TABLE . "}
                 WHERE status = :status
                       AND courseid > :cursor
              GROUP BY courseid
              ORDER BY courseid ASC";

        $rows = $DB->get_records_sql(
            $sql,
            ['status' => self::STATUS_CONFIRMED, 'cursor' => $aftercourseid],
            0,
            $limit
        );

        return array_map('intval', array_keys($rows));
    }

    /**
     * 取單一課綱版本。
     *
     * @param int $id 課綱 id
     * @return \stdClass|null
     */
    public function get(int $id): ?\stdClass {
        global $DB;

        return $DB->get_record(self::TABLE, ['id' => $id]) ?: null;
    }

    /**
     * 收下一份新上傳的課綱，尚未解析（FR-SYL-01）。
     *
     * 版本號在這裡就決定，而不是等解析完成——`FR-SYL-01` 的驗收條件要求
     * 「回傳可追蹤的課綱識別碼，且該識別碼自始即繫結所屬課程」。解析失敗
     * 的那一版仍然佔一個版本號，因為它確實是「第幾次上傳」（SRS §1.6）。
     *
     * @param int $courseid 課程 id
     * @param string $contenthash 原始檔案的 contenthash
     * @param int $userid 上傳者
     * @return int 新課綱的 id
     */
    public function create_upload(int $courseid, string $contenthash, int $userid): int {
        global $DB;

        $now = time();

        return $DB->insert_record(self::TABLE, (object) [
            'courseid' => $courseid,
            'version' => $this->next_version($courseid),
            'status' => self::STATUS_UPLOADED,
            'payload' => null,
            'contenthash' => $contenthash,
            'parsedat' => 0,
            'usermodified' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * 同一課程內的下一個版本號。
     *
     * @param int $courseid 課程 id
     * @return int
     */
    public function next_version(int $courseid): int {
        global $DB;

        $max = $DB->get_field_sql(
            "SELECT MAX(version) FROM {" . self::TABLE . "} WHERE courseid = :courseid",
            ['courseid' => $courseid]
        );

        return ((int) $max) + 1;
    }

    /**
     * 寫入解析結果（FR-SYL-02）。
     *
     * 狀態轉為 parsed 而不是 confirmed：`FR-SYL-04` 要求解析完成後是「待確認」，
     * 且此時呼叫課程建立應被拒絕。
     *
     * @param int $id 課綱 id
     * @param array $payload DM-SYLLABUS
     * @param string $model 產生此版本的模型（NFR-MNT-03）
     * @return void
     */
    public function mark_parsed(int $id, array $payload, string $model): void {
        global $DB;

        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => self::STATUS_PARSED,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'model' => $model,
            'parsedat' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * 寫入老師確認後的結果（FR-SYL-04）。
     *
     * 修改寫回**同一版**的 payload，不另開版本（§3.3）：版本的語意是「第幾次
     * 上傳」，老師改一個字就跳版會讓比對變得沒有意義。
     *
     * @param int $id 課綱 id
     * @param array $payload 已套用修改的 DM-SYLLABUS
     * @param int $userid 確認者（FR-SYL-04 要求記錄）
     * @return void
     */
    public function mark_confirmed(int $id, array $payload, int $userid): void {
        global $DB;

        $now = time();

        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => self::STATUS_CONFIRMED,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'confirmedby' => $userid,
            'confirmedat' => $now,
            'usermodified' => $userid,
            'timemodified' => $now,
        ]);
    }

    /**
     * 標記解析失敗。
     *
     * **不刪除紀錄。** `FR-SYL-02` 要求「連續失敗達上限則記為失敗並保留原始
     * 回應供查核」，而且老師需要看得到「我上傳的那一份怎麼了」——刪掉的話
     * 上傳頁面會顯示成從來沒有人上傳過。
     *
     * @param int $id 課綱 id
     * @return void
     */
    public function mark_failed(int $id): void {
        global $DB;

        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => self::STATUS_FAILED,
            'timemodified' => time(),
        ]);
    }

    /**
     * 取一門課的所有版本，由新到舊。
     *
     * @param int $courseid 課程 id
     * @return \stdClass[]
     */
    public function get_all_for_course(int $courseid): array {
        global $DB;

        return $DB->get_records(self::TABLE, ['courseid' => $courseid], 'version DESC');
    }

    /**
     * 解碼一列課綱的 payload。
     *
     * 解不開時回傳 null 而不是拋例外：payload 是外部（語言模型）產生的內容，
     * 壞掉是可預期的情況，呼叫端該把它當成「這門課這次跳過」而不是整批中止
     * （NFR-REL-03）。
     *
     * @param \stdClass $record 課綱列
     * @return array|null
     */
    public function decode(\stdClass $record): ?array {
        $decoded = json_decode((string) $record->payload, true);

        return is_array($decoded) ? $decoded : null;
    }
}
