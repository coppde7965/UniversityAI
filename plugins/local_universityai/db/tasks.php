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
 * 排程任務註冊。
 *
 * 對應 docs/architecture.md §4.2。這裡給的只是預設值，站台管理員可在
 * 「網站管理 → 伺服器 → 排程任務」中調整，這是 FR-SUM-01 的驗收條件之一。
 *
 * 目前註冊 prune_ops 與 weekly_summary。reconcile 於第三階段加入——
 * 只註冊真的存在的類別，否則排程頁面會列出一個點下去就爆的項目。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_universityai\task\prune_ops',
        'blocking' => 0,
        'minute' => '15',
        'hour' => '3',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
    [
        // 每週一 06:00。挑週一是因為摘要的第一個區塊是「本週重點」，
        // 週一早上發才是本週開始；挑 06:00 是為了在多數人開始上課前跑完。
        // 這只是預設值，管理員可在排程設定中調整（FR-SUM-01 驗收條件）。
        'classname' => 'local_universityai\task\weekly_summary',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '6',
        'day' => '*',
        'dayofweek' => '1',
        'month' => '*',
    ],
];
