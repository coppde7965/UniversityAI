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

namespace local_universityai\redaction;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * 外送資料過濾（NFR-SEC-06 的驗收條件）。
 *
 * SRS §6.3 要求「PHPUnit 以刻意含個資的樣本驗證：過濾後的輸出不含任何黑名單
 * 項目」。樣本不寫在這個檔案裡，而是讀 redaction-samples.json——那份檔案同時
 * 是 Python 端測試集去識別化的樣本來源（A-05）。樣本寫在測試裡就變成 PHP
 * 專屬的，兩邊會各自漂移，而漂移的那一天沒有人會發現。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(filter::class)]
#[CoversClass(ruleset::class)]
#[CoversClass(filtered_payload::class)]
final class redaction_filter_test extends \advanced_testcase {
    /**
     * 讀取共用的黃金樣本。
     *
     * @return array<int, array<string, mixed>>
     */
    private function samples(): array {
        global $CFG;

        $path = $CFG->dirroot . '/local/universityai/' . ruleset::SAMPLES_FILE;
        $this->assertFileExists($path, '黃金樣本檔不存在。');

        $definition = json_decode(file_get_contents($path), true);
        $this->assertIsArray($definition['samples'] ?? null, '樣本檔格式不符。');

        return $definition['samples'];
    }

    /**
     * 每一組黃金樣本都得到期望的結果。
     *
     * 反向樣本（期望輸出等於輸入）與正向樣本同等重要：過濾器最危險的失敗
     * 不是漏掉個資，而是把課程日期誤判成電話號碼——那會安靜地毀掉摘要內容。
     */
    public function test_every_golden_sample_matches(): void {
        $filter = filter::create();

        foreach ($this->samples() as $sample) {
            $this->assertSame(
                $sample['expected'],
                $filter->redact($sample['input'], $sample['literals'] ?? []),
                "黃金樣本 {$sample['id']} 不符。" . ($sample['note'] ?? '')
            );
        }
    }

    /**
     * 黑名單樣本裡的個資，一個都不該留在輸出中。
     *
     * 上一個測試比對的是完整字串，這個測試問的是更直接的問題：輸出裡還有沒有
     * 原文中的信箱、電話、身分證字號。兩者看起來重複，但期望值寫錯時前者會
     * 跟著錯，後者不會。
     */
    public function test_no_blacklisted_fragment_survives(): void {
        $filter = filter::create();

        $forbidden = [
            'chen.yj@example.edu.tw',
            '0912-345-678',
            '02-23456789',
            'A123456789',
            'B10912345',
            '203.0.113.7',
            'sk-ant-api03-AbCdEf123456789',
            '陳怡君',
        ];

        foreach ($this->samples() as $sample) {
            $output = $filter->redact($sample['input'], $sample['literals'] ?? []);

            foreach ($forbidden as $fragment) {
                if (!str_contains($sample['input'], $fragment)) {
                    continue;
                }
                $this->assertStringNotContainsString(
                    $fragment,
                    $output,
                    "樣本 {$sample['id']} 的輸出仍含有「{$fragment}」。"
                );
            }
        }
    }

    /**
     * apply() 回傳的型別記得下命中了哪些規則。
     */
    public function test_apply_reports_which_rules_fired(): void {
        $filter = filter::create();

        $payload = $filter->apply('聯絡 chen.yj@example.edu.tw 或 0912-345-678');
        $this->assertContains('email', $payload->applied());
        $this->assertContains('phone_mobile_tw', $payload->applied());

        $clean = $filter->apply('第 3 週 知識表示與推理');
        $this->assertSame([], $clean->applied());
        $this->assertSame('第 3 週 知識表示與推理', $clean->text());
    }

    /**
     * filtered_payload 不能由 filter 以外的地方建立。
     *
     * 這條測試守的是 A-13 的實質內容。PHP 沒有 friend 機制，私有建構子擋不住
     * 別的類別呼叫公開的靜態工廠，所以保證是靠執行期檢查——而執行期檢查
     * 只有在有測試釘住它的時候才算數。
     */
    public function test_filtered_payload_cannot_be_forged(): void {
        try {
            filtered_payload::create('未經過濾的文字', []);
            $this->fail('繞過 filter 建立 filtered_payload 應該被擋下。');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('filter::apply', $e->getMessage());
        }
    }

    /**
     * SRS §6.3 黑名單列出的每一個項目，都要說出它是怎麼被處理的。
     *
     * 「怎麼處理」包含「靠白名單，這裡不處理」——那是一個明確的答案，
     * 而不是漏掉。成績與作業內容沒有可比對的樣式，硬寫規則只會製造
     * 「已經處理了」的錯覺，所以它們的答案就是 allowlist。
     */
    public function test_every_denylist_category_states_how_it_is_handled(): void {
        $categories = ruleset::from_plugin_file()->categories();

        $required = [
            'person_name', 'student_id', 'email', 'phone', 'postal_address',
            'moodle_user_id', 'ip_address', 'grade', 'assignment_content',
        ];

        foreach ($required as $id) {
            $this->assertArrayHasKey($id, $categories, "SRS §6.3 的黑名單項目 {$id} 沒有出現在規則檔中。");
            $this->assertContains(
                $categories[$id],
                ['pattern', 'literal', 'allowlist'],
                "類別 {$id} 的 handled_by 不是三種允許的處理方式之一。"
            );
        }

        // 這兩個是刻意不寫規則的，把它釘住——將來有人「補上」一條抓成績的
        // 正規表示式時，這條測試會先要求他解釋為什麼那件事做得到。
        $this->assertSame('allowlist', $categories['grade']);
        $this->assertSame('allowlist', $categories['assignment_content']);
        $this->assertSame('literal', $categories['person_name']);
    }

    /**
     * 白名單也在同一份規則檔裡，兩半不分家。
     */
    public function test_allowlist_travels_with_the_rules(): void {
        $fields = ruleset::from_plugin_file()->allowlist_fields();

        $this->assertNotEmpty($fields);
        $this->assertContains('course.fullname', $fields);
        $this->assertContains('event.title', $fields);
    }

    /**
     * 規則集在載入時就驗，不是等到跑到某條規則才炸。
     */
    public function test_ruleset_rejects_broken_definitions(): void {
        $denylist = ['categories' => [['id' => 'email', 'handled_by' => 'pattern']]];
        $ok = ['id' => 'x', 'category' => 'email', 'pattern' => 'a', 'replacement' => 'b'];

        $cases = [
            '空規則集' => ['denylist' => $denylist, 'rules' => []],
            '缺 pattern' => [
                'denylist' => $denylist,
                'rules' => [['id' => 'x', 'category' => 'email', 'replacement' => 'y']],
            ],
            '不合法的正規表示式' => [
                'denylist' => $denylist,
                'rules' => [['id' => 'x', 'category' => 'email', 'pattern' => '([', 'replacement' => 'y']],
            ],
            '規則掛在未宣告的類別下' => [
                'denylist' => $denylist,
                'rules' => [['id' => 'x', 'category' => 'horoscope', 'pattern' => 'a', 'replacement' => 'b']],
            ],
            '規則掛在非 pattern 的類別下' => [
                'denylist' => ['categories' => [['id' => 'person_name', 'handled_by' => 'literal']]],
                'rules' => [['id' => 'x', 'category' => 'person_name', 'pattern' => 'a', 'replacement' => 'b']],
            ],
            '宣告以樣式處理卻沒有規則' => [
                'denylist' => ['categories' => [
                    ['id' => 'email', 'handled_by' => 'pattern'],
                    ['id' => 'phone', 'handled_by' => 'pattern'],
                ]],
                'rules' => [$ok],
            ],
            '類別缺 handled_by' => [
                'denylist' => ['categories' => [['id' => 'email']]],
                'rules' => [$ok],
            ],
        ];

        foreach ($cases as $label => $definition) {
            try {
                ruleset::from_array($definition);
                $this->fail("「{$label}」應該在載入時就被擋下。");
            } catch (\coding_exception $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    /**
     * 過短的字面值不處理。
     *
     * 單字元的字面值（例如姓氏）會在中文文本裡大量誤中，把內容打成篩子。
     * 與其在正式流程中安靜地毀損內容，不如不處理這種輸入。
     */
    public function test_single_character_literals_are_ignored(): void {
        $filter = filter::create();

        $text = '陳老師與陳同學都會出席，陳述如下。';
        $this->assertSame($text, $filter->redact($text, ['陳']));
        $this->assertStringNotContainsString('陳老師', $filter->redact($text, ['陳老師']));
    }

    /**
     * 較長的字面值先換，否則短的會把長的切斷。
     */
    public function test_longer_literals_win(): void {
        $filter = filter::create();

        $output = $filter->redact('授課教師陳怡君。', ['陳怡', '陳怡君']);

        $this->assertStringNotContainsString('陳怡', $output);
        $this->assertSame('授課教師[NAME]。', $output);
    }
}
