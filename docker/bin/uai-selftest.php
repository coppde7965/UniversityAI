<?php
// 外掛的結構性自我檢查。
//
// SRS §8.4 第一階段要求證明五件事：外掛能安裝、出現在管理選單、寫入自有
// 資料表、跑通一個排程任務、發出一則通知。這個腳本把五件事逐項驗過並
// 明確回報，取代「看起來好像有裝起來」這種驗收方式。
//
// 第二階段（每週摘要）在這裡只加結構性的檢查：資料表、排程任務、課程內的
// 頁面打不打得開。**摘要的實際產生不放進來**——那會呼叫 API 並產生費用，
// 而自我檢查應該是隨時可以跑的東西。端到端的實跑在 make summary。
//
// 只用於開發與展示環境，不隨外掛發行——它在 docker/bin/ 而非 plugins/ 下。
//
// 冪等：自己寫入的紀錄自己清掉，可以重複執行。

define('CLI_SCRIPT', true);

require('/var/www/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/adminlib.php');

use local_universityai\notifier;
use local_universityai\ops;

[$options] = cli_get_params([
    'adminuser' => '',
    'adminpass' => '',
]);

$failures = [];
$step = 0;

/**
 * 回報一項檢查的結果。
 *
 * @param bool $ok 是否通過
 * @param string $what 檢查項目的描述
 * @param string $detail 補充說明
 * @return bool 原樣回傳 $ok，方便串接
 */
function uai_check(bool $ok, string $what, string $detail = ''): bool {
    global $failures, $step;

    $step++;
    $mark = $ok ? '  ✔' : '  ✘';
    cli_writeln(sprintf('%s [%d] %s%s', $mark, $step, $what, $detail === '' ? '' : "　（{$detail}）"));

    if (!$ok) {
        $failures[] = $what;
    }

    return $ok;
}

/**
 * 向信件攔截服務的 API 取 JSON。
 *
 * 刻意用原生 curl 而不是 Moodle 的 \curl：後者預設會擋內部網路位址
 * （curlsecurityblockedhosts），而 mail 正是一個容器名稱。這個腳本不隨
 * 外掛發行，用原生 curl 沒有問題；外掛程式碼裡不可以這樣做。
 *
 * @param string $url 要取的位址
 * @return array|null 解析後的 JSON；連不上或不是 JSON 時為 null
 */
function uai_get_json(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $status !== 200) {
        return null;
    }

    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : null;
}

/** @var string 信件攔截服務的 API。埠號是容器內部的，與主機對外埠號無關。 */
const UAI_MAILPIT_API = 'http://mail:8025/api/v1';

/**
 * 以管理員身分抓一個頁面回來。
 *
 * 為什麼需要這件事：本腳本其餘的檢查全部走 CLI，而 CLI 與網頁是兩條不同
 * 的執行路徑。實際發生過一次 CLI 十項全綠、網站管理區卻每頁都掛掉
 * （architecture.md §7.4.1）。要擋住那一類問題，只能真的去抓一次頁面。
 *
 * 連線用 CURLOPT_CONNECT_TO 把 wwwroot 的埠號改導到容器內部的 80：
 * Moodle 會把 Host 不符 wwwroot 的請求重導回 wwwroot，而容器內部沒有人
 * 在聽對外的那個埠。改 Host 標頭不行，必須連線層改導、Host 維持原值。
 *
 * @param string $path 站台內的路徑，例如 /admin/search.php
 * @param string $username 管理員帳號
 * @param string $password 管理員密碼
 * @return array{code:int, body:string, error:string} HTTP 狀態碼、內容與 curl 錯誤
 */
function uai_fetch_as_admin(string $path, string $username, string $password): array {
    global $CFG;

    static $jar = null;
    if ($jar === null) {
        $jar = tempnam(sys_get_temp_dir(), 'uai-cookies-');
    }

    $host = parse_url($CFG->wwwroot, PHP_URL_HOST);
    $port = parse_url($CFG->wwwroot, PHP_URL_PORT) ?: 80;
    $connectto = ["{$host}:{$port}:127.0.0.1:80"];

    $request = function (string $url, ?array $post = null) use ($jar, $connectto): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $jar,
            CURLOPT_COOKIEFILE => $jar,
            CURLOPT_CONNECT_TO => $connectto,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return ['code' => $code, 'body' => $body, 'error' => $error];
    };

    // 只登入一次，之後靠 cookie。
    //
    // 登入失敗時**不能**把 $loggedin 設起來：呼叫端會重試（Apache 剛啟動時
    // 連不上），而略過登入的重試只會拿到登入頁，看起來像「頁面壞了」而不是
    // 「還沒登入」。這種旗標設錯位置的錯誤在重試邏輯裡特別難查。
    static $loggedin = false;
    if (!$loggedin) {
        $loginpage = $request($CFG->wwwroot . '/login/index.php');
        if ($loginpage['error'] !== '') {
            return $loginpage;
        }

        $token = '';
        if (preg_match('/name="logintoken" value="([^"]*)"/', $loginpage['body'], $m)) {
            $token = $m[1];
        }
        $login = $request($CFG->wwwroot . '/login/index.php', [
            'username' => $username,
            'password' => $password,
            'logintoken' => $token,
        ]);
        if ($login['error'] !== '') {
            return $login;
        }

        $loggedin = true;
    }

    return $request($CFG->wwwroot . $path);
}

cli_separator();
cli_writeln(' UniversityAI 第一階段自我檢查');
cli_separator();

// ---------------------------------------------------------------------------
// 1. 外掛已安裝
// ---------------------------------------------------------------------------
$installed = get_config('local_universityai', 'version');
uai_check(
    !empty($installed),
    '外掛已安裝',
    $installed ? "版本 {$installed}" : '在 config_plugins 中找不到版本紀錄'
);

// ---------------------------------------------------------------------------
// 2. 資料表存在
// ---------------------------------------------------------------------------
$dbman = $DB->get_manager();
$tables = [
    'local_universityai_syllabus',
    'local_universityai_evtmap',
    'local_universityai_diff',
    'local_universityai_ops',
    'local_universityai_summary',
];
$missing = [];
foreach ($tables as $table) {
    if (!$dbman->table_exists($table)) {
        $missing[] = $table;
    }
}
uai_check(
    empty($missing),
    count($tables) . ' 張資料表存在',
    $missing ? '缺少 ' . implode('、', $missing) : implode('、', $tables)
);

// ---------------------------------------------------------------------------
// 3. 能力已註冊
// ---------------------------------------------------------------------------
$capabilities = [];
require($CFG->dirroot . '/local/universityai/db/access.php');
$notregistered = [];
foreach (array_keys($capabilities) as $capability) {
    if (!$DB->record_exists('capabilities', ['name' => $capability])) {
        $notregistered[] = $capability;
    }
}
uai_check(
    empty($notregistered),
    '能力已註冊',
    $notregistered ? '缺少 ' . implode('、', $notregistered) : count($capabilities) . ' 項'
);

// ---------------------------------------------------------------------------
// 4. 站台設定的預設值已寫入
// ---------------------------------------------------------------------------
$hassettingsfile = file_exists($CFG->dirroot . '/local/universityai/settings.php');
$batchsize = get_config('local_universityai', 'batchsize');
$retention = get_config('local_universityai', 'opsretentiondays');
uai_check(
    $hassettingsfile && $batchsize !== false && $retention !== false,
    '站台設定已就緒',
    "batchsize={$batchsize}、opsretentiondays={$retention}"
);

// ---------------------------------------------------------------------------
// 5. 外掛出現在管理選單
//
// settings.php 存在不代表選單裡看得到——檔案裡的 $hassiteconfig 判斷、
// $ADMIN->add() 的父節點名稱寫錯，或字串缺失，都會讓它靜靜地不出現。
//
// 這裡直接建出管理樹再去找那個節點，等同於管理員開啟頁面時走的路徑。
// 建樹需要有登入身分（settings.php 的 $hassiteconfig 由能力求值而來），
// CLI 預設沒有，所以先切成管理員。
// ---------------------------------------------------------------------------
\core\session\manager::set_user(get_admin());
$adminroot = admin_get_root(true, true);
$settingspage = $adminroot->locate('local_universityai');
uai_check(
    $settingspage instanceof admin_settingpage,
    '外掛出現在管理選單',
    $settingspage instanceof admin_settingpage
        ? '網站管理 → 外掛 → 本機外掛 → ' . $settingspage->visiblename
        : '在管理樹中找不到 local_universityai 節點'
);

// ---------------------------------------------------------------------------
// 6. 排程任務已註冊
//
// db/tasks.php 只註冊真的存在的類別（§4.2）。反過來說，註冊了卻找不到的
// 類別會讓排程頁面列出一個點下去就拋例外的項目，所以逐一取回來確認。
// ---------------------------------------------------------------------------
$task = \core\task\manager::get_scheduled_task(\local_universityai\task\prune_ops::class);
$summarytask = \core\task\manager::get_scheduled_task(\local_universityai\task\weekly_summary::class);
$registered = array_filter([$task, $summarytask]);
uai_check(
    count($registered) === 2,
    '兩個排程任務都已註冊',
    count($registered) === 2
        ? $task->get_name() . '、' . $summarytask->get_name()
        : '在 task_scheduled 中找不到 prune_ops 或 weekly_summary'
);

// ---------------------------------------------------------------------------
// 7. 寫入自有資料表，並由排程任務清掉
//
// 寫一筆刻意做舊的紀錄，跑一次任務，確認它被刪除。這一步同時證明了
// 「能寫入自有資料表」與「排程任務真的會做事」——只確認任務跑完沒噴錯
// 是不夠的，一個什麼都沒做的任務也不會噴錯。
// ---------------------------------------------------------------------------
$probeid = ops::record(ops::OP_NOTIFY, ops::TARGET_USER, 'selftest-' . time(), ops::STATUS_SUCCESS);
$written = $DB->record_exists(ops::TABLE, ['id' => $probeid]);
$afterwrite = $DB->count_records(ops::TABLE);

// 做舊到保留期之外。
$retentiondays = (int) $retention ?: \local_universityai\task\prune_ops::DEFAULT_RETENTION_DAYS;
$DB->set_field(ops::TABLE, 'occurredat', time() - ($retentiondays + 1) * DAYSECS, ['id' => $probeid]);

if ($task) {
    $task->execute();
}

$pruned = !$DB->record_exists(ops::TABLE, ['id' => $probeid]);
$afterprune = $DB->count_records(ops::TABLE);

uai_check($written, '能寫入自有資料表', "local_universityai_ops id={$probeid}");
uai_check($pruned, '排程任務確實處理了資料', "紀錄數 {$afterwrite} → {$afterprune}");

// ---------------------------------------------------------------------------
// 8. 發出一則通知
//
// 走的是 db/messages.php 註冊的訊息提供者與 Moodle 既有的訊息機制，
// 不是自己寫的寄信程式碼。收件者是全體站台管理員。
// ---------------------------------------------------------------------------
// 送出前先記下通知表的最大 id，事後才知道哪幾列是這次產生的。
//
// 這件事一開始漏了：selftest 的說明寫著「自己寫入的紀錄自己清掉」，但
// 它只清了 _ops，通知留在管理員的訊息中心裡。跑幾次之後那裡就堆著一排
// 「UniversityAI：課綱解析失敗」——內容看起來很嚴重，其實一次真的失敗
// 都沒發生過。展示時（NFR-MNT-05）管理員的鈴鐺掛著一排假警報很難看，
// 而且會訓練人忽略真的警報。
$notificationhwm = (int) $DB->get_field_sql('SELECT COALESCE(MAX(id), 0) FROM {notifications}');

$mailbefore = uai_get_json(UAI_MAILPIT_API . '/messages?limit=1');

$sent = notifier::notify_admins_of_failure(ops::OP_SYLLABUS_PARSE, ops::TARGET_SYLLABUS, 'selftest', 3);
$admincount = count(get_admins());
uai_check(
    $sent > 0 && $sent === $admincount,
    '通知已進入訊息機制',
    "{$sent} / {$admincount} 位管理員"
);

// ---------------------------------------------------------------------------
// 9. 信真的送達信件攔截服務
//
// 上一項只證明 message_send() 回報成功，**那不等於信寄出去了**。
// Moodle 的 email_to_user() 在好幾種情況下會放棄寄送卻 return true，其中
// 一種是收件位址的網域結尾為 .invalid——本專案第一階段第一次跑這個腳本
// 時就是這樣：訊息機制全綠，信件攔截服務裡卻一封都沒有。
//
// SRS §8.4 把「驗證 D-04 的寄信前提」列為第一階段的目的之一，所以這裡
// 一定要查到真正的收件端，不能停在回傳值。
// ---------------------------------------------------------------------------
if ($mailbefore === null) {
    uai_check(false, '信件已送達信件攔截服務', '連不上 ' . UAI_MAILPIT_API . '，服務可能沒起來');
} else {
    $countbefore = (int) ($mailbefore['total'] ?? 0);

    // SMTP 遞送有一點延遲，給它幾秒。
    $mailafter = null;
    $countafter = $countbefore;
    for ($i = 0; $i < 10; $i++) {
        $mailafter = uai_get_json(UAI_MAILPIT_API . '/messages?limit=1');
        $countafter = (int) ($mailafter['total'] ?? 0);
        if ($countafter > $countbefore) {
            break;
        }
        sleep(1);
    }

    $latest = $mailafter['messages'][0]['Subject'] ?? '(無)';
    uai_check(
        $countafter > $countbefore,
        '信件已送達信件攔截服務',
        $countafter > $countbefore
            ? "信箱 {$countbefore} → {$countafter} 封，最新一封：{$latest}"
            : "信箱維持 {$countbefore} 封。message_send() 回報成功但信沒出去，"
                . '最常見的原因是收件者的信箱網域無法寄送'
    );
}

// ---------------------------------------------------------------------------
// 10. 清掉剛才那則通知，不留假警報
//
// 信已經寄出去了（上一項驗過），站台內那一列的任務就結束了。留著它只會
// 讓管理員看到一則從未真正發生的失敗。
//
// 只刪 id 大於送出前水位的那幾列——將來真的有失敗通知時，它們不會被
// 這支腳本掃掉。這是刻意用水位而不是用 component 條件一次刪光的理由。
// ---------------------------------------------------------------------------
$mine = $DB->get_fieldset_select(
    'notifications',
    'id',
    'id > :hwm AND component = :component AND eventtype = :eventtype',
    [
        'hwm' => $notificationhwm,
        'component' => 'local_universityai',
        'eventtype' => 'opsfailure',
    ]
);

if ($mine) {
    // 彈出視窗那張表以 notificationid 參照，沒有級聯刪除，要自己來。
    $DB->delete_records_list('message_popup_notifications', 'notificationid', $mine);
    $DB->delete_records_list('notifications', 'id', $mine);
}

$left = $DB->count_records_select(
    'notifications',
    'id > :hwm AND component = :component',
    ['hwm' => $notificationhwm, 'component' => 'local_universityai']
);

uai_check(
    $left === 0,
    '通知已清理，不留假警報',
    $left === 0 ? '刪除 ' . count($mine) . ' 則' : "仍殘留 {$left} 則"
);

// ---------------------------------------------------------------------------
// 11. 管理頁面在瀏覽器路徑上真的打得開
//
// 其餘檢查全部走 CLI。CLI 與網頁是兩條不同的執行路徑，實際發生過一次
// CLI 全綠而網站管理區每頁都被 Whoops 中斷（architecture.md §7.4.1）——
// 那次是跑了 make test 之後才出現的，與外掛本身無關，但使用者看到的是
// 「網站壞了」。所以這一項抓的是真實頁面，不是回傳值。
// ---------------------------------------------------------------------------
if ($options['adminuser'] === '' || $options['adminpass'] === '') {
    uai_check(false, '管理頁面打得開', '沒有收到 --adminuser / --adminpass，無法登入檢查');
} else {
    // 容器剛重建時 Apache 可能還沒開始聽，重試幾次再判定。不重試的話這一項
    // 會在「make up 之後立刻 make selftest」時假警報，而假警報比沒有檢查更糟
    // ——它會訓練人忽略紅燈。只對連線層失敗重試，HTTP 錯誤直接判定。
    $page = ['code' => 0, 'body' => '', 'error' => '尚未嘗試'];
    for ($i = 0; $i < 15; $i++) {
        $page = uai_fetch_as_admin('/admin/search.php', $options['adminuser'], $options['adminpass']);
        if ($page['error'] === '') {
            break;
        }
        sleep(1);
    }
    $size = strlen($page['body']);

    // 三個條件缺一不可。只看狀態碼會漏掉「200 但內容是錯誤頁」，
    // 只看大小會漏掉重導向到登入頁（那也是一個完整的頁面）。
    $isok = $page['code'] === 200
        && stripos($page['body'], 'whoops') === false
        && str_contains($page['body'], 'admin/search.php');

    $why = '內容不是預期的管理頁';
    if ($page['error'] !== '') {
        $why = 'curl：' . $page['error'];
    } else if (stripos($page['body'], 'whoops') !== false) {
        $why = '被 Whoops 中斷，見 architecture.md §7.4.1';
    }

    uai_check(
        $isok,
        '管理頁面打得開',
        $isok
            ? "/admin/search.php　HTTP {$page['code']}　{$size} bytes"
            : "/admin/search.php　HTTP {$page['code']}　{$size} bytes——{$why}"
    );
}

// ---------------------------------------------------------------------------
// 12. 課程內的每週摘要頁打得開
//
// FR-SUM-01 的「發布於課程中供師生檢視」在 Moodle 上就是這一頁。上一項驗的
// 是站台管理區，這一項驗的是課程脈絡——兩者的權限路徑不同（require_login
// 帶課程參數、課程層級的能力檢查），管理區正常不代表課程頁面正常。
//
// 這裡不檢查頁面上有沒有摘要內容：有沒有內容取決於課綱與當週進度，那是
// make summary 的事。這一項只回答「這一頁會不會爆」。
// ---------------------------------------------------------------------------
$democourse = $DB->get_record(
    'course',
    ['shortname' => getenv('DEMO_COURSE_SHORTNAME') ?: ''],
    'id, shortname'
);

if ($options['adminuser'] === '' || $options['adminpass'] === '') {
    uai_check(false, '課程摘要頁打得開', '沒有收到 --adminuser / --adminpass，無法登入檢查');
} else if (!$democourse) {
    uai_check(false, '課程摘要頁打得開', '找不到示範課程，先執行 make install');
} else {
    $path = '/local/universityai/summary.php?id=' . $democourse->id;
    $page = uai_fetch_as_admin($path, $options['adminuser'], $options['adminpass']);

    $isok = $page['code'] === 200
        && stripos($page['body'], 'whoops') === false
        && str_contains($page['body'], 'local-universityai-summaries');

    uai_check(
        $isok,
        '課程摘要頁打得開',
        $isok
            ? "{$path}　HTTP {$page['code']}　" . strlen($page['body']) . ' bytes'
            : "{$path}　HTTP {$page['code']}——" . ($page['error'] ?: '內容不是預期的摘要頁')
    );
}

// ---------------------------------------------------------------------------
cli_separator();

if ($failures) {
    cli_writeln(' 未通過：' . implode('、', $failures));
    cli_separator();
    exit(1);
}

cli_writeln(' 全部通過。');
cli_writeln('');
cli_writeln(' 剛才那封通知可以在信件攔截服務的網頁介面上讀到（make help 有連結）。');
cli_separator();

exit(0);
