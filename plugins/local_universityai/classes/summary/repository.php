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

/**
 * 每週摘要的保存與查詢。
 *
 * FR-SUM-01 要求「產生的摘要保留版本與生成時間」，NFR-MNT-03 再加上「可回溯
 * 至來源課綱版本與使用的模型」。四件事都是欄位，不是註解。
 *
 * 冪等性（NFR-REL-04）落在 (courseid, weekstart) 這組鍵上：同一門課同一個
 * 教學週已經有摘要就不再產生。**不用日曆週**——weekstart 是課綱推算出的教學週
 * 起日，兩門開學日不同的課本來就該有不同的週界線（見 context_builder）。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository {
    /** @var string 資料表名。 */
    public const TABLE = 'local_universityai_summary';

    /**
     * 該課該教學週是否已有摘要。
     *
     * @param int $courseid 課程 id
     * @param int $weekstart 教學週起日的時間戳
     * @return bool
     */
    public function exists(int $courseid, int $weekstart): bool {
        global $DB;

        return $DB->record_exists(self::TABLE, ['courseid' => $courseid, 'weekstart' => $weekstart]);
    }

    /**
     * 保存一則摘要。
     *
     * version 於同一個 (courseid, weekstart) 內遞增。排程任務永遠不會走到
     * version 2——它遇到已存在就跳過；會走到的是刻意重新產生的情況（CLI 的
     * --force），此時舊版保留而不是被覆蓋，這是 FR-SUM-01「保留版本」的實質。
     *
     * @param int $courseid 課程 id
     * @param array $facts context_builder 的事實，整份存下以供回溯
     * @param array $result generator::generate() 的結果
     * @return int 新紀錄的 id
     */
    public function save(int $courseid, array $facts, array $result): int {
        global $DB;

        $weekstart = (int) ($facts['weekstart'] ?? 0);
        $now = time();

        return $DB->insert_record(self::TABLE, (object) [
            'courseid' => $courseid,
            'weekstart' => $weekstart,
            'weekno' => (int) ($facts['weekno'] ?? 0),
            'version' => $this->next_version($courseid, $weekstart),
            'syllabusversion' => (int) ($facts['syllabusversion'] ?? 0),
            'status' => $result['status'],
            'content' => $result['content'],

            // 連同事實一起存。摘要事後看起來不對時，唯一能回答「是模型寫歪了
            // 還是資料本來就錯」的東西就是這一欄——重跑一次拿不回當時的輸入。
            'facts' => json_encode($facts, JSON_UNESCAPED_UNICODE),
            'model' => $result['model'],
            'generatedat' => $now,
            'timecreated' => $now,
        ]);
    }

    /**
     * 下一個版本號。
     *
     * @param int $courseid 課程 id
     * @param int $weekstart 教學週起日
     * @return int
     */
    public function next_version(int $courseid, int $weekstart): int {
        global $DB;

        $max = $DB->get_field_sql(
            "SELECT MAX(version) FROM {" . self::TABLE . "} WHERE courseid = :courseid AND weekstart = :weekstart",
            ['courseid' => $courseid, 'weekstart' => $weekstart]
        );

        return ((int) $max) + 1;
    }

    /**
     * 取一門課最近的摘要，每個教學週只取最新版本。
     *
     * @param int $courseid 課程 id
     * @param int $limit 最多幾週
     * @return \stdClass[] 由新到舊
     */
    public function get_recent(int $courseid, int $limit): array {
        global $DB;

        $records = $DB->get_records(
            self::TABLE,
            ['courseid' => $courseid],
            'weekstart DESC, version DESC'
        );

        $latest = [];
        foreach ($records as $record) {
            if (!isset($latest[$record->weekstart])) {
                $latest[$record->weekstart] = $record;
            }
        }

        return array_slice(array_values($latest), 0, $limit);
    }
}
