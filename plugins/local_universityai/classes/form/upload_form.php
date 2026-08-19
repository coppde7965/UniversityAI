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

namespace local_universityai\form;

use local_universityai\extraction\text_extractor;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * 課綱上傳表單（FR-SYL-01）。
 *
 * 格式限制檢查兩次，而且**必須兩次**：
 *
 *   1. 這裡的 `validation()`——趁使用者還在螢幕前，給一句說得清楚的訊息。
 *   2. `text_extractor::extract()`（於 `upload_service`）——真正的把關。
 *      副檔名可以造假，而且表單擋不住從別處呼叫的路徑。
 *
 * 兩者的**清單與訊息來自同一處**，不會漂移。
 *
 * **為什麼不用 filepicker 的 `accepted_types`。** 那是最直覺的做法，也確實
 * 擋得住，但它擋下之後顯示的是核心的 `err_wrongfileextension`，而
 * Moodle 5.2 的英文字串已改用 `{$a->allowlist}`、繁體中文語言包卻還停在
 * 舊的 `{$a->whitelist}`——**中文站台上使用者看到的是一個沒有被替換的
 * 佔位符**。`FR-SYL-01` 明文要求「明確告知支援哪些格式，不得只回傳
 * 『上傳失敗』」，靠一個壞掉的上游字串達不到那個要求。
 *
 * 代價是檔案選擇器不再預先過濾類型。相對於讓使用者看到 `{$a->whitelist}`，
 * 這個代價小得多；表單上方的靜態說明仍然列出支援的格式。
 *
 * 這是上游的缺陷而不是本專案的，語言包更新之後可以改回來——屆時要重跑
 * `make syllabus` 確認訊息真的正常，不要只看語言包版本號。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_form extends \moodleform {
    /**
     * 表單定義。
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', $this->_customdata['courseid']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('static', 'formats', get_string('upload:formats', 'local_universityai'));

        $mform->addElement(
            'filepicker',
            'syllabusfile',
            get_string('upload:file', 'local_universityai'),
            null,
            ['maxbytes' => $this->_customdata['maxbytes']]
        );
        $mform->addRule('syllabusfile', null, 'required', null, 'client');

        $this->add_action_buttons(true, get_string('upload:submit', 'local_universityai'));
    }

    /**
     * 檢查副檔名。
     *
     * 訊息與 `unsupported_format_exception` 共用同一個語言字串與同一份支援
     * 清單——兩處各寫一份的話，總有一天會有一邊漏掉新增的格式，而使用者
     * 會照著錯的清單去轉檔。
     *
     * **這裡絕對不能呼叫 `$this->get_new_filename()`。** 那是取草稿區檔名
     * 最直覺的方法，但核心的實作開頭是
     * `if (!$this->is_submitted() or !$this->is_validated())`，而
     * `is_validated()` 會呼叫 `validation()`——也就是這個方法本身。結果是
     * 無限遞迴，而且**症狀完全看不出原因**：每層遞迴都會下一次 SQL，最後
     * 以「記憶體耗盡於 moodle_database.php 的參數處理」中止，堆疊裡看不到
     * 任何一行本外掛的程式碼。實測撞過，找了很久。
     *
     * 改為直接由草稿區 id 查檔名，不經過任何會回頭呼叫驗證的核心方法。
     *
     * @param array $data 送出的資料
     * @param array $files 送出的檔案
     * @return array 欄位名對應到錯誤訊息
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $filename = $this->draft_filename((int) ($data['syllabusfile'] ?? 0));

        if ($filename !== null && !text_extractor::is_supported($filename)) {
            $extension = \core_text::strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $errors['syllabusfile'] = get_string(
                'error:unsupportedformat',
                'local_universityai',
                (object) [
                    'given' => $extension !== '' ? $extension : '（無副檔名）',
                    'supported' => implode('、', text_extractor::SUPPORTED_EXTENSIONS),
                ]
            );
        }

        return $errors;
    }

    /**
     * 取草稿區裡那個檔案的檔名。
     *
     * @param int $draftitemid 草稿區 id
     * @return string|null 草稿區是空的時候為 null
     */
    private function draft_filename(int $draftitemid): ?string {
        global $USER;

        if ($draftitemid <= 0) {
            return null;
        }

        $files = get_file_storage()->get_area_files(
            \context_user::instance($USER->id)->id,
            'user',
            'draft',
            $draftitemid,
            'id DESC',
            false
        );

        return $files ? reset($files)->get_filename() : null;
    }
}
