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

/**
 * 事件觀察者註冊。
 *
 * 對應 docs/architecture.md §4.6。
 *
 * **觀察者只負責排入任務，不在觀察者內執行耗時工作。** 這是 `FR-NTF-03` 的
 * 驗收條件已經釘死的事：觀察者跑在觸發它的那個請求裡，在裡面做解析會讓
 * 上傳請求卡住三分鐘，而使用者只會看到瀏覽器一直轉。
 *
 * 目前只註冊 syllabus_uploaded。其餘（syllabus_confirmed、核心的
 * user_enrolment_deleted 與 course_deleted）於各自的功能建立時加入——
 * 註冊一個指向不存在方法的觀察者會在事件觸發時才爆，而那時多半沒有人在看。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\local_universityai\event\syllabus_uploaded',
        'callback' => '\local_universityai\observer::syllabus_uploaded',
    ],
];
