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
 * 依已確認課綱填入課程、章節與事件（FR-CRS-01、FR-CRS-02、FR-CAL-01）。
 *
 * 進入點層（§2.2）：接收請求 → 檢查權限 → 呼叫 Model → 交給表單。
 *
 * **這一步刻意由老師觸發，不由 `syllabus_confirmed` 觀察者自動排入。**
 * docs/architecture.md §4.3 原本把 `populate_course` 列為臨機任務，但
 * `FR-CRS-01` 的驗收條件要求「課程既有欄位若已有內容且與課綱不同，須先向
 * 老師呈現將被覆寫的項目，不得靜默蓋掉」——背景任務沒有人可以呈現。
 * 偏離已記於 `A-25`。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

use local_universityai\course_populator;
use local_universityai\form\populate_form;
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
require_capability('local/universityai:populate', $context);

$url = new moodle_url('/local/universityai/populate.php', ['id' => $syllabusid]);
$uploadurl = new moodle_url('/local/universityai/upload.php', ['id' => $course->id]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('populate:pagetitle', 'local_universityai'));
$PAGE->set_heading($course->fullname);

$populator = new course_populator($repository);

// 狀態不對就在這裡擋下，訊息說清楚是哪一種狀態——這是 FR-CRS-01 的第一條
// 驗收條件，也是 FR-SYL-04 那條「待確認時呼叫課程建立應被拒絕」的另一面。
try {
    $preview = $populator->preview($syllabusid);
} catch (moodle_exception $e) {
    redirect($uploadurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
}

$form = new populate_form($url, ['syllabusid' => $syllabusid, 'preview' => $preview]);

if ($form->is_cancelled()) {
    redirect($uploadurl);
}

if ($form->get_data()) {
    try {
        $result = $populator->apply($syllabusid, $USER->id);
    } catch (moodle_exception $e) {
        redirect($uploadurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }

    redirect(
        new moodle_url('/course/view.php', ['id' => $course->id]),
        get_string('populate:done', 'local_universityai', (object) $result),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$overwrites = count(array_filter($preview['fields'], static fn($f) => $f['overwrites']));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('populate:pagetitle', 'local_universityai'));

if ($overwrites > 0) {
    echo $OUTPUT->notification(
        get_string('populate:overwritewarning', 'local_universityai', $overwrites),
        \core\output\notification::NOTIFY_WARNING
    );
}

$form->display();
echo $OUTPUT->footer();
