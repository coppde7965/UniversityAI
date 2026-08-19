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

namespace aiprovider_claude;

/**
 * 摘要文字（core_ai 的 summarise_text 動作）。
 *
 * 請求形狀與產生文字完全相同，差別只在動作各自的系統指令與站台設定，
 * 兩者都由 abstract_processor 依動作類別取得。
 *
 * @package    aiprovider_claude
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_summarise_text extends abstract_processor {
}
