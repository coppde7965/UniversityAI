<?php
// 建立一門空的示範課程。
//
// D-10 之後課程外殼由 Moodle 既有流程建立，外掛只負責填入內容。
// 這個腳本存在的理由是讓 make install 產出一個「可以直接展示」的站台
// （NFR-MNT-05），而不是裝完還要先手動點一輪才能開始。
//
// 冪等：課程短名已存在時直接結束。

define('CLI_SCRIPT', true);

require('/var/www/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

[$options, $unrecognised] = cli_get_params(
    [
        'shortname' => 'UAI101',
        'fullname'  => '課綱助理示範課程',
        'help'      => false,
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error('無法辨識的參數：' . implode(', ', $unrecognised));
}

if ($options['help']) {
    cli_writeln("建立一門空的示範課程。

選項：
  --shortname=STRING  課程短名，預設 UAI101
  --fullname=STRING   課程全名
  -h, --help          顯示此說明
");
    exit(0);
}

if ($DB->record_exists('course', ['shortname' => $options['shortname']])) {
    cli_writeln("課程 {$options['shortname']} 已存在，略過。");
    exit(0);
}

// 取第一個課程類別。全新站台上這會是 Moodle 自動建立的預設類別。
$categoryid = $DB->get_field_sql('SELECT MIN(id) FROM {course_categories}');
if (empty($categoryid)) {
    cli_error('找不到任何課程類別，站台可能尚未安裝完成。');
}

$course = create_course((object) [
    'category'  => $categoryid,
    'shortname' => $options['shortname'],
    'fullname'  => $options['fullname'],
    'format'    => 'topics',
    'startdate' => time(),
    'visible'   => 1,
]);

cli_writeln("已建立課程：{$course->shortname}（id={$course->id}）");
