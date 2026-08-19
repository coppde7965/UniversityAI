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

namespace local_universityai\extraction;

/**
 * 上傳了不支援的檔案格式。
 *
 * FR-SYL-01 的驗收條件寫得很明確：「上傳不支援的格式時，系統拒絕並**明確
 * 告知支援哪些格式**，不得只回傳『上傳失敗』」。所以訊息裡一定要帶出支援
 * 清單，而清單由 text_extractor 提供，不在語言檔裡另寫一份——兩處各寫一份
 * 就會有一天不一致，而不一致的那一天使用者會照著錯的清單去轉檔。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unsupported_format_exception extends \moodle_exception {
    /**
     * 建構子。
     *
     * @param string $extension 使用者上傳的副檔名
     */
    public function __construct(string $extension) {
        parent::__construct('error:unsupportedformat', 'local_universityai', '', (object) [
            'given' => $extension !== '' ? $extension : '（無副檔名）',
            'supported' => implode('、', text_extractor::SUPPORTED_EXTENSIONS),
        ]);
    }
}
