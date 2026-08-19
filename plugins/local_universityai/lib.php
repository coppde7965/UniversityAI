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
 * 外掛的回呼函式。
 *
 * local 外掛只能以這裡的回呼把自己掛進課程導覽，沒有別的機制——這是
 * FR-SUM-01「發布於課程中供師生檢視」在 Moodle 上的具體形式。
 *
 * 這個檔案在**每一個頁面請求**都會被載入，所以裡面只放回呼，不放任何
 * 會執行的程式碼，也不要 require 其他檔案。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * 在課程導覽中加入每週摘要的連結。
 *
 * 沒有 viewsummary 能力的人連連結都不該看到。這不是唯一的防線——
 * summary.php 自己也會檢查一次（§5.2），因為導覽項目藏起來不等於網址擋住了。
 *
 * @param navigation_node $navigation 課程導覽節點
 * @param stdClass $course 課程
 * @param context_course $context 課程脈絡
 * @return void
 */
function local_universityai_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
): void {
    if (has_capability('local/universityai:viewsummary', $context)) {
        $navigation->add(
            get_string('summary:navtitle', 'local_universityai'),
            new moodle_url('/local/universityai/summary.php', ['id' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_universityai_summary',
            new pix_icon('i/report', '')
        );
    }

    if (has_capability('local/universityai:upload', $context)) {
        $navigation->add(
            get_string('upload:navtitle', 'local_universityai'),
            new moodle_url('/local/universityai/upload.php', ['id' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_universityai_upload',
            new pix_icon('i/upload', '')
        );
    }
}

/**
 * 送出課綱原始檔案。
 *
 * `FR-SYL-01` 的最後一條驗收條件：「上傳的原始檔案保留於該課程的檔案區，
 * 供日後查核」。保留了但下載不到等於沒保留，所以這個回呼是那條驗收條件的
 * 一部分，不是附加功能。
 *
 * 權限用 `upload` 而不是另設一個 `viewfile`：能上傳課綱的人本來就看得到
 * 自己上傳的東西，多一個能力只會讓權限矩陣更難解釋（`NFR-SEC-01` 的最小
 * 授權指的是不要有多餘的路徑，不是不要有多餘的能力名稱）。
 *
 * @param stdClass $course 課程
 * @param stdClass|null $cm 課程模組，local 外掛一律為 null
 * @param context $context 檔案所屬脈絡
 * @param string $filearea 檔案區
 * @param array $args 其餘路徑片段，第一個是 itemid
 * @param bool $forcedownload 是否強制下載
 * @param array $options 送檔選項
 * @return bool 僅在找不到檔案時回傳 false
 */
function local_universityai_pluginfile(
    $course,
    $cm,
    $context,
    $filearea,
    $args,
    $forcedownload,
    array $options = []
): bool {
    if ($context->contextlevel !== CONTEXT_COURSE) {
        return false;
    }
    if ($filearea !== \local_universityai\syllabus_repository::FILE_AREA) {
        return false;
    }

    require_login($course, false, $cm);
    require_capability('local/universityai:upload', $context);

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $file = get_file_storage()->get_file(
        $context->id,
        \local_universityai\syllabus_repository::FILE_COMPONENT,
        $filearea,
        $itemid,
        $filepath,
        $filename
    );

    if (!$file || $file->is_directory()) {
        return false;
    }

    // 一律強制下載：課綱是 PDF 或 DOCX，讓瀏覽器內嵌開啟沒有好處，
    // 而 PDF 在瀏覽器內執行的風險是真的（Moodle 核心對使用者上傳的檔案
    // 也是這個立場）。
    send_stored_file($file, 0, 0, true, $options);

    return true;
}
