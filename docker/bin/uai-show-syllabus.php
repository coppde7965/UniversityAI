<?php
// 印出某門課最新一版課綱的解析結果。
//
// 開發工具，不隨外掛發行。存在的理由與 uai-show-summary.php 相同：驗證要驗到
// 最終落地的資料，不要停在任務回報的「已完成」——那個回傳值在什麼都沒做時
// 也是綠的（architecture.md §4.8）。

define('CLI_SCRIPT', true);

require('/var/www/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');

use local_universityai\extraction\validator;
use local_universityai\syllabus_repository;

[$options] = cli_get_params([
    'shortname' => '',
    'help' => false,
]);

$shortname = $options['shortname'] !== '' ? $options['shortname'] : (getenv('DEMO_COURSE_SHORTNAME') ?: '');

if ($options['help'] || $shortname === '') {
    cli_writeln("印出某門課最新一版課綱的解析結果。

選項：
  --shortname=X   課程簡稱，預設取環境變數 DEMO_COURSE_SHORTNAME
  -h, --help      顯示此說明
");
    exit($shortname === '' ? 1 : 0);
}

$course = $DB->get_record('course', ['shortname' => $shortname], 'id, fullname');
if (!$course) {
    cli_error('找不到課程：' . $shortname);
}

$repository = new syllabus_repository();
$versions = $repository->get_all_for_course((int) $course->id);

if (!$versions) {
    cli_writeln('這門課還沒有上傳過課綱。');
    exit(1);
}

cli_writeln('  版本　狀態　　　　模型');
foreach ($versions as $version) {
    cli_writeln(sprintf(
        '  %4d　%-10s　%s',
        $version->version,
        $version->status,
        $version->model ?: '—'
    ));
}

$latest = reset($versions);
$payload = $repository->decode($latest);

if ($payload === null) {
    cli_writeln('');
    cli_writeln('最新一版還沒有解析結果（狀態 ' . $latest->status . '）。');
    exit($latest->status === syllabus_repository::STATUS_FAILED ? 1 : 0);
}

cli_separator();
cli_writeln(' 第 ' . $latest->version . ' 版的抽取結果');
cli_separator();

cli_writeln('  課程　' . ($payload['course']['title'] ?? '?')
    . '　代碼 ' . ($payload['course']['code'] ?? '—')
    . '　學期 ' . ($payload['course']['semester'] ?? '—'));

// 教師欄位刻意檢查：SRS §6.3 要求教師姓名在進入解析之前就自原文移除，
// 所以這一欄應該是 null 或佔位符。抽到真實姓名代表過濾沒有生效。
$teacher = $payload['course']['teacher'] ?? null;
cli_writeln('  教師　' . ($teacher === null ? 'null ✔（已於送出前移除）' : $teacher));

cli_writeln('  學期起　' . ($payload['term']['start_date'] ?? '?')
    . '　共 ' . ($payload['term']['total_weeks'] ?? '?') . ' 週'
    . '　星期 ' . ($payload['term']['weekday'] ?? '—'));

cli_writeln('');
cli_writeln('  週次');
foreach ($payload['weeks'] ?? [] as $week) {
    cli_writeln(sprintf(
        '    %2d　%-10s　%-28s　信心 %.2f',
        $week['week_no'],
        $week['date'] ?? '—',
        $week['topic'],
        $week['confidence']
    ));
}

cli_writeln('');
cli_writeln('  事件');
foreach ($payload['events'] ?? [] as $event) {
    cli_writeln(sprintf(
        '    %-10s　%-16s　%s %s　信心 %.2f',
        $event['type'],
        $event['title'],
        $event['due_date'] ?? '—',
        $event['due_time'] ?? '',
        $event['confidence']
    ));
    cli_writeln('      event_id ' . ($event['event_id'] ?? '（缺）'));
}

cli_writeln('');
cli_writeln('  評分');
foreach ($payload['grading'] ?? [] as $item) {
    cli_writeln('    ' . $item['item'] . '　' . $item['weight'] . '%');
}

$unresolved = $payload['parse_meta']['unresolved'] ?? [];
cli_writeln('');
cli_writeln('  未決項目 ' . count($unresolved) . ' 項');
foreach ($unresolved as $item) {
    cli_writeln('    - ' . $item);
}

// 重跑一次驗證，確認**存進去的與驗過的是同一份**——mark_parsed() 與
// finalise() 之間有機會把資料弄壞，而那種錯誤不會有任何徵兆。
//
// 驗之前要先剝掉系統補上的欄位：extraction_schema 描述的是**模型該產出
// 什麼**，而落地的 payload 還多了 syllabus_id、event_id、overridden 這些
// 由本外掛決定的欄位。schema 的 additionalProperties=false 會把它們判定為
// 未定義欄位，那不是資料錯，是拿錯尺量。
$stored = $payload;
unset($stored['syllabus_id'], $stored['course_ref'], $stored['version'], $stored['status']);
unset($stored['parse_meta']['source_file'], $stored['parse_meta']['parsed_at']);
unset($stored['parse_meta']['model'], $stored['parse_meta']['source_length']);

foreach ($stored['weeks'] as $i => $week) {
    unset($stored['weeks'][$i]['overridden']);
}
foreach ($stored['events'] as $i => $event) {
    unset($stored['events'][$i]['event_id'], $stored['events'][$i]['overridden']);
}

$verdict = (new validator())->validate($stored);
cli_writeln('');
cli_writeln('  重新驗證：' . ($verdict['errors'] === []
    ? '通過 ✔'
    : count($verdict['errors']) . ' 項不符 ✘'));
foreach ($verdict['errors'] as $error) {
    cli_writeln('    ' . $error);
}
foreach ($verdict['notes'] as $note) {
    cli_writeln('    （已回報）' . $note);
}

cli_separator();

exit($verdict['errors'] === [] ? 0 : 1);
