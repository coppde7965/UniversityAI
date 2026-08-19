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
 * 繁體中文語言字串。
 *
 * 鍵必須與 lang/en/ 完全一致，tests/lang_test.php 會比對。
 *
 * 兩個容易踩到的地方：
 *
 * 1. 字串一律用單引號。Moodle 的參數語法（錢字號 a 加箭頭）長得像 PHP 的
 *    變數插值，用雙引號會讓 PHP 在載入語言檔時就把它解析掉，得到空字串。
 *
 * 2. 鍵必須嚴格照字母順序排列，且字串之間不可以插入註解——後者會讓
 *    Moodle 的自動排序工具失效，phpcs 也會標出來。因此下面沒有分組註解，
 *    分組的語意由鍵的前綴承擔。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['error:extractionfailed'] = '解析課綱時無法連上語言模型。系統會自動重試。';
$string['error:filemissing'] = '在課程檔案區中找不到上傳的課綱檔案。';
$string['error:noextractor'] = '尚未設定可用於課綱抽取的 AI 供應商。請站台管理員為供應商填入 API 金鑰，解析任務下一次重試就會成功。';
$string['error:notconfirmable'] = '這份課綱目前的狀態是「{$a}」，無法確認。只有解析完成、等待確認的課綱可以確認。';
$string['error:notextlayer'] = '這份 {$a} 檔沒有可讀取的文字層，看起來是掃描影像。目前僅支援文字可選取的 PDF 與 DOCX，請改用原始檔案，或先將掃描檔轉為可選取文字的格式再上傳。';
$string['error:notpopulatable'] = '這份課綱目前的狀態是「{$a}」，無法填入課程。只有已確認的課綱可以填入。';
$string['error:syllabusnotfound'] = '找不到這份課綱。';
$string['error:unsupportedformat'] = '不支援的檔案格式「{$a->given}」。請改上傳下列格式之一：{$a->supported}。';
$string['error:validationfailed'] = '抽取結果有 {$a} 項不符規格，且已請模型修正過一次仍未通過。';
$string['event:syllabusconfirmed'] = '課綱已確認';
$string['event:syllabusuploaded'] = '課綱已上傳';
$string['messageprovider:opsfailure'] = '背景作業失敗';
$string['notification:opsfailure:body'] = '「{$a->operation}」對{$a->targettype} {$a->targetid} 的處理在第 {$a->attempt} 次嘗試後仍然失敗，將不再重試。

這則通知刻意不附細節。請至站台的營運狀態面板查看。';
$string['notification:opsfailure:subject'] = 'UniversityAI：{$a->operation}失敗';
$string['operation:calendar_push'] = '行事曆推送';
$string['operation:course_populate'] = '課程資訊填入';
$string['operation:event_create'] = '課程事件建立';
$string['operation:notify'] = '通知發送';
$string['operation:syllabus_parse'] = '課綱解析';
$string['operation:weekly_summary'] = '每週摘要';
$string['pluginname'] = 'UniversityAI 課程助理';
$string['populate:action'] = '填入課程';
$string['populate:apply'] = '填入課程';
$string['populate:colcurrent'] = '目前的值';
$string['populate:colfield'] = '欄位';
$string['populate:colproposed'] = '課綱的值';
$string['populate:difftitle'] = '將被寫入的課程欄位';
$string['populate:done'] = '已填入課程：欄位 {$a->fields} 項、章節 {$a->sections} 個、行事曆事件 {$a->events} 個。';
$string['populate:empty'] = '空白';
$string['populate:events'] = '行事曆事件';
$string['populate:eventscount'] = '將建立或更新 {$a} 個事件。重複執行不會產生重複的事件。';
$string['populate:field:enddate'] = '課程結束日期';
$string['populate:field:fullname'] = '課程全名';
$string['populate:field:startdate'] = '課程開始日期';
$string['populate:field:summary'] = '課程簡述';
$string['populate:overwritewarning'] = '有 {$a} 個欄位原本就有內容，且與課綱不同。它們在下方已標示出來，繼續的話會被取代。';
$string['populate:pagetitle'] = '將課綱填入這門課程';
$string['populate:sections'] = '週次章節';
$string['populate:sectionscount'] = '將依課綱週次建立或更新 {$a} 個章節。';
$string['populate:summary:code'] = '課程代碼：{$a}';
$string['populate:summary:credits'] = '學分數：{$a}';
$string['populate:summary:grading'] = '評分方式－{$a->item}：{$a->weight}%';
$string['populate:summary:semester'] = '學期：{$a}';
$string['populate:summary:weeks'] = '課程長度：{$a} 週';
$string['populate:unchanged'] = '不變';
$string['populate:willoverwrite'] = '將被取代';
$string['review:action'] = '確認';
$string['review:alreadyconfirmed'] = '這份課綱已經確認過了。可以在此檢視，但無法再次確認；要修改請上傳新的版本。';
$string['review:confirm'] = '確認課綱';
$string['review:confirmed'] = '課綱已確認。其中 {$a} 項標記為人工覆寫，日後重新解析不會覆蓋它們。';
$string['review:eventduedate'] = '截止日期';
$string['review:eventduetime'] = '截止時間';
$string['review:events'] = '課程事件';
$string['review:eventtitle'] = '名稱';
$string['review:eventtype:assignment'] = '作業';
$string['review:eventtype:exam'] = '考試';
$string['review:eventtype:other'] = '其他';
$string['review:eventtype:project'] = '專題';
$string['review:lowcount'] = '有 {$a} 項的抽取信心偏低，已在下方標示。請優先檢查那幾項。';
$string['review:needscheck'] = '請確認';
$string['review:notready'] = '這份課綱還沒有可以確認的內容（狀態：{$a}）。';
$string['review:overridden'] = '您修改過';
$string['review:pagetitle'] = '確認課綱解析結果';
$string['review:sourcespan'] = '原文出處';
$string['review:unresolved'] = '解析時無法判定的項目';
$string['review:view'] = '檢視';
$string['review:weekdate'] = '日期';
$string['review:weeknote'] = '備註';
$string['review:weeks'] = '週次進度';
$string['review:weektopic'] = '主題';
$string['settings:batchsize'] = '單次處理量上限';
$string['settings:batchsize_desc'] = '排程任務單次最多處理幾個項目。課程數多的站台把這個值壓低可避免撞上 PHP 的執行時間與記憶體上限，未處理完的部分由下一輪接續。';
$string['settings:confidencethreshold'] = '低信心門檻';
$string['settings:confidencethreshold_desc'] = '抽取信心值低於此值的週次與事件會在確認介面上標示出來，讓老師優先檢查。';
$string['settings:extractionmode'] = '抽取模式';
$string['settings:extractionmode:prompted'] = '僅以提示詞要求 JSON（任何供應商皆可用）';
$string['settings:extractionmode:structured'] = '原生結構化輸出（建議）';
$string['settings:extractionmode_desc'] = '原生結構化輸出由供應商保證回應的結構。模型不支援時才改用提示詞模式。這裡刻意沒有自動回退：不支援的模型會以明確的錯誤失敗，而不是安靜地降低正確率、拉高 token 用量卻沒有人發現。';
$string['settings:extractorclass'] = '抽取器類別';
$string['settings:extractorclass_desc'] = '執行結構化抽取的類別完整名稱。這裡以字串指名而不是在程式碼中引用，是為了讓 AI 供應商外掛在沒有安裝 UniversityAI 的站台上仍可獨立安裝。只有在更換供應商外掛時才需要改動。';
$string['settings:opsretentiondays'] = '營運紀錄保留天數';
$string['settings:opsretentiondays_desc'] = '超過這個天數的營運狀態紀錄會被每日的清理任務刪除。';
$string['settings:parseprompt'] = '課綱解析提示詞';
$string['settings:parseprompt_desc'] = '連同課綱原文一起送給語言模型的指令。回應的結構由 JSON Schema 強制，不由這裡控制，因此這段文字能調整的是日期格式、無法判定時如何回報這類措辭規則。留白即回到內建預設值。';
$string['settings:summaryprompt'] = '每週摘要提示詞';
$string['settings:summaryprompt_desc'] = '連同本週的課程事實一起送給語言模型的指令。事實本身以 JSON 產生，不由這裡控制，因此這段文字能調整的是語氣、長度與輸出語言。留白即回到內建預設值。';
$string['summary:badgenoschedule'] = '本週無安排';
$string['summary:badgeversion'] = '第 {$a} 版';
$string['summary:bymodel'] = '模型';
$string['summary:fromsyllabus'] = '課綱版本';
$string['summary:generatedat'] = '生成於';
$string['summary:navtitle'] = '每週摘要';
$string['summary:none'] = '這門課還沒有每週摘要。課程有已確認的課綱、且排程任務跑過之後就會出現。';
$string['summary:noschedule'] = '本週無安排：課綱在這一週沒有列出主題，未來七日內也沒有到期的課程事件。';
$string['summary:pagetitle'] = '每週課程摘要';
$string['summary:weeklabel'] = '第 {$a} 週';
$string['syllabus:colfile'] = '檔案';
$string['syllabus:colparsed'] = '解析時間';
$string['syllabus:colstatus'] = '狀態';
$string['syllabus:coluploaded'] = '上傳時間';
$string['syllabus:colversion'] = '版本';
$string['syllabus:listheading'] = '已上傳的課綱';
$string['syllabus:none'] = '這門課還沒有上傳過課綱。';
$string['syllabus:status:confirmed'] = '已確認';
$string['syllabus:status:failed'] = '解析失敗';
$string['syllabus:status:parsed'] = '待確認';
$string['syllabus:status:uploaded'] = '等待解析';
$string['syllabus:versionlabel'] = '第 {$a} 版';
$string['targettype:course'] = '課程';
$string['targettype:event'] = '事件';
$string['targettype:syllabus'] = '課綱';
$string['targettype:user'] = '使用者';
$string['task:pruneops'] = '清除逾期的營運狀態紀錄';
$string['task:weeklysummary'] = '產生每週課程摘要';
$string['universityai:chat'] = '向課程助理提問';
$string['universityai:confirm'] = '確認課綱解析結果';
$string['universityai:populate'] = '依課綱填入課程資訊';
$string['universityai:syncown'] = '將自己的課程事件同步至外部行事曆';
$string['universityai:upload'] = '上傳課綱';
$string['universityai:viewops'] = '檢視營運狀態面板';
$string['universityai:viewsummary'] = '檢視每週課程摘要';
$string['upload:file'] = '課綱檔案';
$string['upload:formats'] = '支援格式：文字可選取的 PDF 與 DOCX。掃描影像、民國年日期與以圖片呈現的表格不在此版支援範圍。';
$string['upload:navtitle'] = '上傳課綱';
$string['upload:nofile'] = '沒有收到檔案。請選擇檔案後再送出。';
$string['upload:pagetitle'] = '上傳課綱';
$string['upload:queued'] = '課綱已收下，為第 {$a} 版，並已排入解析。排程任務跑完後可在本頁看到狀態。';
$string['upload:submit'] = '上傳並解析';
