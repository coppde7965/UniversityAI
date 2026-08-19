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
 * 繁體中文語言字串。
 *
 * 鍵必須與 lang/en/ 一致。字串一律用單引號。
 *
 * @package    aiprovider_claude
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['apikey'] = 'Anthropic API 金鑰';
$string['apikey_help'] = '於 console.anthropic.com 建立。金鑰存放於站台設定並以遮蔽方式顯示，不會寫進外掛原始碼或版本控制。';
$string['endpoint'] = 'API 端點';
$string['endpoint_help'] = '除非要把請求導向測試樁，否則維持預設值。';
$string['maxtokens'] = '回應長度上限';
$string['maxtokens_help'] = 'Anthropic 的 Messages API 要求必填，缺了請求會被拒絕。它限制的是回應長度，不是提示長度。';
$string['model'] = '模型';
$string['model_help'] = '模型識別，例如 claude-haiku-4-5。建議用別名而非帶日期的完整 id，這樣會隨上游更新。';
$string['pluginname'] = 'Claude API 供應商';
$string['privacy:metadata:aiprovider_claude:externalpurpose'] = '這些資訊會送至 Anthropic API 以產生回應。Anthropic 不會用 API 的輸入或輸出訓練模型。';
$string['privacy:metadata:aiprovider_claude:model'] = '用於產生回應的模型。';
$string['privacy:metadata:aiprovider_claude:prompttext'] = '為產生回應而送出的提示文字。';
$string['privacy:metadata:aiprovider_claude:userid'] = '使用者識別的站台專屬雜湊值，用於追查濫用而不洩漏使用者身分。';
$string['systeminstruction'] = '系統指令';
