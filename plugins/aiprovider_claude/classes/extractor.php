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
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

/**
 * 結構化抽取——D-05 的第二條呼叫路徑。
 *
 * 為什麼不走 AI 子系統：core_ai 的 generate_text 動作建構子只收
 * (contextid, userid, prompttext) 三個參數，帶不了 JSON Schema。這是讀
 * Moodle 5.2 原始碼確認的，SRS D-05 據此分成兩條路徑。抽取因此由本外掛
 * 直接發出請求，不經子系統。
 *
 * 兩條路徑共用同一個供應商執行個體的金鑰與端點，站台管理員只設定一次。
 *
 * @package    aiprovider_claude
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class extractor {
    /** @var provider 供應商執行個體，提供金鑰與端點。 */
    private provider $provider;

    /** @var string 模型識別。 */
    private string $model;

    /** @var int 回應長度上限。 */
    private int $maxtokens;

    /**
     * 建構子。
     *
     * @param provider $provider 供應商執行個體
     * @param string $model 模型識別，留空表示用供應商預設
     * @param int $maxtokens 回應長度上限
     */
    public function __construct(provider $provider, string $model = '', int $maxtokens = 8192) {
        $this->provider = $provider;
        $this->model = $model !== '' ? $model : provider::DEFAULT_MODEL;
        $this->maxtokens = $maxtokens;
    }

    /**
     * 找到本站台已設定好的 Claude 供應商執行個體並建立抽取器。
     *
     * **這個方法與 extract() 共同構成本外掛對 local_universityai 的契約**，
     * 但本類別刻意**不 implements 對方的介面**：那會讓本外掛在一個沒有安裝
     * UniversityAI 的站台上一載入就致命錯誤，而獨立可安裝正是本外掛的價值
     * （見對方的 extraction\configured_extractor 與其 A-11）。
     *
     * 契約的形狀由對方以反射比對，不靠型別系統。因此**改動這兩個方法的簽章
     * 等於改動跨外掛的契約**，不要當成內部重構。
     *
     * @return self|null 找不到已設定金鑰的執行個體時為 null
     */
    public static function create(): ?self {
        $manager = \core\di::get(\core_ai\manager::class);

        foreach ($manager->get_provider_instances(['provider' => provider::class]) as $instance) {
            if (!$instance->is_provider_configured()) {
                continue;
            }

            // 模型沿用 generate_text 動作的設定，讓管理員只需要設定一次。
            // 抽取路徑不經 AI 子系統，但沒有理由用不同的模型。
            $model = $instance->actionconfig[\core_ai\aiactions\generate_text::class]['settings']['model']
                ?? provider::DEFAULT_MODEL;

            return new self($instance, $model);
        }

        return null;
    }

    /**
     * 依模式抽取。契約見 create() 的說明。
     *
     * @param string $text 課綱原文
     * @param array $schema JSON Schema
     * @param string $instruction 系統指令
     * @param string $mode 'structured' 或 'prompted'
     * @return array 見 self::request()
     */
    public function extract(string $text, array $schema, string $instruction, string $mode): array {
        return $mode === 'prompted'
            ? $this->extract_prompted($text, $schema, $instruction)
            : $this->extract_structured($text, $schema, $instruction);
    }

    /**
     * 以原生結構化輸出抽取。由 API 端保證回應符合 schema。
     *
     * @param string $text 課綱原文
     * @param array $schema JSON Schema
     * @param string $instruction 系統指令
     * @return array 見 self::request()
     */
    public function extract_structured(string $text, array $schema, string $instruction): array {
        return $this->request($text, $instruction, self::structured_output($schema));
    }

    /**
     * 直接以 PDF 檔案抽取，不先在本地把文字取出來。
     *
     * Anthropic 的 document 內容區塊接受 base64 的 PDF，模型同時看得到文字與
     * 版面。實測不需要任何 beta 標頭（見 architecture.md §9.10）。
     *
     * **這條路徑有一個無法迴避的安全代價**：送出的是整份原始檔，
     * NFR-SEC-06 的過濾在二進位內容上做不了。要不要採用是實測正確率差距
     * 之後的決定，不是實作方便與否的問題——所以這個方法存在，不代表它會被
     * 正式流程使用。
     *
     * @param string $pdfcontent PDF 檔案的原始位元組
     * @param array $schema JSON Schema
     * @param string $instruction 系統指令
     * @param string $question 隨檔案一起送出的指示文字
     * @return array 見 self::request()
     */
    public function extract_structured_from_pdf(
        string $pdfcontent,
        array $schema,
        string $instruction,
        string $question = '請從這份課綱中抽取結構化資訊。'
    ): array {
        $content = [
            [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'application/pdf',
                    'data' => base64_encode($pdfcontent),
                ],
            ],
            ['type' => 'text', 'text' => $question],
        ];

        return $this->request($content, $instruction, self::structured_output($schema));
    }

    /**
     * 原生結構化輸出的請求參數。
     *
     * @param array $schema JSON Schema
     * @return array
     */
    private static function structured_output(array $schema): array {
        return [
            'output_config' => [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => $schema,
                ],
            ],
        ];
    }

    /**
     * 以提示詞要求 JSON 的方式抽取——通用實作的退路（NFR-EXT-03）。
     *
     * 這條路徑不保證回應是合法 JSON，也不保證符合 schema，呼叫端必須自行
     * 驗證並決定是否重試。schema 以文字形式放進系統指令裡。
     *
     * @param string $text 課綱原文
     * @param array $schema JSON Schema
     * @param string $instruction 系統指令
     * @return array 見 self::request()
     */
    public function extract_prompted(string $text, array $schema, string $instruction): array {
        $schematext = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $full = $instruction . PHP_EOL . PHP_EOL
            . '請只輸出符合下列 JSON Schema 的 JSON，不要加任何說明文字，也不要用程式碼圍籬包起來。'
            . PHP_EOL . PHP_EOL . $schematext;

        return $this->request($text, $full, []);
    }

    /**
     * 發出請求。
     *
     * $content 可以是字串，也可以是 Anthropic 的內容區塊陣列。API 兩種都收，
     * 但只有陣列形式帶得動 document 區塊——PDF 路徑因此必須用陣列。
     *
     * @param string|array $content 使用者訊息內容：純文字或內容區塊陣列
     * @param string $instruction 系統指令
     * @param array $extra 併入請求主體的額外參數
     * @return array success/data/raw/error/model/prompttokens/completiontokens/durationms
     */
    private function request(string|array $content, string $instruction, array $extra): array {
        $body = array_merge([
            'model' => $this->model,
            'max_tokens' => $this->maxtokens,
            'system' => $instruction,
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
        ], $extra);

        $request = new Request(
            method: 'POST',
            uri: $this->provider->get_endpoint(),
            body: json_encode($body, JSON_UNESCAPED_UNICODE),
            headers: ['content-type' => 'application/json'],
        );
        $request = $this->provider->add_authentication_headers($request);

        $started = microtime(true);
        try {
            $response = \core\di::get(http_client::class)->send($request, [
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::TIMEOUT => 180,
            ]);
        } catch (RequestException $e) {
            return $this->failure('傳輸失敗：' . $e->getMessage(), $started);
        }

        $durationms = (int) round((microtime(true) - $started) * 1000);
        $raw = $response->getBody()->getContents();
        $decoded = json_decode($raw, true);

        if ($response->getStatusCode() !== 200) {
            $message = $decoded['error']['message'] ?? $response->getReasonPhrase();

            return [
                'success' => false,
                'data' => null,
                'raw' => $raw,
                'error' => 'HTTP ' . $response->getStatusCode() . '：' . $message,
                'model' => $this->model,
                'prompttokens' => null,
                'completiontokens' => null,
                'durationms' => $durationms,
            ];
        }

        // content 是區塊陣列而非字串，只取 text 區塊。
        $answer = '';
        foreach ($decoded['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $answer .= $block['text'];
            }
        }

        return [
            'success' => true,
            'data' => $this->decode_payload($answer),
            'raw' => $answer,
            'error' => '',
            'model' => $decoded['model'] ?? $this->model,
            'prompttokens' => $decoded['usage']['input_tokens'] ?? null,
            'completiontokens' => $decoded['usage']['output_tokens'] ?? null,
            'durationms' => $durationms,
        ];
    }

    /**
     * 把回應文字解析成陣列。
     *
     * 提示詞路徑的模型常會用 Markdown 程式碼圍籬把 JSON 包起來，即使指令
     * 說了不要。這裡把圍籬剝掉再解析——不是縱容，而是要讓量測反映「結構
     * 正確與否」，而不是「有沒有加圍籬」這種一行後處理就能解決的表面差異。
     *
     * 圍籬字元用 chr(96) 組出來而非直接寫，是為了讓這個檔案能被各種 shell
     * 的 heredoc 安全產生，不必處理反引號的跳脫。
     *
     * @param string $text 回應文字
     * @return array|null 解析結果，失敗時為 null
     */
    private function decode_payload(string $text): ?array {
        $trimmed = trim($text);
        $fence = str_repeat(chr(96), 3);

        if (str_starts_with($trimmed, $fence)) {
            $break = strpos($trimmed, PHP_EOL);
            $trimmed = $break === false ? '' : substr($trimmed, $break + 1);
            $end = strrpos($trimmed, $fence);
            if ($end !== false) {
                $trimmed = substr($trimmed, 0, $end);
            }
            $trimmed = trim($trimmed);
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 組出失敗結果。
     *
     * @param string $message 錯誤訊息
     * @param float $started 起始時間
     * @return array
     */
    private function failure(string $message, float $started): array {
        return [
            'success' => false,
            'data' => null,
            'raw' => '',
            'error' => $message,
            'model' => $this->model,
            'prompttokens' => null,
            'completiontokens' => null,
            'durationms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }
}
