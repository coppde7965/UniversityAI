<?php
// 以真實的網頁表單上傳一份課綱。
//
// 為什麼不直接寫資料庫：FR-SYL-01 的驗收條件講的全是**介面的行為**——
// 只有具該課程 editingteacher 能力者可存取、不支援的格式要明確拒絕、
// 無文字層的 PDF 要在解析前就被擋下。繞過表單直接建紀錄，這些一項都驗不到，
// 而那正是「CLI 全綠、網頁掛掉」那一類問題的溫床（architecture.md §7.4.1）。
//
// **上傳要走兩步，因為 Moodle 的 filepicker 就是兩步**：檔案先經
// repository_ajax.php 進入草稿區，表單提交的其實只是草稿區的 itemid。
// 把檔案直接 POST 給 upload.php 不會有任何效果——表單會收到一個空的草稿區，
// 然後以「沒有收到檔案」拒絕，而那個訊息看起來會很像上傳功能壞了。
//
// 順帶驗兩條負面路徑：不支援的格式與無文字層的檔案各送一次，確認被拒。
//
// 開發工具，不隨外掛發行。

define('CLI_SCRIPT', true);

require('/var/www/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');

[$options] = cli_get_params([
    'shortname' => '',
    'adminuser' => '',
    'adminpass' => '',
    'fixture' => 'f1-table',
    'verbose' => false,
    'help' => false,
]);

/** @var bool 印出每一步的 HTTP 狀態，診斷用。 */
$verbose = (bool) $options['verbose'];

$shortname = $options['shortname'] !== '' ? $options['shortname'] : (getenv('DEMO_COURSE_SHORTNAME') ?: '');

if ($options['help'] || $shortname === '' || $options['adminuser'] === '') {
    cli_writeln("以真實表單上傳一份課綱。

選項：
  --shortname=X   課程簡稱，預設取環境變數 DEMO_COURSE_SHORTNAME
  --adminuser=X   登入帳號
  --adminpass=X   登入密碼
  --fixture=ID    要上傳的固定樣本，預設 f1-table
  -h, --help      顯示此說明
");
    exit($shortname === '' ? 1 : 0);
}

$course = $DB->get_record('course', ['shortname' => $shortname], 'id, fullname');
if (!$course) {
    cli_error('找不到課程：' . $shortname);
}

$pdf = '/opt/uai/eval/build/' . $options['fixture'] . '.pdf';
if (!is_readable($pdf)) {
    cli_error('找不到樣本 ' . $pdf . '，先執行 make fixtures。');
}

$contextid = context_course::instance($course->id)->id;
$uploadurl = $CFG->wwwroot . '/local/universityai/upload.php?id=' . $course->id;

// 「上傳檔案」儲存庫的執行個體 id。filepicker 送檔案時要指名它。
$repoid = $DB->get_field_sql(
    "SELECT i.id FROM {repository_instances} i
       JOIN {repository} r ON r.id = i.typeid
      WHERE r.type = :type",
    ['type' => 'upload']
);
if (!$repoid) {
    cli_error('找不到「上傳檔案」儲存庫，站台設定可能不完整。');
}

/** @var string cookie 罐，整個腳本共用一次登入。 */
$jar = tempnam(sys_get_temp_dir(), 'uai-upload-');

/**
 * 發出一次請求。
 *
 * 用 CURLOPT_CONNECT_TO 把 wwwroot 的埠號改導到容器內部的 80：Moodle 會把
 * Host 不符 wwwroot 的請求重導回 wwwroot，而容器內部沒有人在聽對外那個埠。
 * 改 Host 標頭不行，必須連線層改導、Host 維持原值。
 *
 * @param string $url 網址
 * @param array|null $post 表單欄位，null 表示 GET
 * @param array $files 要上傳的檔案，欄位名對應到路徑
 * @return array{code:int, body:string, error:string}
 */
function uai_request(string $url, ?array $post = null, array $files = []): array {
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
        foreach ($files as $field => $path) {
            $post[$field] = new CURLFile($path, mime_content_type($path), basename($path));
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $files ? $post : http_build_query($post));
    }

    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return ['code' => $code, 'body' => $body, 'error' => $error];
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

/**
 * 走一次完整的上傳流程：草稿區 → 表單。
 *
 * 每次都重新取表單：sesskey 與草稿區 itemid 是一次性的，重用會被 Moodle 以
 * 「表單已過期」擋下，而那個錯誤看起來會很像上傳功能壞了。
 *
 * @param string $path 要上傳的檔案
 * @return array{code:int, body:string, error:string, draft:string}
 */
function uai_upload(string $path): array {
    global $CFG, $uploadurl, $repoid, $contextid, $verbose;

    $page = uai_request($uploadurl);
    $sesskey = uai_field($page['body'], 'sesskey');
    $draft = uai_field($page['body'], 'syllabusfile');

    // 第一步：檔案進草稿區。這就是瀏覽器裡按下「選擇檔案」之後發生的事。
    $ajax = uai_request(
        $CFG->wwwroot . '/repository/repository_ajax.php?action=upload',
        [
            'sesskey' => $sesskey,
            'repo_id' => $repoid,
            'itemid' => $draft,
            'ctx_id' => $contextid,
            'savepath' => '/',
            'title' => basename($path),
            'author' => '',
            'license' => 'unknown',
        ],
        ['repo_upload_file' => $path]
    );

    if ($verbose) {
        cli_writeln('      表單頁 HTTP ' . $page['code']
            . '　sesskey ' . ($sesskey !== '' ? '有' : '無')
            . '　草稿區 ' . ($draft !== '' ? $draft : '無'));
        cli_writeln('      草稿上傳 HTTP ' . $ajax['code'] . '　'
            . core_text::substr(trim($ajax['body']), 0, 160));
    }

    // 第二步：提交表單，送的是草稿區的 itemid 而不是檔案本身。
    $result = uai_request($uploadurl, [
        'id' => uai_field($page['body'], 'id'),
        'sesskey' => $sesskey,
        '_qf__local_universityai_form_upload_form' => '1',
        'syllabusfile' => $draft,
        'submitbutton' => 'go',
    ]);

    $result['draft'] = $draft;

    if ($verbose) {
        $heading = preg_match('/<h1[^>]*>(.{0,120}?)</s', $result['body'], $m) ? trim($m[1]) : '（無 h1）';
        cli_writeln('      表單提交 HTTP ' . $result['code'] . '　標題「' . $heading . '」');

        // 把整頁存下來。診斷「頁面渲染了但狀態碼是 404」這種情況，靠 regex
        // 猜關鍵字只會一直猜錯——要看的是完整內容。
        $dump = '/opt/uai/eval/build/last-upload-' . pathinfo($path, PATHINFO_EXTENSION) . '.html';
        if (is_dir(dirname($dump))) {
            file_put_contents($dump, $result['body']);
            cli_writeln('      完整回應已存至 ' . $dump);
        }
    }

    return $result;
}

/**
 * 判斷回應中是否出現某一段訊息。
 *
 * 中英文都比對，因為站台語言可能不是繁體中文。
 *
 * @param string $body 頁面內容
 * @param string[] $needles 任一命中即可
 * @return bool
 */
function uai_mentions(string $body, array $needles): bool {
    foreach ($needles as $needle) {
        if (str_contains($body, $needle)) {
            return true;
        }
    }

    return false;
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

if (uai_mentions($login['body'], ['loginerrormessage'])) {
    cli_error('登入失敗。');
}

// ---------------------------------------------------------------------------
// 負面路徑一：不支援的格式
// ---------------------------------------------------------------------------
$bad = tempnam(sys_get_temp_dir(), 'uai-bad-') . '.pptx';
file_put_contents($bad, str_repeat('這不是課綱。', 100));

$result = uai_upload($bad);
cli_writeln('  不支援的格式　'
    . (uai_mentions($result['body'], ['不支援的檔案格式', 'is not supported']) ? '已被拒絕 ✔' : '未被拒絕 ✘'));
unlink($bad);

// ---------------------------------------------------------------------------
// 負面路徑二：沒有文字層
// ---------------------------------------------------------------------------
$scan = tempnam(sys_get_temp_dir(), 'uai-scan-') . '.pdf';
file_put_contents($scan, "%PDF-1.4\n% 這份檔案沒有文字層\n");

$result = uai_upload($scan);
cli_writeln('  無文字層　　　'
    . (uai_mentions($result['body'], ['沒有可讀取的文字層', 'no readable text layer']) ? '已被拒絕 ✔' : '未被拒絕 ✘'));
unlink($scan);

// ---------------------------------------------------------------------------
// 正常路徑
// ---------------------------------------------------------------------------
$before = $DB->count_records('local_universityai_syllabus', ['courseid' => $course->id]);
$result = uai_upload($pdf);
$after = $DB->count_records('local_universityai_syllabus', ['courseid' => $course->id]);

if ($after <= $before) {
    cli_writeln('  正常上傳　　　失敗 ✘（課綱數維持 ' . $before . '）');
    cli_writeln('  HTTP ' . $result['code'] . '　' . strlen($result['body']) . ' bytes');
    exit(1);
}

$queued = $DB->count_records('task_adhoc', ['classname' => '\local_universityai\task\parse_syllabus']);
$latest = $DB->get_record_sql(
    "SELECT id, version, status FROM {local_universityai_syllabus}
      WHERE courseid = :courseid ORDER BY version DESC",
    ['courseid' => $course->id],
    IGNORE_MULTIPLE
);

cli_writeln('  正常上傳　　　成功 ✔（課綱數 ' . $before . ' → ' . $after . '）');
cli_writeln('  第 ' . $latest->version . ' 版　id ' . $latest->id . '　狀態 ' . $latest->status);
cli_writeln('  已排入的解析任務：' . $queued . ' 個');

exit(0);
