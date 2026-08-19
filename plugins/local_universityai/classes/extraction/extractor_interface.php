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

/**
 * 結構化抽取的契約（D-05 的第二條呼叫路徑，§9.3）。
 *
 * **實作者刻意不 `implements` 這個介面。**
 *
 * 這看起來很奇怪，所以理由要寫清楚（完整版見 §9.2 的 `A-11`）：§1.2 的第 1
 * 條規則要求 `aiprovider_claude` 能單獨安裝在一個沒有 UniversityAI 的站台上。
 * 若它 `implements local_universityai\extraction\extractor_interface`，那個
 * 站台上一載入該類別就是致命錯誤——而 `core_component` 的掃描、PHPUnit 的
 * 涵蓋率蒐集、任何遍歷外掛類別的工具都可能載入它。
 *
 * 所以這個介面的角色是**契約文件加上可驗證的形狀**，不是型別約束：
 *
 *   1. 本外掛自己若要寫內建實作，可以正常 `implements`。
 *   2. `configured_extractor` 以反射比對外部實作的方法簽章是否吻合。
 *   3. `tests/` 用同一份反射斷言隨附的供應商確實符合。
 *
 * 代價是沒有編譯期型別檢查。三道執行期防線補回來，見 `configured_extractor`。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface extractor_interface {
    /**
     * 建立實例；供應商未設定完成時回傳 null。
     *
     * 靜態工廠而不是建構子：實作需要金鑰與端點，而那些在供應商執行個體裡。
     * 讓實作自己去找自己的執行個體，呼叫端就不必知道供應商長什麼樣。
     *
     * @return static|null 找不到或未設定時為 null
     */
    public static function create(): ?static;

    /**
     * 抽取結構化資料。
     *
     * 回傳陣列而不是值物件：值物件會讓實作端被迫引用本外掛的型別，違反
     * §1.2 的第 1 條規則。鍵為 `success`、`data`、`raw`、`error`、`model`、
     * `prompttokens`、`completiontokens`、`durationms`。
     *
     * **`raw` 一定要有**：schema 驗證失敗時要靠它才知道模型到底吐了什麼，
     * 而那正是最需要診斷資訊的時刻。
     *
     * @param string $text 課綱原文
     * @param array $schema JSON Schema
     * @param string $instruction 系統指令
     * @param string $mode 'structured' 或 'prompted'（§9.4）
     * @return array 見上方說明
     */
    public function extract(string $text, array $schema, string $instruction, string $mode): array;
}
