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

/**
 * 外掛版本資訊。
 *
 * 第一階段（SRS §8.4）：四張資料表、七項能力、一個排程任務、一個訊息
 * 提供者與站台設定頁。
 *
 * 第二階段：每週摘要（FR-SUM-01）。新增第五張表、第二個排程任務、外送資料
 * 過濾元件與生成類 AI 呼叫的收口。這是第一條端到端可運作的鏈路。
 *
 * 第三階段：課綱上傳與解析（FR-SYL-01、FR-SYL-02）。新增文字抽取、
 * DM-SYLLABUS 的 schema 與驗證、抽取器接縫、自訂事件與臨機任務。
 *
 * GPL 檔頭與著作權標註自第一個檔案就寫上——D-07 的判斷是「補做比一開始
 * 就遵守貴得多」，這是上架審查的必要項（NFR-MNT-04）。
 *
 * 順帶一提：說明文字裡不要出現以 at 符號開頭的標籤名，Moodle 的 PHPDoc
 * 檢查會把它當成行內標籤而報錯。這一行原本就是這樣被擋下來的。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_universityai';

// 格式為 YYYYMMDDXX。每次改動資料表或需要觸發升級時遞增。
$plugin->version = 2026081900;

// Moodle 5.2 的分支版本號（D-09）。低於此版的站台不得安裝——
// 5.2 之前的 AI 子系統沒有供應商執行個體 API，整套設計不成立。
$plugin->requires = 2026042000;

$plugin->maturity = MATURITY_ALPHA;
$plugin->release = '0.2.0';
