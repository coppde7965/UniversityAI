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

namespace local_universityai;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * db/install.xml 與實際資料庫的一致性。
 *
 * 這組測試守的是一個具體的失誤：改了 install.xml 卻忘了遞增
 * version.php 的版本號。這種情況下站台不會執行任何升級，資料庫維持舊
 * 結構，而所有讀寫舊欄位的程式碼照常運作——直到有人碰到新欄位為止。
 * 沒有任何錯誤訊息會提醒你。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class schema_test extends \advanced_testcase {
    /**
     * install.xml 宣告的每一張表、每一個欄位都真的存在於資料庫中。
     */
    public function test_install_xml_matches_the_database(): void {
        global $CFG, $DB;

        $xmldbfile = new \xmldb_file($CFG->dirroot . '/local/universityai/db/install.xml');
        $this->assertTrue($xmldbfile->fileExists(), 'db/install.xml 不存在。');
        $this->assertTrue($xmldbfile->loadXMLStructure(), 'db/install.xml 無法解析。');

        $structure = $xmldbfile->getStructure();
        $tables = $structure->getTables();
        $this->assertNotEmpty($tables);

        $dbman = $DB->get_manager();

        foreach ($tables as $table) {
            $name = $table->getName();

            $this->assertTrue(
                $dbman->table_exists($name),
                "install.xml 宣告了資料表 {$name}，但資料庫裡沒有。"
                . '通常是改了 install.xml 卻沒有遞增 version.php 的版本號。'
            );

            $declared = [];
            foreach ($table->getFields() as $field) {
                $declared[] = $field->getName();
            }
            $actual = array_keys($DB->get_columns($name));

            sort($declared);
            sort($actual);
            $this->assertSame($declared, $actual, "資料表 {$name} 的欄位與 install.xml 不一致。");
        }
    }

    /**
     * 資料表名長度沒有超過 Moodle 的上限。
     *
     * Moodle 由表名衍生索引與鍵的名稱並截斷至 30 字元
     * （sql_generator::$names_max_length），而本外掛的必要前綴
     * local_universityai_ 就佔了 19 字元。後綴取太長不會壞，但名稱會被
     * 截斷成看不懂的東西，除錯時很痛苦。這裡把界線畫在表名本身。
     */
    public function test_table_names_stay_within_the_generator_limit(): void {
        global $CFG;

        $xmldbfile = new \xmldb_file($CFG->dirroot . '/local/universityai/db/install.xml');
        $xmldbfile->loadXMLStructure();

        foreach ($xmldbfile->getStructure()->getTables() as $table) {
            $this->assertLessThanOrEqual(
                28,
                \core_text::strlen($table->getName()),
                "資料表名 {$table->getName()} 太長，衍生的索引名會被截斷。"
            );
        }
    }
}
