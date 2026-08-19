已就目前的檔案樹、資料表、測試與文件實際盤點過，以下是統整。

階段進度
階段	內容	狀態
一	Docker 環境、local_universityai 骨架、資料表、排程任務、通知	✅
一‧五	aiprovider_claude 三項可行性閘門	✅ 結果記於 architecture §9.1
二	每週摘要 FR-SUM-01	✅
三	FR-SYL-01～04、FR-CRS-01/02、FR-CAL-01	✅
三（剩）	FR-CAL-02～04（iCal 訂閱、Google 寫入、同步紀錄）、FR-DIF-01～03（版本比對）	❌
四	FR-CHT-* 聊天助理、block_universityai	❌ 目前只有 .gitkeep
未動的還有 FR-NTF-01/02/04（老師／學生提醒）、FR-DSH-01/02（儀表板）、FR-AUT-02。

三個外掛
local_universityai — 主體，46 個非第三方 PHP 檔。分層依 architecture.md §2.2：

進入點：upload.php、review.php、populate.php、summary.php，每支都薄，邏輯在 service
抽取 classes/extraction/：schema（DM-SYLLABUS）、validator（自製 JSON Schema 驗證＋4 條資料不變式＋source_span 逐字核對）、text_extractor（PDF/DOCX）、configured_extractor（A-11 設定指名的接縫）、event_key（跨版本穩定鍵）
去識別化 classes/redaction/：ruleset / filter / filtered_payload，後者用私有建構子＋呼叫端檢查，讓「未過濾的字串」型別上進不了 AI 呼叫
AI 收口 classes/ai/text_client.php：A-13 的唯一出口，只吃 filtered_payload
服務：upload_service、confirmation_service、course_populator、syllabus_repository、summary/*
任務：task\weekly_summary（排程）、task\prune_ops（排程）、task\parse_syllabus（臨機）
aiprovider_claude — 12 個檔，同時支援 core_ai 的 generate_text/summarise_text 兩條路徑，與繞過子系統直接發帶 JSON Schema 請求的抽取路徑。

block_universityai — 尚未開始。

資料表（5 張，全部已被寫入過）
_syllabus、_evtmap、_diff、_summary、_ops。其中 _diff 是唯一還沒有任何程式寫進去過的表——這正是建議下一步做 FR-DIF-* 的理由。

可重跑的驗證

make selftest        安裝、資料表、排程、通知      13/13
make test            PHPUnit                       94 個測試方法
make lint            phpcs + behat 檔案檢查
make ai-verify       一‧五階段三項閘門（會呼叫 API）
make summary         每週摘要端到端（會呼叫 API）
make syllabus        課綱五段端到端（會呼叫 API）
make syllabus-gate   PDF 直送 vs 先抽文字對照（會呼叫 API）
make fixtures        產生 5 份合成課綱 PDF
make eval            解析正確率量測
make syllabus 的五段是：真實表單上傳 → Moodle 臨機任務執行器解析 → 讀回 → 真實表單確認 → 真實表單填入課程。每一段都走網頁路徑而非直接呼叫 Model，因為多數驗收條件講的是介面行為。

上次執行結果是 94 測試 / 428 斷言、selftest 13/13、五段全通過；本次盤點只做靜態清點，沒有重跑（會產生 API 費用）。

25 條設計決定
完整索引在 architecture.md §11。實作過程中真正改變做法的幾條：

A-13／A-14 — 外送過濾從第三階段提前到第二階段，因為 AI 收口點沒有過濾就先蓋起來的話，第二階段的摘要會直接違反 NFR-SEC-06
A-18 — 用 5 份固定樣本 × 3 種送法量測後才決定「先抽文字再送」；PDF 直送多花一倍 token，且放棄過濾與 source_span 核對
A-19 — PdfParser 的中文解碼缺陷（比例 → kÔO‹）刻意不修，因為日期正確率 100%、主題 83%，兩項驗收指標都沒被打到；等真實語料進來再重測
A-24 — 事件建成行事曆事件而非活動模組，避免「期中考」變成學生要繳交檔案的作業
A-25 — 填入課程由老師觸發；背景任務沒有人可以呈現覆寫清單、也沒有人可以點頭
明確的缺口
FR-SYL-02 的 ≥80% 正確率尚未在真實課綱上量測過——目前只有合成樣本。eval/corpus/ 與 eval/annotations/ 是 gitignore 的空目錄（OI-03）
去識別化規則的 Python 端還沒建，§6.2 第 3 點「兩邊分歧會被自動偵測」目前不成立
§8.2 的四項自訂靜態檢查（含「只有 text_client 能呼叫 process_action」）還沒寫成腳本
ops::record() 的 error 欄位仍不可寫（A-10）
SRS §10.1 對 NFR-SEC-06 的措辭要改成「共用同一份規則定義與驗證樣本」
工作區乾淨，全部內容都在 ff982f0。