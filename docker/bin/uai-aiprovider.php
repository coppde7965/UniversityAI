<?php
// 依 .env 建立或更新 Claude 供應商執行個體。
//
// 為什麼要用腳本而不是叫人去後台點：NFR-MNT-05 要求換一台機器能重現同一套
// 系統，而供應商執行個體是 AI 功能的前提。手動步驟寫在 README 裡遲早會被
// 跳過，展示前才發現沒設定。
//
// 金鑰的落點仍然符合 NFR-SEC-05：它最終存在 Moodle 的站台設定中，本腳本
// 只是把它從 .env（已 gitignore）搬進去，不寫進任何進版控的檔案。
//
// 冪等：同名執行個體已存在時更新其設定，不重複建立。

define('CLI_SCRIPT', true);

require('/var/www/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');

use aiprovider_claude\provider as claudeprovider;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;

[$options] = cli_get_params([
    'name' => 'Claude (UniversityAI)',
    'apikey' => '',
    'model' => '',
    'help' => false,
]);

if ($options['help']) {
    cli_writeln("建立或更新 Claude 供應商執行個體。

選項：
  --name=STRING    執行個體名稱，預設 'Claude (UniversityAI)'
  --apikey=STRING  Anthropic API 金鑰
  --model=STRING   模型識別，預設 " . claudeprovider::DEFAULT_MODEL . "
  -h, --help       顯示此說明
");
    exit(0);
}

$apikey = trim((string) $options['apikey']);
if ($apikey === '') {
    // 沒有金鑰時安靜跳過而不是失敗：沒有金鑰的環境（例如只想看看外掛長怎樣）
    // 仍然應該能跑完 make install。
    cli_writeln('未提供 ANTHROPIC_API_KEY，略過供應商設定。');
    cli_writeln('  之後補設定：把金鑰寫進 .env 再執行一次 make install。');
    exit(0);
}

$model = trim((string) $options['model']);
if ($model === '') {
    $model = claudeprovider::DEFAULT_MODEL;
}

$manager = \core\di::get(\core_ai\manager::class);

// 兩個動作用同一組設定。系統指令留空表示採用動作自己的預設值——
// 那是 Moodle 針對每個動作寫好的，沒有理由在這裡自作聰明覆寫。
$actionconfig = [];
foreach ([generate_text::class, summarise_text::class] as $actionclass) {
    $actionconfig[$actionclass] = [
        'enabled' => true,
        'settings' => [
            'model' => $model,
            'max_tokens' => 4096,
            'systeminstruction' => $actionclass::get_system_instruction(),
        ],
    ];
}

$config = [
    'apikey' => $apikey,
    'endpoint' => claudeprovider::DEFAULT_ENDPOINT,
];

$existing = null;
// ai_providers 的 provider 欄位存的是完整類別名（含命名空間），不是外掛名
// aiprovider_claude。用外掛名去 filter 會得到空結果，於是每次安裝都新增
// 一個重複的執行個體，而且不會有任何錯誤訊息。實際踩過。
foreach ($manager->get_provider_instances(['provider' => claudeprovider::class]) as $instance) {
    if ($instance->name === $options['name']) {
        $existing = $instance;
        break;
    }
}

if ($existing !== null) {
    $manager->update_provider_instance(
        provider: $existing,
        config: $config,
        actionconfig: $actionconfig,
    );
    $manager->enable_provider_instance($existing);
    cli_writeln("供應商「{$options['name']}」已更新（模型 {$model}）。");
    exit(0);
}

$manager->create_provider_instance(
    classname: claudeprovider::class,
    name: $options['name'],
    enabled: true,
    config: $config,
    actionconfig: $actionconfig,
);

cli_writeln("供應商「{$options['name']}」已建立並啟用（模型 {$model}）。");
