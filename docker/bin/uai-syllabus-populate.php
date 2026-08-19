<?php
// 以真實的網頁表單把已確認課綱填入課程（FR-CRS-01、FR-CRS-02、FR-CAL-01）。
//
// 為什麼不直接呼叫 course_populator：FR-CRS-01 的核心驗收條件是「課程既有
// 欄位若已有內容且與課綱不同，須**先向老師呈現**將被覆寫的項目」，那是介面
// 的行為。直接呼叫 Model 驗不到「呈現」這件事，而那正是這條需求的重點。
//
// 順帶驗兩件事：
//   - 未確認的課綱不得填入（FR-SYL-04 的另一面）
//   - 重複執行不產生重複的章節或事件（NFR-REL-04、FR-CAL-01）
//
// 開發工具，不隨外掛發行。

define('CLI_SCRIPT', true);

require('/var/www/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');

use local_universityai\course_populator;
use local_universityai\syllabus_repository;

[$options] = cli_get_params([
    'shortname' => '',
    'adminuser' => '',
    'adminpass' => '',
    'help' => false,
]);

$shortname = $options['shortname'] !== '' ? $options['shortname'] : (getenv('DEMO_COURSE_SHORTNAME') ?: '');

if ($options['help'] || $shortname === '' || $options['adminuser'] === '') {
    cli_writeln("以真實表單把最新一版已確認課綱填入課程。

選項：
  --shortname=X   課程簡稱，預設取環境變數 DEMO_COURSE_SHORTNAME
  --adminuser=X   登入帳號
  --adminpass=X   登入密碼
  -h, --help      顯示此說明
");
    exit($shortname === '' ? 1 : 0);
}

$course = $DB->get_record('course', ['shortname' => $shortname], 'id, fullname');
if (!$course) {
    cli_error('找不到課程：' . $shortname);
}

$repository = new syllabus_repository();
$target = $repository->get_latest_confirmed((int) $course->id);

if ($target === null) {
    cli_writeln('  沒有已確認的課綱。先執行 make syllabus。');
    exit(1);
}

/** @var string cookie 罐。 */
$jar = tempnam(sys_get_temp_dir(), 'uai-populate-');

/**
 * 發出一次請求。理由與 uai-syllabus-upload.php 的同名函式相同。
 *
 * @param string $url 網址
 * @param array|null $post 表單欄位
 * @return array{code:int, body:string}
 */
function uai_request(string $url, ?array $post = null): array {
    global $CFG, $jar;

    $host = parse_url($CFG->wwwroot, PHP_URL_HOST);
    $port = parse_url($CFG->wwwroot, PHP_URL_PORT) ?: 80;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_CONNECT_TO => ["{$host}:{$port}:127.0.0.1:80"],
        CURLOPT_TIMEOUT => 120,
    ]);

    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => $body];
}

/**
 * 自 HTML 中取出一個表單欄位的值。
 *
 * @param string $html 頁面內容
 * @param string $name 欄位名
 * @return string
 */
function uai_field(string $html, string $name): string {
    $quoted = preg_quote($name, '/');

    if (preg_match('/name="' . $quoted . '"[^>]*value="([^"]*)"/', $html, $m)) {
        return $m[1];
    }

    return preg_match('/value="([^"]*)"[^>]*name="' . $quoted . '"/', $html, $m) ? $m[1] : '';
}

// ---------------------------------------------------------------------------
// 登入
// ---------------------------------------------------------------------------
$loginpage = uai_request($CFG->wwwroot . '/login/index.php');
$login = uai_request($CFG->wwwroot . '/login/index.php', [
    'username' => $options['adminuser'],
    'password' => $options['adminpass'],
    'logintoken' => uai_field($loginpage['body'], 'logintoken'),
]);

if (str_contains($login['body'], 'loginerrormessage')) {
    cli_error('登入失敗。');
}

// ---------------------------------------------------------------------------
// 負面路徑：未確認的課綱不得填入
// ---------------------------------------------------------------------------
$pending = null;
foreach ($repository->get_all_for_course((int) $course->id) as $candidate) {
    if ($candidate->status !== syllabus_repository::STATUS_CONFIRMED) {
        $pending = $candidate;
        break;
    }
}

if ($pending !== null) {
    $blocked = uai_request($CFG->wwwroot . '/local/universityai/populate.php?id=' . $pending->id);
    $rejected = str_contains($blocked['body'], '無法填入課程')
        || str_contains($blocked['body'], 'cannot be applied');
    cli_writeln('  未確認的課綱被擋　' . ($rejected ? '✔' : '✘'));
} else {
    cli_writeln('  未確認的課綱被擋　不適用（本次沒有未確認的版本）');
}

// ---------------------------------------------------------------------------
// 先看預覽：FR-CRS-01 要求呈現將被覆寫的項目
// ---------------------------------------------------------------------------
$populateurl = $CFG->wwwroot . '/local/universityai/populate.php?id=' . $target->id;
$page = uai_request($populateurl);

$preview = (new course_populator($repository))->preview((int) $target->id);
$overwrites = count(array_filter($preview['fields'], static fn($f) => $f['overwrites']));

$labels = ['populate:difftitle', 'populate:colcurrent', 'populate:colproposed'];
$shown = true;
foreach ($labels as $key) {
    $text = get_string($key, 'local_universityai');
    if (str_contains($text, '[[')) {
        cli_error('語言字串 ' . $key . ' 取不到，請先執行 make purge。');
    }
    $shown = $shown && str_contains($page['body'], $text);
}

cli_writeln('  差異對照表呈現　' . ($shown ? '✔' : '✘'));
cli_writeln('  將被覆寫的欄位　' . $overwrites . ' 項'
    . ($overwrites > 0
        ? (str_contains($page['body'], get_string('populate:willoverwrite', 'local_universityai'))
            ? '，已標示 ✔'
            : '，未標示 ✘')
        : '（無）'));

// ---------------------------------------------------------------------------
// 套用
// ---------------------------------------------------------------------------
$post = [
    'id' => $target->id,
    'sesskey' => uai_field($page['body'], 'sesskey'),
    '_qf__local_universityai_form_populate_form' => '1',
    'submitbutton' => 'go',
];

uai_request($populateurl, $post);

$after = $DB->get_record('course', ['id' => $course->id], '*', MUST_EXIST);
$payload = $repository->decode($target);
$sections = $DB->count_records_select(
    'course_sections',
    'course = :courseid AND section > 0',
    ['courseid' => $course->id]
);
$events = $DB->count_records('event', ['courseid' => $course->id]);
$mapped = $DB->count_records(course_populator::EVTMAP_TABLE, ['courseid' => $course->id]);

$expectedweeks = count($payload['weeks'] ?? []);
$datedevents = 0;
foreach ($payload['events'] ?? [] as $event) {
    if (!empty($event['due_date'])) {
        $datedevents++;
    }
}

cli_writeln('');
cli_writeln('  課程全名　　　' . ($after->fullname === $payload['course']['title'] ? '✔ ' : '✘ ') . $after->fullname);
cli_writeln('  課程簡述　　　' . ($after->summary !== '' ? '✔ 已填入' : '✘ 仍為空'));
cli_writeln('  起訖日期　　　' . ((int) $after->startdate > 0 && (int) $after->enddate > 0 ? '✔' : '✘'));
cli_writeln('  週次章節　　　' . ($sections >= $expectedweeks ? '✔ ' : '✘ ')
    . $sections . ' 個（課綱 ' . $expectedweeks . ' 週）');
cli_writeln('  行事曆事件　　' . ($events >= $datedevents ? '✔ ' : '✘ ')
    . $events . ' 個（課綱有日期者 ' . $datedevents . ' 個）');
cli_writeln('  事件對應紀錄　' . $mapped . ' 筆');

// ---------------------------------------------------------------------------
// 冪等：再套用一次
// ---------------------------------------------------------------------------
$page2 = uai_request($populateurl);
$post['sesskey'] = uai_field($page2['body'], 'sesskey');
uai_request($populateurl, $post);

$sections2 = $DB->count_records_select(
    'course_sections',
    'course = :courseid AND section > 0',
    ['courseid' => $course->id]
);
$events2 = $DB->count_records('event', ['courseid' => $course->id]);

cli_writeln('');
cli_writeln('  重複套用：章節　' . ($sections2 === $sections ? '✔ 未增加' : '✘ ' . $sections . ' → ' . $sections2));
cli_writeln('  重複套用：事件　' . ($events2 === $events ? '✔ 未增加' : '✘ ' . $events . ' → ' . $events2));

exit(0);
