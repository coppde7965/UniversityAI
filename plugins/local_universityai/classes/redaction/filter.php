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
 * 外送資料過濾（NFR-SEC-06）。
 *
 * 純函式：無 IO、無資料庫存取、無設定讀取，規則一律由建構子注入（§6.1）。
 * 這讓它在 bdd-guide 的 L1 層可被完整覆蓋，而不需要任何測試替身。
 *
 * **黑名單只是第二道防線。** 第一道是白名單——SRS §6.3 規定只有課綱文字、
 * 已確認課綱的結構化欄位、課程名稱與簡述、事件標題與日期、使用者自行輸入
 * 的提問得以送出。組裝提示詞的那一層負責只放進白名單內的欄位；本元件負責
 * 那些欄位裡**仍可能夾帶**的個資。成績與作業內容沒有可比對的樣式，靠的正是
 * 白名單而不是這裡的規則——把它們寫進黑名單只會製造「已經處理了」的錯覺。
 *
 * 中文姓名同理：沒有可靠的樣式。呼叫端把已知的姓名以 $literals 傳入，
 * 這是 apply() 第二個參數存在的全部理由。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class filter {
    /** @var int 字面值至少要這麼長才會被移除，見 remove_literals()。 */
    private const MIN_LITERAL_LENGTH = 2;

    /** @var ruleset 規則。 */
    private ruleset $ruleset;

    /**
     * 建構子。
     *
     * @param ruleset $ruleset 規則集
     */
    public function __construct(ruleset $ruleset) {
        $this->ruleset = $ruleset;
    }

    /**
     * 以外掛內建的規則檔建立。
     *
     * 這是唯一會碰到檔案系統的地方，且刻意放在靜態工廠而不是建構子——
     * 建構子維持可注入，測試才能餵自訂規則。
     *
     * @return self
     */
    public static function create(): self {
        return new self(ruleset::from_plugin_file());
    }

    /**
     * 過濾並包裝成可外送的型別。
     *
     * @param string $text 原始文字
     * @param string[] $literals 呼叫端已知的敏感字面值，通常是姓名
     * @return filtered_payload
     */
    public function apply(string $text, array $literals = []): filtered_payload {
        [$filtered, $applied] = $this->scrub($text, $literals);

        return filtered_payload::create($filtered, $applied);
    }

    /**
     * 過濾後回傳純字串。
     *
     * 用於**不外送**的場合：寫入 _ops.error、寫入記錄檔（§6.3）。那些路徑
     * 不需要 filtered_payload 的型別保證，硬要求反而會讓人為了型別去繞路。
     *
     * 要送給語言模型時一律用 apply()，不要用這個方法再自己拆字串。
     *
     * @param string $text 原始文字
     * @param string[] $literals 呼叫端已知的敏感字面值
     * @return string
     */
    public function redact(string $text, array $literals = []): string {
        return $this->scrub($text, $literals)[0];
    }

    /**
     * 實際的過濾。
     *
     * 字面值先於樣式：姓名常與信箱相鄰（「陳怡君 chen.yj 小老鼠 example.edu.tw」），
     * 先移除姓名可以讓後續的樣式比對面對比較乾淨的文字。
     *
     * 上一行刻意不寫真的信箱格式：Moodle 的 PHPDoc 檢查會把註解裡以 at 符號
     * 開頭的字當成行內標籤，於是 example.edu.tw 前面那個符號會讓整個檔案報錯。
     *
     * @param string $text 原始文字
     * @param string[] $literals 敏感字面值
     * @return array{0: string, 1: string[]} 過濾結果與命中的規則代號
     */
    private function scrub(string $text, array $literals): array {
        $applied = [];

        $text = $this->remove_literals($text, $literals, $applied);

        foreach ($this->ruleset->rules() as $rule) {
            $count = 0;
            $text = preg_replace($rule['pattern'], $rule['replacement'], $text, -1, $count);

            // preg_replace 失敗時回傳 null。這裡若安靜地帶著 null 往下走，
            // 結果會是外送一個空字串——看起來像「原文本來就空」而不是失敗。
            if ($text === null) {
                throw new \coding_exception('過濾規則 ' . $rule['id'] . ' 執行失敗：'
                    . preg_last_error_msg());
            }
            if ($count > 0) {
                $applied[] = $rule['id'];
            }
        }

        return [$text, $applied];
    }

    /**
     * 移除呼叫端指定的字面值。
     *
     * 長度下限存在的理由：單字元的字面值（例如姓氏「陳」）會在中文文本裡
     * 大量誤中，把摘要打成篩子。與其在正式流程中安靜地毀損內容，不如不處理
     * 這種輸入——真正要遮蔽單姓的情境不存在。
     *
     * @param string $text 原始文字
     * @param string[] $literals 字面值
     * @param string[] $applied 命中紀錄，以參考傳入
     * @return string
     */
    private function remove_literals(string $text, array $literals, array &$applied): string {
        $placeholder = $this->ruleset->placeholder('name');
        $hit = false;

        // 長的先換：「陳怡君」與「陳怡」同時傳入時，先換短的會讓長的再也對不上。
        usort($literals, static fn($a, $b) => \core_text::strlen($b) <=> \core_text::strlen($a));

        foreach ($literals as $literal) {
            $literal = trim((string) $literal);
            if (\core_text::strlen($literal) < self::MIN_LITERAL_LENGTH) {
                continue;
            }
            if (!str_contains($text, $literal)) {
                continue;
            }
            $text = str_replace($literal, $placeholder, $text);
            $hit = true;
        }

        if ($hit) {
            $applied[] = 'literal';
        }

        return $text;
    }
}
