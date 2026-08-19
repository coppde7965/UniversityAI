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
use local_universityai\ops;
use local_universityai\redaction\filter;

/**
 * 由事實產生每週摘要的文字（FR-SUM-01）。
 *
 * Model 層。這裡是 §9.7 那條鏈路上「呼叫端」的位置：
 *
 *     context_builder（事實）→ generator（過濾＋提示詞）→ text_client（外送）
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generator {
    /** @var string 已產生摘要內容。 */
    public const STATUS_GENERATED = 'generated';

    /** @var string 當週三個區塊皆無資料，發布明確的「本週無安排」。 */
    public const STATUS_NOSCHEDULE = 'noschedule';

    /**
     * 預設提示詞。
     *
     * 站台管理員可在設定中覆寫（NFR-MNT-01），這裡只是預設值。
     *
     * 刻意**不放在語言檔**：這是給模型的指令，不是給使用者看的介面文字。
     * 放語言檔會讓摘要的語言跟著「觸發者的語言偏好」跑，而排程任務沒有
     * 觸發者，實際上會跟著 cron 執行時的語言跑——同一門課的摘要可能這週
     * 中文、下週英文。摘要的語言應該由站台決定，所以綁在設定值上。
     *
     * @var string
     */
    public const DEFAULT_PROMPT = '你是課程助理，負責為修課師生撰寫每週課程摘要。
以下 JSON 是本課程本週的全部事實，請完全依據它撰寫，不得補充 JSON 以外的任何資訊，
不得臆測未列出的內容，也不要重述 JSON 的欄位名稱。

請以繁體中文輸出三個段落，每段以標題起首：
本週重點、下週重點、待辦提醒。
某個段落沒有對應資料時，請直接寫「本週無安排」「下週無安排」或「近七日無待辦」，不要留白，也不要自行編造。
語氣平實，全文不超過三百字，不要使用 Markdown 標記。';

    /** @var filter 外送過濾。 */
    private filter $filter;

    /** @var text_client 生成類呼叫的收口。 */
    private text_client $client;

    /**
     * 建構子。兩個相依都可注入，L1 測試才不必碰網路與檔案系統。
     *
     * @param filter|null $filter 過濾器，預設載入外掛內建規則
     * @param text_client|null $client AI 呼叫收口
     */
    public function __construct(?filter $filter = null, ?text_client $client = null) {
        $this->filter = $filter ?? filter::create();
        $this->client = $client ?? new text_client();
    }

    /**
     * 產生一門課的摘要。
     *
     * 三個區塊皆空時**不呼叫語言模型**，直接回傳固定文字。理由有兩個：
     * 一是驗收條件要的就是一句明確的「本週無安排」，那不需要模型；
     * 二是全站每週一次、每門課一次的呼叫，對沒有內容的課程仍付費是浪費。
     *
     * @param array $facts context_builder::build() 的輸出
     * @param int $contextid 課程脈絡 id
     * @param int $userid 歸屬使用者，排程任務用管理員
     * @return array{success: bool, status: string, content: string, model: string,
     *     durationms: int, errormessage: string}
     */
    public function generate(array $facts, int $contextid, int $userid): array {
        if (empty($facts['hascontent'])) {
            return [
                'success' => true,
                'status' => self::STATUS_NOSCHEDULE,
                'content' => get_string('summary:noschedule', 'local_universityai'),
                'model' => '',
                'durationms' => 0,
                'errormessage' => '',
            ];
        }

        $payload = $this->filter->apply(
            $this->compose_prompt($facts),
            $facts['literals'] ?? []
        );

        $result = $this->client->generate(
            $payload,
            $contextid,
            $userid,
            ops::OP_WEEKLY_SUMMARY,
            (string) $facts['courseid']
        );

        return [
            'success' => $result['success'] && $result['content'] !== '',
            'status' => self::STATUS_GENERATED,
            'content' => $result['content'],
            'model' => $result['model'],
            'durationms' => $result['durationms'],
            'errormessage' => $result['errormessage'],
        ];
    }

    /**
     * 組出送給模型的提示詞。
     *
     * 事實以 JSON 而非自然語言條列送出，這是刻意的：
     *
     *   1. **可稽核。** 送出去的欄位就是 to_whitelisted_facts() 回傳的那幾個鍵，
     *      要多送一個欄位就得改那個方法，程式碼審查看得見。條列式的文字很容易
     *      在某次調整版面時順手多帶一個欄位出去。
     *   2. **不預設語言。** 條列格式需要「本週」「下週」這類中文標籤，那會把
     *      輸出語言寫死在程式碼裡；JSON 的鍵是英文結構名，語言完全由提示詞決定，
     *      而提示詞是站台設定。
     *
     * @param array $facts 事實
     * @return string
     */
    private function compose_prompt(array $facts): string {
        $prompt = trim((string) get_config('local_universityai', 'summaryprompt'));
        if ($prompt === '') {
            $prompt = self::DEFAULT_PROMPT;
        }

        $json = json_encode(
            $this->to_whitelisted_facts($facts),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );

        return $prompt . "\n\n" . $json;
    }

    /**
     * 白名單：這個方法的回傳值就是實際離開站台的全部內容。
     *
     * 刻意不含 courseid、syllabusversion、事件來源與任何使用者識別。
     * SRS §6.3 禁止送出可跨課程或跨時間串接的識別碼，而課程 id 正是那種
     * 識別碼——模型撰寫摘要完全用不到它。
     *
     * @param array $facts context_builder 的完整事實
     * @return array 可外送的子集
     */
    private function to_whitelisted_facts(array $facts): array {
        $tz = \core_date::get_server_timezone_object();

        $events = [];
        foreach ($facts['dueevents'] ?? [] as $event) {
            $due = (new \DateTimeImmutable('@' . $event['due']))->setTimezone($tz);
            $events[] = [
                'title' => $event['title'],
                'type' => $event['type'],
                'due_at' => $due->format('Y-m-d H:i'),
            ];
        }

        return [
            'course_name' => $facts['coursename'] ?? '',
            'week_no' => $facts['weekno'],
            'total_weeks' => $facts['totalweeks'] ?? 0,
            'this_week' => $this->week_subset($facts['thisweek'] ?? null),
            'next_week' => $this->week_subset($facts['nextweek'] ?? null),
            'due_events' => $events,
        ];
    }

    /**
     * 取週次中可外送的欄位。
     *
     * confidence 與 source_span 不送：前者是給老師看的信心標註（FR-SYL-03），
     * 後者是課綱原文片段——那正是最可能夾帶教師聯絡方式的欄位。
     *
     * @param array|null $week 週次資料
     * @return array|null
     */
    private function week_subset(?array $week): ?array {
        if ($week === null) {
            return null;
        }

        return [
            'topic' => $week['topic'],
            'note' => $week['note'],
        ];
    }
}
