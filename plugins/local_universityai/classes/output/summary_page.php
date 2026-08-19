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

namespace local_universityai\output;

use local_universityai\summary\generator;
use renderer_base;

/**
 * 每週摘要頁的 ViewModel（D-08 / NFR-MNT-06）。
 *
 * export_for_template() 只回傳 stdClass、陣列與純量，不回傳物件實例；
 * 所有條件顯示所需的布林值都在這裡算好，模板不做業務判斷（§2.2）。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class summary_page implements \renderable, \templatable {
    /** @var \stdClass[] 由新到舊的摘要列。 */
    private array $summaries;

    /**
     * 建構子。
     *
     * @param \stdClass[] $summaries repository::get_recent() 的結果
     */
    public function __construct(array $summaries) {
        $this->summaries = $summaries;
    }

    /**
     * 匯出給模板。
     *
     * 摘要內文由語言模型產生，因此**一律當成純文字處理**：拆成段落交給模板，
     * 由 Mustache 的預設跳脫輸出。不走 format_text() 也不允許任何 HTML——
     * 模型的輸出是不受信任的內容，讓它能產生標記等於開一條注入路徑，而摘要
     * 本來就不需要格式。
     *
     * @param renderer_base $output 渲染器
     * @return \stdClass
     */
    public function export_for_template(renderer_base $output): \stdClass {
        $items = [];

        foreach ($this->summaries as $summary) {
            $items[] = (object) [
                'weekno' => (int) $summary->weekno,
                'weeklabel' => get_string('summary:weeklabel', 'local_universityai', $summary->weekno),
                'weekrange' => userdate((int) $summary->weekstart, get_string('strftimedate')),
                'paragraphs' => $this->to_paragraphs((string) $summary->content),
                'isnoschedule' => $summary->status === generator::STATUS_NOSCHEDULE,
                'generatedat' => userdate((int) $summary->generatedat, get_string('strftimedatetimeshort')),

                // NFR-MNT-03 要求每一則 AI 產出可回溯至來源課綱版本、模型與
                // 生成時間。三者都顯示在頁面上而不是只留在資料庫裡——看得到的
                // 出處才有意義，尤其是摘要內容看起來不對的時候。
                'model' => (string) ($summary->model ?? ''),
                'hasmodel' => !empty($summary->model),
                'syllabusversion' => (int) $summary->syllabusversion,
                'versionlabel' => get_string('summary:badgeversion', 'local_universityai', $summary->version),
                'isrevision' => ((int) $summary->version) > 1,
            ];
        }

        return (object) [
            'hasitems' => $items !== [],
            'items' => $items,
        ];
    }

    /**
     * 把模型產生的文字拆成段落。
     *
     * @param string $content 摘要全文
     * @return array<int, array{text: string}>
     */
    private function to_paragraphs(string $content): array {
        $lines = preg_split('/\R+/u', trim($content)) ?: [];

        $paragraphs = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $paragraphs[] = ['text' => $line];
            }
        }

        return $paragraphs;
    }
}
