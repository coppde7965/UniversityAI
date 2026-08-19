<?php
// 讓管理員帳號的電子郵件位址與 .env 一致。
//
// 存在的理由是一個踩過的坑：Moodle 的 email_to_user() 對結尾為 .invalid 的
// 位址直接放棄寄送，然後 return true——回報成功。站台內看得到通知、訊息
// 機制一切正常，信卻永遠不會出現在信件攔截服務裡，而且沒有任何錯誤。
//
// .env 是這個環境的宣告來源（NFR-MNT-05），所以 make install 每次都把帳號
// 對回去，而不是只在第一次安裝時設定。資料庫是留存的，只改 .env.example
// 對已經裝好的站台沒有作用。
//
// 冪等：位址已經一致時直接結束。

define('CLI_SCRIPT', true);

require('/var/www/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');

[$options, $unrecognised] = cli_get_params(
    [
        'username' => '',
        'email' => '',
        'help' => false,
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error('無法辨識的參數：' . implode(', ', $unrecognised));
}

if ($options['help'] || $options['username'] === '' || $options['email'] === '') {
    cli_writeln("讓管理員帳號的電子郵件位址與 .env 一致。

選項：
  --username=STRING  帳號名稱
  --email=STRING     電子郵件位址
  -h, --help         顯示此說明
");
    exit($options['help'] ? 0 : 1);
}

if (!validate_email($options['email'])) {
    cli_error("「{$options['email']}」不是合法的電子郵件位址。");
}

if (substr($options['email'], -8) === '.invalid') {
    cli_error("網域 .invalid 會讓 Moodle 靜靜地放棄寄信卻回報成功。請改用別的網域。");
}

$user = $DB->get_record('user', ['username' => $options['username'], 'deleted' => 0]);
if (!$user) {
    cli_error("找不到帳號 {$options['username']}。");
}

if ($user->email === $options['email']) {
    cli_writeln("管理員信箱已是 {$options['email']}，略過。");
    exit(0);
}

$old = $user->email;
user_update_user((object) ['id' => $user->id, 'email' => $options['email']], false, false);

cli_writeln("管理員信箱：{$old} → {$options['email']}");
