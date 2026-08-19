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

use local_universityai\syllabus_repository;

/**
 * 單一課程的每週摘要流程（FR-SUM-01）。
 *
 * Model 層。排程任務與 CLI 都呼叫這裡，兩邊因此走完全相同的路徑——
 * 「手動跑得出來、排程跑不出來」是最難查的那種缺陷。
 *
 * 每個結果都有明確的狀態碼而不是布林值。摘要沒產生的原因有五種，其中四種
 * 是正常的（沒課綱、學期未開始或已結束、本週已產生過），只有一種是失敗；
 * 混成一個布林值會讓排程輸出無法區分「不需要做」與「做壞了」。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service {
    /** @var string 已產生並保存。 */
    public const RESULT_CREATED = 'created';

    /** @var string 課程不存在。 */
    public const RESULT_NOCOURSE = 'nocourse';

    /** @var string 沒有已確認的課綱，還輪不到摘要。 */
    public const RESULT_NOSYLLABUS = 'nosyllabus';

    /** @var string 課綱的 payload 解不開。 */
    public const RESULT_BADPAYLOAD = 'badpayload';

    /** @var string 今天不在學期範圍內。 */
    public const RESULT_NOTRUNNING = 'notrunning';

    /** @var string 這個教學週已經有摘要了（冪等）。 */
    public const RESULT_EXISTS = 'exists';

    /** @var string 呼叫語言模型失敗。 */
    public const RESULT_FAILED = 'failed';

    /** @var syllabus_repository 課綱查詢。 */
    private syllabus_repository $syllabi;

    /** @var context_builder 事實蒐集。 */
    private context_builder $builder;

    /** @var generator 文字生成。 */
    private generator $generator;

    /** @var repository 摘要保存。 */
    private repository $summaries;

    /**
     * 建構子。四個相依都可注入，L1 測試才能不碰網路。
     *
     * @param syllabus_repository|null $syllabi 課綱查詢
     * @param context_builder|null $builder 事實蒐集
     * @param generator|null $generator 文字生成
     * @param repository|null $summaries 摘要保存
     */
    public function __construct(
        ?syllabus_repository $syllabi = null,
        ?context_builder $builder = null,
        ?generator $generator = null,
        ?repository $summaries = null
    ) {
        $this->syllabi = $syllabi ?? new syllabus_repository();
        $this->builder = $builder ?? new context_builder();
        $this->generator = $generator ?? new generator();
        $this->summaries = $summaries ?? new repository();
    }

    /**
     * 為一門課產生本週摘要。
     *
     * @param int $courseid 課程 id
     * @param int $now 參考時間，0 表示現在。測試與補跑會指定
     * @param bool $force 已存在時仍重新產生，保留為新版本
     * @return array{result: string, summaryid: int, weekno: int|null, status: string,
     *     model: string, durationms: int, errormessage: string}
     */
    public function generate_for_course(int $courseid, int $now = 0, bool $force = false): array {
        global $DB;

        $now = $now > 0 ? $now : time();
        $outcome = static fn(string $result, array $extra = []): array => $extra + [
            'result' => $result,
            'summaryid' => 0,
            'weekno' => null,
            'status' => '',
            'model' => '',
            'durationms' => 0,
            'errormessage' => '',
        ];

        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname');
        if (!$course) {
            return $outcome(self::RESULT_NOCOURSE);
        }

        $syllabus = $this->syllabi->get_latest_confirmed($courseid);
        if ($syllabus === null) {
            return $outcome(self::RESULT_NOSYLLABUS);
        }

        $payload = $this->syllabi->decode($syllabus);
        if ($payload === null) {
            return $outcome(self::RESULT_BADPAYLOAD);
        }

        $facts = $this->builder->build(
            $courseid,
            (string) $course->fullname,
            $payload,
            (int) $syllabus->version,
            $now
        );

        // 學期未開始或已結束就不產生。驗收條件的「本週無安排」講的是**開課中**
        // 但當週沒有進度的情況，不是課程結束後每週繼續發一則空摘要——那會讓
        // 一門三年前的課到今天還在累積紀錄。
        if (!$facts['running']) {
            return $outcome(self::RESULT_NOTRUNNING);
        }

        if (!$force && $this->summaries->exists($courseid, $facts['weekstart'])) {
            return $outcome(self::RESULT_EXISTS, ['weekno' => $facts['weekno']]);
        }

        $result = $this->generator->generate(
            $facts,
            \context_course::instance($courseid)->id,
            $this->attribution_userid()
        );

        if (!$result['success']) {
            return $outcome(self::RESULT_FAILED, [
                'weekno' => $facts['weekno'],
                'errormessage' => $result['errormessage'],
                'durationms' => $result['durationms'],
            ]);
        }

        return $outcome(self::RESULT_CREATED, [
            'summaryid' => $this->summaries->save($courseid, $facts, $result),
            'weekno' => $facts['weekno'],
            'status' => $result['status'],
            'model' => $result['model'],
            'durationms' => $result['durationms'],
        ]);
    }

    /**
     * AI 呼叫要掛在哪個使用者名下。
     *
     * 排程任務沒有互動使用者，但 core_ai 的動作一定要有 userid——它會拿去
     * 寫核心的 ai_action_register，也是速率限制的計數對象。用主管理員。
     *
     * **副作用要知道**：若站台在供應商設定中開啟了「每位使用者的速率限制」，
     * 全站的摘要會共用管理員這一個額度，課程一多就會被擋。那項設定預設關閉，
     * 開啟前要先想到這件事（見核心 core_ai\provider::is_request_allowed）。
     *
     * @return int
     */
    private function attribution_userid(): int {
        $admin = get_admin();

        return $admin ? (int) $admin->id : 0;
    }
}
