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

namespace local_universityai\task;

use local_universityai\extraction\configured_extractor;
use local_universityai\extraction\event_key;
use local_universityai\extraction\schema;
use local_universityai\extraction\text_extractor;
use local_universityai\extraction\validator;
use local_universityai\notifier;
use local_universityai\ops;
use local_universityai\redaction\filter;
use local_universityai\syllabus_repository;

/**
 * 解析一份已上傳的課綱（FR-SYL-02）。
 *
 * 臨機任務：由 `syllabus_uploaded` 觀察者排入，因為解析要花上一次語言模型
 * 呼叫的時間，不能卡在上傳請求裡（§4.1）。
 *
 * **重試分兩層，界線在這裡最需要講清楚**（§9.5）：
 *
 * | 情況 | 由誰處理 |
 * | --- | --- |
 * | 回應不是合法 JSON 或不符 schema | 本任務**立即重問一次**，把驗證錯誤附在提示詞後 |
 * | 重問後仍不符、HTTP 錯誤、逾時 | 讓例外往上拋，由 `\core\task` 的重試機制接手 |
 *
 * 第一層之所以例外於「不要自行實作重試迴圈」那條規則：它不是重試同一個
 * 請求，而是帶著新資訊重問，成本一次、資訊量不同。上限就是一次——連續
 * 失敗代表 schema 或提示詞有問題，重問更多次只是燒錢。
 *
 * **抽不出文字則完全不重試**：那是永久性失敗，重跑一百次結果一樣。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class parse_syllabus extends \core\task\adhoc_task {
    /** @var int 重試次數上限（NFR-REL-01 要求至少三次）。 */
    public const MAX_ATTEMPTS = 3;

    /** @var int 同類任務的併行上限（NFR-PRF-03）。 */
    public const CONCURRENCY_LIMIT = 2;

    /**
     * 預設提示詞。
     *
     * 站台管理員可覆寫（NFR-MNT-01）。與摘要的提示詞同理，不放語言檔——
     * 這是給模型的指令而不是介面文字，且排程任務沒有觸發者可以決定語言。
     *
     * 「weight 是百分比數值」那一句是實測補上的：閘門量測時模型曾把
     * 40%／25%／35% 抽成 0.4／0.25／0.35，於是不變式四判定加總為 1。
     *
     * @var string
     */
    public const DEFAULT_PROMPT = '你是課綱結構化助理。從提供的課程大綱中抽取結構化資訊。
只根據內容作答，絕對不要臆測；無法從原文判定的項目一律填 null，
並在 parse_meta.unresolved 中以簡短中文說明原因。

confidence 依該欄位在原文中的明確程度給 0 到 1 之間的值。
source_span 必須逐字引用原文片段，不得改寫、不得重組欄位標題，
也不得自行加上「週次」「日期」這類標籤——找不到可引用的連續片段時，
請引用該項目所在的那一行原文。

日期一律輸出西元 YYYY-MM-DD 格式；民國年請換算成西元。
grading[].weight 是百分比的數值，例如三成請輸出 30 而不是 0.3。';

    /**
     * 排入任務。
     *
     * @param int $syllabusid 課綱 id
     * @param int $userid 上傳者，任務以其身分執行
     * @return void
     */
    public static function queue(int $syllabusid, int $userid): void {
        $task = new self();
        $task->set_custom_data(['syllabusid' => $syllabusid]);
        $task->set_userid($userid);
        $task->set_attempts_available(self::MAX_ATTEMPTS);

        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * 同類任務的併行上限。
     *
     * 壓低是為了不讓一次大量上傳把 API 的速率限制吃光，也避免多個 cron
     * 程序同時各跑一次語言模型呼叫而撞上記憶體上限。
     *
     * @return int
     */
    protected function get_default_concurrency_limit(): int {
        return self::CONCURRENCY_LIMIT;
    }

    /**
     * 執行解析。
     *
     * @return void
     */
    public function execute() {
        $data = $this->get_custom_data();
        $syllabusid = (int) ($data->syllabusid ?? 0);
        $attempt = max(1, self::MAX_ATTEMPTS - $this->get_attempts_available() + 1);

        $repository = new syllabus_repository();
        $record = $repository->get($syllabusid);

        if ($record === null) {
            mtrace('local_universityai: 課綱 ' . $syllabusid . ' 已不存在，略過。');

            return;
        }

        // 冪等（NFR-REL-04）：已經解析過就不再做一次。任務有可能被重複排入
        // ——事件重播、管理員手動重跑——而重解析會覆蓋老師已經改過的內容。
        if ($record->status !== syllabus_repository::STATUS_UPLOADED) {
            mtrace('local_universityai: 課綱 ' . $syllabusid . ' 狀態為 '
                . $record->status . '，不需要解析。');

            return;
        }

        try {
            $text = $this->extract_text($record);
        } catch (\moodle_exception $e) {
            // 抽不出文字是永久性失敗：檔案就是掃描檔，重跑一百次結果一樣。
            // 讓它往上拋只會讓同一件事再發生兩次，還延後了老師收到訊息的時間。
            $this->give_up($record, $attempt, $e->getMessage());

            return;
        }

        $extractor = configured_extractor::create();
        if ($extractor === null) {
            // 供應商還沒設定金鑰。這**要**往上拋：管理員設定好之後重試就會成功，
            // 而放棄的話這份課綱要老師重新上傳一次才有機會。
            throw new \moodle_exception('error:noextractor', 'local_universityai');
        }

        $result = $this->extract_with_one_retry($extractor, $record, $text, $attempt);

        $repository->mark_parsed(
            $syllabusid,
            $this->finalise($result['data'], $record, $text),
            (string) $result['model']
        );

        ops::record(
            ops::OP_SYLLABUS_PARSE,
            ops::TARGET_SYLLABUS,
            (string) $syllabusid,
            ops::STATUS_SUCCESS,
            $attempt
        );

        mtrace('local_universityai: 課綱 ' . $syllabusid . ' 解析完成（'
            . $result['model'] . '　' . $result['durationms'] . ' ms　第 ' . $attempt . ' 次嘗試）。');
    }

    /**
     * 取出課綱檔案的文字。
     *
     * @param \stdClass $record 課綱列
     * @return string
     * @throws \moodle_exception 找不到檔案或抽不出文字時
     */
    private function extract_text(\stdClass $record): string {
        $file = $this->stored_file($record);

        if ($file === null) {
            throw new \moodle_exception('error:filemissing', 'local_universityai');
        }

        return (new text_extractor())->extract($file->get_content(), $file->get_filename());
    }

    /**
     * 取得課綱的原始檔案。
     *
     * @param \stdClass $record 課綱列
     * @return \stored_file|null
     */
    private function stored_file(\stdClass $record): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            \context_course::instance($record->courseid)->id,
            syllabus_repository::FILE_COMPONENT,
            syllabus_repository::FILE_AREA,
            $record->id,
            'itemid',
            false
        );

        return $files ? reset($files) : null;
    }

    /**
     * 抽取，並在驗證失敗時帶著錯誤重問一次（§9.5 的第一層）。
     *
     * @param configured_extractor $extractor 抽取器
     * @param \stdClass $record 課綱列
     * @param string $text 課綱原文
     * @param int $attempt 第幾次任務嘗試，供失敗紀錄使用
     * @return array 抽取結果
     * @throws \moodle_exception 重問後仍失敗時
     */
    private function extract_with_one_retry(
        configured_extractor $extractor,
        \stdClass $record,
        string $text,
        int $attempt
    ): array {
        $schema = schema::extraction_schema();
        $validator = new validator();
        $literals = $this->teacher_literals((int) $record->courseid);
        $instruction = $this->prompt();

        // source_span 要比對的是**模型實際看到的文字**，不是過濾前的原文。
        // 用原文比對會把過濾造成的差異誤判成幻覺（FR-SYL-03）。
        $seen = $extractor->filtered($text, $literals)->text();

        for ($round = 1; $round <= 2; $round++) {
            $result = $extractor->extract($text, $schema, $instruction, $literals);

            if (!$result['success']) {
                // HTTP 錯誤或逾時：不在這裡重問，交給 Moodle 的重試機制。
                $this->maybe_give_up($record, $attempt, (string) $result['error']);

                throw new \moodle_exception(
                    'error:extractionfailed',
                    'local_universityai',
                    '',
                    null,
                    (string) $result['error']
                );
            }

            $verdict = $result['data'] === null
                ? ['errors' => ['回應不是合法的 JSON。'], 'notes' => []]
                : $validator->validate($result['data'], $seen);

            if ($verdict['errors'] === []) {
                return $result;
            }

            if ($round === 1) {
                mtrace('local_universityai: 課綱 ' . $record->id . ' 驗證不符 '
                    . count($verdict['errors']) . ' 項，帶著錯誤重問一次。');
                $instruction = $this->retry_prompt($this->prompt(), $verdict['errors']);
                continue;
            }

            // 重問後仍不符。連續失敗代表 schema 或提示詞有問題，再問只是燒錢。
            $this->maybe_give_up($record, $attempt, implode('；', $verdict['errors']));

            throw new \moodle_exception(
                'error:validationfailed',
                'local_universityai',
                '',
                count($verdict['errors']),
                implode("\n", $verdict['errors'])
            );
        }

        // 迴圈保證會 return 或 throw，這行只是讓靜態分析安心。
        throw new \moodle_exception('error:extractionfailed', 'local_universityai');
    }

    /**
     * 組出重問用的提示詞。
     *
     * **驗證錯誤先過濾再附上。** 錯誤訊息裡有 source_span 的片段，也就是
     * 課綱內容；雖然模型本來就看過那份內容，但這條路徑一旦被別處複製去
     * 寫記錄檔就會外洩（§6.3）。過濾的成本是零，不過濾的風險不是。
     *
     * @param string $instruction 原本的提示詞
     * @param string[] $errors 驗證錯誤
     * @return string
     */
    private function retry_prompt(string $instruction, array $errors): string {
        $redactor = filter::create();
        $lines = [];

        foreach (array_slice($errors, 0, 10) as $error) {
            $lines[] = '- ' . $redactor->redact($error);
        }

        return $instruction . "\n\n"
            . "上一次的輸出有以下問題，請逐項修正後重新輸出完整結果：\n"
            . implode("\n", $lines);
    }

    /**
     * 站台設定的提示詞。
     *
     * @return string
     */
    private function prompt(): string {
        $prompt = trim((string) get_config('local_universityai', 'parseprompt'));

        return $prompt !== '' ? $prompt : self::DEFAULT_PROMPT;
    }

    /**
     * 課程內已知的教師姓名，交給過濾器移除。
     *
     * SRS §6.3 要求「授課教師姓名與聯絡方式須在**進入解析之前**即自課綱
     * 原文中移除」。這裡有一個雞生蛋的問題：要移除姓名得先知道姓名，而
     * 姓名正是要從課綱裡解析出來的東西之一。
     *
     * **解法是不要問課綱，問 Moodle。** 這門課的授課教師是誰，站台本來就
     * 知道——具 `local/universityai:upload` 能力的人就是。三種常見的姓名
     * 排列都放進去：中文課綱寫「陳怡君」，而 Moodle 的 fullname 可能依
     * 站台設定排成別的順序。
     *
     * @param int $courseid 課程 id
     * @return string[]
     */
    private function teacher_literals(int $courseid): array {
        $context = \context_course::instance($courseid);
        $literals = [];

        foreach (get_users_by_capability($context, 'local/universityai:upload') as $user) {
            $literals[] = fullname($user);
            $literals[] = $user->lastname . $user->firstname;
            $literals[] = $user->firstname . $user->lastname;
        }

        return array_values(array_unique(array_filter($literals, static fn($n) => trim($n) !== '')));
    }

    /**
     * 補上由系統決定的欄位。
     *
     * 這些欄位**刻意不在 schema 裡**（見 `extraction\schema`）：問模型只會
     * 得到編造的識別碼，而 event_id 需要的正是跨版本的穩定性（§9.6）。
     *
     * @param array $payload 模型的抽取結果
     * @param \stdClass $record 課綱列
     * @param string $text 課綱原文
     * @return array 完整的 DM-SYLLABUS
     */
    private function finalise(array $payload, \stdClass $record, string $text): array {
        $payload['syllabus_id'] = (string) $record->id;
        $payload['course_ref'] = (int) $record->courseid;
        $payload['version'] = (int) $record->version;
        $payload['status'] = syllabus_repository::STATUS_PARSED;

        foreach ($payload['weeks'] as $index => $week) {
            $payload['weeks'][$index]['overridden'] = false;
        }

        $payload['events'] = event_key::assign($payload['events']);
        foreach ($payload['events'] as $index => $event) {
            $payload['events'][$index]['overridden'] = false;
        }

        $file = $this->stored_file($record);
        $payload['parse_meta']['source_file'] = $file ? $file->get_filename() : '';
        $payload['parse_meta']['parsed_at'] = date(DATE_ATOM);
        $payload['parse_meta']['model'] = (string) $record->model;
        $payload['parse_meta']['source_length'] = \core_text::strlen($text);

        return $payload;
    }

    /**
     * 若這是最後一次嘗試，記為失敗並通知管理員（NFR-REL-02）。
     *
     * 不是最後一次就什麼都不做——例外會往上拋，Moodle 會安排下一次。
     *
     * @param \stdClass $record 課綱列
     * @param int $attempt 第幾次嘗試
     * @param string $reason 失敗原因，只寫進 mtrace
     * @return void
     */
    private function maybe_give_up(\stdClass $record, int $attempt, string $reason): void {
        if ($this->get_attempts_available() > 1) {
            return;
        }

        $this->give_up($record, $attempt, $reason);
    }

    /**
     * 記為失敗、寫營運紀錄、通知管理員。
     *
     * @param \stdClass $record 課綱列
     * @param int $attempt 第幾次嘗試
     * @param string $reason 失敗原因，只寫進 mtrace
     * @return void
     */
    private function give_up(\stdClass $record, int $attempt, string $reason): void {
        (new syllabus_repository())->mark_failed((int) $record->id);

        ops::record(
            ops::OP_SYLLABUS_PARSE,
            ops::TARGET_SYLLABUS,
            (string) $record->id,
            ops::STATUS_FAILED,
            $attempt
        );

        notifier::notify_admins_of_failure(
            ops::OP_SYLLABUS_PARSE,
            ops::TARGET_SYLLABUS,
            (string) $record->id,
            $attempt
        );

        // 原因寫進排程記錄，但要過濾——那份記錄存在資料庫裡，會被匯出、
        // 被截圖、被貼進問題回報（§6.3）。
        mtrace('local_universityai: 課綱 ' . $record->id . ' 解析失敗（第 '
            . $attempt . ' 次）：' . filter::create()->redact($reason));
    }
}
