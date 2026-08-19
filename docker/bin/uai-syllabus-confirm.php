<?php
// 以真實的網頁表單確認一份已解析的課綱（FR-SYL-03、FR-SYL-04）。
//
// 為什麼不直接改資料庫：驗收條件講的是介面的行為——低信心項目要標示、
// 老師可以逐項修改、修改過的欄位要標記為人工覆寫。繞過表單，這三項一個都
// 驗不到，而 confirmation_service 的單元測試也驗不到「表單真的把欄位名送對」
// ——欄位名對不上時症狀是「按了確認、成功了、但什麼都沒改」，沒有錯誤訊息。
//
// 順帶驗一條負面路徑：同一份課綱確認兩次，第二次要被拒絕。
//
// 開發工具，不隨外掛發行。

define('CLI_SCRIPT', true);

require('/var/www/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');

use local_universityai\confirmation_service;
use local_universityai\syllabus_repository;

[$options] = cli_get_params([
    'shortname' => '',
    'adminuser' => '',
    'adminpass' => '',
    'help' => false,
]);

$shortname = $options['shortname'] !== '' ? $options['shortname'] : (getenv('DEMO_COURSE_SHORTNAME') ?: '');

if ($options['help'] || $shortname === '' || $options['adminuser'] === '') {
    cli_writeln("以真實表單確認最新一版已解析的課綱。

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
$target = null;
foreach ($repository->get_all_for_course((int) $course->id) as $candidate) {
    if ($candidate->status === syllabus_repository::STATUS_PARSED) {
        $target = $candidate;
        break;
    }
}

if ($target === null) {
    cli_writeln('  沒有等待確認的課綱。先執行 make syllabus。');
    exit(1);
}

$payload = $repository->decode($target);
$annotated = (new confirmation_service())->annotate($payload);

cli_writeln('  第 ' . $target->version . ' 版　週次 ' . count($annotated['weeks'])
    . '　事件 ' . count($annotated['events'])
    . '　低信心 ' . $annotated['lowcount'] . ' 項'
    . '　未決 ' . count($annotated['unresolved']) . ' 項');

/** @var string cookie 罐。 */
$jar = tempnam(sys_get_temp_dir(), 'uai-confirm-');

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
        CURLOPT_TIMEOUT => 60,
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

$reviewurl = $CFG->wwwroot . '/local/universityai/review.php?id=' . $target->id;
$page = uai_request($reviewurl);

/**
 * 回報一項介面檢查。
 *
 * 沒有樣本可驗時要印「不適用」而不是「通過」。這兩者在輸出上長得一樣的話，
 * 一份剛好沒有低信心項目的課綱會讓 FR-SYL-03 的標示功能看起來被驗過了，
 * 而其實一次都沒觸發——那正是最容易讓人放心的假綠。
 *
 * @param string $label 檢查項目
 * @param int $samples 有幾個該被標示的項目
 * @param bool $found 頁面上是否找得到標示
 * @return void
 */
function uai_ui_check(string $label, int $samples, bool $found): void {
    if ($samples === 0) {
        cli_writeln('  ' . $label . '不適用（本次無此類項目）');

        return;
    }

    cli_writeln('  ' . $label . ($found ? '有 ✔' : '無 ✘'));
}

// FR-SYL-03：低信心項目與 unresolved 都要在介面上有明顯標示。
//
// get_string() 失敗時回傳 [[key]]，而頁面上也會印出同樣的 [[key]]——
// 於是 str_contains 找得到，檢查通過，但兩邊其實都壞了。語言字串快取沒更新
// 時就是這個情況，實測撞過。所以先確認字串本身是好的。
$strings = [];
foreach (['review:needscheck', 'review:unresolved', 'review:sourcespan'] as $key) {
    $strings[$key] = get_string($key, 'local_universityai');
    if (str_contains($strings[$key], '[[')) {
        cli_error('語言字串 ' . $key . ' 取不到，請先執行 make purge。');
    }
}

uai_ui_check('低信心標示　　', $annotated['lowcount'], str_contains($page['body'], $strings['review:needscheck']));
uai_ui_check(
    '未決項目標示　',
    count($annotated['unresolved']),
    str_contains($page['body'], $strings['review:unresolved'])
);
uai_ui_check('原文出處標示　', count($annotated['weeks']), str_contains($page['body'], $strings['review:sourcespan']));

// ---------------------------------------------------------------------------
// 送出：改一個週次主題，其餘照原樣
// ---------------------------------------------------------------------------
$post = [
    'id' => $target->id,
    'sesskey' => uai_field($page['body'], 'sesskey'),
    '_qf__local_universityai_form_review_form' => '1',
    'submitbutton' => 'go',
];

foreach ($annotated['weeks'] as $week) {
    foreach (confirmation_service::WEEK_FIELDS as $field) {
        $post[confirmation_service::field_name('week', $week['index'], $field)] = $week[$field];
    }
}
foreach ($annotated['events'] as $event) {
    foreach (confirmation_service::EVENT_FIELDS as $field) {
        $post[confirmation_service::field_name('event', $event['index'], $field)] = $event[$field];
    }
}

$edited = '（老師修正過的主題）';
$post[confirmation_service::field_name('week', 0, 'topic')] = $edited;

uai_request($reviewurl, $post);

$after = $repository->get((int) $target->id);
$stored = $repository->decode($after);

cli_writeln('');
cli_writeln('  確認後狀態　　' . $after->status
    . ($after->status === syllabus_repository::STATUS_CONFIRMED ? ' ✔' : ' ✘'));
cli_writeln('  確認者　　　　' . ($after->confirmedby > 0 ? 'id ' . $after->confirmedby . ' ✔' : '未記錄 ✘'));
cli_writeln('  確認時間　　　' . ($after->confirmedat > 0
    ? userdate((int) $after->confirmedat, '%Y-%m-%d %H:%M') . ' ✔'
    : '未記錄 ✘'));
cli_writeln('  版本未跳號　　' . ($after->version === $target->version ? '✔' : '✘'));

$topic = $stored['weeks'][0]['topic'] ?? '';
cli_writeln('  修改已生效　　' . ($topic === $edited ? '✔' : '✘（實際為「' . $topic . '」）'));
cli_writeln('  已標記覆寫　　' . (!empty($stored['weeks'][0]['overridden']) ? '✔' : '✘'));
cli_writeln('  其餘未被標記　' . (empty($stored['weeks'][1]['overridden']) ? '✔' : '✘'));

// ---------------------------------------------------------------------------
// 負面路徑：再確認一次要被拒絕
// ---------------------------------------------------------------------------
$again = uai_request($reviewurl, $post);
$rejected = str_contains($again['body'], '無法確認')
    || str_contains($again['body'], 'cannot be confirmed')
    || str_contains($again['body'], get_string('review:alreadyconfirmed', 'local_universityai'));

cli_writeln('  重複確認被擋　' . ($rejected ? '✔' : '✘'));

exit(0);
