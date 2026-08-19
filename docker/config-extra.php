<?php
// UniversityAI 的補充 Moodle 設定
//
// 這個檔案由 make install 在 config.php 中插入一行 require_once 載入，
// 插入位置在 config.php 結尾的 require_once(__DIR__ . '/lib/setup.php') 之前
// ——setup.php 一旦載入就來不及設定了。
//
// 之所以不直接把這些內容寫進 config.php：config.php 由 Moodle 的安裝程式產生
// 且被 .gitignore 忽略，而這些設定必須進版控才能達成 NFR-MNT-05 的環境可重現。
//
// 對應 docs/architecture.md §7.4。

defined('MOODLE_INTERNAL') || true; // 這個檔案在 setup.php 之前載入，尚無該常數
global $CFG;

// ---------------------------------------------------------------------------
// Behat（驗收測試）
//
// 三個設定必須與開發用的完全分開，否則跑一次測試就把開發資料清掉。
// ---------------------------------------------------------------------------

// 必須是 selenium 容器解析得到的位址。寫 localhost 會讓瀏覽器連到它自己容器內的
// localhost，症狀是每個場景都逾時——這是第一次跑 Behat 最常撞到的坑。
$CFG->behat_wwwroot  = 'http://moodle';
$CFG->behat_dataroot = '/var/www/behatdata';

// 資料表前綴上限 10 字元（Moodle 4.3 起）。
$CFG->behat_prefix   = 'bht_';

$CFG->behat_faildump_path = '/var/www/behatdata/faildumps';

$CFG->behat_profiles = [
    'default' => [
        'browser' => 'chrome',
        // Selenium 4 同時接受 http://selenium:4444 與帶 /wd/hub 的舊路徑，
        // 這裡用舊路徑以相容較舊的 selenium 映像。
        'wd_host' => 'http://selenium:4444/wd/hub',
    ],
];

// ---------------------------------------------------------------------------
// PHPUnit（單元測試）
// ---------------------------------------------------------------------------

$CFG->phpunit_dataroot = '/var/www/phpunitdata';
$CFG->phpunit_prefix   = 'phpu_';

// ---------------------------------------------------------------------------
// 寄信
//
// 本機站台沒有對外寄信管道（D-04），一律導向信件攔截容器。
// 在 config.php 設定會覆蓋資料庫中的值並在後台顯示為鎖定，正是我們要的效果：
// 開發環境不可能不小心真的把信寄出去。對應 FR-NTF-01。
// ---------------------------------------------------------------------------

$CFG->smtphosts = 'mail:1025';
$CFG->smtpuser  = '';
$CFG->smtppass  = '';

// 系統寄出的通知以這個位址為寄件者。沒設的話 core_user::get_noreply_user()
// 會在 PHP 8 下噴 Undefined property 警告，通知照樣送得出去，但輸出裡多一
// 行雜訊，久了就會開始忽略警告。
//
// 網域不可以用 .invalid：Moodle 的 email_to_user() 對這個 TLD 直接放棄寄送
// 然後回報成功，是本專案第一階段驗收時實際踩到的坑。
$CFG->noreplyaddress = 'noreply@universityai.example';

// ---------------------------------------------------------------------------
// 開發便利
// ---------------------------------------------------------------------------

// 32767 即 DEBUG_DEVELOPER。這裡不能用該常數，它定義在 setup.php 中而尚未載入；
// 也不用 E_ALL | E_STRICT，因為 E_STRICT 在 PHP 8.4 已棄用。
$CFG->debug        = 32767;
$CFG->debugdisplay = 1;

// ── 一個可以放心忽略的警告 ──────────────────────────────────────────
// 開了 DEBUG_DEVELOPER 之後，每個管理頁面都會出現：
//
//   Locale warning (not fatal) U_USING_FALLBACK_WARNING: Requested locale
//   "zh_TW.UTF-8" not found, locale "zh@collation=stroke" used instead.
//
// 這是 Moodle 的判斷邏輯不夠精確，不是環境缺東西。core_collator 的檢查是
// 「ICU 實際採用的 locale 是不是請求字串的前綴」，但所有繁體中文變體
// （zh_TW、zh_Hant、zh_Hant_TW）都會讓 ICU 選 zh@collation=stroke，而帶
// @collation= 關鍵字的字串永遠不可能是 zh_TW.UTF-8 的前綴，所以必定觸發。
//
// **ICU 選對了**：VALID_LOCALE 是 zh_Hant_TW，代表繁中資料完整，而筆畫排序
// 正是繁體中文該用的。實測 ["張三","李四","王五","陳六"] 排出
// 「王五 李四 張三 陳六」，完全正確。
//
// ── 三件已經實測排除的「解法」──────────────────────────────────────
//
// 1. **locale-gen 沒有用**，而且我們早就做了（見 Dockerfile）。locale-gen
//    產生的是 glibc locale，影響的是 setlocale()；這個警告來自 **ICU**，
//    它用自己編進 libicu 的資料，不讀 /usr/lib/locale。實測 setlocale
//    (LC_ALL,'zh_TW.UTF-8') 回傳成功，警告照樣出現。順帶一提，ICU 根本
//    沒有 zh_TW 這個 locale，它有的是 zh_Hant_TW。
//
// 2. **改 $CFG->locale 沒有用。** core_collator 取的是
//    get_string('locale','langconfig')，也就是語言包裡的值，$CFG->locale
//    完全不經過這條路徑。
//
// 3. **覆寫語言包的 locale 字串可以消除警告，但代價不可接受。** 實測六個
//    候選值，只有 "zh" 與 "zh@collation=stroke" 的 ICU 錯誤碼是 0；其餘
//    繁中變體（zh_TW、zh_Hant、zh_Hant_TW）一律得到 -128 與
//    ACTUAL_LOCALE=zh@collation=stroke，必定觸發警告。
//    - "zh" 會把排序切成拼音序（實測排出「陳六 李四 王五 張三」，錯誤）。
//    - "zh@collation=stroke" 排序正確，但**全站日期會變成簡體中文慣例**：
//      月份由「1月 2月」變成「一月 二月」，週幾由「週一」變成「周一」。
//      原因是 core_date::strftime() 讀同一個字串餵給 IntlDateFormatter，
//      而它的原始碼註解明說**刻意忽略 $CFG->locale**，補不回來。
//
// 結論：這行訊息留著。它是 Moodle 前綴比對不夠精確的產物，ICU 的行為與
// 排序結果都是對的。
// ────────────────────────────────────────────────────────────────────

// ── 關閉 Whoops（必須，否則上面那行警告會讓每個頁面掛掉）──────────────
// Moodle 5.x 把 filp/whoops 列為 dev 相依，而 make test 會在 Moodle 根目錄
// 執行 composer install --dev，於是它被裝進共用的 moodlesrc 磁碟區——
// **網頁容器跟著受影響，即使測試是在 tools 容器裡跑的**。
//
// 裝上之後，setup.php 會把 debug_developer_use_pretty_exceptions 預設為
// true（見 public/lib/setup.php 中的 property_exists 判斷），接著開發模式
// 下的每一次 debugging() 都被轉成一頁 Whoops 錯誤頁並**中止請求**。上面
// 那個 ICU 警告每頁都會觸發，於是整個網站管理區直接不能用。
//
// 這個檔案在 lib/setup.php 之前載入，所以在這裡設值就能搶在預設值之前。
//
// 代價：真正的例外不再有 Whoops 的漂亮頁面，回到 Moodle 內建的錯誤畫面。
// 這比「開發模式下網站不能用」好。若哪天 ICU 警告消失了，可以再打開。
$CFG->debug_developer_use_pretty_exceptions = false;
// ────────────────────────────────────────────────────────────────────

// 維持 Moodle 預設的開啟。
//
// 實測：對伺服器端的頁面產生時間**幾乎沒有影響**（開與關都是 0.25 秒）。
// 差別在瀏覽器端——關閉時 Moodle 給資源的 revision 是 -1，意思是瀏覽器
// 不要快取，於是每次切頁都重抓十幾個 JS，每個約 40 毫秒。有感但不致命。
//
// 之所以特別註明，是因為這裡原本設成 false（理由是「改了 JS 立刻生效」），
// 而排查頁面卡頓時一度被誤判為主因。真正的原因是快取目錄落在 bind mount
// 上，見下方 A-09。維持開啟只是因為它是預設值且沒有理由關；代價是改完
// AMD 模組要執行 make purge。
$CFG->cachejs = true;

$CFG->pathtophp = '/usr/local/bin/php';

// ── 快取目錄必須離開 bind mount（A-09）────────────────────────────────
// 這三個目錄預設在 moodledata 之下，而 moodledata 是掛在專案目錄的
// bind mount（D-04 的可攜性要求，裡面有老師上傳的課綱原檔）。
// Windows 的 bind mount 每次檔案操作約 2.5 毫秒，而管理頁面一次請求要碰
// 數百個快取檔與編譯後的模板，實測 /admin/search.php 要 7 秒。
// 把這三個目錄指到具名磁碟區之後降到 0.25 秒。
//
// 這麼做不影響 D-04 的可攜性：它們是可重建的快取，不是資料。換機器時
// Moodle 會自己重建，不需要跟著搬。
// ────────────────────────────────────────────────────────────────────
$CFG->localcachedir = '/var/www/moodlecache/localcache';
$CFG->cachedir      = '/var/www/moodlecache/cache';
$CFG->tempdir       = '/var/www/moodlecache/temp';
