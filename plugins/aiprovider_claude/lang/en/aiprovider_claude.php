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
 * 英文語言字串。
 *
 * 鍵必須與 lang/zh_tw/ 一致，且照字母順序排列、字串之間不得插註解
 * （Moodle 的語言檔規範，phpcs 會檢查）。
 *
 * @package    aiprovider_claude
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['apikey'] = 'Anthropic API key';
$string['apikey_help'] = 'Create a key at console.anthropic.com. It is stored in the site configuration as a masked value and is never written to the plugin source or version control.';
$string['endpoint'] = 'API endpoint';
$string['endpoint_help'] = 'Leave as the default unless you are pointing the plugin at a test double.';
$string['maxtokens'] = 'Maximum response tokens';
$string['maxtokens_help'] = 'The Anthropic Messages API requires this parameter; a request without it is rejected. It caps the response length, not the prompt.';
$string['model'] = 'Model';
$string['model_help'] = 'A model id such as claude-haiku-4-5. Prefer an alias over a dated id so the plugin follows upstream releases.';
$string['pluginname'] = 'Claude API provider';
$string['privacy:metadata:aiprovider_claude:externalpurpose'] = 'This information is sent to the Anthropic API so that a response can be generated. Anthropic does not use API inputs or outputs to train its models.';
$string['privacy:metadata:aiprovider_claude:model'] = 'The model used to generate the response.';
$string['privacy:metadata:aiprovider_claude:prompttext'] = 'The prompt text sent to generate the response.';
$string['privacy:metadata:aiprovider_claude:userid'] = 'A site-specific hash of the user id, sent so that abuse can be traced without revealing the user.';
$string['systeminstruction'] = 'System instruction';
