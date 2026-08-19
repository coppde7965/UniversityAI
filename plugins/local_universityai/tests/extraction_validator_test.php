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
 * DM-SYLLABUS 的 schema 與驗證（FR-SYL-02、FR-SYL-03）。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(validator::class)]
#[CoversClass(schema::class)]
final class extraction_validator_test extends \advanced_testcase {
    /**
     * 一份完全合規的抽取結果。
     *
     * 三週、一個事件、評分加總 100。每個測試都從這份出發再破壞一處，
     * 這樣失敗訊息指到的就是被破壞的那一處。
     *
     * @return array
     */
    private function valid(): array {
        return [
            'course' => [
                'title' => '人工智慧導論',
                'code' => 'CS3025',
                'teacher' => null,
                'semester' => '114-1',
                'credits' => 3,
            ],
            'term' => ['start_date' => '2025-09-09', 'total_weeks' => 3, 'weekday' => 'TUE'],
            'grading' => [
                ['item' => '作業', 'weight' => 40, 'note' => ''],
                ['item' => '期末考', 'weight' => 60, 'note' => ''],
            ],
            'weeks' => [
                [
                    'week_no' => 1,
                    'date' => '2025-09-09',
                    'topic' => '課程介紹',
                    'note' => '',
                    'confidence' => 0.9,
                    'source_span' => '第 1 週 課程介紹',
                ],
                [
                    'week_no' => 2,
                    'date' => '2025-09-16',
                    'topic' => '搜尋演算法',
                    'note' => '',
                    'confidence' => 0.8,
                    'source_span' => '第 2 週 搜尋演算法',
                ],
                [
                    'week_no' => 3,
                    'date' => '2025-09-23',
                    'topic' => '知識表示',
                    'note' => '',
                    'confidence' => 0.7,
                    'source_span' => '第 3 週 知識表示',
                ],
            ],
            'events' => [
                [
                    'type' => 'assignment',
                    'title' => '作業一',
                    'due_date' => '2025-09-23',
                    'due_time' => '23:59',
                    'week_no' => 3,
                    'confidence' => 0.85,
                    'source_span' => '作業一 2025-09-23 前繳交',
                ],
            ],
            'parse_meta' => ['unresolved' => []],
        ];
    }

    /**
     * 對應上面那份結果的課綱原文。
     *
     * @return string
     */
    private function source(): string {
        return "第 1 週 課程介紹\n第 2 週 搜尋演算法\n第 3 週 知識表示\n作業一 2025-09-23 前繳交\n";
    }

    /**
     * 合規的結果不產生任何違規。
     */
    public function test_a_valid_payload_passes(): void {
        $verdict = (new validator())->validate($this->valid(), $this->source());

        $this->assertSame([], $verdict['errors']);
        $this->assertSame([], $verdict['notes']);
    }

    /**
     * schema 層：缺必填、多出欄位、列舉值不合法，都要被擋。
     */
    public function test_schema_violations_are_reported(): void {
        $validator = new validator();

        $missing = $this->valid();
        unset($missing['course']['code']);
        $this->assertNotEmpty($validator->validate($missing)['errors']);

        $extra = $this->valid();
        $extra['course']['room'] = 'E301';
        $this->assertNotEmpty($validator->validate($extra)['errors']);

        $badenum = $this->valid();
        $badenum['term']['weekday'] = 'MONDAY';
        $this->assertNotEmpty($validator->validate($badenum)['errors']);

        $badtype = $this->valid();
        $badtype['events'][0]['type'] = 'quiz';
        $this->assertNotEmpty($validator->validate($badtype)['errors']);
    }

    /**
     * schema 不過就不再跑不變式。
     *
     * 不變式全都假設欄位存在且型別正確，在壞掉的結構上跑只會產生一堆衍生
     * 錯誤，把真正的原因淹掉——而讀錯誤訊息的人會先看到那堆衍生錯誤。
     */
    public function test_invariants_are_skipped_when_the_schema_fails(): void {
        $broken = $this->valid();
        $broken['weeks'] = 'not an array';

        $verdict = (new validator())->validate($broken, $this->source());

        $this->assertCount(1, $verdict['errors']);
        $this->assertStringContainsString('weeks', $verdict['errors'][0]);
    }

    /**
     * 不變式一與二：週次連續，且數量等於 total_weeks。
     */
    public function test_week_sequence_and_count_invariants(): void {
        $validator = new validator();

        $gap = $this->valid();
        $gap['weeks'][2]['week_no'] = 5;
        $errors = $validator->validate($gap)['errors'];
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('連續', implode('', $errors));

        $short = $this->valid();
        array_pop($short['weeks']);
        $errors = $validator->validate($short)['errors'];
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('total_weeks', implode('', $errors));
    }

    /**
     * FR-SYL-03：confidence 必須介於 0 與 1 之間。
     *
     * 這條檢查在這裡而不是 schema 裡，是因為 API 端的 schema 不接受 number
     * 的 minimum/maximum（實測，見 schema.php）。搬過來的附帶好處是提示詞
     * 路徑也受同一份約束——那條路本來就沒有 API 端的保證。
     */
    public function test_confidence_must_be_within_range(): void {
        $data = $this->valid();
        $data['weeks'][0]['confidence'] = 1.5;

        $errors = (new validator())->validate($data)['errors'];

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('confidence', implode('', $errors));
    }

    /**
     * FR-SYL-03：source_span 必須實際出現在課綱原文中。
     *
     * 這是整組驗證裡最有價值的一項：它擋的是模型憑空生成內容、再附上一段
     * 同樣憑空生成的「出處」。實測撞到過——讀表格的純文字時，模型會把
     * 「週次1日期2025-09-12主題網路分層架構」這種重組後的字串當成原文引用。
     */
    public function test_fabricated_source_spans_are_caught(): void {
        $data = $this->valid();
        $data['weeks'][1]['source_span'] = '週次 2 日期 2025-09-16 主題 搜尋演算法';

        $errors = (new validator())->validate($data, $this->source())['errors'];

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('source_span', implode('', $errors));
    }

    /**
     * 中文斷行造成的空白差異不算幻覺。
     *
     * PDF 把「課程介紹與人工智慧發展簡史」折成兩行之後，抽出的文字中間會多
     * 一個空白，而模型引用時不會有。留著空白比對，每一個被折行的項目都會被
     * 判成幻覺——那是在量排版，不是在量幻覺。
     */
    public function test_line_wrapping_whitespace_is_ignored(): void {
        $data = $this->valid();
        $data['weeks'][0]['source_span'] = '第1週課程介紹';

        $verdict = (new validator())->validate($data, $this->source());

        $this->assertSame([], $verdict['errors']);
    }

    /**
     * 沒有原文時略過 source_span 檢查，而不是全部判成違規。
     *
     * 直接把 PDF 送給模型的路徑就沒有本地原文可比對。那條路的代價之一正是
     * 這項檢查失效——但失效要表現為「沒有檢查」，不是「檢查全數失敗」。
     */
    public function test_span_check_is_skipped_without_source_text(): void {
        $data = $this->valid();
        $data['weeks'][1]['source_span'] = '完全不存在於原文的一段話';

        $this->assertSame([], (new validator())->validate($data)['errors']);
    }

    /**
     * 不變式三與四：違反時只要誠實回報就是可接受的結果。
     *
     * SRS §5 對這兩條寫的是「須列入 unresolved，不得自動調整」——所以判準
     * 是有沒有回報，不是有沒有發生。真實課綱本來就會有補交期限落在學期外、
     * 或評分比例加總不是 100 的情況。
     */
    public function test_out_of_range_findings_depend_on_being_reported(): void {
        $validator = new validator();

        $late = $this->valid();
        $late['events'][0]['due_date'] = '2026-01-15';
        $lightweight = $late;
        $lightweight['grading'][0]['weight'] = 10;

        // 沒回報 → 錯誤。
        $verdict = $validator->validate($lightweight, $this->source());
        $this->assertCount(2, $verdict['errors']);
        $this->assertSame([], $verdict['notes']);

        // 有回報 → 只記為附註，可以寫入。
        $lightweight['parse_meta']['unresolved'] = ['補交期限在學期結束後', '評分比例加總不足 100'];
        $verdict = $validator->validate($lightweight, $this->source());
        $this->assertSame([], $verdict['errors']);
        $this->assertCount(2, $verdict['notes']);
    }

    /**
     * schema 不得含 API 端會拒絕的關鍵字。
     *
     * 三條約束都是實測撞到的（§9.1、§9.10），而且症狀都是整個請求 400，
     * 與資料內容無關。加回去很容易——minimum/maximum 看起來就是該寫的東西
     * ——所以用測試釘住。
     */
    public function test_schema_avoids_keywords_the_api_rejects(): void {
        $encoded = json_encode(schema::extraction_schema());

        $this->assertStringNotContainsString('minimum', $encoded);
        $this->assertStringNotContainsString('maximum', $encoded);

        $this->walk_objects(schema::extraction_schema(), function (array $node, string $path): void {
            $this->assertArrayHasKey('additionalProperties', $node, "{$path} 缺少 additionalProperties。");
            $this->assertFalse($node['additionalProperties'], "{$path} 的 additionalProperties 不是 false。");
            $this->assertArrayHasKey('required', $node, "{$path} 缺少 required。");
            $this->assertSame(
                array_keys($node['properties']),
                $node['required'],
                "{$path} 的 required 與 properties 不一致。"
            );
        });
    }

    /**
     * schema 不得向模型索取由系統決定的欄位。
     *
     * event_id 由 §9.6 的規則推導，交給模型會失去跨版本的穩定性；
     * overridden 是老師修改後才由系統標記的。兩者問了都只會得到編造的值。
     */
    public function test_schema_does_not_ask_for_system_assigned_fields(): void {
        $encoded = json_encode(schema::extraction_schema());

        foreach (['event_id', 'overridden', 'syllabus_id', 'course_ref', 'status'] as $field) {
            $this->assertStringNotContainsString(
                '"' . $field . '"',
                $encoded,
                "schema 不該向模型索取 {$field}。"
            );
        }
    }

    /**
     * 走訪 schema 中所有 object 節點。
     *
     * @param array $node 目前節點
     * @param callable $callback 對每個 object 節點呼叫
     * @param string $path 路徑，供失敗訊息使用
     * @return void
     */
    private function walk_objects(array $node, callable $callback, string $path = '$'): void {
        if (($node['type'] ?? null) === 'object') {
            $callback($node, $path);
            foreach ($node['properties'] ?? [] as $key => $child) {
                $this->walk_objects($child, $callback, $path . '.' . $key);
            }
        }

        if (($node['type'] ?? null) === 'array' && isset($node['items'])) {
            $this->walk_objects($node['items'], $callback, $path . '[]');
        }
    }
}
