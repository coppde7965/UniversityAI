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
 * 已通過外送過濾的文字（NFR-SEC-06）。
 *
 * 這個型別存在的唯一目的是「無法繞過」：所有外送包裝層都只接受本型別，
 * 而本型別只能由 filter 產生。想送一段沒過濾的文字出去，型別就過不了。
 *
 * **PHP 的型別系統做不到完整的保證**，這一點要講清楚。docs/architecture.md
 * §6.1 寫「型別系統因此保證了無繞過路徑」，但 PHP 沒有 friend 之類的機制，
 * 私有建構子擋不住同命名空間下另一個類別呼叫公開的靜態工廠。因此這裡補上
 * 一道執行期檢查：工廠確認呼叫者確實是 filter，不是就拋例外。
 *
 * 成本是每次外送一次 debug_backtrace（限深度 2、不含參數），相對於一次語言
 * 模型呼叫可以忽略。換到的是「約定」變成「保證」。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class filtered_payload {
    /** @var string 過濾後的文字。 */
    private string $text;

    /** @var string[] 實際命中的規則代號，供稽核與測試使用。 */
    private array $applied;

    /**
     * 私有建構子，見類別說明。
     *
     * @param string $text 過濾後的文字
     * @param string[] $applied 命中的規則代號
     */
    private function __construct(string $text, array $applied) {
        $this->text = $text;
        $this->applied = $applied;
    }

    /**
     * 建立實例。只有 filter 可以呼叫。
     *
     * @param string $text 過濾後的文字
     * @param string[] $applied 命中的規則代號
     * @return self
     * @throws \coding_exception 由 filter 以外的類別呼叫時
     */
    public static function create(string $text, array $applied): self {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $frames[1]['class'] ?? '';

        if ($caller !== filter::class) {
            throw new \coding_exception(
                'filtered_payload 只能由 ' . filter::class . ' 建立，實際呼叫者為 '
                . ($caller !== '' ? $caller : '（全域範圍）')
                . '。想外送文字請走 filter::apply()，不要繞過過濾。'
            );
        }

        return new self($text, $applied);
    }

    /**
     * 取出可外送的文字。
     *
     * @return string
     */
    public function text(): string {
        return $this->text;
    }

    /**
     * 命中的規則代號。空陣列代表原文本來就不含可辨識的個資樣式。
     *
     * @return string[]
     */
    public function applied(): array {
        return $this->applied;
    }
}
