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
 * 課綱確認與逐項修正（FR-SYL-03、FR-SYL-04）。
 *
 * 進入點層（§2.2）：接收請求 → 檢查權限 → 呼叫 Model → 交給表單。
 *
 * 參數是**課綱 id 而不是課程 id**，而課程由課綱查得——前端傳入的識別碼一律
 * 重新解析為脈絡後檢查，不採信（§5.2）。老師拿到別門課的課綱 id 也進不來，
 * 因為能力檢查跑在那門課的脈絡上。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

use local_universityai\confirmation_service;
use local_universityai\form\review_form;
use local_universityai\syllabus_repository;

$syllabusid = required_param('id', PARAM_INT);

$repository = new syllabus_repository();
$syllabus = $repository->get($syllabusid);

if ($syllabus === null) {
    throw new moodle_exception('error:syllabusnotfound', 'local_universityai');
}

$course = get_course($syllabus->courseid);

require_login($course);

$context = context_course::instance($course->id);
require_capability('local/universityai:confirm', $context);

$url = new moodle_url('/local/universityai/review.php', ['id' => $syllabusid]);
$uploadurl = new moodle_url('/local/universityai/upload.php', ['id' => $course->id]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('review:pagetitle', 'local_universityai'));
$PAGE->set_heading($course->fullname);

$payload = $repository->decode($syllabus);

// 還沒解析完、解析失敗、或 payload 壞掉的課綱沒有東西可以確認。回上傳頁
// 並說明原因，而不是顯示一個空表單——空表單會讓人以為課綱是空的。
if ($payload === null) {
    redirect(
        $uploadurl,
        get_string('review:notready', 'local_universityai', get_string(
            'syllabus:status:' . $syllabus->status,
            'local_universityai'
        )),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$service = new confirmation_service($repository);
$annotated = $service->annotate($payload);

$form = new review_form($url, ['syllabusid' => $syllabusid, 'annotated' => $annotated]);

if ($form->is_cancelled()) {
    redirect($uploadurl);
}

if ($data = $form->get_data()) {
    try {
        $overrides = $service->confirm($syllabusid, $data, $USER->id, $context);
    } catch (moodle_exception $e) {
        redirect($uploadurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }

    redirect(
        $uploadurl,
        get_string('review:confirmed', 'local_universityai', $overrides),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('review:pagetitle', 'local_universityai'));

// 已確認的課綱仍然打得開——老師需要看得到自己確認過什麼。但要說清楚
// 再次送出會發生什麼事（狀態已是 confirmed，confirm() 會拒絕）。
if ($syllabus->status === syllabus_repository::STATUS_CONFIRMED) {
    echo $OUTPUT->notification(
        get_string('review:alreadyconfirmed', 'local_universityai'),
        \core\output\notification::NOTIFY_INFO
    );
}

if ($annotated['lowcount'] > 0) {
    echo $OUTPUT->notification(
        get_string('review:lowcount', 'local_universityai', $annotated['lowcount']),
        \core\output\notification::NOTIFY_WARNING
    );
}

$form->display();
echo $OUTPUT->footer();
