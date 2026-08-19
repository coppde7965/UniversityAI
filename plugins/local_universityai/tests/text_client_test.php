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

namespace local_universityai\ai;

use core_ai\aiactions\responses\response_generate_text;
use core_ai\manager;
use local_universityai\ops;
use local_universityai\redaction\filter;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * 生成類 AI 呼叫收口的行為（A-13）。
 *
 * 用替身換掉 \core_ai\manager，所以整組測試不碰網路——這正是 §9.8 說的
 * L1 做法：核心自己的供應商測試也是這樣寫的。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(text_client::class)]
final class text_client_test extends \advanced_testcase {
    /**
     * 每個測試都在交易中執行並於結束時回捲。
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * 建立一個回傳固定回應的 AI 子系統替身。
     *
     * @param bool $success 要成功還是失敗
     * @return manager
     */
    private function stub_manager(bool $success): manager {
        $response = new response_generate_text(
            success: $success,
            errorcode: $success ? 0 : 500,
            error: $success ? '' : 'upstream',
            errormessage: $success ? '' : '供應商回報錯誤',
        );

        if ($success) {
            $response->set_response_data([
                'generatedcontent' => "  本週重點：知識表示與推理。  \n",
                'model' => 'claude-haiku-4-5-20251001',
                'prompttokens' => 120,
                'completiontokens' => 88,
            ]);
        }

        $manager = $this->createMock(manager::class);
        $manager->method('process_action')->willReturn($response);

        return $manager;
    }

    /**
     * 成功時回傳內容與模型，並寫下一筆成功的營運紀錄。
     *
     * 成功也記，是因為 FR-DSH-02 的面板要能回答「這週的摘要跑了沒」。
     * 只記失敗的話，「沒有紀錄」會同時代表成功與根本沒跑。
     */
    public function test_successful_call_returns_content_and_records_success(): void {
        global $DB;

        $client = new text_client($this->stub_manager(true));
        $payload = filter::create()->apply('請產生本週摘要。');

        $result = $client->generate($payload, \context_system::instance()->id, 2, ops::OP_WEEKLY_SUMMARY, '7');

        $this->assertTrue($result['success']);
        $this->assertSame('本週重點：知識表示與推理。', $result['content']);
        $this->assertSame('claude-haiku-4-5-20251001', $result['model']);
        $this->assertSame(120, $result['prompttokens']);
        $this->assertSame(88, $result['completiontokens']);

        $record = $DB->get_record(ops::TABLE, ['targetid' => '7'], '*', MUST_EXIST);
        $this->assertSame(ops::OP_WEEKLY_SUMMARY, $record->operation);
        $this->assertSame(ops::STATUS_SUCCESS, $record->status);
    }

    /**
     * 失敗時不拋例外，回傳失敗結果並記一筆失敗。
     *
     * 不在這裡拋，是因為要不要讓例外往上給 Moodle 的重試機制（§4.4）是
     * 呼叫端的決定；收口層自己決定會讓每個呼叫端都失去選擇。
     */
    public function test_failed_call_records_failure_without_throwing(): void {
        global $DB;

        $client = new text_client($this->stub_manager(false));
        $payload = filter::create()->apply('請產生本週摘要。');

        $result = $client->generate($payload, \context_system::instance()->id, 2, ops::OP_WEEKLY_SUMMARY, '7');

        $this->assertFalse($result['success']);
        $this->assertSame('', $result['content']);
        $this->assertNotSame('', $result['errormessage']);

        $record = $DB->get_record(ops::TABLE, ['targetid' => '7'], '*', MUST_EXIST);
        $this->assertSame(ops::STATUS_FAILED, $record->status);

        // 失敗訊息不落地。它來自外部服務，可能夾帶請求內容（§6.3）。
        $this->assertNull($record->error);
    }

    /**
     * 送出的文字就是過濾後的文字，不是原文。
     */
    public function test_only_the_filtered_text_leaves_the_site(): void {
        $captured = '';

        $manager = $this->createMock(manager::class);
        $manager->method('process_action')->willReturnCallback(
            function ($action) use (&$captured) {
                $captured = $action->get_configuration('prompttext');
                $response = new response_generate_text(success: true);
                $response->set_response_data(['generatedcontent' => 'ok', 'model' => 'stub']);

                return $response;
            }
        );

        $payload = filter::create()->apply('聯絡 chen.yj@example.edu.tw', ['陳怡君']);
        (new text_client($manager))->generate(
            $payload,
            \context_system::instance()->id,
            2,
            ops::OP_WEEKLY_SUMMARY,
            '7'
        );

        $this->assertStringNotContainsString('chen.yj@example.edu.tw', $captured);
        $this->assertStringContainsString('[EMAIL]', $captured);
    }
}
