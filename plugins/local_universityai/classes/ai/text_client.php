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

use core_ai\aiactions\generate_text;
use core_ai\manager;
use local_universityai\ops;
use local_universityai\redaction\filtered_payload;

/**
 * 生成類 AI 呼叫的唯一收口（A-13）。
 *
 * docs/architecture.md §9.7：摘要、差異說明、課程問答都走 Moodle 的 AI
 * 子系統，但**不得由各處直接呼叫** \core_ai\manager::process_action()。
 * 只要有第二個呼叫點，NFR-SEC-06 的外送過濾就沒有單一收口，漏掉一處等於
 * 整條防線失效。這條規則可由 make lint 的靜態檢查驗（§8.2）。
 *
 * 提示詞的型別是 filtered_payload 而不是 string，這是刻意的：那個型別只能
 * 由 redaction\filter 產生，所以「忘了過濾」在這裡是型別錯誤而不是安全漏洞。
 *
 * 這個類別刻意**不宣告為 final**，儘管「唯一收口」聽起來像是該封死的東西。
 * final 擋不住任何人另外寫一個類別去呼叫 process_action——那條規則本來就
 * 只能由 §8.2 的靜態檢查驗；而 final 會擋掉 PHPUnit 的測試替身，讓呼叫端的
 * 測試被迫真的發出網路請求。用一個擋不住的限制換掉可測性不划算。
 *
 * 進入子系統之後 Moodle 會依序嘗試所有支援 generate_text 的供應商，直到成功
 * （見核心 manager::process_action）。站台同時啟用多家供應商時，同一批摘要
 * 可能由不同模型產生——這正是回傳值一定帶 model、且呼叫端必須把它存下來的
 * 理由（NFR-MNT-03）。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_client {
    /** @var manager|null AI 子系統。null 表示尚未取得，於第一次使用時自容器取。 */
    private ?manager $manager;

    /**
     * 建構子。
     *
     * 允許注入 manager 是為了 L1 測試（bdd-guide §4）：PHPUnit 可以餵一個
     * 回傳固定回應的替身，不必真的呼叫 API。正式路徑一律留空，由 \core\di
     * 取得站台設定好的那一個。
     *
     * @param manager|null $manager 測試用的替身；正式使用時不傳
     */
    public function __construct(?manager $manager = null) {
        $this->manager = $manager;
    }

    /**
     * 送出一次文字生成請求。
     *
     * 成敗都寫入 _ops（§9.7 的第 4 步）。成功也記的理由是 FR-DSH-02 的面板
     * 要能回答「這週的摘要到底跑了沒」，只記失敗的話「沒有紀錄」會同時代表
     * 「成功了」與「根本沒跑」兩件事。
     *
     * **不在此重試。** 失敗時回傳失敗結果，由呼叫端決定要不要讓例外往上拋給
     * \core\task 的重試機制處理（§4.4）。在這裡 catch 後重跑會讓 Moodle 的
     * 失敗延遲機制看不到失敗。
     *
     * @param filtered_payload $prompt 已通過外送過濾的提示詞
     * @param int $contextid 脈絡 id，用課程脈絡而非站台脈絡
     * @param int $userid 歸屬的使用者 id
     * @param string $operation 見 ops::operations()
     * @param string $targetid 對象識別，通常是課程 id
     * @param string $targettype 見 ops::targettypes()
     * @return array{success: bool, content: string, model: string, prompttokens: int,
     *     completiontokens: int, durationms: int, errormessage: string}
     */
    public function generate(
        filtered_payload $prompt,
        int $contextid,
        int $userid,
        string $operation,
        string $targetid,
        string $targettype = ops::TARGET_COURSE
    ): array {
        $action = new generate_text(
            contextid: $contextid,
            userid: $userid,
            prompttext: $prompt->text(),
        );

        $started = microtime(true);
        $response = $this->manager()->process_action($action);
        $durationms = (int) round((microtime(true) - $started) * 1000);

        $data = $response->get_response_data();
        $success = $response->get_success();

        ops::record(
            $operation,
            $targettype,
            $targetid,
            $success ? ops::STATUS_SUCCESS : ops::STATUS_FAILED
        );

        return [
            'success' => $success,
            'content' => trim((string) ($data['generatedcontent'] ?? '')),
            'model' => (string) ($data['model'] ?? ''),
            'prompttokens' => (int) ($data['prompttokens'] ?? 0),
            'completiontokens' => (int) ($data['completiontokens'] ?? 0),
            'durationms' => $durationms,

            // 錯誤訊息來自外部服務，可能夾帶請求內容。這裡原樣回傳，由呼叫端
            // 決定要不要顯示；**寫進資料庫或記錄檔之前必須先過 filter::redact()**
            // （§6.3）。目前唯一的呼叫端只把它送進 mtrace，不落地。
            'errormessage' => (string) $response->get_errormessage(),
        ];
    }

    /**
     * 取得 AI 子系統，必要時自 DI 容器解析。
     *
     * @return manager
     */
    private function manager(): manager {
        $this->manager ??= \core\di::get(manager::class);

        return $this->manager;
    }
}
