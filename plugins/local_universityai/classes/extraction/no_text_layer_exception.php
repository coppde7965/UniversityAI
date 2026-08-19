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
 * 檔案沒有可抽取的文字層，多半是掃描影像。
 *
 * FR-SYL-01 要求「上傳無文字層的 PDF 時，系統在解析前即偵測並告知原因」。
 * 這個例外就是那個「告知」——它的訊息會直接呈現給老師，所以要說清楚發生
 * 什麼事與下一步怎麼做（NFR-USA-03），不能只說「解析失敗」。
 *
 * 訊息刻意**不含函式庫的內部錯誤**。那些訊息對老師沒有意義，而且有可能
 * 夾帶檔案內容（NFR-SEC-06）。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class no_text_layer_exception extends \moodle_exception {
    /**
     * 建構子。
     *
     * @param string $extension 檔案的副檔名
     */
    public function __construct(string $extension) {
        parent::__construct('error:notextlayer', 'local_universityai', '', $extension);
    }
}
