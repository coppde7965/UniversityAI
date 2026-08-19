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
 * 英文語言字串。
 *
 * 字串一律抽離至語言檔，不得寫死在程式中（NFR-MNT-04）。英文是必要的
 * 基準語言；繁體中文在 lang/zh_tw/，兩邊的鍵必須一致——
 * tests/lang_test.php 會比對，少一個就紅。
 *
 * 兩條 Moodle 語言檔的格式規則，phpcs 會檢查：
 *   1. 鍵必須嚴格照字母順序排列。
 *   2. 字串之間不可以插入註解——那會讓自動排序工具失效。
 * 所以下面是一整片沒有分組註解的清單，看起來不好讀，但這是上游的慣例。
 * 分組的語意由鍵的前綴承擔（operation:、targettype:、settings: …）。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['error:extractionfailed'] = 'The language model could not be reached while parsing the syllabus. The task will be retried automatically.';
$string['error:filemissing'] = 'The uploaded syllabus file could not be found in the course file area.';
$string['error:noextractor'] = 'No AI provider is configured for syllabus extraction yet. Ask a site administrator to add an API key to the AI provider, then the parsing task will succeed on its next attempt.';
$string['error:notconfirmable'] = 'This syllabus cannot be confirmed because its status is "{$a}". Only a parsed syllabus awaiting confirmation can be confirmed.';
$string['error:notextlayer'] = 'This {$a} file has no readable text layer, so it looks like a scan or a set of images. This version only supports PDF and DOCX files whose text can be selected. Please upload the original file, or convert the scan to selectable text first.';
$string['error:notpopulatable'] = 'This syllabus cannot be applied to the course because its status is {$a}. Only a confirmed syllabus can be applied.';
$string['error:syllabusnotfound'] = 'That syllabus could not be found.';
$string['error:unsupportedformat'] = 'The file format "{$a->given}" is not supported. Please upload one of: {$a->supported}.';
$string['error:validationfailed'] = 'The extracted syllabus failed validation on {$a} point(s), even after the model was asked to correct them.';
$string['event:syllabusconfirmed'] = 'Syllabus confirmed';
$string['event:syllabusuploaded'] = 'Syllabus uploaded';
$string['messageprovider:opsfailure'] = 'Background operation failed';
$string['notification:opsfailure:body'] = 'The operation "{$a->operation}" failed for {$a->targettype} {$a->targetid} after {$a->attempt} attempt(s) and will not be retried.

No details are included in this message on purpose. Open the operations dashboard on the site to see them.';
$string['notification:opsfailure:subject'] = 'UniversityAI: {$a->operation} failed';
$string['operation:calendar_push'] = 'Calendar push';
$string['operation:course_populate'] = 'Course population';
$string['operation:event_create'] = 'Event creation';
$string['operation:notify'] = 'Notification';
$string['operation:syllabus_parse'] = 'Syllabus parsing';
$string['operation:weekly_summary'] = 'Weekly summary';
$string['pluginname'] = 'UniversityAI';
$string['populate:action'] = 'Apply to course';
$string['populate:apply'] = 'Apply to course';
$string['populate:colcurrent'] = 'Current value';
$string['populate:colfield'] = 'Field';
$string['populate:colproposed'] = 'From the syllabus';
$string['populate:difftitle'] = 'Course fields that will be set';
$string['populate:done'] = 'Applied to the course: {$a->fields} field(s), {$a->sections} section(s) and {$a->events} calendar event(s).';
$string['populate:empty'] = 'empty';
$string['populate:events'] = 'Calendar events';
$string['populate:eventscount'] = '{$a} event(s) will be created or updated. Repeating this does not create duplicates.';
$string['populate:field:enddate'] = 'Course end date';
$string['populate:field:fullname'] = 'Course full name';
$string['populate:field:startdate'] = 'Course start date';
$string['populate:field:summary'] = 'Course summary';
$string['populate:overwritewarning'] = '{$a} field(s) already have content that differs from the syllabus. They are highlighted below and will be replaced if you continue.';
$string['populate:pagetitle'] = 'Apply the syllabus to this course';
$string['populate:sections'] = 'Weekly sections';
$string['populate:sectionscount'] = '{$a} section(s) will be created or updated, one per week of the syllabus.';
$string['populate:summary:code'] = 'Course code: {$a}';
$string['populate:summary:credits'] = 'Credits: {$a}';
$string['populate:summary:grading'] = 'Assessment - {$a->item}: {$a->weight}%';
$string['populate:summary:semester'] = 'Semester: {$a}';
$string['populate:summary:weeks'] = 'Length: {$a} weeks';
$string['populate:unchanged'] = 'No change';
$string['populate:willoverwrite'] = 'Will be replaced';
$string['review:action'] = 'Review';
$string['review:alreadyconfirmed'] = 'This syllabus has already been confirmed. You can read it here, but confirming again is not possible; upload a new version instead.';
$string['review:confirm'] = 'Confirm syllabus';
$string['review:confirmed'] = 'The syllabus was confirmed. {$a} item(s) were marked as manually overridden and will not be replaced by a later re-parse.';
$string['review:eventduedate'] = 'Due date';
$string['review:eventduetime'] = 'Due time';
$string['review:events'] = 'Course events';
$string['review:eventtitle'] = 'Title';
$string['review:eventtype:assignment'] = 'Assignment';
$string['review:eventtype:exam'] = 'Exam';
$string['review:eventtype:other'] = 'Other';
$string['review:eventtype:project'] = 'Project';
$string['review:lowcount'] = '{$a} item(s) were extracted with low confidence and are marked below. Please check those first.';
$string['review:needscheck'] = 'Needs checking';
$string['review:notready'] = 'That syllabus has nothing to confirm yet (status: {$a}).';
$string['review:overridden'] = 'Edited by you';
$string['review:pagetitle'] = 'Confirm the parsed syllabus';
$string['review:sourcespan'] = 'From the original';
$string['review:unresolved'] = 'The parser could not determine these';
$string['review:view'] = 'View';
$string['review:weekdate'] = 'Date';
$string['review:weeknote'] = 'Note';
$string['review:weeks'] = 'Weekly schedule';
$string['review:weektopic'] = 'Topic';
$string['settings:batchsize'] = 'Batch size';
$string['settings:batchsize_desc'] = 'Maximum number of items a scheduled task processes in one run. Keeping this low protects against PHP time and memory limits on sites with many courses; the remainder is picked up on the next run.';
$string['settings:confidencethreshold'] = 'Low-confidence threshold';
$string['settings:confidencethreshold_desc'] = 'Weeks and events extracted with a confidence below this value are highlighted on the confirmation screen so the teacher checks them first.';
$string['settings:extractionmode'] = 'Extraction mode';
$string['settings:extractionmode:prompted'] = 'Prompt only (works with any provider)';
$string['settings:extractionmode:structured'] = 'Native structured output (recommended)';
$string['settings:extractionmode_desc'] = 'Native structured output asks the provider to guarantee the response shape. Use the prompt-only mode when the model does not support that. There is deliberately no automatic fallback: an unsupported model fails with a clear error instead of quietly degrading accuracy and token usage without anyone noticing.';
$string['settings:extractorclass'] = 'Extractor class';
$string['settings:extractorclass_desc'] = 'Fully qualified name of the class that performs structured extraction. It is named here as a string rather than referenced in code, so that the AI provider plugin stays installable on sites without UniversityAI. Change this only when replacing the provider plugin.';
$string['settings:opsretentiondays'] = 'Operation record retention (days)';
$string['settings:opsretentiondays_desc'] = 'Operation records older than this are deleted by the daily cleanup task.';
$string['settings:parseprompt'] = 'Syllabus parsing prompt';
$string['settings:parseprompt_desc'] = 'Instruction sent to the language model together with the syllabus text. The response shape is enforced by the JSON schema and cannot be changed here, so this controls wording rules such as date formats and how unresolved items are reported. Leave empty to restore the built-in default.';
$string['settings:summaryprompt'] = 'Weekly summary prompt';
$string['settings:summaryprompt_desc'] = 'Instruction sent to the language model together with this week\'s course facts. The facts themselves are supplied as JSON and cannot be changed here, so the prompt controls tone, length and output language only. Leave empty to restore the built-in default.';
$string['summary:badgenoschedule'] = 'Nothing scheduled';
$string['summary:badgeversion'] = 'Version {$a}';
$string['summary:bymodel'] = 'model';
$string['summary:fromsyllabus'] = 'syllabus version';
$string['summary:generatedat'] = 'Generated';
$string['summary:navtitle'] = 'Weekly summary';
$string['summary:none'] = 'No weekly summary has been published for this course yet. Summaries appear once the course has a confirmed syllabus and the scheduled task has run.';
$string['summary:noschedule'] = 'Nothing is scheduled for this week: the syllabus lists no topic for it, and no course event falls due within the next seven days.';
$string['summary:pagetitle'] = 'Weekly course summary';
$string['summary:weeklabel'] = 'Week {$a}';
$string['syllabus:colfile'] = 'File';
$string['syllabus:colparsed'] = 'Parsed';
$string['syllabus:colstatus'] = 'Status';
$string['syllabus:coluploaded'] = 'Uploaded';
$string['syllabus:colversion'] = 'Version';
$string['syllabus:listheading'] = 'Uploaded syllabi';
$string['syllabus:none'] = 'No syllabus has been uploaded for this course yet.';
$string['syllabus:status:confirmed'] = 'Confirmed';
$string['syllabus:status:failed'] = 'Parsing failed';
$string['syllabus:status:parsed'] = 'Awaiting confirmation';
$string['syllabus:status:uploaded'] = 'Waiting to be parsed';
$string['syllabus:versionlabel'] = 'Version {$a}';
$string['targettype:course'] = 'course';
$string['targettype:event'] = 'event';
$string['targettype:syllabus'] = 'syllabus';
$string['targettype:user'] = 'user';
$string['task:pruneops'] = 'Prune expired operation records';
$string['task:weeklysummary'] = 'Generate weekly course summaries';
$string['universityai:chat'] = 'Ask the course assistant questions';
$string['universityai:confirm'] = 'Confirm a parsed syllabus';
$string['universityai:populate'] = 'Populate course content from a syllabus';
$string['universityai:syncown'] = 'Sync own course events to an external calendar';
$string['universityai:upload'] = 'Upload a syllabus';
$string['universityai:viewops'] = 'View the operations dashboard';
$string['universityai:viewsummary'] = 'View weekly course summaries';
$string['upload:file'] = 'Syllabus file';
$string['upload:formats'] = 'Supported formats: PDF and DOCX whose text can be selected. Scanned images, Minguo-era dates and tables rendered as pictures are not supported in this version.';
$string['upload:navtitle'] = 'Upload syllabus';
$string['upload:nofile'] = 'No file was received. Please choose a file and try again.';
$string['upload:pagetitle'] = 'Upload a syllabus';
$string['upload:queued'] = 'The syllabus was received as version {$a} and queued for parsing. This page shows its status once the scheduled task has run.';
$string['upload:submit'] = 'Upload and parse';
