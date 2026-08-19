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
 * 課綱檔案已上傳（FR-SYL-01）。
 *
 * 自訂事件同時滿足 `NFR-SEC-04` 的操作紀錄要求（§4.6）：Moodle 事件會自動
 * 進入標準記錄檔，含操作者與時間，且一般使用者無法刪改。**不要另建一套
 * 稽核表**——那份表會與這裡的紀錄不一致，而且沒有人會去維護它。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class syllabus_uploaded extends \core\event\base {
    /**
     * 初始化事件屬性。
     *
     * crud 為 c（建立），教育等級為 TEACHING——上傳課綱是教師的教學行為，
     * 不是參與或其他。
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'local_universityai_syllabus';
    }

    /**
     * 記錄檔中顯示的事件名稱。
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:syllabusuploaded', 'local_universityai');
    }

    /**
     * 記錄檔中的敘述。
     *
     * 只寫 id 與課程，**不寫檔名**：檔名由使用者提供，可能含姓名或學號，
     * 而記錄檔會被匯出（NFR-SEC-06）。要查是哪一個檔案的人，查得到課綱 id
     * 就查得到檔案。
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' uploaded a syllabus with id "
            . "'{$this->objectid}' in the course with id '{$this->courseid}'.";
    }

    /**
     * 記錄檔中連往的網址。
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/universityai/upload.php', ['id' => $this->courseid]);
    }
}
