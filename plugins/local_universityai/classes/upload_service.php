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

use local_universityai\extraction\text_extractor;

/**
 * 收下一份上傳的課綱（FR-SYL-01）。
 *
 * Model 層。這段邏輯原本寫在 `upload.php` 裡，但那違反 §2.2——進入點只該
 * 接收請求、檢查權限、呼叫 Model、交給 renderer。搬過來之後 `upload.php`
 * 剩下十行，而這裡可以被 PHPUnit 直接測試，不必模擬一個 HTTP 請求。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_service {
    /** @var syllabus_repository 課綱查詢與寫入。 */
    private syllabus_repository $repository;

    /** @var text_extractor 文字抽取。 */
    private text_extractor $extractor;

    /**
     * 建構子。兩個相依都可注入，測試才不必準備真的 PDF。
     *
     * @param syllabus_repository|null $repository 課綱倉儲
     * @param text_extractor|null $extractor 文字抽取
     */
    public function __construct(?syllabus_repository $repository = null, ?text_extractor $extractor = null) {
        $this->repository = $repository ?? new syllabus_repository();
        $this->extractor = $extractor ?? new text_extractor();
    }

    /**
     * 收下草稿區裡的檔案，建立課綱版本並排入解析。
     *
     * 順序是刻意的，四步都不能換位置：
     *
     *   1. **先抽一次文字。** `FR-SYL-01` 要求「上傳無文字層的 PDF 時，系統
     *      在解析前即偵測並告知原因」。做在這裡而不是解析任務裡，是因為
     *      「在解析前」的實質意義是「趁使用者還在螢幕前」——放到背景任務裡
     *      告知，老師要等到收信才知道，而那時他已經離開了。
     *   2. 建立課綱版本，取得 id。檔案區要用它當 itemid。
     *   3. 存檔。
     *   4. 才觸發事件。**檔案要先落地**，否則解析任務可能在檔案存好之前就
     *      跑起來——cron 與網頁是不同的行程，沒有任何東西保證先後。
     *
     * @param int $courseid 課程 id
     * @param int $draftitemid 草稿區 id
     * @param int $userid 上傳者
     * @param \context_course $context 課程脈絡
     * @return int 新課綱的 id
     * @throws \moodle_exception 沒有檔案、格式不支援或沒有文字層時
     */
    public function accept(int $courseid, int $draftitemid, int $userid, \context_course $context): int {
        $draft = $this->draft_file($draftitemid, $userid);

        if ($draft === null) {
            throw new \moodle_exception('upload:nofile', 'local_universityai');
        }

        // 例外原樣往上拋：unsupported_format_exception 與 no_text_layer_exception
        // 的訊息就是要給老師看的（NFR-USA-03），包裝一層只會把它們變模糊。
        $this->extractor->extract($draft->get_content(), $draft->get_filename());

        $syllabusid = $this->repository->create_upload($courseid, $draft->get_contenthash(), $userid);

        file_save_draft_area_files(
            $draftitemid,
            $context->id,
            syllabus_repository::FILE_COMPONENT,
            syllabus_repository::FILE_AREA,
            $syllabusid
        );

        event\syllabus_uploaded::create([
            'objectid' => $syllabusid,
            'context' => $context,
        ])->trigger();

        return $syllabusid;
    }

    /**
     * 取出草稿區裡的第一個檔案。
     *
     * `filepicker` 一次只收一個檔案，但草稿區的 API 一律回傳陣列。
     *
     * @param int $draftitemid 草稿區 id
     * @param int $userid 草稿區的擁有者
     * @return \stored_file|null
     */
    private function draft_file(int $draftitemid, int $userid): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            \context_user::instance($userid)->id,
            'user',
            'draft',
            $draftitemid,
            'id',
            false
        );

        return $files ? reset($files) : null;
    }
}
