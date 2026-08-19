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
 * 課綱上傳（FR-SYL-01）。
 *
 * 進入點層（§2.2）：接收請求 → 檢查權限 → 呼叫 Model → 交給 renderer。
 *
 * 上傳介面**位於課程脈絡內**，這是 `D-10` 之後的前提：課程外殼必定先存在，
 * 課綱識別碼自始即繫結所屬課程。`require_login($course)` 帶課程參數，因此
 * 未選課者、其他課程的老師一律被擋（`FR-AUT-01`）。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

use local_universityai\form\upload_form;
use local_universityai\syllabus_repository;
use local_universityai\upload_service;

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);

require_login($course);

$context = context_course::instance($course->id);
require_capability('local/universityai:upload', $context);

$url = new moodle_url('/local/universityai/upload.php', ['id' => $course->id]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('upload:pagetitle', 'local_universityai'));
$PAGE->set_heading($course->fullname);

$maxbytes = get_max_upload_file_size($CFG->maxbytes, $course->maxbytes);
$form = new upload_form($url, ['courseid' => $course->id, 'maxbytes' => $maxbytes]);
$repository = new syllabus_repository();

if ($form->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $course->id]));
}

if ($data = $form->get_data()) {
    // 業務邏輯全在 upload_service（§2.2）。這裡只負責把例外轉成使用者
    // 看得懂的畫面——那三種例外的訊息本來就是寫給老師看的（NFR-USA-03），
    // 所以原樣顯示，不再包裝。
    try {
        $syllabusid = (new upload_service())->accept($course->id, $data->syllabusfile, $USER->id, $context);
    } catch (moodle_exception $e) {
        redirect($url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }

    redirect(
        $url,
        get_string('upload:queued', 'local_universityai', $syllabusid),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$renderer = $PAGE->get_renderer('local_universityai');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('upload:pagetitle', 'local_universityai'));
$form->display();
echo $renderer->render(new \local_universityai\output\syllabus_list(
    $repository->get_all_for_course($course->id),
    $course->id
));
echo $OUTPUT->footer();
