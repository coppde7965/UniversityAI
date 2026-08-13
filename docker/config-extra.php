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

// ---------------------------------------------------------------------------
// 開發便利
// ---------------------------------------------------------------------------

// 32767 即 DEBUG_DEVELOPER。這裡不能用該常數，它定義在 setup.php 中而尚未載入；
// 也不用 E_ALL | E_STRICT，因為 E_STRICT 在 PHP 8.4 已棄用。
$CFG->debug        = 32767;
$CFG->debugdisplay = 1;

// 改了 JS 立刻生效，不必每次清快取
$CFG->cachejs = false;

$CFG->pathtophp = '/usr/local/bin/php';
