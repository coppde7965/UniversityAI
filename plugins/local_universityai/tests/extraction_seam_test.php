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

namespace local_universityai\extraction;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * 抽取器接縫的三道防線（A-11）。
 *
 * 這條接縫沒有編譯期型別檢查——實作**不會** `implements` 我們的介面，理由
 * 見 `extractor_interface`。所以這組測試就是那個型別檢查的替代品，少了它，
 * 打錯類別名或改壞簽章都要等到老師上傳課綱那一刻才發現。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(configured_extractor::class)]
#[CoversClass(event_key::class)]
final class extraction_seam_test extends \advanced_testcase {
    /**
     * 每個測試都在交易中執行並於結束時回捲。
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * 隨附的供應商實作確實符合契約。
     *
     * **這是整組測試裡最重要的一條。** 它用反射比對，不用 `instanceof`——
     * 對方不 implements 我們的介面，`instanceof` 一定是 false。改動任一邊的
     * 簽章都會讓這裡紅，而那正是跨外掛契約需要的保護。
     */
    public function test_the_bundled_provider_matches_the_contract(): void {
        $classname = configured_extractor::DEFAULT_CLASS;

        if (!class_exists($classname)) {
            $this->markTestSkipped('aiprovider_claude 未安裝（外掛可獨立安裝）。');
        }

        foreach (configured_extractor::contract() as $method => $required) {
            $this->assertTrue(
                method_exists($classname, $method),
                "{$classname} 缺少契約要求的方法 {$method}()。"
            );
            $this->assertSame(
                $required,
                (new \ReflectionMethod($classname, $method))->getNumberOfRequiredParameters(),
                "{$classname}::{$method}() 的必要參數個數與契約不符。"
            );
        }
    }

    /**
     * 契約由介面反射而來，不是寫死的清單。
     *
     * 寫死的話，改了介面不會讓檢查跟著改——而那正是這道防線唯一要防的事。
     */
    public function test_the_contract_is_derived_from_the_interface(): void {
        $contract = configured_extractor::contract();

        $this->assertArrayHasKey('create', $contract);
        $this->assertArrayHasKey('extract', $contract);
        $this->assertSame(0, $contract['create']);
        $this->assertSame(4, $contract['extract']);
    }

    /**
     * 設定指名的類別不存在或不符契約時，要拋出說得清楚的錯誤。
     *
     * 回傳 null 會讓症狀變成「上傳課綱之後永遠沒有反應」，那是最難查的失敗
     * 形式。設定錯誤就該讓管理員看到設定錯誤。
     */
    public function test_a_misconfigured_class_fails_loudly(): void {
        set_config('extractorclass', 'local_universityai\\does_not_exist', 'local_universityai');

        try {
            configured_extractor::create();
            $this->fail('不存在的類別應該被擋下。');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('does_not_exist', $e->getMessage());
        }

        // 存在但不符契約的類別同樣要被擋——這裡借用一個一定存在、但沒有
        // create()/extract() 的核心類別。
        set_config('extractorclass', \stdClass::class, 'local_universityai');

        try {
            configured_extractor::create();
            $this->fail('不符契約的類別應該被擋下。');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('create', $e->getMessage());
        }
    }

    /**
     * 抽取模式由站台設定決定，且只認得兩個值（A-12）。
     *
     * 設定值被寫壞時回到 structured 而不是拋例外：那是預設值，而一個打錯的
     * 設定不該讓整個解析流程停擺。
     */
    public function test_extraction_mode_falls_back_to_structured(): void {
        set_config('extractionmode', configured_extractor::MODE_PROMPTED, 'local_universityai');
        $this->assertSame(configured_extractor::MODE_PROMPTED, configured_extractor::configured_mode());

        set_config('extractionmode', 'telepathy', 'local_universityai');
        $this->assertSame(configured_extractor::MODE_STRUCTURED, configured_extractor::configured_mode());

        unset_config('extractionmode', 'local_universityai');
        $this->assertSame(configured_extractor::MODE_STRUCTURED, configured_extractor::configured_mode());
    }

    /**
     * event_id 不含日期，所以改期不會改變識別碼。
     *
     * 這是 §9.6 的核心：同一場期中考在第 1 版與第 2 版必須得到相同的識別碼，
     * 否則 _evtmap 的唯一鍵擋不住重複，比對也會把「日期改了的同一場考試」
     * 看成「刪掉一場、新增一場」。
     */
    public function test_event_id_survives_a_date_change(): void {
        $before = event_key::assign([
            ['type' => 'exam', 'title' => '期中考', 'due_date' => '2025-10-14'],
        ]);
        $after = event_key::assign([
            ['type' => 'exam', 'title' => '期中考', 'due_date' => '2025-10-21'],
        ]);

        $this->assertSame($before[0]['event_id'], $after[0]['event_id']);
        $this->assertSame(event_key::LENGTH, strlen($before[0]['event_id']));
    }

    /**
     * 同一份課綱裡的同名事件靠出現序號區分。
     *
     * 沒有序號的話，一門課裡兩次「小考」會得到同一個識別碼，而 _evtmap 的
     * 唯一鍵會把第二次當成第一次的重複。
     */
    public function test_repeated_titles_get_distinct_ids(): void {
        $events = event_key::assign([
            ['type' => 'other', 'title' => '小考', 'due_date' => '2025-09-16'],
            ['type' => 'other', 'title' => '小考', 'due_date' => '2025-10-07'],
            ['type' => 'exam', 'title' => '小考', 'due_date' => '2025-10-21'],
        ]);

        $ids = array_column($events, 'event_id');
        $this->assertCount(3, array_unique($ids), '同名事件應該得到不同的識別碼。');
    }

    /**
     * 標題正規化只處理空白與全形數字，不做語意處理。
     *
     * 過度正規化會讓「作業一」與「作業二」有機會碰撞，那比不正規化糟得多。
     */
    public function test_title_normalisation_is_conservative(): void {
        $this->assertSame('作業 1', event_key::normalise('  作業　１  '));
        $this->assertSame('期中考', event_key::normalise("期中考\n"));

        // 不同的作業必須維持不同。
        $this->assertNotSame(
            event_key::normalise('作業一'),
            event_key::normalise('作業二')
        );
    }
}
