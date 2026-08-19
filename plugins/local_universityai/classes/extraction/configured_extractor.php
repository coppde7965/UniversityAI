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

use local_universityai\redaction\filter;
use local_universityai\redaction\filtered_payload;

/**
 * 抽取路徑的收口（`A-11`、`A-13`）。
 *
 * 這是兩條 AI 呼叫路徑中的第二個收口點，與生成類的 `ai\text_client` 對稱：
 * 外送文字一律先經 `redaction\filter`，而且**只有這個類別可以建立抽取器
 * 實作**。§8.2 的靜態檢查驗的就是這件事。
 *
 * 實作類別由站台設定以字串指名（`A-11`），不在程式碼中交叉引用。代價是
 * 沒有編譯期型別檢查，用三道執行期防線補回來：
 *
 *   1. **建立前**：類別存在、可實例化、具備 `create()` 與 `extract()`，
 *      且簽章與 `extractor_interface` 吻合（反射比對，不用 `instanceof`）。
 *   2. **回傳後**：形狀檢查，缺鍵就當成失敗而不是讓 null 往下流。
 *   3. **測試**：`tests/` 以同一份反射斷言隨附的實作確實符合。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class configured_extractor {
    /** @var string 預設的實作類別。這是一個設定值，不是程式碼中的類別引用。 */
    public const DEFAULT_CLASS = 'aiprovider_claude\extractor';

    /** @var string 原生結構化輸出。 */
    public const MODE_STRUCTURED = 'structured';

    /** @var string 以提示詞要求 JSON 的通用實作。 */
    public const MODE_PROMPTED = 'prompted';

    /** @var string[] extract() 回傳值必須具備的鍵。 */
    private const REQUIRED_KEYS = [
        'success', 'data', 'raw', 'error', 'model',
        'prompttokens', 'completiontokens', 'durationms',
    ];

    /** @var object 實作實例。刻意宣告為 object——它不 implements 我們的介面。 */
    private object $implementation;

    /** @var filter 外送過濾。 */
    private filter $filter;

    /**
     * 建構子。
     *
     * @param object $implementation 已驗證過形狀的實作
     * @param filter $filter 外送過濾
     */
    private function __construct(object $implementation, filter $filter) {
        $this->implementation = $implementation;
        $this->filter = $filter;
    }

    /**
     * 依站台設定建立。
     *
     * @param filter|null $filter 過濾器，預設載入外掛內建規則
     * @return self|null 未設定完成或實作不可用時為 null
     * @throws \coding_exception 設定指名的類別不符契約時
     */
    public static function create(?filter $filter = null): ?self {
        $classname = trim((string) get_config('local_universityai', 'extractorclass'));
        if ($classname === '') {
            $classname = self::DEFAULT_CLASS;
        }

        self::assert_contract($classname);

        $implementation = $classname::create();
        if ($implementation === null) {
            // 供應商還沒設定金鑰。這不是錯誤，是「還沒準備好」——呼叫端
            // 據此決定是跳過還是報錯（§9.3）。
            return null;
        }

        return new self($implementation, $filter ?? filter::create());
    }

    /**
     * 抽取。
     *
     * 課綱原文在此經過濾後才離開站台。**教師姓名與聯絡方式在進入解析之前
     * 就要移除**，這是 SRS §6.3 的明文要求，也是 `redaction-rules.json` 的
     * `options.teacher_name_from_syllabus` 那個旗標的意思——所以呼叫端要把
     * 已知的姓名以 `$literals` 傳進來。
     *
     * @param string $text 課綱原文
     * @param array $schema JSON Schema
     * @param string $instruction 系統指令
     * @param string[] $literals 已知的敏感字面值，通常是教師姓名
     * @param string|null $mode 覆寫站台設定的模式，測試用
     * @return array 見 extractor_interface::extract()
     */
    public function extract(
        string $text,
        array $schema,
        string $instruction,
        array $literals = [],
        ?string $mode = null
    ): array {
        $payload = $this->filter->apply($text, $literals);

        $result = $this->implementation->extract(
            $payload->text(),
            $schema,
            $instruction,
            $mode ?? self::configured_mode()
        );

        return self::normalise($result);
    }

    /**
     * 過濾後、真正送出的文字。
     *
     * 呼叫端需要它來驗證 `source_span`（`FR-SYL-03`）——那條驗收條件比對的
     * 對象必須是**模型實際看到的文字**，不是過濾前的原文。用原文比對會把
     * 過濾造成的差異誤判成幻覺。
     *
     * @param string $text 課綱原文
     * @param string[] $literals 敏感字面值
     * @return filtered_payload
     */
    public function filtered(string $text, array $literals = []): filtered_payload {
        return $this->filter->apply($text, $literals);
    }

    /**
     * 站台設定的抽取模式（`A-12`）。
     *
     * **不做自動回退。** 模型不支援結構化輸出時回應是 HTTP 400 且訊息明確；
     * 自動改走提示詞路徑會讓站台安靜降級——正確率下降、token 用量上升，
     * 而管理員完全不知道發生了什麼。
     *
     * @return string
     */
    public static function configured_mode(): string {
        $mode = trim((string) get_config('local_universityai', 'extractionmode'));

        return $mode === self::MODE_PROMPTED ? self::MODE_PROMPTED : self::MODE_STRUCTURED;
    }

    /**
     * 第一道防線：確認設定指名的類別真的符合契約。
     *
     * 用反射比對方法名稱與參數個數，不用 `instanceof`——實作不會（也不該）
     * `implements` 我們的介面，理由見 `extractor_interface`。
     *
     * 拋 `coding_exception` 而不是回傳 null：類別名打錯是設定錯誤，必須讓
     * 管理員看到明確的訊息。安靜地跳過只會變成「上傳課綱之後永遠沒有反應」。
     *
     * @param string $classname 設定指名的類別
     * @return void
     * @throws \coding_exception 不符契約時
     */
    private static function assert_contract(string $classname): void {
        if (!class_exists($classname)) {
            throw new \coding_exception('設定的抽取器類別不存在：' . $classname
                . '。請檢查「網站管理 → 外掛 → 本機外掛 → UniversityAI」的抽取器設定。');
        }

        foreach (self::contract() as $method => $expected) {
            if (!method_exists($classname, $method)) {
                throw new \coding_exception("抽取器 {$classname} 缺少方法 {$method}()。");
            }

            $actual = (new \ReflectionMethod($classname, $method))->getNumberOfRequiredParameters();
            if ($actual !== $expected) {
                throw new \coding_exception("抽取器 {$classname} 的 {$method}() 需要 {$actual} 個參數，"
                    . "契約要求 {$expected} 個。");
            }
        }
    }

    /**
     * 契約：方法名稱對應到必要參數個數。
     *
     * 由 `extractor_interface` 反射得出而不是寫死——寫死的話改介面不會讓
     * 這裡跟著改，而那正是這道防線唯一要防的事。
     *
     * @return array<string, int>
     */
    public static function contract(): array {
        $contract = [];

        foreach ((new \ReflectionClass(extractor_interface::class))->getMethods() as $method) {
            $contract[$method->getName()] = $method->getNumberOfRequiredParameters();
        }

        return $contract;
    }

    /**
     * 第二道防線：回傳值的形狀檢查。
     *
     * 缺鍵就整份當成失敗，而不是讓 null 往下流。呼叫端接著要讀 `data` 去驗
     * schema，一個缺 `data` 鍵的陣列會在那裡炸成一個與真正原因無關的錯誤。
     *
     * @param mixed $result 實作的回傳值
     * @return array
     */
    private static function normalise($result): array {
        if (!is_array($result)) {
            return self::failure('抽取器回傳的不是陣列。');
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $result)) {
                return self::failure('抽取器的回傳值缺少 ' . $key . ' 鍵。');
            }
        }

        return $result;
    }

    /**
     * 組出符合契約的失敗結果。
     *
     * @param string $message 錯誤訊息
     * @return array
     */
    private static function failure(string $message): array {
        return [
            'success' => false,
            'data' => null,
            'raw' => '',
            'error' => $message,
            'model' => '',
            'prompttokens' => null,
            'completiontokens' => null,
            'durationms' => 0,
        ];
    }
}
