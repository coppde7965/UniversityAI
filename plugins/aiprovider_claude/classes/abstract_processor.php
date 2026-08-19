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

namespace aiprovider_claude;

use core\http_client;
use core_ai\process_base;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 所有動作共用的請求與回應處理。
 *
 * @package    aiprovider_claude
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class abstract_processor extends process_base {
    /**
     * 這個動作要用的模型。
     *
     * @return string
     */
    protected function get_model(): string {
        return $this->provider->actionconfig[$this->action::class]['settings']['model']
            ?? provider::DEFAULT_MODEL;
    }

    /**
     * 回應長度上限。
     *
     * Anthropic 的 Messages API **要求**帶 max_tokens，缺了直接 400。
     * 這與 OpenAI 相容 API 不同，是移植時最常撞到的第一個差異。
     *
     * @return int
     */
    protected function get_max_tokens(): int {
        $value = (int) ($this->provider->actionconfig[$this->action::class]['settings']['max_tokens'] ?? 0);

        return $value > 0 ? $value : 4096;
    }

    /**
     * 系統指令。動作自己有預設值，站台設定可以覆寫。
     *
     * @return string
     */
    protected function get_system_instruction(): string {
        return $this->provider->actionconfig[$this->action::class]['settings']['systeminstruction']
            ?? $this->action::get_system_instruction();
    }

    /**
     * 組出 Anthropic Messages API 的請求。
     *
     * @param string $userid 經過雜湊的使用者識別
     * @return RequestInterface
     */
    protected function create_request_object(string $userid): RequestInterface {
        $body = [
            'model' => $this->get_model(),
            'max_tokens' => $this->get_max_tokens(),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->action->get_configuration('prompttext'),
                ],
            ],
        ];

        // system 是**頂層參數**，不是 messages 裡角色為 system 的一則訊息。
        // 後者是 OpenAI 的形狀，Anthropic 會回 400。
        $systeminstruction = $this->get_system_instruction();
        if ($systeminstruction !== '') {
            $body['system'] = $systeminstruction;
        }

        // 讓 Anthropic 端能把濫用行為對應到某個使用者，同時不洩漏真實身分——
        // $userid 已由 core_ai 以站台密鑰雜湊過。
        $body['metadata'] = ['user_id' => $userid];

        return new Request(
            method: 'POST',
            uri: $this->provider->get_endpoint(),
            body: json_encode($body),
            headers: ['content-type' => 'application/json'],
        );
    }

    #[\Override]
    protected function query_ai_api(): array {
        $request = $this->create_request_object(
            $this->provider->generate_userid($this->action->get_configuration('userid'))
        );
        $request = $this->provider->add_authentication_headers($request);

        $client = \core\di::get(http_client::class);
        try {
            $response = $client->send($request, [RequestOptions::HTTP_ERRORS => false]);
        } catch (RequestException $e) {
            return \core_ai\error\factory::create($e->getCode(), $e->getMessage())->get_error_details();
        }

        $status = $response->getStatusCode();
        if ($status === 200) {
            return $this->handle_api_success($response);
        }

        return $this->handle_api_error($response);
    }

    /**
     * 成功回應的解析。
     *
     * @param ResponseInterface $response
     * @return array
     */
    protected function handle_api_success(ResponseInterface $response): array {
        $bodyobj = json_decode($response->getBody()->getContents());

        // content 是區塊陣列，不是字串。可能同時含 thinking 與 text 區塊，
        // 只取 text 的部分串起來。直接讀 content[0]->text 在開了思考的模型
        // 上會拿到錯的東西。
        $text = '';
        foreach ($bodyobj->content ?? [] as $block) {
            if (($block->type ?? '') === 'text') {
                $text .= $block->text;
            }
        }

        return [
            'success' => true,
            'id' => $bodyobj->id ?? null,
            'generatedcontent' => $text,
            'finishreason' => $bodyobj->stop_reason ?? null,
            'prompttokens' => $bodyobj->usage->input_tokens ?? null,
            'completiontokens' => $bodyobj->usage->output_tokens ?? null,
            'model' => $bodyobj->model ?? $this->get_model(),
        ];
    }

    /**
     * 錯誤回應的解析。
     *
     * Anthropic 的錯誤形狀是 {"type":"error","error":{"type":..,"message":..}}，
     * 與 Ollama 的 {"error":"字串"} 不同——照抄核心供應商的寫法會在解析
     * 錯誤訊息時再拋一次錯，把真正的原因蓋掉。
     *
     * @param ResponseInterface $response
     * @return array
     */
    protected function handle_api_error(ResponseInterface $response): array {
        $status = $response->getStatusCode();
        $bodyobj = json_decode($response->getBody()->getContents());

        $errormessage = $bodyobj->error->message
            ?? $bodyobj->error->type
            ?? $response->getReasonPhrase();

        return \core_ai\error\factory::create($status, $errormessage)->get_error_details();
    }
}
