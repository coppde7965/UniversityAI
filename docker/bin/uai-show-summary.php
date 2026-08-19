<?php
// 讀回某門課已產生的每週摘要。
//
// 開發工具，不隨外掛發行。存在的理由是驗證要驗到最終落地的資料，而不是
// 停在排程任務回報的「已完成」——那個回傳值在什麼都沒做時也是綠的
// （docs/architecture.md §4.8 記的就是這一類錯誤）。

define('CLI_SCRIPT', true);

require('/var/www/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');

use local_universityai\summary\repository;

[$options] = cli_get_params([
    'shortname' => '',
    'weeks' => 4,
    'help' => false,
]);

$shortname = $options['shortname'] !== '' ? $options['shortname'] : (getenv('DEMO_COURSE_SHORTNAME') ?: '');

if ($options['help'] || $shortname === '') {
    cli_writeln("印出某門課最近的每週摘要。

選項：
  --shortname=X   課程簡稱，預設取環境變數 DEMO_COURSE_SHORTNAME
  --weeks=N       最多印幾週，預設 4
  -h, --help      顯示此說明
");
    exit($shortname === '' ? 1 : 0);
}

$course = $DB->get_record('course', ['shortname' => $shortname], 'id, fullname');
if (!$course) {
    cli_error('找不到課程：' . $shortname);
}

$summaries = (new repository())->get_recent((int) $course->id, max(1, (int) $options['weeks']));

if (!$summaries) {
    cli_writeln('這門課還沒有任何摘要。');
    cli_writeln('可能的原因：沒有已確認課綱、今天不在學期範圍內，或排程任務還沒跑。');
    exit(1);
}

foreach ($summaries as $summary) {
    cli_separator();
    cli_writeln(' 第 ' . $summary->weekno . ' 週　'
        . userdate((int) $summary->weekstart, '%Y-%m-%d')
        . '　狀態 ' . $summary->status
        . '　版本 ' . $summary->version);
    cli_writeln(' 課綱版本 ' . $summary->syllabusversion
        . '　模型 ' . ($summary->model !== '' && $summary->model !== null ? $summary->model : '（未呼叫模型）')
        . '　生成於 ' . userdate((int) $summary->generatedat, '%Y-%m-%d %H:%M'));
    cli_separator();
    cli_writeln($summary->content);
    cli_writeln('');
}

cli_writeln('共 ' . count($summaries) . ' 週。');

exit(0);
