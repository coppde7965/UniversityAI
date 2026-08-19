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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * \local_universityai\ops 的單元測試（bdd-guide L1）。
 *
 * 涵蓋範圍以屬性標註而非 @ covers 註解：Moodle 5.2 帶的是 PHPUnit 11，
 * 註解式的中繼資料已棄用，PHPUnit 12 會直接不支援。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(ops::class)]
final class ops_test extends \advanced_testcase {
    /**
     * 每個測試都在交易中執行並於結束時回捲。
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * 合法的紀錄會寫入，且欄位原樣存回。
     */
    public function test_record_writes_the_row(): void {
        global $DB;

        $before = time();
        $id = ops::record(ops::OP_SYLLABUS_PARSE, ops::TARGET_SYLLABUS, '42', ops::STATUS_SUCCESS);
        $after = time();

        $row = $DB->get_record(ops::TABLE, ['id' => $id], '*', MUST_EXIST);

        $this->assertSame(ops::OP_SYLLABUS_PARSE, $row->operation);
        $this->assertSame(ops::TARGET_SYLLABUS, $row->targettype);
        $this->assertSame('42', $row->targetid);
        $this->assertSame(ops::STATUS_SUCCESS, $row->status);
        $this->assertEquals(1, $row->attempt);
        $this->assertGreaterThanOrEqual($before, (int) $row->occurredat);
        $this->assertLessThanOrEqual($after, (int) $row->occurredat);
    }

    /**
     * error 欄位永遠是 null。
     *
     * 這條測試守的是一個安全決定而不是行為細節：DM-OPSTATUS 的 error
     * 必須先經過外送資料過濾（NFR-SEC-06）才能寫入，過濾元件在第三階段
     * 才建立。在那之前 record() 不開放這條路徑；若有人日後加了 error
     * 參數卻忘了接上過濾，這條測試會先紅。
     */
    public function test_record_never_writes_error_text(): void {
        global $DB;

        $id = ops::record(ops::OP_SYLLABUS_PARSE, ops::TARGET_SYLLABUS, '42', ops::STATUS_FAILED, 3);
        $row = $DB->get_record(ops::TABLE, ['id' => $id], '*', MUST_EXIST);

        $this->assertNull($row->error);
    }

    /**
     * 未定義的列舉值一律擋下，不要靜靜寫進去。
     *
     * 這裡用迴圈而不是 dataProvider，是為了不依賴 PHPUnit 的中繼資料寫法
     * ——註解式與屬性式在 PHPUnit 9 到 12 之間換過，跨版本會壞。
     */
    public function test_record_rejects_undefined_values(): void {
        global $DB;

        $cases = [
            '未定義的作業類型' => ['syllabus_delete', ops::TARGET_SYLLABUS, '1', ops::STATUS_SUCCESS],
            '未定義的對象類型' => [ops::OP_NOTIFY, 'group', '1', ops::STATUS_SUCCESS],
            '未定義的狀態' => [ops::OP_NOTIFY, ops::TARGET_USER, '1', 'pending'],
            '空的對象識別' => [ops::OP_NOTIFY, ops::TARGET_USER, '', ops::STATUS_SUCCESS],
            '過長的對象識別' => [ops::OP_NOTIFY, ops::TARGET_USER, str_repeat('x', 65), ops::STATUS_SUCCESS],
            '嘗試次數小於 1' => [ops::OP_NOTIFY, ops::TARGET_USER, '1', ops::STATUS_SUCCESS, 0],
        ];

        foreach ($cases as $label => $args) {
            try {
                ops::record(...$args);
                $this->fail("「{$label}」應該被擋下卻寫入了。");
            } catch (\coding_exception $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }

        // 擋下之後不該留下任何半套的紀錄。
        $this->assertSame(0, $DB->count_records(ops::TABLE));
    }

    /**
     * prune 只刪掉超過保留期的紀錄，且受單次上限約束。
     */
    public function test_prune_respects_retention_and_limit(): void {
        global $DB;

        $now = time();

        // 三筆很舊、兩筆很新。
        for ($i = 0; $i < 3; $i++) {
            $id = ops::record(ops::OP_NOTIFY, ops::TARGET_USER, 'old' . $i, ops::STATUS_SUCCESS);
            $DB->set_field(ops::TABLE, 'occurredat', $now - 100 * DAYSECS - $i, ['id' => $id]);
        }
        for ($i = 0; $i < 2; $i++) {
            ops::record(ops::OP_NOTIFY, ops::TARGET_USER, 'new' . $i, ops::STATUS_SUCCESS);
        }

        // 單次上限 2：只該刪掉最舊的兩筆。
        $this->assertSame(2, ops::prune(90, 2));
        $this->assertSame(3, $DB->count_records(ops::TABLE));

        // 再跑一次把剩下那筆舊的刪掉。
        $this->assertSame(1, ops::prune(90, 2));
        $this->assertSame(2, $DB->count_records(ops::TABLE));

        // 新的兩筆不動，之後再跑也不會刪。
        $this->assertSame(0, ops::prune(90, 2));
        $this->assertSame(2, $DB->count_records(ops::TABLE));
    }

    /**
     * 保留天數為 0 的語意是「不要清理」，不是「全部刪掉」。
     */
    public function test_prune_treats_zero_retention_as_disabled(): void {
        global $DB;

        $id = ops::record(ops::OP_NOTIFY, ops::TARGET_USER, '1', ops::STATUS_SUCCESS);
        $DB->set_field(ops::TABLE, 'occurredat', 1, ['id' => $id]);

        $this->assertSame(0, ops::prune(0, 100));
        $this->assertSame(0, ops::prune(-1, 100));
        $this->assertSame(1, $DB->count_records(ops::TABLE));
    }
}
