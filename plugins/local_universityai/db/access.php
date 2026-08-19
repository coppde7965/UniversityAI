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
 * 自訂能力定義。
 *
 * 對應 docs/architecture.md §5.1。
 *
 * 這裡**沒有**站台層級的「建立課程」能力，那是 D-10 的直接結果：課程由
 * Moodle 既有流程建立，外掛不介入，因此不需要也不應該擁有那個權限。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // 上傳課綱（FR-SYL-01）。檢查點是課程脈絡，不是站台脈絡。
    'local/universityai:upload' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // 確認解析結果（FR-SYL-04）。
    'local/universityai:confirm' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // 依課綱填入課程資訊（FR-CRS-01、FR-CRS-02）。
    'local/universityai:populate' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // 檢視每週摘要（FR-SUM-01）。學生是主要對象。
    'local/universityai:viewsummary' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'student' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],

    // 使用課程問答（FR-CHT-01 ~ FR-CHT-03）。
    'local/universityai:chat' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'student' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],

    // 授權把自己的課程事件同步到外部行事曆（FR-CAL-03）。
    // 脈絡是使用者而非課程：授權的對象是「我的行事曆」，與修哪門課無關。
    'local/universityai:syncown' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_USER,
        'archetypes' => [
            'user' => CAP_ALLOW,
        ],
    ],

    // 檢視營運狀態面板（FR-DSH-02）。站台層級，只給管理者。
    'local/universityai:viewops' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
