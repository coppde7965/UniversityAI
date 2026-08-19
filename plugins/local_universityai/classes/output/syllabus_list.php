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

use local_universityai\syllabus_repository;
use renderer_base;

/**
 * 課綱版本清單的 ViewModel（FR-SYL-01）。
 *
 * 所有條件顯示所需的布林值都在這裡算好，模板不做業務判斷（§2.2）。
 * 「這一版現在是什麼狀態」是業務語意，不是顯示細節。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class syllabus_list implements \renderable, \templatable {
    /** @var \stdClass[] 課綱版本，由新到舊。 */
    private array $syllabi;

    /** @var int 課程 id。 */
    private int $courseid;

    /**
     * 建構子。
     *
     * @param \stdClass[] $syllabi 課綱版本
     * @param int $courseid 課程 id
     */
    public function __construct(array $syllabi, int $courseid) {
        $this->syllabi = $syllabi;
        $this->courseid = $courseid;
    }

    /**
     * 匯出給模板。
     *
     * @param renderer_base $output 渲染器
     * @return \stdClass
     */
    public function export_for_template(renderer_base $output): \stdClass {
        $items = [];

        foreach ($this->syllabi as $syllabus) {
            $status = (string) $syllabus->status;
            $file = $this->file_for($syllabus);

            $items[] = (object) [
                'version' => (int) $syllabus->version,
                'versionlabel' => get_string('syllabus:versionlabel', 'local_universityai', $syllabus->version),
                'statuslabel' => get_string('syllabus:status:' . $status, 'local_universityai'),

                // 狀態的顯示樣式由這裡決定，模板只負責套用。三種狀態各自
                // 對應一個布林值而不是傳一個 CSS 類別字串——傳類別名等於
                // 讓 Model 層知道 Bootstrap 的存在。
                'ispending' => $status === syllabus_repository::STATUS_UPLOADED,
                'isparsed' => $status === syllabus_repository::STATUS_PARSED,
                'isconfirmed' => $status === syllabus_repository::STATUS_CONFIRMED,
                'isfailed' => $status === syllabus_repository::STATUS_FAILED,

                'uploadedat' => userdate((int) $syllabus->timecreated, get_string('strftimedatetimeshort')),
                'hasparsed' => (int) $syllabus->parsedat > 0,
                'parsedat' => userdate((int) $syllabus->parsedat, get_string('strftimedatetimeshort')),
                'model' => (string) ($syllabus->model ?? ''),
                'hasmodel' => !empty($syllabus->model),
                'filename' => $file ? $file->get_filename() : '',
                'hasfile' => $file !== null,
                'fileurl' => $file ? $this->url_for($file) : '',

                // 只有解析出東西的版本才進得了確認介面。uploaded 還沒有內容、
                // failed 沒有內容可確認——給連結只會讓老師點進一個空白畫面。
                'canreview' => in_array($status, [
                    syllabus_repository::STATUS_PARSED,
                    syllabus_repository::STATUS_CONFIRMED,
                ], true),
                'reviewurl' => (new \moodle_url(
                    '/local/universityai/review.php',
                    ['id' => $syllabus->id]
                ))->out(false),
                'reviewlabel' => get_string(
                    $status === syllabus_repository::STATUS_CONFIRMED ? 'review:view' : 'review:action',
                    'local_universityai'
                ),

                // 只有已確認的課綱能填入課程（FR-CRS-01 的第一條驗收條件）。
                'canpopulate' => $status === syllabus_repository::STATUS_CONFIRMED,
                'populateurl' => (new \moodle_url(
                    '/local/universityai/populate.php',
                    ['id' => $syllabus->id]
                ))->out(false),
            ];
        }

        return (object) [
            'hasitems' => $items !== [],
            'items' => $items,
            'courseid' => $this->courseid,
        ];
    }

    /**
     * 取某一版課綱的原始檔案。
     *
     * @param \stdClass $syllabus 課綱列
     * @return \stored_file|null
     */
    private function file_for(\stdClass $syllabus): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            \context_course::instance($syllabus->courseid)->id,
            syllabus_repository::FILE_COMPONENT,
            syllabus_repository::FILE_AREA,
            $syllabus->id,
            'itemid',
            false
        );

        return $files ? reset($files) : null;
    }

    /**
     * 檔案的下載網址。
     *
     * @param \stored_file $file 檔案
     * @return string
     */
    private function url_for(\stored_file $file): string {
        return \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename(),
            true
        )->out(false);
    }
}
