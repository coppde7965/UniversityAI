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

use local_universityai\task\parse_syllabus;

/**
 * 事件觀察者（§4.6）。
 *
 * 進入點層：**只排任務，不做事**。觀察者跑在觸發它的那個請求裡，在裡面
 * 解析課綱會讓上傳請求卡住整個 API 呼叫的時間，而使用者只會看到瀏覽器
 * 一直轉。這是 `FR-NTF-03` 的驗收條件已經釘死的規則。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * 課綱上傳後排入解析任務。
     *
     * @param \local_universityai\event\syllabus_uploaded $event 事件
     * @return void
     */
    public static function syllabus_uploaded(event\syllabus_uploaded $event): void {
        parse_syllabus::queue((int) $event->objectid, (int) $event->userid);
    }
}
