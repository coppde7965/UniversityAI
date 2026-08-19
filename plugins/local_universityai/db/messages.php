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
 * 訊息提供者註冊。
 *
 * 通知一律走 Moodle 既有的訊息機制，不自建寄信程式碼——這樣使用者的
 * 通知偏好設定、免打擾時段與訊息中心全部免費繼承（FR-NTF-01）。
 *
 * capability 欄位限定「誰有資格收到這類通知」。opsfailure 綁在
 * local/universityai:viewops 上，語意是：看得到營運面板的人才收得到
 * 營運失敗通知。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$messageproviders = [

    // 作業重試耗盡後通知管理員（NFR-REL-02）。
    'opsfailure' => [
        'capability' => 'local/universityai:viewops',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],
];
