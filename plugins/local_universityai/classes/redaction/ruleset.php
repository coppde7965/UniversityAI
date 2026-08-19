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

/**
 * 過濾規則的載入（A-05）。
 *
 * 規則本身是一份與程式語言無關的資料檔，PHP 與 Python 各自讀取同一份——
 * 這是 docs/architecture.md §6.2 對 SRS「共用同一份實作」那句話的可執行版本。
 *
 * 這個類別是 filter 的相依，存在的理由只有一個：把 IO 從 filter 拿掉。
 * filter 因此保持純函式，可在 bdd-guide 的 L1 層完整覆蓋（§6.1）。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ruleset {
    /** @var string 規則檔相對於外掛根目錄的檔名。 */
    public const RULES_FILE = 'redaction-rules.json';

    /** @var string 黃金樣本檔，供 PHPUnit 與 Python 端共用。 */
    public const SAMPLES_FILE = 'redaction-samples.json';

    /** @var array<int, array{id: string, category: string, pattern: string, replacement: string}> 依序套用的規則。 */
    private array $rules;

    /** @var array<string, string> 佔位符對照。 */
    private array $placeholders;

    /** @var array<string, string> 黑名單類別對應到它的處理方式。 */
    private array $categories;

    /** @var string[] 白名單欄位。 */
    private array $allowlist;

    /**
     * 建構子刻意設為私有：規則的來源只有兩條，都由靜態工廠把關。
     *
     * @param array $rules 已驗證過的規則
     * @param array $placeholders 佔位符對照
     * @param array $categories 黑名單類別對應到 handled_by
     * @param array $allowlist 白名單欄位
     */
    private function __construct(array $rules, array $placeholders, array $categories, array $allowlist) {
        $this->rules = $rules;
        $this->placeholders = $placeholders;
        $this->categories = $categories;
        $this->allowlist = $allowlist;
    }

    /**
     * 自外掛內的規則檔載入。
     *
     * 規則檔放在外掛目錄下而不是專案根目錄，與 §6.2 的原始措辭有落差：
     * 外掛安裝到站台之後看不到專案目錄，放根目錄會讓外掛無法獨立安裝。
     * 專案內的 Python 端讀 plugins/local_universityai/ 下的同一份檔，
     * 「單一來源」這個實質目的不受影響。
     *
     * @return self
     * @throws \coding_exception 規則檔不存在或格式不符時
     */
    public static function from_plugin_file(): self {
        $path = dirname(__DIR__, 2) . '/' . self::RULES_FILE;

        return self::from_file($path);
    }

    /**
     * 自指定路徑載入。
     *
     * @param string $path 規則檔的絕對路徑
     * @return self
     * @throws \coding_exception 檔案不存在或不是合法 JSON 時
     */
    public static function from_file(string $path): self {
        if (!is_readable($path)) {
            throw new \coding_exception('讀不到過濾規則檔：' . $path);
        }

        $definition = json_decode(file_get_contents($path), true);
        if (!is_array($definition)) {
            throw new \coding_exception('過濾規則檔不是合法的 JSON 物件：' . $path);
        }

        return self::from_array($definition);
    }

    /**
     * 自已解析的定義建立。
     *
     * 每條規則在此就先編譯一次。規則檔寫錯時要在載入的當下失敗，而不是
     * 等到某個週日凌晨的排程任務跑到某條規則才拋出——那時沒有人在看。
     *
     * @param array $definition 規則定義
     * @return self
     * @throws \coding_exception 規則缺欄位或正規表示式不合法時
     */
    public static function from_array(array $definition): self {
        $categories = [];
        foreach ($definition['denylist']['categories'] ?? [] as $category) {
            if (!isset($category['id'], $category['handled_by'])) {
                throw new \coding_exception('黑名單類別必須同時宣告 id 與 handled_by。');
            }
            $categories[$category['id']] = $category['handled_by'];
        }

        $rules = [];
        $covered = [];

        foreach ($definition['rules'] ?? [] as $index => $rule) {
            foreach (['id', 'category', 'pattern', 'replacement'] as $field) {
                if (!isset($rule[$field]) || !is_string($rule[$field])) {
                    throw new \coding_exception("第 {$index} 條過濾規則缺少 {$field}。");
                }
            }

            // 每條規則都要掛在一個宣告過的黑名單類別下。沒有這個檢查的話，
            // 「新增了一條規則，但它擋的東西根本不在 NFR-SEC-06 的黑名單裡」
            // 這種偏移不會被發現——而規則檔是可以被非開發者編輯的。
            if (($categories[$rule['category']] ?? null) !== 'pattern') {
                throw new \coding_exception("過濾規則 {$rule['id']} 的類別 {$rule['category']} "
                    . '未在黑名單中宣告為 handled_by=pattern。');
            }
            $covered[$rule['category']] = true;

            $delimited = self::compile($rule['pattern']);
            if (@preg_match($delimited, '') === false) {
                throw new \coding_exception("過濾規則 {$rule['id']} 的樣式不是合法的正規表示式。");
            }

            $rules[] = [
                'id' => $rule['id'],
                'category' => $rule['category'],
                'pattern' => $delimited,
                'replacement' => $rule['replacement'],
            ];
        }

        if ($rules === []) {
            throw new \coding_exception('過濾規則為空。空規則集會讓過濾器變成直通管線，'
                . '而呼叫端看不出差別——這種失效方式必須擋在這裡。');
        }

        // 反向檢查：宣告要用樣式處理的類別，必須真的有樣式。少了這一半，
        // 把某個類別的規則整條刪掉不會有任何跡象，而黑名單看起來仍然完整。
        foreach ($categories as $id => $handledby) {
            if ($handledby === 'pattern' && !isset($covered[$id])) {
                throw new \coding_exception("黑名單類別 {$id} 宣告以樣式處理，卻沒有任何對應規則。");
            }
        }

        return new self(
            $rules,
            $definition['placeholder'] ?? [],
            $categories,
            $definition['allowlist']['fields'] ?? []
        );
    }

    /**
     * 把資料檔中的裸樣式加上分隔符與修飾子。
     *
     * u 修飾子是必要的：規則中的字元類別含中文字面值（如 [路街道]），
     * 沒有 u 的話 PCRE 會逐位元組處理，把一個中文字拆成三個位元組比對。
     *
     * @param string $pattern 資料檔中的樣式
     * @return string 可交給 preg_* 的完整樣式
     */
    private static function compile(string $pattern): string {
        return '/' . str_replace('/', '\/', $pattern) . '/u';
    }

    /**
     * 依序取得規則。順序有意義，見 redaction-rules.json 的 note 欄位。
     *
     * @return array<int, array> 每筆含 id、category、pattern、replacement
     */
    public function rules(): array {
        return $this->rules;
    }

    /**
     * 黑名單類別對應到它的處理方式（pattern / literal / allowlist）。
     *
     * 公開這件事是為了讓測試能斷言「SRS §6.3 列出的每一個項目都有交代」——
     * 交代包含「靠白名單，這裡不處理」，那也是一種明確的答案。
     *
     * @return array<string, string>
     */
    public function categories(): array {
        return $this->categories;
    }

    /**
     * 白名單欄位。
     *
     * 過濾器本身不使用它——白名單由組裝提示詞的那一層執行。放在同一份規則檔
     * 裡是因為兩者是同一條需求（NFR-SEC-06）的兩半，分開放會漂移。
     *
     * @return string[]
     */
    public function allowlist_fields(): array {
        return $this->allowlist;
    }

    /**
     * 取得佔位符，未定義時回傳一個明顯不會被誤認為內容的預設值。
     *
     * @param string $key 佔位符鍵名
     * @return string
     */
    public function placeholder(string $key): string {
        return $this->placeholders[$key] ?? '[REDACTED]';
    }
}
