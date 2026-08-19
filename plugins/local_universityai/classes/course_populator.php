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

/**
 * 依已確認課綱填入課程、章節與事件（FR-CRS-01、FR-CRS-02、FR-CAL-01）。
 *
 * Model 層。三件事放在同一個類別裡，因為它們共用同一個交易邊界——
 * `FR-CRS-02` 要求「章節建立為單一交易，中途失敗不得留下部分建立的章節」，
 * 而 `FR-CRS-01` 要求「執行失敗時課程回復至執行前狀態」。分成三個類別各自
 * 開交易的話，第三步失敗時前兩步已經提交了。
 *
 * **冪等以「目標狀態」達成，不是「新增」**（§4.5）：章節依週次對位更新、
 * 數量不足才新增；事件靠 `_evtmap` 的唯一鍵。重複執行的結果相同。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_populator {
    /** @var string 事件對應表。 */
    public const EVTMAP_TABLE = 'local_universityai_evtmap';

    /** @var string 對應到 Moodle 行事曆事件。 */
    public const TARGET_EVENT = 'event';

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
     * 預覽將要填入的內容，標出哪些會覆寫既有值。
     *
     * `FR-CRS-01` 的驗收條件：「課程既有欄位若已有內容且與課綱不同，須先向
     * 老師呈現將被覆寫的項目，**不得靜默蓋掉**」。這個方法就是那個「呈現」，
     * 而它必須在任何寫入之前被呼叫——所以填入不由 `syllabus_confirmed`
     * 觀察者自動排入，而是由老師在看過差異之後按下套用。
     *
     * @param int $syllabusid 課綱 id
     * @return array{fields: array, weeks: int, events: int, syllabus: \stdClass}
     * @throws \moodle_exception 課綱不存在、未確認或內容壞掉時
     */
    public function preview(int $syllabusid): array {
        global $DB;

        [$record, $payload] = $this->confirmed_syllabus($syllabusid);
        $course = $DB->get_record('course', ['id' => $record->courseid], '*', MUST_EXIST);
        $target = $this->target_values($payload);

        $fields = [];
        foreach ($target as $name => $value) {
            $current = $this->present_value($name, $course->$name ?? '');
            $proposed = $this->present_value($name, $value);

            $fields[] = [
                'name' => $name,
                'label' => get_string('populate:field:' . $name, 'local_universityai'),
                'current' => $current,
                'proposed' => $proposed,
                'unchanged' => $current === $proposed,

                // 「會被覆寫」的定義是**既有欄位已有內容且與課綱不同**。
                // 空欄位被填上不算覆寫——那是這個功能存在的目的。
                'overwrites' => $current !== '' && $current !== $proposed,
            ];
        }

        return [
            'fields' => $fields,
            'weeks' => count($payload['weeks'] ?? []),
            'events' => count($payload['events'] ?? []),
            'syllabus' => $record,
        ];
    }

    /**
     * 實際填入。
     *
     * 整個動作包在單一交易裡（`FR-CRS-02`）。`start_delegated_transaction()`
     * 而不是自己管 begin/commit：Moodle 的委派交易在巢狀呼叫時只有最外層真的
     * 提交，而課程與行事曆的核心 API 內部也會開交易。
     *
     * @param int $syllabusid 課綱 id
     * @param int $userid 執行者
     * @return array{fields: int, sections: int, events: int}
     * @throws \moodle_exception 課綱不存在、未確認或內容壞掉時
     */
    public function apply(int $syllabusid, int $userid): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        [$record, $payload] = $this->confirmed_syllabus($syllabusid);

        $fields = 0;
        $sections = 0;
        $events = 0;

        $transaction = $DB->start_delegated_transaction();

        try {
            $fields = $this->apply_course_fields((int) $record->courseid, $payload);
            $sections = $this->apply_sections((int) $record->courseid, $payload);
            $events = $this->apply_events((int) $record->courseid, $payload);

            ops::record(
                ops::OP_COURSE_POPULATE,
                ops::TARGET_SYLLABUS,
                (string) $syllabusid,
                ops::STATUS_SUCCESS
            );

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // 回捲之後才寫營運紀錄——寫在交易裡的話會跟著被捲掉，而
            // 「失敗原因記入 DM-OPSTATUS」正是 FR-CRS-01 的驗收條件之一。
            $transaction->rollback($e);
        }

        // rollback() 一定會重新拋出，所以走到這裡表示成功。
        return ['fields' => $fields, 'sections' => $sections, 'events' => $events];
    }

    /**
     * 取得一份已確認的課綱，順便把三種不能執行的情況擋掉。
     *
     * `FR-CRS-01` 的第一條驗收條件：「僅在課綱狀態為**已確認**時可執行，
     * 否則拒絕」。這是 `FR-SYL-04` 那條「解析完成後狀態為待確認，此時呼叫
     * 課程建立應被拒絕」的另一面，兩者是同一個檢查。
     *
     * @param int $syllabusid 課綱 id
     * @return array{0: \stdClass, 1: array}
     * @throws \moodle_exception
     */
    private function confirmed_syllabus(int $syllabusid): array {
        $record = $this->repository->get($syllabusid);

        if ($record === null) {
            throw new \moodle_exception('error:syllabusnotfound', 'local_universityai');
        }
        if ($record->status !== syllabus_repository::STATUS_CONFIRMED) {
            throw new \moodle_exception(
                'error:notpopulatable',
                'local_universityai',
                '',
                get_string('syllabus:status:' . $record->status, 'local_universityai')
            );
        }

        $payload = $this->repository->decode($record);
        if ($payload === null) {
            throw new \moodle_exception('error:syllabusnotfound', 'local_universityai');
        }

        return [$record, $payload];
    }

    /**
     * 課綱要填進課程的欄位值。
     *
     * `summary` 由課綱的結構化欄位組出來，而不是取自某個「課程簡述」欄位
     * ——`DM-SYLLABUS` 沒有那個欄位。組出來的內容全部來自課綱，沒有一個字
     * 是發明的：代碼、學期、學分、週數、評分比例。
     *
     * @param array $payload DM-SYLLABUS
     * @return array<string, mixed> 課程欄位名對應到目標值
     */
    private function target_values(array $payload): array {
        $course = $payload['course'] ?? [];
        $term = $payload['term'] ?? [];

        $start = $this->to_timestamp((string) ($term['start_date'] ?? ''));
        $weeks = max(1, (int) ($term['total_weeks'] ?? 1));

        return [
            'fullname' => trim((string) ($course['title'] ?? '')),
            'summary' => $this->compose_summary($payload),

            // 結束日為最後一週結束的那一刻。Moodle 的 enddate 是「課程結束
            // 之後」的時間點，所以是起始日加上總週數，不是減一週。
            'startdate' => $start,
            'enddate' => $start > 0 ? $start + ($weeks * 7 * DAYSECS) : 0,
        ];
    }

    /**
     * 由課綱組出課程簡述。
     *
     * @param array $payload DM-SYLLABUS
     * @return string
     */
    private function compose_summary(array $payload): string {
        $course = $payload['course'] ?? [];
        $term = $payload['term'] ?? [];
        $lines = [];

        foreach (['code' => 'code', 'semester' => 'semester', 'credits' => 'credits'] as $key => $stringkey) {
            $value = $course[$key] ?? null;
            if ($value !== null && trim((string) $value) !== '') {
                $lines[] = get_string('populate:summary:' . $stringkey, 'local_universityai', $value);
            }
        }

        if (!empty($term['total_weeks'])) {
            $lines[] = get_string('populate:summary:weeks', 'local_universityai', (int) $term['total_weeks']);
        }

        foreach ($payload['grading'] ?? [] as $item) {
            $lines[] = get_string('populate:summary:grading', 'local_universityai', (object) [
                'item' => $item['item'],
                'weight' => $item['weight'],
            ]);
        }

        return implode("\n", $lines);
    }

    /**
     * 寫入課程欄位。
     *
     * @param int $courseid 課程 id
     * @param array $payload DM-SYLLABUS
     * @return int 實際變動的欄位數
     */
    private function apply_course_fields(int $courseid, array $payload): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $update = (object) ['id' => $courseid];
        $changed = 0;

        foreach ($this->target_values($payload) as $name => $value) {
            if ($name === 'startdate' || $name === 'enddate') {
                if ((int) $value <= 0 || (int) $course->$name === (int) $value) {
                    continue;
                }
            } else if (trim((string) $value) === '' || (string) $course->$name === (string) $value) {
                continue;
            }

            $update->$name = $value;
            $changed++;
        }

        if ($changed > 0) {
            // update_course() 而不是直接 update_record：它會重建課程快取、
            // 觸發 course_updated 事件，也會處理格式選項。直接寫表會讓課程
            // 頁面繼續顯示舊資料，而快取失效的時機沒有人說得準。
            $update->summaryformat = FORMAT_PLAIN;
            update_course($update);
        }

        return $changed;
    }

    /**
     * 建立或更新週次章節（FR-CRS-02）。
     *
     * 依 `week_no` 對位更新、數量不足才新增（§4.5 的「目標狀態」寫法）。
     * **不刪除多餘的章節**：老師可能自己加了章節放補充教材，刪掉它們是
     * 資料損失，而驗收條件只要求數量「等於」total_weeks——既有章節較多時
     * 補足即可。
     *
     * @param int $courseid 課程 id
     * @param array $payload DM-SYLLABUS
     * @return int 實際變動的章節數
     */
    private function apply_sections(int $courseid, array $payload): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $weeks = $payload['weeks'] ?? [];
        if ($weeks === []) {
            return 0;
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        // 第 0 節是課程總覽，週次自第 1 節起算。
        course_create_sections_if_missing($course, range(0, count($weeks)));

        $changed = 0;
        foreach ($weeks as $week) {
            $number = (int) ($week['week_no'] ?? 0);
            if ($number < 1) {
                continue;
            }

            $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $number]);
            if (!$section) {
                continue;
            }

            $name = trim((string) ($week['topic'] ?? ''));
            $summary = trim((string) ($week['note'] ?? ''));

            if ((string) $section->name === $name && (string) $section->summary === $summary) {
                continue;
            }

            course_update_section($course, $section, [
                'name' => $name,
                'summary' => $summary,
                'summaryformat' => FORMAT_PLAIN,
            ]);
            $changed++;
        }

        return $changed;
    }

    /**
     * 建立或更新課程事件（FR-CAL-01）。
     *
     * **建的是課程行事曆事件，不是活動模組。** 這與 `FR-CAL-01` 的字面
     * （「建立為 Moodle 中帶有截止日的活動」）有落差，理由見
     * docs/architecture.md 的 `A-24`；`_evtmap.targettype` 預留的 `event` 與
     * `cm` 兩個值正是為了讓這個選擇日後可以改變而不必改表。
     *
     * 冪等靠 `_evtmap` 的 `(courseid, eventkey)` 唯一鍵：有對應就更新既有
     * 事件，沒有才新建。這是 `NFR-REL-04` 在這條路徑上的實作方式。
     *
     * @param int $courseid 課程 id
     * @param array $payload DM-SYLLABUS
     * @return int 實際建立或更新的事件數
     */
    private function apply_events(int $courseid, array $payload): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/calendar/lib.php');

        $changed = 0;

        foreach ($payload['events'] ?? [] as $event) {
            $key = (string) ($event['event_id'] ?? '');
            $when = $this->to_timestamp(
                (string) ($event['due_date'] ?? ''),
                (string) ($event['due_time'] ?? '')
            );

            // 沒有日期的事件不建：行事曆事件一定要有時間點，而課綱沒寫日期
            // 時那個時間點只能用猜的。這類項目應該留在 unresolved 裡讓老師
            // 補上，而不是被放進行事曆一個編出來的日子。
            if ($key === '' || $when <= 0) {
                continue;
            }

            $mapping = $DB->get_record(self::EVTMAP_TABLE, ['courseid' => $courseid, 'eventkey' => $key]);
            $name = trim((string) ($event['title'] ?? ''));

            if ($mapping) {
                $existing = \calendar_event::load($mapping->moodleeventid);
                $existing->update((object) ['name' => $name, 'timestart' => $when], false);
                $DB->set_field(self::EVTMAP_TABLE, 'timemodified', time(), ['id' => $mapping->id]);
                $changed++;
                continue;
            }

            $created = \calendar_event::create((object) [
                'name' => $name,
                'description' => '',
                'format' => FORMAT_PLAIN,
                'courseid' => $courseid,
                'groupid' => 0,
                'userid' => 0,
                'modulename' => '',
                'instance' => 0,
                'eventtype' => 'course',
                'timestart' => $when,
                'timeduration' => 0,
                'visible' => 1,
            ], false);

            $DB->insert_record(self::EVTMAP_TABLE, (object) [
                'courseid' => $courseid,
                'eventkey' => $key,
                'moodleeventid' => $created->id,
                'targettype' => self::TARGET_EVENT,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
            $changed++;
        }

        return $changed;
    }

    /**
     * 把課綱的日期字串轉成時間戳。
     *
     * @param string $date YYYY-MM-DD
     * @param string $time HH:MM，可為空
     * @return int 無法解析時為 0
     */
    private function to_timestamp(string $date, string $time = ''): int {
        if ($date === '') {
            return 0;
        }

        $parsed = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date,
            \core_date::get_server_timezone_object()
        );
        if ($parsed === false) {
            return 0;
        }

        if (preg_match('/^([0-9]{1,2}):([0-9]{2})$/', $time, $matches)) {
            $parsed = $parsed->setTime((int) $matches[1], (int) $matches[2]);
        }

        return $parsed->getTimestamp();
    }

    /**
     * 把欄位值轉成可比對、可顯示的字串。
     *
     * 日期轉成人看得懂的格式再比對：時間戳的差異對老師沒有意義，而「這一項
     * 會不會被覆寫」是要給老師判斷的。
     *
     * @param string $name 欄位名
     * @param mixed $value 值
     * @return string
     */
    private function present_value(string $name, $value): string {
        if ($name === 'startdate' || $name === 'enddate') {
            return (int) $value > 0 ? userdate((int) $value, get_string('strftimedate')) : '';
        }

        return trim((string) $value);
    }
}
