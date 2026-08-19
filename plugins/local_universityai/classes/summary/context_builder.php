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

/**
 * 蒐集每週摘要所需的事實（FR-SUM-01）。
 *
 * Model 層，且是這條鏈路上唯一決定「哪些內容可以外送」的地方。
 *
 * **這個類別存在的主要理由是一條驗收條件**：「摘要內容僅根據已確認課綱與
 * 課程事件產生，不得引入課綱以外的資訊」。做法是把事實的蒐集與文字的生成
 * 徹底分開——語言模型拿到的是本類別輸出的結構化事實，**不是課綱原文**。
 * 模型看不到的東西就不可能寫進摘要，這比在提示詞裡叮嚀「不要臆測」可靠。
 *
 * 同一個切分也讓 SRS §6.3 的白名單變成一件可檢查的事：能外送的欄位就是
 * to_facts() 回傳的那幾個鍵，多一個都要改這裡。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class context_builder {
    /** @var int 待辦提醒的時間範圍：七日內（FR-SUM-01 的第三個區塊）。 */
    public const DUE_WINDOW_DAYS = 7;

    /** @var string 事件來自課綱的 events[]。 */
    public const SOURCE_SYLLABUS = 'syllabus';

    /** @var string 事件來自 Moodle 行事曆。 */
    public const SOURCE_CALENDAR = 'calendar';

    /**
     * 組出一門課在某個時間點的摘要事實。
     *
     * @param int $courseid 課程 id
     * @param string $coursename 課程全名，取自 Moodle 而非課綱——學生看到的是這個
     * @param array $payload 已解碼的 DM-SYLLABUS
     * @param int $syllabusversion 來源課綱版本（NFR-MNT-03）
     * @param int $now 參考時間，測試可指定
     * @return array 見類別說明
     */
    public function build(
        int $courseid,
        string $coursename,
        array $payload,
        int $syllabusversion,
        int $now
    ): array {
        $tz = \core_date::get_server_timezone_object();
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0, 0);

        $term = $this->resolve_term($payload, $today, $tz);
        $weeks = $this->index_weeks($payload);

        $windowfrom = $today->getTimestamp();
        $windowto = $today->modify('+' . self::DUE_WINDOW_DAYS . ' days')->getTimestamp();

        $facts = [
            'courseid' => $courseid,
            'coursename' => $coursename,
            'syllabusversion' => $syllabusversion,
            'running' => $term['running'],
            'weekno' => $term['weekno'],
            'weekstart' => $term['weekstart'],
            'totalweeks' => $term['totalweeks'],
            'thisweek' => $term['weekno'] !== null ? ($weeks[$term['weekno']] ?? null) : null,
            'nextweek' => $term['weekno'] !== null ? ($weeks[$term['weekno'] + 1] ?? null) : null,
            'dueevents' => $this->collect_events($courseid, $payload, $windowfrom, $windowto, $tz),
            'literals' => $this->sensitive_literals($payload),
        ];

        // 三個區塊全空就是「本週無安排」。這個旗標由這裡算好而不是交給
        // 模板或提示詞判斷，理由同 §2.2 的分層規則：業務判斷屬 Model。
        $facts['hascontent'] = $facts['thisweek'] !== null
            || $facts['nextweek'] !== null
            || $facts['dueevents'] !== [];

        return $facts;
    }

    /**
     * 由 term.start_date 推算目前是第幾教學週。
     *
     * 週次刻意由課綱的學期起始日推算，而不是用站台行事曆的一週起始日：
     * 「第 3 週」是課程的概念，不是日曆的概念。兩門起始日不同的課在同一天
     * 本來就該落在不同的教學週，用日曆週會把它們混成同一週。
     *
     * @param array $payload DM-SYLLABUS
     * @param \DateTimeImmutable $today 今天零時
     * @param \DateTimeZone $tz 伺服器時區
     * @return array{running: bool, weekno: int|null, weekstart: int, totalweeks: int}
     */
    private function resolve_term(array $payload, \DateTimeImmutable $today, \DateTimeZone $tz): array {
        $totalweeks = (int) ($payload['term']['total_weeks'] ?? 0);
        $startdate = (string) ($payload['term']['start_date'] ?? '');
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $startdate, $tz);

        $none = ['running' => false, 'weekno' => null, 'weekstart' => 0, 'totalweeks' => $totalweeks];

        if ($start === false || $totalweeks < 1) {
            return $none;
        }
        if ($today < $start) {
            return $none;
        }

        // diff()->days 取的是完整天數，不受日光節約時間影響——直接相減
        // 時間戳再除以 86400 在有夏令時間的時區會在切換那一週差一天。
        $weekno = intdiv((int) $start->diff($today)->days, 7) + 1;
        if ($weekno > $totalweeks) {
            return $none;
        }

        return [
            'running' => true,
            'weekno' => $weekno,
            'weekstart' => $start->modify('+' . (($weekno - 1) * 7) . ' days')->getTimestamp(),
            'totalweeks' => $totalweeks,
        ];
    }

    /**
     * 把 weeks[] 依 week_no 建索引。
     *
     * 主題與備註都空白的週次視同不存在。DM-SYLLABUS 要求 weeks 的長度等於
     * total_weeks（SRS §5 的約束），所以停課週、期中考週這類沒有進度的週次
     * 仍然會有一列，只是內容是空的。把它當成「有當週資料」會讓摘要送出一個
     * 空主題給模型，而模型面對空字串一定會編——那正是驗收條件要防的事。
     *
     * @param array $payload DM-SYLLABUS
     * @return array<int, array{week_no: int, topic: string, note: string}>
     */
    private function index_weeks(array $payload): array {
        $indexed = [];

        foreach ($payload['weeks'] ?? [] as $week) {
            $weekno = (int) ($week['week_no'] ?? 0);
            $topic = trim((string) ($week['topic'] ?? ''));
            $note = trim((string) ($week['note'] ?? ''));

            if ($weekno < 1 || ($topic === '' && $note === '')) {
                continue;
            }

            $indexed[$weekno] = [
                'week_no' => $weekno,
                'topic' => $topic,
                'note' => $note,
            ];
        }

        return $indexed;
    }

    /**
     * 蒐集時間窗內到期的課程事件。
     *
     * 兩個來源都要看，因為驗收條件寫的是「已確認課綱**與**課程事件」：
     * 課綱的 events[] 是老師確認過的規劃，Moodle 行事曆則含教師後來手動
     * 建立或活動模組自動產生的期限，兩者都算課程事件。
     *
     * 重複的處理靠 _evtmap：課綱事件一旦被 FR-CAL-01 寫成 Moodle 事件，
     * 對應關係就會留在那張表，此時以 Moodle 端為準——老師可能事後改過日期，
     * 而課綱那一份不會跟著動。
     *
     * @param int $courseid 課程 id
     * @param array $payload DM-SYLLABUS
     * @param int $from 時間窗起（含）
     * @param int $to 時間窗迄（不含）
     * @param \DateTimeZone $tz 伺服器時區
     * @return array<int, array{title: string, type: string, due: int, source: string}>
     */
    private function collect_events(int $courseid, array $payload, int $from, int $to, \DateTimeZone $tz): array {
        global $DB;

        $mapped = $DB->get_fieldset_select(
            'local_universityai_evtmap',
            'eventkey',
            'courseid = :courseid',
            ['courseid' => $courseid]
        );
        $mapped = array_flip($mapped ?: []);

        $events = [];

        foreach ($payload['events'] ?? [] as $event) {
            $key = (string) ($event['event_id'] ?? '');
            if ($key !== '' && isset($mapped[$key])) {
                // 已經進了 Moodle 行事曆，下面的查詢會取到權威版本。
                continue;
            }

            $due = $this->to_timestamp(
                (string) ($event['due_date'] ?? ''),
                (string) ($event['due_time'] ?? ''),
                $tz
            );
            if ($due === null || $due < $from || $due >= $to) {
                continue;
            }

            $events[] = [
                'title' => trim((string) ($event['title'] ?? '')),
                'type' => (string) ($event['type'] ?? 'other'),
                'due' => $due,
                'source' => self::SOURCE_SYLLABUS,
            ];
        }

        $calendar = $DB->get_records_select(
            'event',
            'courseid = :courseid AND visible = 1 AND timestart >= :from AND timestart < :to',
            ['courseid' => $courseid, 'from' => $from, 'to' => $to],
            'timestart ASC'
        );

        foreach ($calendar as $record) {
            $events[] = [
                'title' => trim((string) $record->name),
                'type' => (string) ($record->modulename ?: 'other'),
                'due' => (int) $record->timestart,
                'source' => self::SOURCE_CALENDAR,
            ];
        }

        usort($events, static fn($a, $b) => $a['due'] <=> $b['due']);

        return $events;
    }

    /**
     * 把課綱的日期字串轉成時間戳。
     *
     * @param string $date YYYY-MM-DD
     * @param string $time HH:MM，可為空
     * @param \DateTimeZone $tz 伺服器時區
     * @return int|null 日期無法解析時回傳 null
     */
    private function to_timestamp(string $date, string $time, \DateTimeZone $tz): ?int {
        if ($date === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
        if ($parsed === false) {
            return null;
        }

        if (preg_match('/^([0-9]{1,2}):([0-9]{2})$/', $time, $matches)) {
            $parsed = $parsed->setTime((int) $matches[1], (int) $matches[2]);
        }

        return $parsed->getTimestamp();
    }

    /**
     * 課綱中已知的敏感字面值，交給 redaction\filter 移除。
     *
     * 目前只有授課教師姓名。SRS §6.3 要求「授課教師姓名與聯絡方式須在進入
     * 解析之前即自課綱原文中移除」，那是第三階段解析路徑的事；本階段送出的
     * 是結構化事實而不是原文，教師姓名本來就不在送出的欄位裡。這裡仍然把它
     * 列進去，是因為週次主題這類自由文字有機會夾帶（「客座：某某某」）。
     *
     * 修課學生的姓名不在此列——摘要從不包含任何學生資料，把全班姓名塞進
     * 過濾器只會讓每次摘要多做幾百次字串比對，卻擋不住任何實際存在的風險。
     *
     * @param array $payload DM-SYLLABUS
     * @return string[]
     */
    private function sensitive_literals(array $payload): array {
        $literals = [];

        $teacher = trim((string) ($payload['course']['teacher'] ?? ''));
        if ($teacher !== '') {
            $literals[] = $teacher;
        }

        return $literals;
    }
}
