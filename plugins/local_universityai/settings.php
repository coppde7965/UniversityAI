<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * 站台設定頁。
 *
 * 出現在「網站管理 → 外掛 → 本機外掛 → UniversityAI」。
 *
 * 這個檔案只要存在，Moodle 就會把它載入管理樹——所以裡面必須守住
 * $hassiteconfig，否則沒有站台設定權限的使用者一樣會看到選單項目。
 *
 * API 金鑰不放在這裡：它屬於 aiprovider_claude 的供應商執行個體設定
 * （NFR-SEC-05），由 Moodle 的 AI 子系統以密碼欄位保管。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_universityai',
        new lang_string('pluginname', 'local_universityai')
    );
    $ADMIN->add('localplugins', $settings);

    // A-04：排程任務一律採游標分批，單次處理量有上限。
    $settings->add(new admin_setting_configtext(
        'local_universityai/batchsize',
        new lang_string('settings:batchsize', 'local_universityai'),
        new lang_string('settings:batchsize_desc', 'local_universityai'),
        \local_universityai\task\prune_ops::DEFAULT_BATCH_SIZE,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_universityai/opsretentiondays',
        new lang_string('settings:opsretentiondays', 'local_universityai'),
        new lang_string('settings:opsretentiondays_desc', 'local_universityai'),
        \local_universityai\task\prune_ops::DEFAULT_RETENTION_DAYS,
        PARAM_INT
    ));

    // NFR-MNT-01：提示詞以站台設定管理，調整不需改動程式邏輯。
    //
    // 預設值取自 generator 的常數而不是語言檔——那是給模型的指令，不是介面
    // 文字。放語言檔會讓摘要的語言跟著執行 cron 時的語言跑，理由見該常數的
    // 註解。設定值留空時程式會自動回到預設，所以清空是安全的。
    $settings->add(new admin_setting_configtextarea(
        'local_universityai/summaryprompt',
        new lang_string('settings:summaryprompt', 'local_universityai'),
        new lang_string('settings:summaryprompt_desc', 'local_universityai'),
        \local_universityai\summary\generator::DEFAULT_PROMPT,
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_universityai/parseprompt',
        new lang_string('settings:parseprompt', 'local_universityai'),
        new lang_string('settings:parseprompt_desc', 'local_universityai'),
        \local_universityai\task\parse_syllabus::DEFAULT_PROMPT,
        PARAM_RAW
    ));

    // A-12：原生與提示詞兩種模式由設定切換，**不做自動回退**。
    // 模型不支援結構化輸出時回應是 HTTP 400 且訊息明確；自動改走提示詞
    // 路徑會讓站台安靜降級，而管理員完全不知道發生了什麼。
    $settings->add(new admin_setting_configselect(
        'local_universityai/extractionmode',
        new lang_string('settings:extractionmode', 'local_universityai'),
        new lang_string('settings:extractionmode_desc', 'local_universityai'),
        \local_universityai\extraction\configured_extractor::MODE_STRUCTURED,
        [
            \local_universityai\extraction\configured_extractor::MODE_STRUCTURED =>
                new lang_string('settings:extractionmode:structured', 'local_universityai'),
            \local_universityai\extraction\configured_extractor::MODE_PROMPTED =>
                new lang_string('settings:extractionmode:prompted', 'local_universityai'),
        ]
    ));

    // A-11：抽取器的實作類別由設定以字串指名，不在程式碼中交叉引用。
    // 這是 §1.2 三條相依規則下唯一可行的接縫，代價與三道防線見
    // extraction\configured_extractor 的類別說明。
    $settings->add(new admin_setting_configtext(
        'local_universityai/extractorclass',
        new lang_string('settings:extractorclass', 'local_universityai'),
        new lang_string('settings:extractorclass_desc', 'local_universityai'),
        \local_universityai\extraction\configured_extractor::DEFAULT_CLASS,
        PARAM_RAW_TRIMMED
    ));

    // FR-SYL-03：信心值低於此門檻的項目在確認介面上要標示。
    $settings->add(new admin_setting_configtext(
        'local_universityai/confidencethreshold',
        new lang_string('settings:confidencethreshold', 'local_universityai'),
        new lang_string('settings:confidencethreshold_desc', 'local_universityai'),
        \local_universityai\extraction\validator::DEFAULT_CONFIDENCE_THRESHOLD,
        PARAM_FLOAT
    ));
}
