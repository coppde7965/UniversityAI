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
 * 課程內的每週摘要（FR-SUM-01 的「發布於課程中供師生檢視」）。
 *
 * 進入點層（docs/architecture.md §2.2）：接收請求 → 檢查權限 → 呼叫 Model
 * → 交給 renderer，不含任何業務邏輯。
 *
 * 權限樣式照 §5.2 的頁面腳本那一列：require_login($course) 之後在課程脈絡
 * 上檢查能力。**課程 id 一律重新解析為脈絡再檢查**，不採信前端傳入的值。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

/** @var int 頁面顯示最近幾週。設成常數而非站台設定：沒有人需要調整它。 */
const LOCAL_UNIVERSITYAI_SUMMARY_WEEKS = 8;

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);

require_login($course);

$context = context_course::instance($course->id);
require_capability('local/universityai:viewsummary', $context);

$url = new moodle_url('/local/universityai/summary.php', ['id' => $course->id]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('summary:pagetitle', 'local_universityai'));
$PAGE->set_heading($course->fullname);

$summaries = (new \local_universityai\summary\repository())
    ->get_recent($course->id, LOCAL_UNIVERSITYAI_SUMMARY_WEEKS);

$renderer = $PAGE->get_renderer('local_universityai');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('summary:pagetitle', 'local_universityai'));
echo $renderer->render(new \local_universityai\output\summary_page($summaries));
echo $OUTPUT->footer();
