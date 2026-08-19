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

namespace local_universityai\event;

/**
 * 課綱已由老師確認（FR-SYL-04）。
 *
 * `FR-SYL-04` 要求「確認動作記錄確認者與時間」。資料表的 `confirmedby` 與
 * `confirmedat` 記的是**當前狀態**，這個事件記的是**發生過的事**——兩者不
 * 重複：老師若重新確認，欄位會被覆寫，而事件不會。`NFR-SEC-04` 要的是後者。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class syllabus_confirmed extends \core\event\base {
    /**
     * 初始化事件屬性。
     *
     * crud 為 u（更新）：確認改變的是既有課綱的狀態，不是建立新東西。
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'local_universityai_syllabus';
    }

    /**
     * 記錄檔中顯示的事件名稱。
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:syllabusconfirmed', 'local_universityai');
    }

    /**
     * 記錄檔中的敘述。
     *
     * 帶上被人工覆寫的項目數，因為那是這個動作最重要的後果：被覆寫的欄位
     * 在後續重新解析時不得被覆蓋（`FR-DIF-02`）。**不寫覆寫的內容**——
     * 那是課綱文字，而記錄檔會被匯出（`NFR-SEC-06`）。
     *
     * @return string
     */
    public function get_description() {
        $overrides = $this->other['overrides'] ?? 0;

        return "The user with id '{$this->userid}' confirmed the syllabus with id "
            . "'{$this->objectid}' in the course with id '{$this->courseid}', "
            . "overriding {$overrides} item(s).";
    }

    /**
     * 記錄檔中連往的網址。
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/universityai/review.php', ['id' => $this->objectid]);
    }
}
