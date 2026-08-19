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
 * 版本資訊。
 *
 * Moodle AI 子系統的 Claude 供應商（SRS D-05 的第一條呼叫路徑）。
 *
 * 這個外掛同時承擔第二條路徑：課綱結構化抽取。子系統的 generate_text
 * 動作只收一個提示字串（實地確認過 \core_ai\aiactions\generate_text 的
 * 建構子簽章），帶不了 JSON Schema，所以抽取走外掛自己的公開介面。
 * 兩條路徑共用同一份金鑰與 HTTP 客戶端。
 *
 * @package    aiprovider_claude
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'aiprovider_claude';
$plugin->version = 2026081800;

// Moodle 5.2 的分支版本號（D-09）。5.2 之前的 AI 子系統沒有供應商執行個體
// API，整套設計不成立。
$plugin->requires = 2026042000;

$plugin->maturity = MATURITY_ALPHA;
$plugin->release = '0.1.0';
