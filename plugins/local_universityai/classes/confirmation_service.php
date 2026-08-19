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

namespace local_universityai;

use local_universityai\extraction\validator;

/**
 * 老師的逐項修正與確認（FR-SYL-03、FR-SYL-04）。
 *
 * Model 層。兩件事在這裡合流：
 *
 *   1. **標示哪些項目需要老師特別看**（`FR-SYL-03`）——信心值低於門檻的
 *      項目。門檻是站台設定，判斷是業務規則，所以算在這裡而不是模板裡。
 *   2. **套用修改並標記人工覆寫**（`FR-SYL-04`、`FR-DIF-02`）。
 *
 * **修改寫回同一版的 payload，不另開版本**（§3.3）。版本的語意是「第幾次
 * 上傳」（SRS §1.6），老師改一個字就跳版會讓比對變得沒有意義。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class confirmation_service {
    /** @var string[] 週次中老師可以修改的欄位。 */
    public const WEEK_FIELDS = ['date', 'topic', 'note'];

    /** @var string[] 事件中老師可以修改的欄位。 */
    public const EVENT_FIELDS = ['title', 'due_date', 'due_time'];

    /** @var syllabus_repository 課綱倉儲。 */
    private syllabus_repository $repository;

    /**
     * 建構子。
     *
     * @param syllabus_repository|null $repository 課綱倉儲
     */
    public function __construct(?syllabus_repository $repository = null) {
        $this->repository = $repository ?? new syllabus_repository();
    }

    /**
     * 低信心門檻（FR-SYL-03）。
     *
     * @return float
     */
    public function threshold(): float {
        $configured = (float) get_config('local_universityai', 'confidencethreshold');

        // 設定未寫入時 get_config() 回傳 false，轉成 float 是 0.0——而 0.0
        // 的語意是「什麼都不標示」，也就是這條驗收條件安靜地失效。
        // prune_ops 撞過同一個坑，這裡沿用同樣的下限判斷。
        return $configured > 0 ? $configured : validator::DEFAULT_CONFIDENCE_THRESHOLD;
    }

    /**
     * 為每個週次與事件加上「是否需要老師特別檢查」的標記。
     *
     * 回傳的是顯示用的扁平結構，不是 DM-SYLLABUS——呼叫端是表單與 ViewModel，
     * 它們需要的是「這一項要不要標紅」這個布林值，不是原始的信心數值。
     * 這正是 §2.2 那個「門檻的比較屬業務規則，做在 Model」的例子。
     *
     * @param array $payload DM-SYLLABUS
     * @return array{weeks: array, events: array, unresolved: string[], lowcount: int}
     */
    public function annotate(array $payload): array {
        $threshold = $this->threshold();
        $lowcount = 0;

        $weeks = [];
        foreach ($payload['weeks'] ?? [] as $index => $week) {
            $low = ((float) ($week['confidence'] ?? 1)) < $threshold;
            $lowcount += $low ? 1 : 0;

            $weeks[] = [
                'index' => $index,
                'week_no' => (int) ($week['week_no'] ?? $index + 1),
                'date' => (string) ($week['date'] ?? ''),
                'topic' => (string) ($week['topic'] ?? ''),
                'note' => (string) ($week['note'] ?? ''),
                'confidence' => (float) ($week['confidence'] ?? 0),
                'islowconfidence' => $low,
                'source_span' => (string) ($week['source_span'] ?? ''),
                'isoverridden' => !empty($week['overridden']),
            ];
        }

        $events = [];
        foreach ($payload['events'] ?? [] as $index => $event) {
            $low = ((float) ($event['confidence'] ?? 1)) < $threshold;
            $lowcount += $low ? 1 : 0;

            $events[] = [
                'index' => $index,
                'type' => (string) ($event['type'] ?? 'other'),
                'title' => (string) ($event['title'] ?? ''),
                'due_date' => (string) ($event['due_date'] ?? ''),
                'due_time' => (string) ($event['due_time'] ?? ''),
                'confidence' => (float) ($event['confidence'] ?? 0),
                'islowconfidence' => $low,
                'source_span' => (string) ($event['source_span'] ?? ''),
                'isoverridden' => !empty($event['overridden']),
            ];
        }

        return [
            'weeks' => $weeks,
            'events' => $events,
            'unresolved' => $payload['parse_meta']['unresolved'] ?? [],
            'lowcount' => $lowcount,
        ];
    }

    /**
     * 套用老師的修改並確認課綱。
     *
     * **只有 `parsed` 狀態能被確認。** `uploaded` 表示還沒解析完，`confirmed`
     * 表示已經確認過（重複送出，多半是使用者按了兩次），`failed` 沒有內容可
     * 確認。三種都不該安靜地成功。
     *
     * @param int $syllabusid 課綱 id
     * @param \stdClass $submitted 表單資料，欄位名見 self::field_name()
     * @param int $userid 確認者
     * @param \context_course $context 課程脈絡
     * @return int 被標記為人工覆寫的項目數
     * @throws \moodle_exception 課綱不存在或狀態不對時
     */
    public function confirm(int $syllabusid, \stdClass $submitted, int $userid, \context_course $context): int {
        $record = $this->repository->get($syllabusid);

        if ($record === null) {
            throw new \moodle_exception('error:syllabusnotfound', 'local_universityai');
        }
        if ($record->status !== syllabus_repository::STATUS_PARSED) {
            throw new \moodle_exception(
                'error:notconfirmable',
                'local_universityai',
                '',
                get_string('syllabus:status:' . $record->status, 'local_universityai')
            );
        }

        $payload = $this->repository->decode($record);
        if ($payload === null) {
            throw new \moodle_exception('error:syllabusnotfound', 'local_universityai');
        }

        $overrides = 0;
        $payload['weeks'] = $this->apply_section(
            $payload['weeks'] ?? [],
            $submitted,
            'week',
            self::WEEK_FIELDS,
            $overrides
        );
        $payload['events'] = $this->apply_section(
            $payload['events'] ?? [],
            $submitted,
            'event',
            self::EVENT_FIELDS,
            $overrides
        );

        $payload['status'] = syllabus_repository::STATUS_CONFIRMED;

        $this->repository->mark_confirmed($syllabusid, $payload, $userid);

        event\syllabus_confirmed::create([
            'objectid' => $syllabusid,
            'context' => $context,
            'other' => ['overrides' => $overrides],
        ])->trigger();

        return $overrides;
    }

    /**
     * 把表單送來的值套回一個區段，並標記被改動的項目。
     *
     * **覆寫是項目層級而不是欄位層級**，因為 `DM-SYLLABUS` 的 `overridden`
     * 就定義在項目上。老師改了第 3 週的日期，整個第 3 週就是人工資料，
     * 後續重新解析不得覆蓋它（`FR-DIF-02`）。
     *
     * 已經是 overridden 的項目**不會被取消標記**，即使老師把值改回原樣：
     * 「這一項老師看過並決定過」是一個不可逆的事實，而那正是 `FR-DIF-02`
     * 要保護的東西。
     *
     * @param array $items 週次或事件
     * @param \stdClass $submitted 表單資料
     * @param string $prefix 欄位前綴，week 或 event
     * @param string[] $fields 可修改的欄位
     * @param int $overrides 覆寫計數，以參考傳入
     * @return array 套用後的項目
     */
    private function apply_section(
        array $items,
        \stdClass $submitted,
        string $prefix,
        array $fields,
        int &$overrides
    ): array {
        foreach ($items as $index => $item) {
            $changed = false;

            foreach ($fields as $field) {
                $name = self::field_name($prefix, $index, $field);
                if (!property_exists($submitted, $name)) {
                    continue;
                }

                $new = trim((string) $submitted->$name);
                $old = (string) ($item[$field] ?? '');

                if ($new === $old) {
                    continue;
                }

                // 空字串存回 null 而不是 ''：DM-SYLLABUS 的可空欄位用 null
                // 表示「沒有這個值」，而 '' 會讓下游分不清「老師清空了」
                // 與「模型抽出了一個空字串」。
                $items[$index][$field] = $new === '' ? null : $new;
                $changed = true;
            }

            if ($changed) {
                $items[$index]['overridden'] = true;
                $overrides++;
            }
        }

        return $items;
    }

    /**
     * 表單欄位名。
     *
     * 表單與套用邏輯必須用同一個規則產生欄位名，否則老師的修改會安靜地
     * 不生效——表單看起來正常、按下確認也成功，只是什麼都沒改。這種缺陷
     * 沒有任何錯誤訊息，所以名稱只在這裡產生一次。
     *
     * @param string $prefix week 或 event
     * @param int $index 項目索引
     * @param string $field 欄位名
     * @return string
     */
    public static function field_name(string $prefix, int $index, string $field): string {
        return $prefix . '_' . $index . '_' . str_replace('_', '', $field);
    }
}
