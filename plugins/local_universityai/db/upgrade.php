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
 * 升級步驟。
 *
 * 全新安裝走 db/install.xml，既有站台走這裡。兩者必須產生相同的結構——
 * 這是 Moodle 外掛最常見的一種漂移，而且不會有任何錯誤訊息，只會在某天
 * 出現「開發機正常、別人的站台說找不到欄位」。
 *
 * 兩邊各由不同方式驗證，因為沒有單一測試涵蓋得了：
 *   - install.xml 這一側由 tests/schema_test.php 比對實際資料庫。
 *   - 這一側只能實跑——本專案的驗證方式是讓開發站台真的從舊版升上來
 *     （make install），再確認五張表都在。改動這裡時請照做，不要只跑
 *     PHPUnit：PHPUnit 的測試資料庫是用 install.xml 建的，走不到這裡。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// 這個檔案刻意沒有 defined('MOODLE_INTERNAL') 檢查。Moodle 的編碼規範是
// 「只定義函式、沒有副作用的檔案不需要」，加了反而會被 phpcs 標為多餘。

/**
 * 執行 local_universityai 的升級步驟。
 *
 * @param int $oldversion 站台目前記錄的版本號
 * @return bool
 */
function xmldb_local_universityai_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // 第一階段：骨架長出四張資料表（docs/architecture.md §3.2）。
    if ($oldversion < 2026081700) {
        $table = new xmldb_table('local_universityai_syllabus');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('version', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'parsed');
        $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('contenthash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('model', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('parsedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('confirmedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('confirmedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('confirmedby', XMLDB_KEY_FOREIGN, ['confirmedby'], 'user', ['id']);
        $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
        $table->add_index('courseid-version', XMLDB_INDEX_UNIQUE, ['courseid', 'version']);
        $table->add_index('courseid-status', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'status']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_universityai_evtmap');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('eventkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('moodleeventid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('targettype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'event');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_index('courseid-eventkey', XMLDB_INDEX_UNIQUE, ['courseid', 'eventkey']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_universityai_diff');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('fromversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('toversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('changes', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('summarytext', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('hasmajor', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_index(
            'courseid-fromversion-toversion',
            XMLDB_INDEX_UNIQUE,
            ['courseid', 'fromversion', 'toversion']
        );
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_universityai_ops');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('operation', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('targettype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('targetid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('attempt', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('error', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('occurredat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('operation-occurredat', XMLDB_INDEX_NOTUNIQUE, ['operation', 'occurredat']);
        $table->add_index('targettype-targetid', XMLDB_INDEX_NOTUNIQUE, ['targettype', 'targetid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026081700, 'local', 'universityai');
    }

    // 第二階段：每週摘要（FR-SUM-01）需要一張自己的表。
    if ($oldversion < 2026081800) {
        $table = new xmldb_table('local_universityai_summary');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('weekstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('weekno', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('version', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('syllabusversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'generated');
        $table->add_field('content', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('facts', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('model', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('generatedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_index('courseid-weekstart-version', XMLDB_INDEX_UNIQUE, ['courseid', 'weekstart', 'version']);
        $table->add_index('courseid-weekstart', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'weekstart']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026081800, 'local', 'universityai');
    }

    // 第三階段：上傳與解析是兩個時刻（FR-SYL-01、FR-SYL-02）。
    //
    // payload 原本是 NOT NULL，因為第一階段設計時只想到「解析完才有一列」。
    // 實際接上上傳流程後才發現順序相反：檔案先進來、拿到課綱 id、才排解析
    // 任務。用空字串佔位會讓「還沒解析」與「解析出空結果」分不開，所以改為
    // 允許 null，並把預設狀態改成 uploaded。
    if ($oldversion < 2026081900) {
        $table = new xmldb_table('local_universityai_syllabus');

        $field = new xmldb_field('payload', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $dbman->change_field_notnull($table, $field);

        // status 被 courseid-status 索引參照，而 XMLDB 拒絕修改被索引參照的
        // 欄位（ddldependencyerror）。必須先卸索引、改欄位、再建回來。
        //
        // 這一段實測撞到過：改的只是預設值，直覺上不該有影響，但 XMLDB 的
        // 檢查看的是欄位有沒有被參照，不是改了什麼。
        $index = new xmldb_index('courseid-status', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'status']);
        $hadindex = $dbman->index_exists($table, $index);
        if ($hadindex) {
            $dbman->drop_index($table, $index);
        }

        $field = new xmldb_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'uploaded');
        $dbman->change_field_default($table, $field);

        if ($hadindex) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026081900, 'local', 'universityai');
    }

    return true;
}
