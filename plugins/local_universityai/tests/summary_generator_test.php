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

namespace local_universityai\summary;

use local_universityai\ai\text_client;
use local_universityai\redaction\filter;
use local_universityai\redaction\filtered_payload;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * 摘要文字生成的行為（FR-SUM-01、NFR-SEC-06）。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(generator::class)]
final class summary_generator_test extends \advanced_testcase {
    /**
     * 每個測試都在交易中執行並於結束時回捲。
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * 一組有內容的事實。
     *
     * @return array
     */
    private function facts(): array {
        return [
            'courseid' => 987654,
            'coursename' => '人工智慧導論',
            'syllabusversion' => 3,
            'running' => true,
            'weekno' => 3,
            'weekstart' => 1756684800,
            'totalweeks' => 6,
            'thisweek' => ['week_no' => 3, 'topic' => '知識表示與推理', 'note' => ''],
            'nextweek' => ['week_no' => 4, 'topic' => '機器學習概論', 'note' => ''],
            'dueevents' => [
                ['title' => '作業一', 'type' => 'assignment', 'due' => 1757030340, 'source' => 'syllabus'],
            ],
            'literals' => ['陳怡君'],
            'hascontent' => true,
        ];
    }

    /**
     * 沒有內容時不呼叫語言模型，直接回傳「本週無安排」。
     *
     * 兩件事同時成立才算對：文字是那句固定字串，而且模型**完全沒有被呼叫**。
     * 只驗前者的話，一個每次都花錢問模型「請說本週無安排」的實作也會通過。
     */
    public function test_empty_week_never_calls_the_model(): void {
        $client = $this->createMock(text_client::class);
        $client->expects($this->never())->method('generate');

        $facts = $this->facts();
        $facts['hascontent'] = false;

        $result = (new generator(filter::create(), $client))->generate($facts, 1, 2);

        $this->assertTrue($result['success']);
        $this->assertSame(generator::STATUS_NOSCHEDULE, $result['status']);
        $this->assertSame(get_string('summary:noschedule', 'local_universityai'), $result['content']);
        $this->assertSame('', $result['model']);
    }

    /**
     * 送出的內容只含白名單欄位。
     *
     * SRS §6.3 禁止送出可跨課程或跨時間串接的識別碼。課程 id 正是那種識別碼，
     * 而模型撰寫摘要完全用不到它——這條測試釘住的就是「用不到就不要送」。
     */
    public function test_only_whitelisted_fields_leave_the_site(): void {
        $captured = null;

        $client = $this->createMock(text_client::class);
        $client->method('generate')->willReturnCallback(
            function (filtered_payload $payload) use (&$captured) {
                $captured = $payload->text();

                return [
                    'success' => true,
                    'content' => '本週重點……',
                    'model' => 'stub',
                    'prompttokens' => 1,
                    'completiontokens' => 1,
                    'durationms' => 5,
                    'errormessage' => '',
                ];
            }
        );

        (new generator(filter::create(), $client))->generate($this->facts(), 1, 2);

        $this->assertNotNull($captured);
        $this->assertStringContainsString('人工智慧導論', $captured);
        $this->assertStringContainsString('知識表示與推理', $captured);
        $this->assertStringContainsString('作業一', $captured);

        foreach (['courseid', 'course_id', 'syllabusversion', 'syllabus_version', 'literals', 'source'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $captured,
                "提示詞中出現了不該外送的欄位 {$forbidden}。"
            );
        }

        // 987654 是課程 id。它不該以任何形式出現在提示詞裡。
        $this->assertStringNotContainsString('987654', $captured);
    }

    /**
     * 週次主題裡夾帶的教師姓名會被過濾掉。
     *
     * 教師姓名不在白名單欄位內，但自由文字有機會夾帶——這正是
     * context_builder 要回傳 literals 的理由。
     */
    public function test_names_hidden_in_free_text_are_redacted(): void {
        $captured = null;

        $client = $this->createMock(text_client::class);
        $client->method('generate')->willReturnCallback(
            function (filtered_payload $payload) use (&$captured) {
                $captured = $payload->text();

                return [
                    'success' => true,
                    'content' => 'ok',
                    'model' => 'stub',
                    'prompttokens' => 1,
                    'completiontokens' => 1,
                    'durationms' => 1,
                    'errormessage' => '',
                ];
            }
        );

        $facts = $this->facts();
        $facts['thisweek']['note'] = '本週由陳怡君老師客座，聯絡信箱 chen.yj@example.edu.tw';

        (new generator(filter::create(), $client))->generate($facts, 1, 2);

        $this->assertStringNotContainsString('陳怡君', $captured);
        $this->assertStringNotContainsString('chen.yj@example.edu.tw', $captured);
        $this->assertStringContainsString('[NAME]', $captured);
        $this->assertStringContainsString('[EMAIL]', $captured);
    }

    /**
     * 站台設定可以覆寫提示詞（NFR-MNT-01），留白則回到預設值。
     */
    public function test_prompt_comes_from_site_configuration(): void {
        $captured = null;

        $client = $this->createMock(text_client::class);
        $client->method('generate')->willReturnCallback(
            function (filtered_payload $payload) use (&$captured) {
                $captured = $payload->text();

                return [
                    'success' => true,
                    'content' => 'ok',
                    'model' => 'stub',
                    'prompttokens' => 1,
                    'completiontokens' => 1,
                    'durationms' => 1,
                    'errormessage' => '',
                ];
            }
        );

        $generator = new generator(filter::create(), $client);

        set_config('summaryprompt', '請用一句話總結。', 'local_universityai');
        $generator->generate($this->facts(), 1, 2);
        $this->assertStringStartsWith('請用一句話總結。', $captured);

        set_config('summaryprompt', '', 'local_universityai');
        $generator->generate($this->facts(), 1, 2);
        $this->assertStringStartsWith('你是課程助理', $captured);
    }

    /**
     * 模型回了空字串時視為失敗。
     *
     * 供應商回報成功但內容是空的，這種情況存在（例如被安全機制擋下）。
     * 不擋的話會發布一則空白摘要，而那正是驗收條件明文禁止的。
     */
    public function test_empty_content_counts_as_failure(): void {
        $client = $this->createMock(text_client::class);
        $client->method('generate')->willReturn([
            'success' => true,
            'content' => '',
            'model' => 'stub',
            'prompttokens' => 1,
            'completiontokens' => 0,
            'durationms' => 1,
            'errormessage' => '',
        ]);

        $result = (new generator(filter::create(), $client))->generate($this->facts(), 1, 2);

        $this->assertFalse($result['success']);
    }
}
