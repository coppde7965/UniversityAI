<?php
// 為示範課程植入一份「已確認」的課綱。
//
// 第二階段（每週摘要）的鏈路是：已確認課綱 → 事實 → 過濾 → 語言模型 → 發布。
// 課綱的產生屬第三階段（FR-SYL-*），所以在那之前這條鏈路的起點是空的，
// 整個階段就變成無法實跑、只能靠單元測試相信它會動——那正是 SRS §8.4 把
// 第二階段定為「取得第一條端到端可運作的鏈路」時想避免的事。
//
// 因此這支腳本補上起點。它是**開發工具**，不隨外掛發行，也刻意放在
// docker/bin/ 而不是外掛裡：外掛內不該有任何能憑空造出課綱資料的程式碼。
//
// 日期一律相對於執行當天計算，所以任何時候跑都會落在學期中，示範不會因為
// 寫死的日期過期而變成一片「本週無安排」。

define('CLI_SCRIPT', true);

require('/var/www/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');

use local_universityai\syllabus_repository;

[$options] = cli_get_params([
    'shortname' => '',
    'weeks' => 6,
    'currentweek' => 3,
    'help' => false,
]);

$shortname = $options['shortname'] !== '' ? $options['shortname'] : (getenv('DEMO_COURSE_SHORTNAME') ?: '');

if ($options['help'] || $shortname === '') {
    cli_writeln("為課程植入一份已確認的課綱，供第二階段的每週摘要使用。

選項：
  --shortname=X     課程簡稱，預設取環境變數 DEMO_COURSE_SHORTNAME
  --weeks=N         學期總週數，預設 6
  --currentweek=N   讓今天落在第幾週，預設 3
  -h, --help        顯示此說明
");
    exit($shortname === '' ? 1 : 0);
}

$course = $DB->get_record('course', ['shortname' => $shortname], 'id, fullname');
if (!$course) {
    cli_error('找不到課程：' . $shortname . '（先執行 make install 建立示範課程）');
}

$totalweeks = max(1, (int) $options['weeks']);
$currentweek = min($totalweeks, max(1, (int) $options['currentweek']));

$tz = core_date::get_server_timezone_object();
$today = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0);

// 學期起始日回推到「今天所在的教學週」的第一天，再往前 currentweek-1 週。
// context_builder 是以 (今天 - 起始日) 的完整天數除以 7 推算週次，所以只要
// 起始日與今天相差的天數落在 (currentweek-1)*7 到 currentweek*7-1 之間即可。
$termstart = $today->modify('-' . (($currentweek - 1) * 7) . ' days');

/**
 * 依 docs/architecture.md §9.6 的規則推導事件識別碼。
 *
 * 刻意不含日期：日期正是最常改動的欄位，放進識別碼等於保證跨版本對不起來。
 * 正式的實作屬第三階段的抽取邏輯，這裡先照同一條規則算，讓植入的資料與
 * 將來真的解析出來的資料在這個欄位上是同一種東西。
 *
 * @param string $type 事件類型
 * @param string $title 事件標題
 * @param int $occurrence 同一份課綱內的出現序號，自 0 起算
 * @return string
 */
function uai_event_id(string $type, string $title, int $occurrence): string {
    $normalised = preg_replace('/[ ]+/u', ' ', trim($title));

    return substr(sha1($type . '|' . $normalised . '|' . $occurrence), 0, 32);
}

$topics = [
    '課程介紹與人工智慧發展簡史',
    '搜尋演算法：BFS、DFS、A*',
    '知識表示與推理',
    '機器學習概論',
    '神經網路與深度學習入門',
    '期末專題報告',
];

$weeks = [];
for ($i = 1; $i <= $totalweeks; $i++) {
    $weekdate = $termstart->modify('+' . (($i - 1) * 7) . ' days');
    $weeks[] = [
        'week_no' => $i,
        'date' => $weekdate->format('Y-m-d'),
        'topic' => $topics[($i - 1) % count($topics)],
        'note' => $i === $currentweek ? '本週需繳交作業一' : '',
        'confidence' => 0.95,
        'source_span' => '第 ' . $i . ' 週　' . $weekdate->format('n/j'),
        'overridden' => false,
    ];
}

// 兩個事件刻意一近一遠：三天後的落在七日窗內，十四天後的落在窗外。
// 「窗外的沒被寫進摘要」和「窗內的有」一樣重要，缺了後者這支腳本就驗不出
// context_builder 的時間窗到底有沒有在運作。
$events = [
    [
        'event_id' => uai_event_id('assignment', '作業一', 0),
        'type' => 'assignment',
        'title' => '作業一：搜尋演算法實作',
        'due_date' => $today->modify('+3 days')->format('Y-m-d'),
        'due_time' => '23:59',
        'week_no' => $currentweek,
        'confidence' => 0.9,
        'source_span' => '本週需繳交作業一',
        'overridden' => false,
    ],
    [
        'event_id' => uai_event_id('exam', '期中考', 0),
        'type' => 'exam',
        'title' => '期中考',
        'due_date' => $today->modify('+14 days')->format('Y-m-d'),
        'due_time' => null,
        'week_no' => min($totalweeks, $currentweek + 2),
        'confidence' => 0.8,
        'source_span' => '期中考（暫定，日期另行公告）',
        'overridden' => false,
    ],
];

$payload = [
    'syllabus_id' => bin2hex(random_bytes(16)),
    'course_ref' => (int) $course->id,
    // version 於下方決定實際版本號後補上——payload 裡的版本必須與資料表
    // 那一欄一致，寫死 1 會讓兩者對不起來。
    'version' => 0,
    'status' => syllabus_repository::STATUS_CONFIRMED,
    'course' => [
        'title' => '人工智慧導論',
        'code' => 'CS3025',
        // 教師姓名刻意留在 payload 裡。它不在送出的白名單內，但週次備註這類
        // 自由文字有機會夾帶，過濾器的 literals 機制正是靠這個欄位取得姓名。
        'teacher' => '陳怡君',
        'semester' => '114-1',
        'credits' => 3,
    ],
    'term' => [
        'start_date' => $termstart->format('Y-m-d'),
        'total_weeks' => $totalweeks,
        'weekday' => strtoupper(substr($termstart->format('D'), 0, 3)),
    ],
    'grading' => [
        ['item' => '作業', 'weight' => 30, 'note' => ''],
        ['item' => '期中考', 'weight' => 25, 'note' => ''],
        ['item' => '期末專題', 'weight' => 35, 'note' => ''],
    ],
    'weeks' => $weeks,
    'events' => $events,
    'parse_meta' => [
        'source_file' => 'seeded-by-uai-seed-syllabus.php',
        'parsed_at' => (new DateTimeImmutable('now', $tz))->format(DATE_ATOM),
        'model' => 'seed',
        'unresolved' => ['評分比例加總為 90，與 100 不符（刻意保留，用於觀察下游行為）'],
    ],
];

$now = time();

// 先刪掉自己上一次植入的那一版，再以**最高版本**重新建立。
//
// 固定用 version=1 會出問題：make syllabus 會經由真實上傳流程產生第 2、3、…
// 版，而 syllabus_repository::get_latest_confirmed() 取的是版本號最大的那一份。
// 於是每週摘要會去讀 fixture 那份寫死 2025 年日期的課綱，判定學期已結束、
// 什麼都不做——而畫面上完全看不出原因。
//
// 只刪 model='seed' 的列，真實上傳的版本不動。
$DB->delete_records(syllabus_repository::TABLE, ['courseid' => $course->id, 'model' => 'seed']);
$version = (new syllabus_repository())->next_version((int) $course->id);
$payload['version'] = $version;

$record = (object) [
    'courseid' => $course->id,
    'version' => $version,
    'status' => syllabus_repository::STATUS_CONFIRMED,
    'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
    'contenthash' => sha1('seed:' . $course->id),
    'model' => 'seed',
    'parsedat' => $now,
    'confirmedby' => get_admin()->id,
    'confirmedat' => $now,
    'usermodified' => get_admin()->id,
    'timemodified' => $now,
];

$record->timecreated = $now;
$id = $DB->insert_record(syllabus_repository::TABLE, $record);
cli_writeln('已植入第 ' . $version . ' 版課綱（id ' . $id . '）。');

cli_writeln('  課程　　' . $course->fullname . '（id ' . $course->id . '）');
cli_writeln('  學期起　' . $termstart->format('Y-m-d') . '　共 ' . $totalweeks . ' 週');
cli_writeln('  今天落在第 ' . $currentweek . ' 週：' . $weeks[$currentweek - 1]['topic']);
cli_writeln('  七日內到期　' . $events[0]['title'] . '（' . $events[0]['due_date'] . '）');
cli_writeln('  窗外事件　　' . $events[1]['title'] . '（' . $events[1]['due_date'] . '，不應出現在摘要中）');
cli_writeln('');
cli_writeln('接著執行：make summary');

exit(0);
