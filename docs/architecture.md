# UniversityAI 系統架構

| 項目     | 內容                                                                 |
| -------- | -------------------------------------------------------------------- |
| 版本     | v1.0                                                                 |
| 狀態     | 待評審                                                               |
| 上游文件 | [SRS.md](SRS.md) v1.4                                                |
| 下游文件 | [bdd-guide.md](bdd-guide.md) v1.0                                    |
| 目標平台 | Moodle 5.2 / PHP 8.3–8.4（`D-09`、`A-01`）／PostgreSQL 16（`A-03`） |

## 0. 本文件的範圍與缺口

本文件回答「怎麼做」。每個設計決定都應能追溯到 SRS 的需求編號或 §2.2 的決策；若此處出現 SRS 沒有的功能，代表 SRS 漏寫，回頭補 SRS。

**§9 的 AI 呼叫設計已於 2026-08-18 補齊。** `D-05` 的兩條呼叫路徑原本只是讀 Moodle 5.2 原始碼推導的結論——讀原始碼能確定子系統不支援什麼，但確定不了自製供應商實際跑起來的行為。SRS §8.4 第一‧五階段的三項驗證已實跑並通過（見 §9.1），§9 的每一項決定都建立在那些量測上，而不是推論上。重跑方式：`make ai-verify`。

**§6 的外送資料過濾元件與 §9.7 的呼叫包裝層都已於第二階段建立**（`A-14`）。原訂第三階段才做，但第二階段的每週摘要就要呼叫語言模型，而 `A-13` 要求外送必經過濾——把摘要當成例外、或先建一個不做事的空過濾器，都會讓「已經有過濾了」變成假的，所以提前。Python 側的規則共用仍未接上，那是第三階段的事（§6.2 標明了這個空窗）。

**第三階段進行中。** 開場的閘門（§9.9 第 1 題：PDF 直送還是先抽文字）已於 2026-08-19 實測結案，見 §9.10 與 `A-18`。`FR-SYL-01`（上傳）與 `FR-SYL-02`（解析）已完成並實跑通過，見 §8.3.3。

`FR-SYL-01`～`FR-SYL-04`、`FR-CRS-01`、`FR-CRS-02` 與 `FR-CAL-01` 全部完成並實跑通過。`event_id` 的跨版本穩定性與 `_evtmap` 的冪等設計都在真實資料上驗到了。

**目前仍是空的**：`FR-CAL-02`～`FR-CAL-04`（訂閱與外部行事曆同步）、`FR-DIF-*`（版本比對）尚未建立；§8.2 的四條自訂靜態檢查尚未寫成腳本，由程式碼審查維持；`ops::record()` 的 `error` 欄位仍不開放寫入（`A-10`）；`OI-03` 的真實課綱測試集與人工標註尚未建立，因此 `FR-SYL-02` 的 ≥ 80% 正確率仍是未量測的數字。教師姓名的過濾有一個已知的邊界（§8.3.5）。

本文件新增的設計決定以 `A-nn` 編號，與 SRS 的 `D-nn` 區分：`D` 是需求層級的架構前提，變更需改 SRS；`A` 是實作層級的設計選擇，變更只需改本文件。

---

## 1. 外掛邊界與相依方向

### 1.1 三個外掛的職責

| 外掛                 | 職責                                                         | 不做什麼                                    |
| -------------------- | ------------------------------------------------------------ | ------------------------------------------- |
| `local_universityai` | 課綱的接收、解析編排、確認介面、課程填入、行事曆、通知、摘要、比對、營運狀態、排程與臨機任務、事件觀察者、站台設定 | 不直接呼叫任何 AI 服務的 HTTP 端點          |
| `block_universityai` | 課程頁面內的聊天區塊與其前端                                 | 不直接讀 `local_universityai` 的資料表       |
| `aiprovider_claude`  | 把 Claude API 包裝成 Moodle AI 供應商，並實作抽取介面        | 不知道課綱、課程、週次的存在                |

### 1.2 相依方向

```
        block_universityai
                 │
                 │ 只經公開介面（PHP API + external）
                 ▼
        local_universityai ──────► core_ai（Moodle AI 子系統）
                 │                        │
                 │ 抽取介面                │ generate_text / summarise_text
                 └────────────────────────┴──────► aiprovider_claude
```

三條規則，`NFR-MNT-02` 與 `NFR-MNT-06` 的可驗收形式：

1. **`aiprovider_claude` 不得引用另外兩個外掛的任何類別。** 它必須能單獨安裝在一個沒有 UniversityAI 的站台上正常運作——這是它能獨立上架的前提（`D-05` 的附帶價值）。
2. **`block_universityai` 不得存取 `local_universityai` 的資料表，也不得引用其 `classes/` 下的內部類別。** 只能呼叫 `local_universityai\api` 這個明確的門面類別。理由：區塊是使用者可自行加入課程頁面的元件，它的生命週期與核心外掛不同，直接耦合會讓核心的任何重構都可能打破區塊。
3. **`local_universityai` 不得直接引用 `aiprovider_claude`。** 生成類經 `\core_ai\manager`，抽取類經自己定義的介面——供應商是介面的實作者，不是被點名的相依對象。這是 `NFR-EXT-03` 在程式碼層面的形式。

> `local_universityai\api` 是唯一的跨外掛介面。它薄，只做參數檢查與轉呼叫，不含業務邏輯——業務邏輯在 `classes/` 下的領域類別，門面只是收口。

### 1.3 為什麼區塊不直接讀表

會有人問：都在同一個專案裡，多一層門面是不是形式主義。不是。

`block_universityai` 依 `D-06` 執行於課程頁面的區塊脈絡中，它的每一次請求都必須先做 `require_login($course)` 與課程層級的能力檢查（`FR-CHT-03`）。若它能直接下 SQL，那麼「檢索範圍限定於當前課程脈絡」這條約束就散落在區塊的每一個查詢裡，任何一處寫漏就是越權。收口到門面之後，範圍限制只需在門面的入口實作一次，並且能被單一測試覆蓋。

---

## 2. 分層落點（MVVM）

依 `D-08`，MVVM 對應到 Moodle Output API 既有機制，不引入框架。

### 2.1 `local_universityai` 的目錄結構

```
local_universityai/
├── version.php
├── settings.php                       進入點：站台設定頁
├── lib.php                            回呼：課程導覽與檔案輸出（FR-SYL-01）
├── index.php                          進入點：課程內的課綱總覽
├── upload.php                         進入點：上傳（FR-SYL-01）
├── review.php                         進入點：確認與逐項修正（FR-SYL-03、04）
├── populate.php                       進入點：填入課程（FR-CRS-*、FR-CAL-01）
├── summary.php                        進入點：每週摘要（FR-SUM-01）
├── ops.php                            進入點：營運狀態（FR-DSH-02）
├── redaction-rules.json               外送過濾規則，與 Python 端共用（§6.2）
├── redaction-samples.json             過濾的黃金樣本，兩邊跑同一組
├── db/
│   ├── install.xml                    資料表定義（§3）
│   ├── access.php                     能力定義（§5）
│   ├── tasks.php                      排程任務註冊（§4）
│   ├── messages.php                   訊息提供者註冊（§4.7）
│   ├── events.php                     事件觀察者註冊（§4.3）
│   └── upgrade.php
├── classes/
│   ├── api.php                        門面（§1.2）
│   ├── ops.php                        Model：營運狀態紀錄（§3.2）
│   ├── notifier.php                   Model：通知送出點（§4.7）
│   ├── syllabus.php                   Model：\core\persistent
│   ├── syllabus_repository.php        Model：版本查詢
│   ├── diff_engine.php                Model：純函式比對（FR-DIF-01）
│   ├── course_populator.php           Model：填課程、建章節、建事件（FR-CRS-*、FR-CAL-01）
│   ├── ical_builder.php               Model：訂閱內容（FR-CAL-02）
│   ├── redaction/
│   │   ├── filter.php                 Model：外送過濾（NFR-SEC-06，§6）
│   │   ├── filtered_payload.php       Model：已過濾文字的型別（§6.1）
│   │   └── ruleset.php                Model：規則載入
│   ├── ai/
│   │   └── text_client.php            Model：生成類呼叫的唯一收口（A-13）
│   ├── summary/
│   │   ├── context_builder.php        Model：蒐集事實（FR-SUM-01）
│   │   ├── generator.php              Model：事實 → 提示詞 → 文字
│   │   ├── repository.php             Model：摘要的保存與查詢
│   │   └── service.php                Model：單一課程的摘要流程
│   ├── extraction/
│   │   ├── schema.php                 Model：DM-SYLLABUS 的 JSON Schema
│   │   ├── validator.php              Model：schema 驗證與四條資料不變式
│   │   ├── text_extractor.php         Model：PDF/DOCX 抽文字（A-18、A-19）
│   │   ├── no_text_layer_exception.php
│   │   ├── unsupported_format_exception.php
│   │   ├── extractor_interface.php    抽取介面（§9.3，待補）
│   │   └── configured_extractor.php   轉接：讀設定動態建立（A-11，待補）
│   ├── event/                         進入點：自訂事件
│   ├── observer.php                   進入點：事件觀察者
│   ├── task/                          進入點：排程與臨機任務
│   ├── external/                      進入點：AJAX
│   ├── privacy/provider.php           NFR-SEC-07
│   └── output/
│       ├── renderer.php               plugin_renderer_base
│       ├── review_page.php            ViewModel：renderable + templatable
│       ├── syllabus_list.php          ViewModel
│       └── ops_dashboard.php          ViewModel
├── templates/
│   ├── review_page.mustache           View
│   ├── syllabus_list.mustache         View
│   └── ops_dashboard.mustache         View
├── amd/src/review.js                  確認介面的前端互動
├── thirdparty/pdfparser/              併入的第三方程式庫（A-19）
├── thirdpartylibs.xml                 第三方程式庫宣告（NFR-MNT-04）
├── lang/en/local_universityai.php     字串（NFR-MNT-04）
└── tests/                             見 bdd-guide §4
```

上表是完成形。目前實際存在的是：

| 階段 | 新增 |
| --- | --- |
| 第一階段 | `version.php`、`settings.php`、`db/{install.xml,upgrade.php,access.php,tasks.php,messages.php}`、`classes/{ops.php,notifier.php}`、`classes/task/prune_ops.php`、兩份語言檔、`tests/` 下四組測試 |
| 第一‧五階段 | 整個 `aiprovider_claude`（§2.4）。`local_universityai` 未動 |
| 第二階段 | `lib.php`、`summary.php`、`redaction-{rules,samples}.json`、`classes/redaction/*`、`classes/ai/text_client.php`、`classes/summary/*`、`classes/syllabus_repository.php`、`classes/output/{renderer,summary_page}.php`、`templates/summary_page.mustache`、`classes/task/weekly_summary.php`、`tests/` 下五組測試 |
| 第三階段（進行中） | `upload.php`、`review.php`、`classes/{upload_service,confirmation_service}.php`、`classes/form/{upload_form,review_form}.php`、`classes/extraction/*`（schema、validator、text_extractor、兩個例外、介面與 `configured_extractor`、`event_key`）、`classes/event/syllabus_uploaded.php`、`classes/observer.php`、`db/events.php`、`classes/task/parse_syllabus.php`、`classes/event/syllabus_confirmed.php`、`classes/output/syllabus_list.php` 與其模板、`thirdparty/pdfparser/`、`thirdpartylibs.xml`、`tests/` 下四組測試。`populate.php`、`classes/course_populator.php`、`classes/form/populate_form.php`。**行事曆訂閱（`FR-CAL-02`～`04`）與比對（`FR-DIF-*`）尚未建立** |

其餘於各自的階段建立。**`classes/redaction/` 原訂第三階段建立，第二階段就必須提前**——理由見 `A-14`。

### 2.2 各層的硬規則

| 層         | 落點                                     | 規則                                                         |
| ---------- | ---------------------------------------- | ------------------------------------------------------------ |
| Model      | `classes/` 下除 `output/` 以外           | **不得 `use` 任何 `output`、`renderer`、`moodle_url`、`html_writer` 類別**。不得回傳含 HTML 的字串 |
| ViewModel  | `classes/output/*.php`                   | 實作 `renderable` 與 `templatable`。`export_for_template()` 只回傳 `stdClass`、陣列與純量，**不得回傳物件實例** |
| View       | `templates/*.mustache`                   | 不得含業務判斷。條件顯示所需的布林值一律由 ViewModel 事先算好 |
| 進入點     | 頁面腳本、`classes/external/`、`classes/task/`、`observer.php` | 接收請求 → 檢查權限 → 呼叫 Model → 交給 renderer。**不得含業務邏輯** |

`NFR-MNT-06` 的驗收就是這張表的前三列，可用靜態檢查執行（§8.4）。

**一個具體例子。** 確認介面要把信心值低於門檻的項目標紅（`FR-SYL-03`）。門檻的比較屬業務規則，做在 Model；ViewModel 的 `export_for_template()` 輸出的是 `['weekno' => 3, 'topic' => '…', 'lowconfidence' => true]`；模板寫 `{{#lowconfidence}}class="text-danger"{{/lowconfidence}}`。模板裡不得出現 `{{#confidence}}` 與數值比較——Mustache 也做不到，這正是無邏輯模板的價值。

### 2.3 `block_universityai` 與唯一真正的資料繫結

```
block_universityai/
├── block_universityai.php             進入點：區塊定義，get_content()
├── classes/
│   ├── output/chat_block.php          ViewModel：初始狀態
│   └── external/ask.php               進入點：AJAX，權限檢查在此
├── templates/
│   ├── chat.mustache                  View：外框與初始骨架
│   └── message.mustache               View：單則訊息（供前端重繪）
└── amd/src/chat.js                    前端 ViewModel
```

`chat.js` 持有對話狀態（訊息陣列、載入中旗標、錯誤狀態）作為視圖模型，狀態改變時以 `Templates.render('block_universityai/message', data)` 重繪片段並插入 DOM。

**這是全系統唯一符合 MVVM 嚴格定義的部分**（`D-08` 的落差說明）。伺服器端的 Mustache 是一次性渲染，沒有繫結；口試說明時應照此區分，不要籠統宣稱整個系統是 MVVM。

`ask.php` 是安全邊界：每次請求重新驗證登入、課程脈絡與 `local/universityai:chat` 能力，**前端傳入的課程識別碼一律重新解析為脈絡後檢查，不採信**（`FR-CHT-03`）。

---

### 2.4 `aiprovider_claude`

```
aiprovider_claude/
├── version.php
├── db/hooks.php                       供應商設定表單的掛鉤註冊
├── classes/
│   ├── provider.php                   core_ai\provider 的實作：動作清單、驗證標頭
│   ├── hook_listener.php              進入點：在設定表單上加金鑰與端點欄位
│   ├── abstract_processor.php         Anthropic Messages API 的請求與回應處理
│   ├── process_generate_text.php      進入點：generate_text 動作
│   ├── process_summarise_text.php     進入點：summarise_text 動作
│   ├── extractor.php                  結構化抽取（D-05 第二條路徑，§9.2）
│   ├── form/action_generate_text_form.php
│   └── privacy/provider.php
└── lang/{en,zh_tw}/aiprovider_claude.php
```

三件與核心既有供應商刻意不同的地方：

1. **不做 model chooser。** 核心供應商用「下拉選單 + AMD 模組 + 每個模型一個類別 + 自訂參數 JSON」讓管理員選模型。這裡只給一個文字欄位填模型識別。模型清單變動很快，維護一份會過期的硬編碼清單不划算；而站台管理員本來就知道自己要用哪個模型。
2. **只宣告 `generate_text` 與 `summarise_text` 兩個動作。** `explain_text` 與 `generate_image` 宣告了就得維護，而 SRS 沒有任何需求用到它們。
3. **`extractor.php` 不經 AI 子系統。** 理由見 §9.2，這是 `D-05` 兩條路徑中的第二條。

移植時最容易撞到的三個 Anthropic 與 OpenAI 相容 API 的差異，都寫在對應的程式碼註解裡：驗證標頭是 `x-api-key` 而非 `Authorization: Bearer`；`max_tokens` 是必填，缺了直接 400；`system` 是頂層參數而非 `messages` 裡角色為 system 的訊息。回應的 `content` 是區塊陣列而非字串，直接讀 `content[0].text` 在開了思考的模型上會拿到錯的東西。


## 3. 資料表設計

### 3.1 `A-02`　課綱結構以整份 JSON 存放，另抽出事件對應表

這是本文件最大的一個設計決定。

**決定**：`DM-SYLLABUS` 的完整結構以 JSON 字串存於單一 `text` 欄位，一版一列；不把 `weeks[]` 與 `events[]` 正規化成子表。唯一的例外是「課綱事件 ↔ Moodle 事件」的對應關係，抽成獨立資料表。

**理由**

1. **XMLDB 沒有 JSON 型別。** 可用的欄位型別只有 `INTEGER`、`NUMBER`、`FLOAT`、`CHAR`、`TEXT`、`BINARY`。也就是說「存成 JSON 但用 SQL 查裡面」這條中間路線在 Moodle 的資料庫抽象層下根本不存在——真要跨資料庫可攜，就只能整份讀出來在 PHP 端處理。這一點直接砍掉了半數選項。
2. **SRS 的存取型態全是整份讀寫。** 解析寫入一整份、確認讀一整份、比對讀兩份。沒有任何需求需要「找出所有在某日有考試的課程」這類跨課綱的欄位查詢。為不存在的查詢付正規化的代價不划算。
3. **正規化付了成本卻拿不到好處。** 拆成 `weeks` 與 `events` 兩張子表後，版本歷程變成每上傳一版就複製全部子列；而 `FR-DIF-01` 的逐欄位比對仍然得把兩個版本各自組回完整物件再比——`DM-DIFF` 的 `path` 欄位（如 `weeks[3].topic`）本來就是文件路徑而非資料表座標。
4. **JSON 存放讓版本不可變。** 一列即一版，確認後不再修改（老師的修改產生新的內容但仍寫在同一版的 payload 內，見 §3.3），比對永遠有完整的兩份可讀。

**為什麼事件對應必須是例外**：`FR-CAL-01` 要求重複執行不產生重複活動（`NFR-REL-04`），這需要一份「這個課綱事件已經對應到哪個 Moodle 事件」的持久紀錄，而且必須**跨課綱版本存活**——第 2 版重新確認時，第 1 版建立的期中考事件應該被更新而不是再建一個。這個對應不是課綱的一部分，它是系統狀態，放進 payload 會讓 payload 不再是「解析結果」。

**若推翻**：改為正規化，`diff_engine` 需重寫為 SQL 比對，版本歷程需另設保存策略，且 `DM-DIFF` 的 `path` 語意須重新定義。

### 3.2 資料表

命名受一個實際限制影響：Moodle 由資料表名衍生索引與鍵的名稱並截斷至 30 字元（`sql_generator::$names_max_length`），而外掛的必要前綴 `local_universityai_` 就佔了 19 字元。後綴因此刻意取短——`_ops` 而非 `_opstatus`。截斷本身不影響正確性（Moodle 會自動加序號消歧），但名稱會變得難讀。

#### `local_universityai_syllabus`

一列一個課綱版本。對應 `DM-SYLLABUS`。

| 欄位             | 型別      | 說明                                             |
| ---------------- | --------- | ------------------------------------------------ |
| `id`             | int(10)   | 主鍵                                             |
| `courseid`       | int(10)   | Moodle 課程 id，上傳時即繫結（`D-10`）           |
| `version`        | int(10)   | 同一課程內單調遞增                               |
| `status`         | char(20)  | `parsed` / `confirmed`（`FR-SYL-04`）            |
| `payload`        | text      | `DM-SYLLABUS` 全文 JSON                          |
| `contenthash`    | char(40)  | 原始檔案的 Moodle 檔案 contenthash               |
| `model`          | char(100) | 產生此版本的模型識別（`NFR-MNT-03`）             |
| `parsedat`       | int(10)   |                                                  |
| `confirmedby`    | int(10)   | 使用者 id（`FR-SYL-04` 要求記錄確認者）          |
| `confirmedat`    | int(10)   |                                                  |
| `usermodified`   | int(10)   | `\core\persistent` 慣例                          |
| `timecreated`    | int(10)   |                                                  |
| `timemodified`   | int(10)   |                                                  |

唯一鍵 `(courseid, version)`；索引 `(courseid, status)`——「取這門課的已確認課綱」是最高頻查詢。

#### `local_universityai_evtmap`

課綱事件與 Moodle 事件的持久對應（§3.1 的例外）。

| 欄位            | 型別     | 說明                                        |
| --------------- | -------- | ------------------------------------------- |
| `id`            | int(10)  |                                             |
| `courseid`      | int(10)  | 刻意鍵在課程而非課綱版本，以跨版本存活      |
| `eventkey`      | char(64) | `DM-SYLLABUS` 的 `events[].event_id`        |
| `moodleeventid` | int(10)  | Moodle 端的識別碼                           |
| `targettype`    | char(20) | `event` / `cm`                              |
| `timecreated`   | int(10)  |                                             |
| `timemodified`  | int(10)  |                                             |

唯一鍵 `(courseid, eventkey)`。這個唯一鍵就是冪等性的實作方式——建立前先查，有則更新，無則新增。

> `eventkey` 的穩定性是這張表能運作的前提：同一場期中考在第 1 版與第 2 版必須產生相同的 `event_id`。它不能用隨機值，須由事件的穩定特徵推導。具體推導規則屬抽取邏輯的一部分，於 §9 定案；在此先標明這是一個**必須被滿足的約束**，不是實作細節。

#### `local_universityai_diff`

對應 `DM-DIFF`。

| 欄位          | 型別    | 說明                                                   |
| ------------- | ------- | ------------------------------------------------------ |
| `id`          | int(10) |                                                        |
| `courseid`    | int(10) |                                                        |
| `fromversion` | int(10) |                                                        |
| `toversion`   | int(10) |                                                        |
| `changes`     | text    | `DM-DIFF.changes` 的 JSON                              |
| `summarytext` | text    | `FR-DIF-03`；比對結果為空時為 null 而非空字串          |
| `hasmajor`    | int(1)  | 是否含 `major` 變更，供 `FR-NTF-04` 判定通知對象       |
| `timecreated` | int(10) |                                                        |

唯一鍵 `(courseid, fromversion, toversion)`。

`hasmajor` 是刻意的反正規化：`FR-NTF-04` 每次都要判斷「是否通知學生」，若不存這個旗標就得每次解析整份 `changes` JSON。它由 `diff_engine` 在寫入時一併算出，不得由其他地方寫入。

#### `local_universityai_summary`

一列一則每週摘要（`FR-SUM-01`）。SRS §5 沒有對應的資料模型——摘要在 SRS 中只是一項功能需求，這張表是第二階段實作時才長出來的。

| 欄位              | 型別      | 說明                                                   |
| ----------------- | --------- | ------------------------------------------------------ |
| `id`              | int(10)   |                                                        |
| `courseid`        | int(10)   |                                                        |
| `weekstart`       | int(10)   | **教學週**起日的時間戳，由課綱的 `term.start_date` 推算 |
| `weekno`          | int(10)   | 第幾教學週，顯示用；權威值是 `weekstart`                |
| `version`         | int(10)   | 同一 `(courseid, weekstart)` 內遞增                     |
| `syllabusversion` | int(10)   | 來源課綱版本（`NFR-MNT-03`）                            |
| `status`          | char(20)  | `generated` / `noschedule`                              |
| `content`         | text      | 供師生檢視的摘要全文                                    |
| `facts`           | text      | 產生當下的輸入事實 JSON                                 |
| `model`           | char(100) | 產生此摘要的模型（`NFR-MNT-03`）；`noschedule` 時為空   |
| `generatedat`     | int(10)   |                                                        |
| `timecreated`     | int(10)   |                                                        |

唯一鍵 `(courseid, weekstart, version)`；索引 `(courseid, weekstart)`。

三件事值得說明，前後兩件合起來是 **`A-17`　冪等鍵為教學週而非日曆週，且版本不覆蓋**：

**`weekstart` 是教學週而不是日曆週。** 冪等性（`NFR-REL-04`）鍵在這一欄，所以它的定義決定了「同一週」是什麼意思。兩門開學日不同的課在同一天本來就落在不同的教學週，用日曆週會把它們混成同一週；而「第 3 週」是課程的概念，不是日曆的概念。

**`facts` 是刻意的重複儲存。** 它與 `content` 的資訊有重疊，但摘要事後看起來不對時，唯一能回答「是模型寫歪了還是課綱本來就錯」的東西就是這一欄——重跑一次拿不回當時的輸入。`NFR-MNT-03` 要求可回溯至來源課綱版本與模型，這一欄是把「可回溯」做到底。

**版本不覆蓋。** 排程任務遇到已存在就跳過，永遠不會產生第 2 版；會走到的是刻意重新產生（改了提示詞想比較），此時舊版保留。這是 `FR-SUM-01`「保留版本與生成時間」的實質內容——只有一版的話「保留版本」沒有意義。

#### `local_universityai_ops`

對應 `DM-OPSTATUS`。

| 欄位         | 型別     | 說明                                                       |
| ------------ | -------- | ---------------------------------------------------------- |
| `id`         | int(10)  |                                                            |
| `operation`  | char(30) | `syllabus_parse` / `course_populate` / `event_create` / `calendar_push` / `weekly_summary` / `notify` |
| `targettype` | char(20) | `syllabus` / `course` / `event` / `user`                   |
| `targetid`   | char(64) |                                                            |
| `status`     | char(20) | `success` / `failed` / `retrying`                          |
| `attempt`    | int(4)   | 預設 1                                                     |
| `error`      | text     | 失敗原因；**寫入前須經 §6 的過濾**，錯誤訊息常夾帶請求內容 |
| `occurredat` | int(10)  |                                                            |

索引 `(operation, occurredat)`（面板依類型看最近狀況）與 `(targettype, targetid)`（追某一份課綱的歷程）。

### 3.3 老師修改與版本的關係

`FR-SYL-04` 允許老師逐項修改，`FR-DIF-02` 要求修改過的欄位不被新版覆蓋。實作方式：

老師的修改**寫回同一版的 `payload`**，並把該項目的 `overridden` 設為 `true`——`DM-SYLLABUS` 已為此保留欄位。不另開版本，因為版本的語意是「第幾次上傳」（SRS §1.6），老師改一個字就跳版會讓比對變得沒有意義。

新版上傳並解析後，`diff_engine` 讀取前一版中所有 `overridden` 為 `true` 的路徑，在 `DM-DIFF` 的對應項目標記 `was_overridden`，由介面要求老師逐項決定（`FR-DIF-02`）。未決定前已確認課綱維持原值——也就是新版停在 `parsed` 狀態不會自動變成 `confirmed`。

### 3.4 `A-03`　資料庫採 PostgreSQL 16

Moodle 5.2 支援 PostgreSQL 16+、MariaDB 10.11+、MySQL 8.4+、MSSQL 2019+（Oracle 自 5.0 起不再支援）。

選 PostgreSQL 的理由是傾印與還原的工具鏈單純，而 `D-04` 的可攜性正是靠 `make db-dump` 產出的 SQL 檔達成。**這不是高風險決定**：因為 XMLDB 完全抽象掉了資料庫差異（§3.1 的第 1 點），換成 MariaDB 的成本僅止於 compose 的服務定義與兩個 Makefile 目標。若團隊對 MariaDB 較熟悉，換掉即可，不需修改任何外掛程式碼。

---

## 4. 任務與事件

### 4.1 切分原則

| 性質                                     | 用什麼   |
| ---------------------------------------- | -------- |
| 定時發生、與特定使用者動作無關           | 排程任務 |
| 由使用者動作觸發、耗時、不該阻塞請求     | 臨機任務 |
| 需要自動重試                             | 臨機任務 |

`FR-NTF-03` 的驗收條件已經釘死了一件事：**事件觀察者只負責排入任務，不在觀察者內執行耗時工作**。觀察者跑在觸發它的那個請求裡，在裡面做解析會讓上傳請求卡住三分鐘。

### 4.2 排程任務（`db/tasks.php`）

| 類別                       | 預設頻率     | 職責                                                         |
| -------------------------- | ------------ | ------------------------------------------------------------ |
| `task\weekly_summary`      | 每週一 06:00 | 為每門有已確認課綱的課程產生並發布摘要（`FR-SUM-01`）        |
| `task\reconcile`           | 每小時       | 補跑安全網：掃出「已解析但未產生比對」「已確認但未建立事件」的課綱，重新排入臨機任務 |
| `task\prune_ops`           | 每日 03:00   | 清除超過保留期的 `_ops` 紀錄                                 |

頻率一律可由站台管理員在 Moodle 的排程設定中調整（`FR-SUM-01` 驗收條件），程式中只給預設值。

`db/tasks.php` **只註冊真的存在的類別**。第一階段只有 `prune_ops`，`weekly_summary` 於第二階段加入，`reconcile` 於第三階段。先註冊佔位會讓排程頁面列出一個點下去就拋類別不存在的項目，而排程頁面是站台管理員判斷系統健康的地方，不該有假的項目。`make selftest` 逐一把註冊的類別取回來確認，反向擋住「註冊了卻找不到」。

**`A-04`　排程任務一律採游標分批，單次處理量有上限。**

`weekly_summary` 不得一次處理全站課程。設計為：以 `courseid` 為游標，單次處理不超過 `batchsize`（站台設定，預設 50）門課，處理完的游標存於外掛設定，下次自本次結束處記錄處續跑；一輪跑完後游標歸零。

理由有二。其一是逾時：PHP 的執行時間與記憶體都有限，全站兩百門課乘上一次語言模型呼叫必然撞牆。其二是 `NFR-REL-03` 的故障隔離——單一課程失敗不得使整個排程任務中止，所以每門課的處理各自包在 try/catch 內，失敗寫 `_ops` 後繼續下一門，而不是讓例外往上拋。

> 這個設計的代價是「每週摘要」實際上不保證在同一分鐘內全部發出。這是可接受的：摘要是週期性資訊，不是即時通知。

實作時補上的兩個細節：

- **游標在最後一批就歸零，不等下一次跑空。** 若嚴格照「一輪跑完後歸零」寫，最後一批的下一次排程會取回空清單、什麼都不做、才歸零——多一次空轉，而且排程記錄上會出現一則看不出意義的「沒有待處理的課程」。改成「本批數量小於 `batchsize` 即歸零」，行為相同而少一次空轉。
- **`get_config()` 缺席時的 0 陷阱。** 設定尚未寫入時 `get_config()` 回傳 `false`，轉成 int 是 0，而 0 對分批查詢的語意是「一門都不處理」——任務會安靜地什麼都不做並回報成功。`prune_ops` 撞過同一個坑，`weekly_summary` 沿用同樣的下限判斷，兩者都有測試釘住。

### 4.3 臨機任務

| 類別                     | 由誰排入                       | 職責                                     |
| ------------------------ | ------------------------------ | ---------------------------------------- |
| `task\parse_syllabus`    | `syllabus_uploaded` 觀察者     | 文字抽取 → 結構化抽取 → 寫入（`FR-SYL-02`） |
| ~~`task\populate_course`~~ | ~~`syllabus_confirmed` 觀察者~~ | **已改為由老師觸發，見 `A-25`**。`FR-CRS-01` 要求覆寫前先向老師呈現，背景任務沒有人可以呈現 |
| `task\generate_diff`     | `parse_syllabus` 完成時        | 與前一版比對（`FR-DIF-01`）              |
| `task\push_calendar`     | 事件變動時、授權完成時         | 推送至 Google（`FR-CAL-03`）             |

### 4.4 重試與併行——用 Moodle 既有機制，不自己造

`\core\task\adhoc_task` 已提供本專案需要的全部機制：

| 需求          | 對應機制                                                     |
| ------------- | ------------------------------------------------------------ |
| `NFR-REL-01`  | `set_attempts_available()` 限定重試次數（設為 3）；失敗延遲由 `get_fail_delay()` / `set_fail_delay()` 遞增，Moodle 在 `manager::adhoc_task_failed()` 中自動推進 |
| `NFR-REL-02`  | 重試耗盡後於任務內寫入 `_ops` 失敗紀錄並通知管理員           |
| `NFR-PRF-03`  | `get_concurrency_limit()` 控制同類任務的併行數               |
| `NFR-REL-04`  | 任務本身須冪等，見 §4.5                                      |

**不要自行實作重試迴圈。** 在 `execute()` 內 catch 後重跑，會讓 Moodle 的失敗延遲機制看不到失敗，`_ops` 的 `attempt` 也失去意義。正確做法是讓例外往上拋，由 Moodle 決定何時重跑。

### 4.5 冪等性的實作位置

`NFR-REL-04` 要求任一任務重跑不產生重複的課程、活動或通知。三處各有做法：

- **課程與章節**：`course_populator` 以「目標狀態」寫入而非「新增」——章節依 `week_no` 對位更新，數量不足才新增，**且不刪除多餘的章節**（老師可能自己加了章節放補充教材）。
- **課程事件**：靠 `_evtmap` 的唯一鍵（§3.2）。
- **通知**：`FR-NTF-02` 的去重需要一份「已對誰發過什麼」的紀錄。以 `_ops` 中 `operation='notify'` 的既有紀錄作為去重依據，查詢條件為 `(targettype, targetid)` 加通知類型——這正是該索引存在的第二個理由。

### 4.6 事件觀察者（`db/events.php`）

| 事件                                    | 來源     | 動作                                            |
| --------------------------------------- | -------- | ----------------------------------------------- |
| `\local_universityai\event\syllabus_uploaded` | 自訂 | 排入 `parse_syllabus`                           |
| `\local_universityai\event\syllabus_confirmed` | 自訂 | **目前無觀察者**——填入課程改由老師觸發（`A-25`）。事件本身仍然觸發，因為 `NFR-SEC-04` 需要那筆操作紀錄 |
| `\core\event\user_enrolment_deleted`    | 核心     | 停止對該使用者的行事曆推送與通知（`FR-AUT-02`） |
| `\core\event\course_deleted`            | 核心     | 清除該課程的外掛資料（`NFR-SEC-07` 的一部分）   |

自訂事件同時滿足 `NFR-SEC-04` 的操作紀錄要求——Moodle 事件會自動進入標準記錄檔，含操作者與時間，且一般使用者無法刪改。**不要另建一套稽核表**。

### 4.7 通知（`db/messages.php` 與 `classes/notifier.php`）

通知一律走 Moodle 既有的訊息機制，不自建寄信程式碼。這樣使用者的通知偏好、免打擾時段與訊息中心全部免費繼承（`FR-NTF-01`）。

**`A-10`　通知內容只由語言字串與結構化參數組成，不接受自由文字。**

`notifier` 的方法簽章刻意不收字串訊息。最自然的寫法是把例外訊息塞進通知裡，而例外訊息經常夾帶請求內容——課綱原文、老師的聯絡方式都可能在其中，那等於繞過 `NFR-SEC-06`。通知只說「哪個作業對哪個對象失敗了」，細節留在 `_ops`，由管理者到站台內查看。`tests/notifier_test.php` 以反射檢查參數名單，加了自由文字參數就會紅。

同一個決定順帶解決了 `_ops.error` 的問題：§6 的外送過濾在第三階段才建立，在那之前 `ops::record()` 不開放寫入 `error`，而不是留一個「記得之後要加過濾」的註解。

> 這條規則的代價是：管理者從通知本身看不出失敗原因，一定得進站台。這是刻意的取捨——通知會離開系統邊界（寄到信箱、推到手機），`_ops` 不會。

### 4.8 一個必須實測才會發現的寄信陷阱

Moodle 的 `email_to_user()` 對結尾為 `.invalid` 的收件位址**直接放棄寄送，然後 `return true`**（`lib/moodlelib.php`，註解引用 RFC 2606）。也就是說 `message_send()` 回報成功、站台內的訊息中心看得到那則通知、記錄檔一切正常——信卻永遠不會寄出，而且沒有任何錯誤。

第一階段的自我檢查第一次執行時就撞到這個：`.env.example` 的預設管理員信箱是 `admin@example.invalid`。修正有三處，缺一不可：

1. `.env.example` 改用 `.example` 網域（同樣是 RFC 2606 保留、不會真的路由，但不在 Moodle 的封鎖清單中）。
2. `make install` 每次都把管理員帳號的信箱對回 `.env`。只改範本對已經裝好的站台沒有作用，而資料庫是留存的。
3. `make selftest` 改為查詢信件攔截服務的 API，確認信箱數真的增加，而不是停在 `message_send()` 的回傳值。

第 3 點是這件事真正的教訓，與 `A-09` 那次量到 1.5 KB 重導向頁是同一類錯誤：**驗證要驗到最終的收件端，不要驗中間層的回傳值。**

---

## 5. 權限模型

### 5.1 自訂能力（`db/access.php`）

| 能力                            | 脈絡    | 預設授予                      | 用於                       |
| ------------------------------- | ------- | ----------------------------- | -------------------------- |
| `local/universityai:upload`     | COURSE  | `editingteacher`、`manager`   | `FR-SYL-01`                |
| `local/universityai:confirm`    | COURSE  | `editingteacher`、`manager`   | `FR-SYL-04`                |
| `local/universityai:populate`   | COURSE  | `editingteacher`、`manager`   | `FR-CRS-01`、`FR-CRS-02`   |
| `local/universityai:viewsummary`| COURSE  | `student`、`editingteacher`   | `FR-SUM-01`                |
| `local/universityai:chat`       | COURSE  | `student`、`editingteacher`   | `FR-CHT-01`~`03`           |
| `local/universityai:syncown`    | USER    | `user`                        | `FR-CAL-03` 授權自己的行事曆 |
| `local/universityai:viewops`    | SYSTEM  | `manager`                     | `FR-DSH-02`                |

**沒有站台層級的「建立課程」能力**，這是 `D-10` 的直接結果：課程由 Moodle 既有流程建立，外掛不介入，因此不需要、也不應該擁有那個權限。

### 5.2 檢查點

`NFR-SEC-01` 要求所有進入點皆經登入與能力檢查。進入點只有四類，逐類的固定樣式：

| 進入點           | 樣式                                                         |
| ---------------- | ------------------------------------------------------------ |
| 頁面腳本         | `require_login($course)` → `require_capability(…, $coursecontext)` |
| `classes/external/` | `external_api::validate_context()` → `require_capability()`；**參數一律經 `validate_parameters()`**，不採信前端傳入的課程 id（`FR-CHT-03`） |
| 任務             | 不做能力檢查（無互動使用者），但**必須自行判定對象範圍**——通知對象一律由 Moodle 選課關係查得，不由任務參數指定 |
| 事件觀察者       | 同任務                                                       |

任務那一列是最容易出錯的：任務跑在無使用者的脈絡下，`has_capability()` 會用 admin 身分求值而全部通過。**任務內不得依賴能力檢查做範圍限制**，範圍必須由查詢本身限定。

---

## 6. 外送資料過濾元件

`NFR-SEC-06` 要求過濾是獨立且可單獨測試的元件，且所有外送路徑無繞過。

**`A-14`　過濾元件自第三階段提前到第二階段建立。**

原本的規劃是第三階段（課綱解析）才需要它。但第二階段的每週摘要就要呼叫語言模型，而 `A-13` 寫的是「凡是離開本站台的文字，都必須先經過 `redaction\filter::apply()`」——兩者直接衝突。

三個選項：把摘要的外送當成例外、先建一個不做事的空過濾器、或把過濾器提前。選第三個。前兩者的共同問題是**它們會讓「已經有過濾了」這件事變成假的**，而安全防線一旦被相信存在就不會有人再檢查；提前的代價只是把工作量往前挪，沒有任何一行程式碼因此白寫。

順帶把 §9.7 那句「`filter` 尚未實作，因此 `text_client` 也不建立」一併結案：兩者都在第二階段建立了。

### 6.1 位置與形狀

`classes/redaction/filter.php`，純函式，無 IO、無資料庫存取、無設定讀取（規則由建構子注入）。這讓它在 bdd-guide 的 L1 層可被完整覆蓋。

所有送往語言模型的內容都必須先經過它。收口的實作方式：**抽取介面與生成呼叫的包裝層都只接受 `filtered_payload` 型別的參數**，而 `filtered_payload` 只能由 `filter::apply()` 產生。

**這句話原本寫成「型別系統因此保證了無繞過路徑」，實作時發現只對了一半。** PHP 沒有 `friend` 之類的機制：私有建構子擋得住 `new`，擋不住別的類別呼叫那個公開的靜態工廠。型別只保證「呼叫端拿到的是一個 `filtered_payload`」，不保證「那個實例真的過濾過」。

補法是在工廠裡加一道執行期檢查，確認呼叫者確實是 `filter`，不是就拋 `coding_exception`；成本是每次外送一次深度 2、不含參數的 `debug_backtrace`，相對於一次語言模型呼叫可以忽略。`tests/redaction_filter_test.php` 直接嘗試偽造一個並斷言被擋下——執行期檢查只有在有測試釘住它的時候才算數。

所以準確的說法是：**型別擋住無心之過，執行期檢查擋住繞過，測試擋住這兩者被拿掉。**

### 6.1.1 規則的形狀

規則是資料而不是程式碼（§6.2），因此有兩件事必須在規則檔本身解決：

**第一，樣式不使用任何反斜線跳脫。** 不用 `\b`、`\d`、`\s`，改用 lookaround 與明列的字元類別。兩個理由疊在一起：PCRE 的 `\x{4e00}` 與 Python 的 `一` 不相容，而反斜線在 JSON 裡還要再跳脫一層——兩層疊加是最容易寫錯、又最難從檔案上看出來的地方。同理，`(?<=^|[ ，])` 這種變寬 lookbehind 在 PCRE 可行、在 Python `re` 是錯誤，一律改用等寬的否定 lookbehind。

**第二，規則有兩種，因為有些黑名單項目沒有樣式可比對。**

| 黑名單項目 | 怎麼處理 |
| --- | --- |
| Email、電話、身分證字號、學號、IP、地址、`userid=N`、API 金鑰 | 正規表示式 |
| 姓名 | **呼叫端以字面值傳入**。中文姓名沒有可靠的樣式，`context_builder` 從課綱的 `course.teacher` 取得後傳給過濾器 |
| 成績、作業內容 | **靠白名單，不靠這裡**。任何數字都可能是成績，寫進黑名單只會製造「已經處理了」的錯覺 |

第三列是這張表最重要的一列。過濾器是第二道防線；第一道是組裝提示詞的那一層只放進白名單欄位（見 `generator::to_whitelisted_facts()`）。

**反向樣本與正向樣本同等重要。** 過濾器最危險的失敗不是漏掉個資，而是把 `2025-09-23` 誤判成電話號碼——那會安靜地毀掉摘要內容，而且沒有人會發現。黃金樣本裡因此有兩組期望輸出等於輸入的樣本（課程週次列、課程代碼），台灣市話規則的區碼範圍 `0[2-8]` 正是為了避開日期裡的 `09` 才這樣寫的。

### 6.2 `A-05`　規則以資料共用，實作不共用

SRS 的 `NFR-SEC-06` 寫「與 `OI-03` 測試集去識別化共用同一份實作」。**這句話在跨語言的現實下無法照字面做到**：過濾在 PHP（產品），測試集去識別化在 Python（評測工具鏈，SRS §2.5）。

可行且達成同樣目的的形式是：

1. 規則定義為一份與語言無關的資料檔（`redaction-rules.json`），PHP 與 Python 各自讀取。
2. 一組共用的黃金樣本：輸入含個資的文字，與期望的過濾結果。PHP 端以 PHPUnit 驗、Python 端以其測試框架驗，**兩邊跑同一組樣本**。
3. 任一邊新增規則時必須同步新增樣本，否則另一邊會因未實作而測試失敗——這使不同步變成一個會被自動發現的錯誤，而不是靠人記得。

這比字面上的「同一份實作」更弱，但它是可執行的；字面版本不可執行。**此處與 SRS 的措辭有落差，已在此標明**，SRS 的該句應於下次修訂時改為「共用同一份規則定義與驗證樣本」。

**`A-15`　兩份資料檔放在外掛目錄下，不放專案根目錄。**

原文寫「置於專案根目錄」。實作時發現那樣行不通：外掛安裝到站台之後，`plugins/` 這個專案目錄根本不在它的視野裡，讀不到根目錄的檔案——而 `NFR-MNT-02` 要求三個外掛各自可獨立安裝。

改放 `plugins/local_universityai/redaction-{rules,samples}.json`。Python 端在專案目錄內執行（SRS §2.5 的評測工具鏈），由 compose 唯讀掛入外掛裡的同一份檔。**「單一來源」這個實質目的完全不受影響**，變的只是那個來源在哪裡。

> **搬移時差點造成兩份規則。** 專案根目錄本來就有一份第一階段建立的 `redaction-rules.json` 骨架，並由 compose 以唯讀掛載進所有容器。第二階段新建外掛裡那一份時沒有先查，結果一度同時存在兩份——**正好是 `A-05` 要防的那件事**。已合併為一份並刪除根目錄的舊檔，compose 的掛載改指向外掛內的檔案。
>
> 合併保留了舊骨架的結構（`allowlist` / `denylist` / `options`），那三段直接對應 SRS §6.3 的三份清單；規則本身用新版的可攜寫法，舊骨架裡的 `\b`、`\d`、`\.` 全部換掉。

**黑名單的每一個類別都要說出它是怎麼被處理的。** `denylist.categories` 的每一項帶一個 `handled_by`：

| 值 | 意思 |
| --- | --- |
| `pattern` | 由 `rules` 的正規表示式移除 |
| `literal` | 沒有可靠的樣式，由呼叫端把已知字面值傳進過濾器（姓名） |
| `allowlist` | 沒有樣式可比對，靠白名單根本不送出（成績、作業內容） |

`ruleset` 對這件事做**雙向**檢查：每條規則的類別必須宣告為 `pattern`，而每個宣告為 `pattern` 的類別必須至少有一條規則。少了任一半都會留下缺口——前者讓「規則擋的東西根本不在黑名單裡」不被發現，後者讓「整條規則被刪掉」不被發現，而黑名單看起來都還是完整的。

`grade` 與 `assignment_content` 的答案是 `allowlist`，這是刻意的，並有測試釘住。**「靠白名單」是一個明確的答案，不是漏掉**；反過來說，將來若有人補上一條「抓成績」的正規表示式，那條測試會先要求他解釋那件事怎麼可能做得到。

第二階段只建立了 PHP 這一側；Python 側於第三階段建立測試集去識別化時接上。**在那之前 §6.2 的第 3 點（任一邊新增規則會讓另一邊測試失敗）尚未生效**——現在只有一邊，不同步還不會被自動發現。這是一個已知的空窗，不是已經達成的保證。

### 6.3 錯誤訊息也要過濾

`_ops.error` 與記錄檔的內容須經同一元件。外部服務的錯誤回應經常把整段請求內容回貼在訊息裡，這是最容易漏掉的外送路徑——它不是「送出」，但它會把內容寫進記錄檔，而記錄檔會被匯出、被截圖、被貼進問題回報。

第二階段找到了第三條這樣的路徑：**排程任務的 `mtrace()`**。它的輸出會進 Moodle 的排程記錄並保存在資料庫裡，性質與記錄檔完全相同。`weekly_summary` 因此把所有要輸出的錯誤訊息都先過 `filter::redact()`。

`redact()` 回傳純字串而不是 `filtered_payload`，這是刻意的：這些路徑不外送，硬要求型別只會讓人為了滿足型別而繞路。要送給語言模型時一律用 `apply()`。

`ops::record()` 仍然不開放寫入 `error`（`A-10`）。過濾器已經存在，接上它是第三階段的事——第二階段沒有任何一條路徑需要寫入錯誤文字，現在接等於加一段沒有呼叫端、也沒有測試涵蓋的程式碼。

---

## 7. 執行環境

### 7.1 專案目錄佈局

```
UniversityAI/
├── Makefile                   唯一操作入口（SRS §2.5）
├── docker-compose.yml         Compose 規範現行的檔名為 compose.yaml，兩者等價
├── .env.example               連接埠、密碼等；實際的 .env 不進版控
├── redaction-rules.json       §6.2 的共用規則
├── docker/
│   ├── moodle/Dockerfile      web 與 tools 兩個 target，共用同一個 base
│   ├── moodle/php.ini         max_input_vars 等 Moodle 5.2 的硬性需求
│   ├── moodle/vhost.conf      document root 指向 public/
│   ├── moodle/entrypoint.sh   資料目錄的建立與權限
│   ├── config-extra.php       §7.4 的補充設定，注入 config.php
│   └── bin/                   全部實作腳本（§7.5）
├── docs/
├── plugins/                   ★ 版控的產品程式碼
│   ├── local_universityai/
│   ├── block_universityai/
│   └── aiprovider_claude/
├── eval/                      Python 評測工具鏈（uv）
│                              （Moodle 核心不在專案目錄下，見 A-08）
├── moodledata/                .gitignore（含老師上傳的課綱原檔）
├── behatdata/                 .gitignore
├── phpunitdata/               .gitignore
└── db/dumps/                  make db-dump 的輸出，.gitignore（保留 .gitkeep）
```

`plugins/` 與 `moodle/` 分開是關鍵：外掛是我們的程式碼，要版控；Moodle 本體是相依，不進版控，由 `make` 取得釘住的**標籤**（不是分支——分支會前進，釘它就達不到 `NFR-MNT-05`）。兩者在容器內才被組合起來。

### 7.2 掛載對應

| 專案目錄                     | 容器內路徑                                   |
| ---------------------------- | -------------------------------------------- |
| `plugins/local_universityai` | `/var/www/moodle/public/local/universityai`   |
| `plugins/block_universityai` | `/var/www/moodle/public/blocks/universityai`  |
| `plugins/aiprovider_claude`  | `/var/www/moodle/public/ai/provider/claude`   |
| （具名磁碟區 `moodlesrc`）   | `/var/www/moodle`（見 `A-08`）               |
| `moodledata/`                | `/var/www/moodledata`                        |
| `behatdata/`                 | `/var/www/behatdata`                         |
| `phpunitdata/`               | `/var/www/phpunitdata`                       |
| `db/dumps/`                  | `/dumps`                                     |
| `docker/config-extra.php`    | `/opt/uai/config-extra.php`（唯讀）           |
| `redaction-rules.json`       | `/opt/uai/redaction-rules.json`（唯讀）       |

`moodle` 與 `tools` 兩個服務掛載**完全相同**的清單，在 compose 中以 YAML 錨點共用定義。若讓兩者各自列一份，遲早會出現「網頁上正常、測試說找不到外掛」這種難查的落差。

**document root 是 `/var/www/moodle/public`，不是 `/var/www/moodle`**（`D-09`）。指錯的症狀是首頁丟出 `rootdirpublic` 例外——Moodle 根目錄的 `index.php` 就是為了攔截這個錯誤而存在的。

供應商外掛的目錄名是 **`claude`** 而非 `aiprovider_claude`：Moodle 的路徑慣例不含外掛類型前綴。

**只掛載已經有 `version.php` 的外掛。** Moodle 掃描外掛目錄時，掃到缺 `version.php` 的目錄會以 `detectedbrokenplugin` **中止整個安裝流程**，不是忽略它。因此 `block_universityai` 與 `aiprovider_claude` 的掛載先註解掉，於各自的階段（第四、第一‧五）解除。實測撞到過，而錯誤訊息只說某個外掛「毀損或是舊版」，看不出是空目錄造成的。

順帶一個相關的坑：**拿掉掛載不會刪除 Docker 先前建立的那個目錄**。目錄留在原處，下次安裝照樣以同一個錯誤失敗，得手動清掉。

### 7.2.2 `A-08`　Moodle 核心置於具名磁碟區

**決定**：Moodle 核心不掛在專案目錄下，改用具名磁碟區 `moodlesrc`。這是 `D-04`「掛載於專案目錄下」的第二個例外。

**理由是實測數據**。Windows 的 Docker bind mount 對大量小檔案讀取極慢：

| 讀取 400 個 PHP 檔 | 耗時     |
| ------------------ | -------- |
| bind mount         | 10.45 秒 |
| 容器內部檔案系統   | 0.00 秒  |

Moodle 每個請求要載入數百個核心檔案，放在 bind mount 上時頁面載入 **14 秒**，遠超過 `NFR-PRF-04` 要求的 2 秒。opcache 調校（`memory_consumption`、`max_accelerated_files`）與 `realpath_cache` 都試過，**完全沒有改善**——連 `opcache.validate_timestamps = 0` 也只從 13.9 秒降到 12.8 秒。瓶頸不是 PHP 的快取策略，是檔案系統本身。改用具名磁碟區後頁面載入降到 **0.52 秒**。

**可攜性不受影響**：Moodle 核心不是資料，也不是我們的程式碼，換機器時由 `make up` 重新取得 `MOODLE_TAG` 指定的版本，本來就不需要跟著搬。`D-04` 要搬的是專案目錄與資料庫傾印，兩者都不含核心。

**代價**：無法從主機直接瀏覽 Moodle 核心原始碼。對正在學 Moodle 外掛開發的人來說這是真的損失——讀核心程式是最有效的學習方式。兩個緩解方式：`make shell` 進容器後 grep，或把整個專案移到 WSL2 的檔案系統下（`\\wsl$\...` 而非 `D:\`），後者能同時解決 bind mount 的效能問題並保留主機可見性。

### 7.2.3 `A-09`　Moodle 的快取目錄一併移出 bind mount

`A-08` 解決了核心原始碼，但管理頁面仍然要 **7 秒**。追下去發現第二個同性質的瓶頸：`$CFG->localcachedir`、`$CFG->cachedir`、`$CFG->tempdir` 預設都在 `moodledata` 之下，而 `moodledata` 依 `D-04` 掛在專案目錄（裡面有老師上傳的課綱原檔，必須可攜）。

`moodledata` 的 bind mount 沒有核心那麼慢——每個檔案操作約 2.5 毫秒而非 26 毫秒——但管理頁面一次請求要碰數百個 MUC 快取檔與編譯後的 Mustache 模板，乘起來就是 7 秒。

**決定**：三個快取目錄指向具名磁碟區 `moodlecache`（掛於 `/var/www/moodlecache`），設定寫在 `docker/config-extra.php`，目錄由 entrypoint 建立。

| 頁面 | 修正前 | 修正後（暖） |
| ---- | ------ | ------------ |
| `/admin/search.php` | 7.2 秒 | 0.25 秒 |
| `/admin/plugins.php` | 8.2 秒 | 0.56 秒 |
| `/my/` | 1.8 秒 | 0.14 秒 |

與 `A-08` 同理，可攜性不受影響：這些是可重建的快取而非資料，換機器時 Moodle 自己重建。`moodledata` 本身**仍留在專案目錄下**，`D-04` 的可攜性要求不變。

#### 7.2.4 同一筆成本的第三次現身：容器啟動

`moodledata` 留在 bind mount 上是 `D-04` 的要求，所以那每項約 2.4 毫秒的成本並沒有消失，只是換了地方出現——這次在容器的進入點。

`entrypoint.sh` 原本對六個資料目錄一律做遞迴 `chown` 與 `chmod`，而這段跑在 `exec apache2-foreground` **之前**。實測：

| 目錄 | 項目數 | 遞迴 chown + chmod |
| --- | ---: | ---: |
| `moodledata`（bind mount） | 2444 | **5788 ms** |
| `phpunitdata`（bind mount） | 105 | 479 ms |
| `moodlecache/cache`（具名磁碟區） | 1068 | **6 ms** |
| 六個目錄合計 | — | 6280 ms |

也就是每次重建容器後有六秒鐘網站連不上，而且會隨 `moodledata` 成長而變慢。症狀完全不會讓人聯想到權限設定：`make up` 之後立刻 `make selftest`，拿到的是 `Failed to connect to 127.0.0.1 port 80`。

修正是**遞迴只在目錄由這次啟動建立時執行**。目錄已存在時，裡面的檔案是 Moodle 自己以 `www-data` 建的，擁有者本來就對；需要修的只有 bind mount 掛上來的那一層頂層目錄。改完後從重啟到 Apache 可連線降到 **0.5 秒**。

> 代價：從主機端手動複製進 `moodledata` 的檔案不會被自動修正擁有者。這是刻意的取捨，`entrypoint.sh` 裡寫了處理方式。

> **排查過程中的一個教訓**，記在這裡以免重犯：中途曾以 `curl` 量到 0.25 秒而誤判問題已解決，實際上那個回應只有 1.5 KB——是登入逾時的重導向頁，不是管理頁。之後所有量測都加上頁面大小檢查（完整的 `/admin/search.php` 約 246 KB）。**量效能時務必確認量到的是真的那個頁面。**
>
> 同一次排查也一度把 `$CFG->cachejs = false` 當成主因，實測後證實它對伺服器端時間幾乎無影響，只影響瀏覽器是否快取 JS。

### 7.2.1 CLI 腳本路徑的不對稱

這是 5.x 結構中最容易寫錯的一點，因為兩類腳本落在不同層級：

| 腳本                                        | 路徑                                       |
| ------------------------------------------- | ------------------------------------------ |
| 核心 CLI（`install`、`upgrade`、`cron`、`purge_caches`、`scheduled_task`、`adhoc_task`） | 專案根目錄的 `admin/cli/`（webroot 之外）  |
| Behat 與 PHPUnit 的工具 CLI（`init`、`run`） | `public/admin/tool/{behat,phpunit}/cli/`   |

根目錄的 `admin/` 只有 `cli/` 一個子目錄，`lib/setup.php` 則是轉發到 `public/lib/setup.php` 的遷移 shim——照舊版文件寫 `public/admin/cli/install.php` 會找不到檔案。

### 7.3 服務

| 服務       | 映像                     | 預設啟動 | 用途                                          |
| ---------- | ------------------------ | -------- | --------------------------------------------- |
| `moodle`   | PHP 8.3 + 網頁伺服器     | 是       | 跑 Moodle 與三個外掛                          |
| `db`       | PostgreSQL 16            | 是       | 資料存於**具名磁碟區**（`D-04` 的例外）       |
| `mail`     | 信件攔截                 | 是       | Moodle 的 SMTP 目標（`FR-NTF-01`）            |
| `tools`    | PHP + Composer + Node    | 是       | `moodle-plugin-ci`、PHPUnit、Behat 的執行環境 |
| `selenium` | 瀏覽器驅動               | 否（`test`） | Behat 的 `@javascript` 場景（bdd-guide §6）   |
| `eval`     | Python + uv              | 否（`eval`） | L3 評測（bdd-guide §2）                       |

**`A-07`　`selenium` 與 `eval` 以 Compose profile 排除在預設啟動之外。**

`selenium` 映像將近 2 GB，而 `NFR-MNT-05` 定義的展示流程只有 `make up` 與 `make install`——展示機永遠不需要瀏覽器容器，第一、二階段也還沒有 Behat 場景。把它留在預設集合中，代價是每台新機器第一次啟動都要多下載 2 GB，並多一個拉取失敗的機會（實測就撞到過 Docker Hub 的 TLS 握手逾時）。`eval` 同理，第三階段才用得到。

`make behat` 與 `make eval` 會各自以 `--profile` 把需要的服務拉起來，使用者不必知道 profile 的存在。

**層的順序決定改一行腳本的代價。** `docker/bin/` 下的實作腳本原本在 `base` 階段複製，看起來合理——兩個 target 都要用。實際後果是：改一行 shell 腳本會讓 `base` 之後的每一層失效，其中包括 `tools` 階段那個要下載整套相依的 `composer create-project moodle-plugin-ci`。

這不只是慢。實際發生過一次：改了一個 `uai-*.php` 之後，`make lint` 七項全部失敗，訊息是 `Failed to find Composer's autoload file`——因為那次重建時相依安裝失敗，而該指令後面接著 `|| echo`，錯誤被吞掉了，留下一個有原始碼、有 `bin/moodle-plugin-ci`、卻沒有 `vendor/` 的半套安裝。從錯誤訊息完全看不出跟映像建置有關。

改成**在 `web` 與 `tools` 各自的最後一層複製腳本**，兩邊各寫一次。實測改一行腳本後重建，`composer create-project` 那層顯示 `CACHED`。連帶修了兩件事：建置時檢查 `vendor/autoload.php` 是否真的存在，缺了就留下標記檔；`uai-lint.sh` 改為驗證該檔案而不只是驗證執行檔存在，並把這種情況視為**未通過**而非略過——「lint 全綠」不能是「其實沒跑」。

**全部映像一律釘版本，不用 `latest`。** 這與 `MOODLE_TAG` 釘標籤是同一個理由（`NFR-MNT-05`）：`latest` 會讓兩台機器在不同時間建出不同的環境，而環境差異造成的測試失敗是最難查的一類。

映像本身有一項與服務清單無關但同樣屬於環境前提的事：**容器內必須產生 `en_AU.UTF-8` 這個地區設定**。Moodle 的 PHPUnit 環境把它寫死在檢查裡，缺了會在 `init` 階段直接中止（`Required locale 'en_AU.UTF-8' is not installed.`），與站台語言無關，也不能靠設定繞過。站台語言用的 `zh_TW.UTF-8` 是另一個，兩個都要。

> AI 端點樁服務（bdd-guide §9.1）尚未決定形式，於 §9 定案。屆時也應評估是否同樣放進 `test` profile。

### 7.4 `config.php` 與補充設定

**`config.php` 位於專案根目錄 `/var/www/moodle/config.php`，在 document root 之外**——這是 5.x 新結構的安全性收穫，網頁伺服器無論如何都讀不到它。它由 `admin/cli/install.php` 產生，並被 `.gitignore` 忽略。

但被忽略的檔案裝不了必須可重現的設定（`NFR-MNT-05`）。因此 Behat、PHPUnit、寄信等設定寫在版控的 `docker/config-extra.php`，由 `make install` 在 `config.php` 中插入一行 `require_once` 載入。

插入位置是關鍵：`config.php` 以 `require_once(__DIR__ . '/lib/setup.php');` 結尾，**必須插在那一行之前**——`setup.php` 一旦載入就來不及設定了。實作見 `docker/bin/uai-install.sh`，該步驟冪等。

補充設定中最容易出錯的三項：

```php
// 必須是 selenium 容器解析得到的位址，而且是容器內部監聽的埠（80），
// 不是發布到主機的那個埠
$CFG->behat_wwwroot  = 'http://moodle';
$CFG->behat_dataroot = '/var/www/behatdata';
$CFG->behat_prefix   = 'bht_';
```

1. **`behat_wwwroot` 必須是 `selenium` 容器解析得到的位址。** 寫 `localhost` 會讓瀏覽器連到它自己容器內的 localhost，症狀是每個場景都逾時。
2. **`behat_dataroot`、`behat_prefix` 與 `phpunit_*` 必須與開發用的完全分開**，否則跑一次測試就清掉開發資料。
3. **資料表前綴上限為 10 字元**（Moodle 4.3 起）。`bht_` 沒問題，但別取長名。

另外兩項刻意寫在這裡而非後台：`$CFG->smtphosts = 'mail:1025'` 讓開發環境不可能不小心真的把信寄出去（在 `config.php` 設定會覆蓋資料庫值並在後台顯示為鎖定）；`$CFG->debug = 32767` 是 `DEBUG_DEVELOPER` 的數值——此處不能用該常數，它定義在尚未載入的 `setup.php` 中。

#### 7.4.1 `make test` 會改變網頁容器的行為

這是一個跨容器的耦合，第一次撞到時完全看不出關聯：**跑一次 `make test`，之後整個網站管理區就打不開了**，每頁都變成一頁 Whoops 錯誤，訊息是那個無害的 ICU locale 警告。

鏈路是這樣的：

1. `filp/whoops` 列在 Moodle 的 `composer.json` 的 **require-dev** 中。
2. `make test` 為了 PHPUnit 在 Moodle 根目錄執行 `composer install`（含 dev 相依），把它裝進 `vendor/`。
3. `vendor/` 位於具名磁碟區 `moodlesrc`（`A-08`），而該磁碟區同時掛給 `moodle` 與 `tools` 兩個容器——**測試是在 `tools` 裡跑的，受影響的卻是 `moodle`**。
4. `public/lib/setup.php` 有一段 `if (!property_exists($CFG, 'debug_developer_use_pretty_exceptions')) { … = true; }`，預設開啟。
5. 開啟後，開發模式下的每一次 `debugging()` 都被轉成 Whoops 頁面並**中止請求**。而 §7.3 的 ICU 警告每個管理頁面都會觸發。

修正是在 `config-extra.php` 關掉它。**該旗標只能由 `config.php` 設定**（後台沒有對應項目），而補充設定正是在 `lib/setup.php` 之前載入，剛好搶在那行預設值之前：

```php
$CFG->debug_developer_use_pretty_exceptions = false;
```

代價是真正的例外回到 Moodle 內建的錯誤畫面，沒有 Whoops 的堆疊瀏覽器。這個取捨很清楚：ICU 警告無法消除（見下），所以留著 Whoops 等於開發模式下網站不能用。

#### 7.4.2 那個 ICU locale 警告：三條已經實測排除的路

這行警告每個管理頁面都出現，而「網路上的標準答案」是錯的，所以把量測結果留在這裡，免得日後有人再走一遍。

**它不是 glibc 的問題，`locale-gen` 沒有用。** 最常見的建議是在伺服器上 `locale-gen zh_TW.UTF-8`——本專案的 Dockerfile 早就做了，`locale -a` 也確實列出 `zh_TW.utf8`，`setlocale(LC_ALL,'zh_TW.UTF-8')` 回傳成功，警告照樣出現。原因是這兩者是**兩套獨立的 locale 系統**：`locale-gen` 產生 glibc locale，影響 `setlocale()`；警告來自 **ICU**，它讀的是編進 `libicu` 的資料，不看 `/usr/lib/locale`。實測 ICU（76.1）根本沒有 `zh_TW` 這個 locale，它有的是 `zh_Hant_TW`。

**`$CFG->locale` 也沒有用。** `core_collator::ensure_collator_available()` 取的是 `get_string('locale', 'langconfig')`，即語言包裡的值，`$CFG->locale` 不經過這條路徑。

**唯一有效的槓桿是覆寫語言包的 `locale` 字串，但代價不可接受。** 逐個候選值實測（錯誤碼須在 `new Collator()` 後**立刻**讀取，否則會被重設為 0——Moodle 原始碼有註解交代，量測時踩過）：

| 請求字串 | ICU 錯誤碼 | `ACTUAL_LOCALE` | Moodle 判定 |
| --- | ---: | --- | --- |
| `zh_TW.UTF-8`（現行） | −128 | `zh@collation=stroke` | 警告 |
| `zh_TW` | −128 | `zh@collation=stroke` | 警告 |
| `zh_Hant_TW` | −128 | `zh@collation=stroke` | 警告 |
| `zh_Hant` | −128 | `zh@collation=stroke` | 警告 |
| `zh` | 0 | `zh` | 不警告 |
| `zh@collation=stroke` | 0 | `zh@collation=stroke` | 不警告 |

所有繁體中文變體都必定觸發，因為 ICU 一律回 `zh@collation=stroke`，而它永遠不可能是請求字串的前綴。兩個能消除警告的值各有代價：

- `zh` 把排序切成拼音序，實測 `["張三","李四","王五","陳六"]` 排出「陳六 李四 王五 張三」——錯的。
- `zh@collation=stroke` 排序正確（「王五 李四 張三 陳六」），但**全站日期會變成簡體中文慣例**：月份由「1月 2月」變成「一月 二月」，週幾由「週一」變成「周一」。原因是 `core_date::strftime()` 讀同一個字串餵給 `IntlDateFormatter`，而其原始碼註解明寫**刻意忽略 `$CFG->locale`**，因此無法分開設定。

**結論：接受這行警告。** ICU 選對了資料（`VALID_LOCALE` 是 `zh_Hant_TW`），排序結果正確，問題只在 Moodle 那個前綴比對不夠精確。用真實行為的退化換一句訊息不划算。

### 7.5 Makefile

**`A-06`　Makefile 的每一行 recipe 都是單一個 `docker compose` 呼叫，全部邏輯放在 `docker/bin/` 下的 POSIX sh 腳本裡。**

理由是 Windows 上的 GNU Make 會依環境選擇 `sh.exe` 或 `cmd.exe`，兩者的引號與跳脫規則不同。把 shell 迴圈或條件判斷寫進 Makefile，就得同時對兩套規則負責；推進容器則完全不必，且三種主機作業系統的行為完全一致。

這條規則不只是潔癖。第一版的 `clean-moodle` 曾經寫成 Makefile 裡的一行 `find ... -exec rm -rf`，而它有個真實的危險：對 bind mount 執行 `rm -rf` 的行為是**先刪掉裡面的檔案，最後才在刪除目錄本身時失敗**——一個看似安全的「刪不掉就算了」寫法會先把外掛原始碼刪光。移進 `uai-clean.sh` 之後才有空間以名稱明確排除那三個路徑，並寫下為什麼。

例外只有 `.env` 這條規則：`.env` 缺席時 Docker Compose 會以一句難懂的 `GetFileAttributesEx` 錯誤中止，因此由 Make 自己從 `.env.example` 補上並提醒改密碼。

SRS §2.5 已定下目標清單。幾個目標的實質內容：

| 目標               | 做什麼                                                       |
| ------------------ | ------------------------------------------------------------ |
| `make up`          | 起全部服務；首次執行時若 `moodle/` 不存在則取得釘住的 5.2 版本 |
| `make install`     | 跑 Moodle 的 CLI 安裝，安裝三個外掛，把管理員信箱對回 `.env`（§4.8），建立示範課程外殼（`D-10` 之後展示需要它） |
| `make selftest`    | 逐項驗證外掛裝好了、資料表建了、節點出現在管理樹中、兩個排程任務都註冊了、通知真的送到信件攔截服務，最後**以真實登入抓管理頁面與課程摘要頁回來**。跑完會把自己產生的紀錄與通知清乾淨（§8.4）。不呼叫 API，隨時可跑。失敗以非零狀態結束，可放進 CI |
| `make ai-verify`   | 第一·五階段的閘門：實跑 AI 可行性的三項驗證（§9.1）。會呼叫 API 並產生費用，`RUNS=n` 調次數 |
| `make summary`     | 第二階段的端到端實跑：植入示範課綱 → 跑 `weekly_summary` 排程 → 讀回產生的摘要。**植入那一步刻意包在裡面**——沒有課綱時排程任務會安靜地什麼都不做並回報成功，那種綠燈驗不到任何東西（§4.8）。會呼叫 API 並產生費用 |
| `make behat`       | **先重新產生 Behat 設定再執行**——新增 feature 檔後不重生會找不到新場景，這一步不能靠人記得（bdd-guide §10.1） |
| `make db-dump`     | `pg_dump` 至 `db/dumps/`，這是換機展示的搬移方式（`D-04`）   |
| `make db-restore`  | 自最新的傾印還原                                             |

主機端只需 Docker 與 GNU Make。**GNU Make 不隨 Windows 內建**，須以 winget 或 scoop 安裝——這是主機端唯一的額外安裝項目。

---

## 8. 品質防線

### 8.1 對應到 bdd-guide

測試怎麼寫、每條需求驗在哪一層，全部在 [bdd-guide.md](bdd-guide.md)，此處不重複。架構上只需保證一件事：**每一個設計元件都落在某一個可測試的邊界內**。

| 元件                  | 為什麼可測                                        |
| --------------------- | ------------------------------------------------- |
| `diff_engine`         | 純函式，輸入兩個 payload 輸出 changes             |
| `redaction\filter`    | 純函式，無 IO（§6.1）                             |
| `summary\context_builder` | 參考時間由參數傳入，不呼叫 `time()`——時間相關的邏輯只有這樣才測得到「第 3 週的最後一天」這種邊界 |
| `summary\generator`   | 過濾器與 `text_client` 皆可注入，L1 不碰網路      |
| `ai\text_client`      | `\core_ai\manager` 可注入替身，同 §9.8 的 L1 做法 |
| `ical_builder`        | 純函式，輸入事件集合輸出 iCal 字串（`FR-CAL-02` 的主防線） |
| `event_mapper`        | 唯一鍵保證冪等，可用重複呼叫驗證                  |
| ViewModel             | `export_for_template()` 回傳純資料，可直接斷言    |

一個實作時撞到的限制：`text_client` 原本宣告為 `final`，畢竟「唯一收口」聽起來就該封死。但 `final` 擋不住任何人另外寫一個類別去呼叫 `process_action`（那條規則只能由 §8.2 的靜態檢查驗），卻會擋掉 PHPUnit 的測試替身，讓每一個呼叫端的測試被迫真的發出網路請求。**用一個擋不住的限制換掉可測性不划算**，所以拿掉了。

這不是巧合，是 §2.2 分層規則的結果——Model 層不碰輸出，自然就可測。

### 8.2 靜態檢查

`NFR-MNT-06` 的三條規則可用檢查執行，納入 `make lint`：

- `classes/` 下除 `output/` 外，不得出現 `use core\output`、`renderer`、`html_writer`、`moodle_url`。
- `templates/*.mustache` 不得出現比較運算（Mustache 本身即不支援，此項為防止以 helper 繞過）。
- 每個 `templatable` 實作的 `export_for_template()` 回傳型別須為 `stdClass` 或 `array`。
- `classes/` 下除 `ai\text_client` 外，不得出現 `process_action`（`A-13`）。

**最後一條尚未寫成腳本**，目前由程式碼審查維持。前三條也是同樣狀態——`make lint` 現在跑的是 `moodle-plugin-ci` 的七個子命令，這四條自訂檢查還沒有實作。寫在這裡是為了讓它們是明確的欠款而不是遺忘。

`moodle-plugin-ci` 涵蓋其餘的上架規範檢查（`NFR-MNT-04`）。

**兩個實際踩到的 phpdoc 檢查陷阱**，都會讓整個檔案報錯而訊息不明顯：

- 註解裡出現以 at 符號開頭的字（例如寫一個範例信箱）會被當成行內標籤，報 `Invalid inline phpdocs tag`。
- `@param` 的型別寫成 `array<string, int>` 會被判定為「參數清單不完整」。`@return` 沒有這個問題，只有 `@param` 有。

**一項已知且刻意接受的落差**：`phpcs` 的 `moodle.Commenting.InlineComment` 要求行內註解以大寫字母開頭、以半形句點結尾。本專案的註解一律用中文，必然違反這兩條，因此 `make lint` 會留下約四十則該類**警告**（不是錯誤，不影響通過與否）。不要為了消掉它們改寫註解或關掉整個 sniff——關掉會連帶失去其他有用的註解檢查。

### 8.3 第一階段的驗證結果

`make selftest` 之外的實跑結果，全部在 Moodle 5.2.2 / PHP 8.3.33 / PostgreSQL 16.14 上取得：

| 項目 | 結果 |
| --- | --- |
| `make lint` | phplint、phpcs、phpmd、phpdoc、mustache、validate、savepoints 全數通過 |
| `make test` | 13 個測試、103 個斷言通過，無 PHPUnit 警示 |
| `make selftest` | 12 項全數通過，含管理選單節點、信件送達信件攔截服務、管理頁面實際取回、通知清理 |
| `make ai-verify` | 三項 AI 可行性驗證全部通過（§9.1）；含一次我自己寫錯 schema 的失敗與修正 |
| 排程任務 | 以 `admin/cli/scheduled_task.php --execute` 實跑通過；排程為每日 03:15 |
| `db/upgrade.php` | 由 `2026081300` 實際升級至 `2026081700`，四張表建立成功 |

測試檔的涵蓋範圍以 PHP 屬性標註（`#[CoversClass]`）而非 `@covers` 註解：Moodle 5.2 帶的是 PHPUnit 11，註解式中繼資料已棄用，PHPUnit 12 起不再支援。

### 8.3.1 第二階段的驗證結果

同一環境。`make summary` 的完整鏈路——植入示範課綱 → 排程任務 → 語言模型 → 保存 → 課程頁面——實跑通過。

| 項目 | 結果 |
| --- | --- |
| `make lint` | 兩個外掛、七項子檢查全數通過 |
| `make test` | **51 個測試、247 個斷言**通過（第一階段為 13 / 103） |
| `make selftest` | **13 項全數通過**，新增課程摘要頁的實際取回 |
| `make summary` | 端到端通過。一次語言模型呼叫 **1765 ms**，摘要三段齊全 |
| 冪等重跑 | 第二次執行回報 `exists 1`，耗時 **47 ms**，未呼叫 API、未新增 `_ops` 紀錄 |
| 「本週無安排」 | 以空白週次實測：`status=noschedule`、`model` 為空——**完全沒有呼叫模型**，直接回傳固定字串 |
| 外送內容 | 自核心的 `ai_action_generate_text` 表讀回實際送出的提示詞，確認只含白名單的六個鍵 |
| `db/upgrade.php` | 由 `2026081700` 實際升級至 `2026081800`，第五張表建立成功 |

**外送內容那一列是這一階段最值得記的驗證方式。** 「提示詞裡沒有課程 id」這種事，讀程式碼看不出來——`to_whitelisted_facts()` 看起來對，不代表上游沒有在別處把 `$facts` 整份塞進去。核心的 AI 子系統會把每次請求的提示詞原文存進 `ai_action_generate_text`，所以直接查那張表就能看到真正離開站台的是什麼。這與 §4.8 的教訓是同一條：**驗證要驗到最終的落點，不要驗中間層。**

實際送出的內容（節錄）：

```json
{
    "course_name": "課綱助理示範課程",
    "week_no": 3,
    "total_weeks": 6,
    "this_week": { "topic": "知識表示與推理", "note": "本週需繳交作業一" },
    "next_week": { "topic": "機器學習概論", "note": "" },
    "due_events": [
        { "title": "作業一：搜尋演算法實作", "type": "assignment", "due_at": "2026-08-21 23:59" }
    ]
}
```

沒有課程 id、沒有課綱版本、沒有教師姓名、沒有事件來源、沒有 `confidence` 與 `source_span`。窗外的那個事件（14 天後的期中考）也確實沒有出現——示範資料刻意一近一遠，就是為了讓「時間窗有在運作」看得出來，而不是靠「沒看到不該有的東西」這種弱證據。

### 8.3.2 `A-16`　語言模型不看課綱原文，只看事實

這是第二階段最主要的一個設計決定，寫在這裡而不是 §9，因為它是**驗收條件的實作方式**而不是 AI 呼叫的設計。

`FR-SUM-01` 要求「摘要內容僅根據已確認課綱與課程事件產生，不得引入課綱以外的資訊」。做法有兩種：把課綱原文丟給模型並在提示詞裡叮嚀「不要臆測」，或是先把事實抽出來、只送事實。

選後者。理由不是提示詞沒用，而是**模型看不到的東西就不可能寫進摘要**——這是一個結構性的保證，不是一個要求模型配合的請求。附帶的三個好處：外送內容大幅縮小（`NFR-SEC-06` 的白名單因此變成一個具體的方法而不是一句原則）、token 用量低、以及「哪些欄位會離開站台」變成程式碼審查看得見的一件事。

代價是模型看不到版面資訊，寫不出「課綱表格裡那個星號的意思」這類東西。摘要不需要那些。**但這個取捨對第三階段的課綱解析不成立**——那裡正好相反，版面資訊是正確率的來源（§9.9 的第 1 題）。同一個專案裡兩條路徑做相反的選擇，是因為它們要的東西不同，不是因為其中一邊沒想清楚。

### 8.3.3 第三階段：上傳與解析的驗證結果

`make syllabus` 走完整條鏈路：**真實網頁表單上傳 → 自訂事件 → 觀察者 → 臨機任務 → 過濾 → 抽取 → 驗證 → 落地**。

| 項目 | 結果 |
| --- | --- |
| `make lint` | 兩個外掛七項全通過。**併入的 46 個第三方檔案零錯誤**——`thirdpartylibs.xml` 確實讓 `moodle-plugin-ci` 排除了那個目錄 |
| `make test` | **86 個測試、397 個斷言** |
| `make selftest` | 13 項全通過 |
| 上傳：不支援的格式 | 已被拒絕，訊息明確列出支援清單 |
| 上傳：無文字層 | **在解析前**被擋下，符合 `FR-SYL-01` 的字面要求 |
| 上傳：正常路徑 | 課綱版本遞增、檔案落地、事件觸發、任務排入 |
| 解析 | 一次呼叫 4.5–5.4 秒，schema 與四條不變式全通過 |
| 確認：低信心／未決／出處標示 | 三者都在介面上找得到（`FR-SYL-03`） |
| 確認：逐項修改 | 改動生效、該項標為人工覆寫、**其餘未被標記**（`FR-DIF-02`） |
| 確認：記錄與版本 | 確認者與時間都寫入，**版本號不跳**（§3.3） |
| 確認：重複送出 | 已確認的課綱再確認一次被拒絕 |
| `db/upgrade.php` | 由 `2026081800` 升級至 `2026081900` |

**`event_id` 的跨版本穩定性在真實資料上驗到了**（§9.6 的核心約束）。同一門課的七個版本，「期中考」這個事件的識別碼全部是 `22a80f71…`：

| 版本 | 來源 | 期中考日期 | `event_id` |
| --- | --- | --- | --- |
| 1 | 手寫 seed | 2026-09-01 | `22a80f7105844ab2497328948b11e6b4` |
| 2–7 | 模型解析 | 2025-10-14 | `22a80f7105844ab2497328948b11e6b4` |

**日期差了將近一年，識別碼相同**——這正是「不含日期」那個決定要換到的東西。第 1 版是手寫的，第 2–7 版是模型抽出來的，兩邊只要 `(type, 正規化標題, 出現序號)` 相同就得到同一個值，不需要任何協調。

**下游也接上了**：`make summary` 現在讀到的是經過完整流程（上傳→解析→確認）的課綱版本，不再只是植入的那一份。

**上傳刻意走真實表單而不是直接寫資料庫。** `FR-SYL-01` 的驗收條件講的全是介面行為——誰可以存取、格式怎麼拒絕、無文字層何時偵測。繞過表單，這些一項都驗不到。這個決定當場就有回報：三個只在網頁路徑才會出現的缺陷全部被抓到，見下一節。

### 8.3.4 三個只在網頁路徑才會現形的缺陷

三個都不會被單元測試抓到，也都不會在 CLI 重現。記下來是因為**它們的症狀都指向錯誤的地方**。

**其一：`lib.php` 不會自動載入。** 把一個輔助函式放在 `lib.php` 裡再從 `upload.php` 呼叫，得到的是 `Call to undefined function`。local 外掛的 `lib.php` 只在核心尋找回呼時載入，一般頁面不會 require 它。

> 修法不是加 `require_once`，而是**把業務邏輯從進入點搬進 `upload_service`**。原本 `upload.php` 裡有抽文字、建紀錄、存檔、觸發事件四件事，那違反 §2.2；搬完之後進入點剩十行，而那段邏輯可以被 PHPUnit 直接測試，不必模擬 HTTP 請求。**缺陷指出的位置與真正的問題不同**——這種情況下修最近的那一行通常是錯的。

**其二：在 `validation()` 裡呼叫 `get_new_filename()` 會無限遞迴。** 核心的 `get_new_filename()` 開頭是：

```php
if (!$this->is_submitted() or !$this->is_validated()) {
    return false;
}
```

而 `is_validated()` 會呼叫 `validation()`——也就是呼叫它的那個方法。**症狀完全看不出原因**：每層遞迴都下一次 SQL，最後以「記憶體耗盡於 `moodle_database.php` 的參數處理」中止，512 MB 用光，堆疊裡看不到任何一行本外掛的程式碼。改為直接由草稿區 id 查檔名即可，不要經過任何會回頭呼叫驗證的核心方法。

**其三：中文站台的檔案類型錯誤訊息是壞的。** 這是**上游的缺陷**：Moodle 5.2 的英文字串已改用 `{$a->allowlist}`，繁體中文語言包還停在舊的 `{$a->whitelist}`，於是使用者看到一個沒有被替換的佔位符。

> `FR-SYL-01` 明文要求「明確告知支援哪些格式，不得只回傳『上傳失敗』」，靠一個壞掉的上游字串達不到那個要求。因此**不使用 filepicker 的 `accepted_types`**，改在表單的 `validation()` 自己檢查、用自己的字串。代價是檔案選擇器不再預先過濾類型，比起讓使用者看到 `{$a->whitelist}`，這個代價小得多。語言包修好之後可以改回來——屆時要重跑 `make syllabus` 確認訊息真的正常，不要只看語言包版本號。

### 8.3.5 教師姓名的過濾有一個做不到的部分

SRS §6.3 要求「授課教師姓名與聯絡方式須在**進入解析之前**即自課綱原文中移除」。這裡有一個雞生蛋的問題：要移除姓名得先知道姓名，而姓名正是要從課綱裡解析出來的東西之一。

**解法是不問課綱，問 Moodle**：這門課的授課教師是誰，站台本來就知道——具 `local/universityai:upload` 能力的人就是。三種姓名排列（`fullname()`、姓名相連、名姓相連）都交給過濾器。

**但這個解法有一個明確的邊界，實測看得到**：合成樣本裡的教師「陳怡君」是虛構的，不是 Moodle 使用者，所以過濾器不認得它，抽取結果裡的 `course.teacher` 確實是「陳怡君」。

真實情境中課綱的授課教師通常就是上傳者，會被移除；但**「通常」不是「一定」**——共同授課、助教代傳、課綱裡出現其他老師的名字，都會落在這個邊界外。`uai-show-syllabus.php` 因此把 `course.teacher` 是否為 null 印成一項明確的檢查，讓這個限制在每次實跑時都看得見，而不是藏在文件裡。

> 要真正做到，需要人名辨識，那是另一個量級的東西。目前的立場是：**能移除的一定移除，移不掉的要看得見**。

### 8.3.6 `A-22`　`phpcbf` 會改到併入的第三方程式庫

**`make lint` 用的 phpcs 會讀 `thirdpartylibs.xml` 並排除宣告過的路徑；`phpcbf` 不會。**

直接對整個外掛目錄跑一次自動修正的後果：

```
A TOTAL OF 706 ERRORS WERE FIXED IN 49 FILES
```

其中 49 個檔案全部在 `thirdparty/pdfparser/` 底下——別人的原始碼被「修正」成本專案的編碼風格。而且**還原不了**：併入的函式庫對 git 而言是新增檔案，`git checkout` 沒有東西可以還原，只能重新下載一份覆蓋回去。

修法是加一層 `make fix`，帶 `--ignore="*/thirdparty/*"`。**不要直接跑 phpcbf**，`uai-lint.sh` 的說明裡原本就寫了一行 phpcbf 指令，現在改成指向 `make fix` 並說明理由。

> 這件事的一般形式值得記住：**「檢查」與「修正」用的是同一套規則，但不是同一支程式，兩者對排除清單的支援可以不同。** 任何會自動改檔案的工具，第一次跑之前要先確認它的作用範圍——而不是跑完再看 `git diff`，因為有些東西 diff 不出來。

### 8.3.7 `A-23`　確認介面不顯示信心數值

`FR-SYL-03` 要求「信心值低於門檻（預設 0.6）的項目，在確認介面中須被標示出來」。介面照做了，但**刻意不把信心數值本身呈現給老師**，只顯示一個「請確認」的標示。

理由是實測看到的（§8.3.3）：模型在自己於 `unresolved` 中回報「文件內容受損，多處出現亂碼」的同一次抽取裡，仍然給每一個週次 `confidence: 1.00`。而那次確實有資料損失——第 1 週的主題被截成「課程介紹與人工智慧發展」，少了「簡史」。

**信心值與實際正確性脫節。** 把 1.00 印在畫面上，等於請老師相信一個已知不可靠的指標；而「這一項系統覺得可疑」是那個數值還能承擔的全部語意。徽章只回答後者。

> 這件事對 `FR-SYL-03` 是壞消息，值得寫在這裡而不是藏起來：那條驗收條件的預設假設是「低信心能指出可疑項目」，而目前的證據顯示模型很少給出低信心值。實測的兩次真實解析中，低信心項目都是 **0 項**——標示功能正確地什麼都沒標，因為沒有東西達到門檻。
>
> 這代表**低信心標示目前在實務上幾乎不會觸發**，老師實際依賴的是 `unresolved` 清單與 `source_span`。門檻要不要調高、或改用別的可疑度指標，要等 `OI-03` 的真實測試集才有依據——現在調只是換一個猜測。

### 8.3.8 第三階段：填入課程的驗證結果

`make syllabus` 現在是五段：**上傳 → 解析 → 讀回 → 確認 → 填入課程**。

| 項目 | 結果 |
| --- | --- |
| `make test` | **94 個測試、428 個斷言** |
| 未確認的課綱不得填入 | 被擋（`FR-SYL-04` 那條「待確認時呼叫課程建立應被拒絕」的另一面） |
| 差異對照表呈現 | 有，且會被覆寫的欄位另外標示（`FR-CRS-01`） |
| 課程欄位 | 全名、簡述、起訖日期全部依課綱寫入 |
| 週次章節 | 6 個，與課綱的 `total_weeks` 一致（`FR-CRS-02`） |
| 行事曆事件 | 2 個，與課綱中有日期的事件數一致（`FR-CAL-01`） |
| **重複套用** | **章節與事件都未增加**（`NFR-REL-04`） |

**`_evtmap` 的冪等設計第一次被真的驗證。** 那張表從第一階段就建好了，唯一鍵 `(courseid, eventkey)` 一直被當成「重複執行不產生重複活動」的實作方式寫在文件裡——但在這之前從來沒有任何東西寫進去過。現在它有資料，而且重複套用兩次的事件數不變。

**整條鏈路的證據**：填入之後課程第 1 章節的名稱是「（老師修正過的主題）」——那是確認階段老師手動改的值，不是模型抽出來的。解析 → 人工修正 → 填入課程三段接得起來，而且用的是修正後的版本。

### 8.3.9 `A-24`　課程事件建成行事曆事件，不是活動模組

`FR-CAL-01` 的字面是「將課綱中的課程事件建立為 Moodle 中**帶有截止日的活動**」。實作建的是**課程行事曆事件**（`\calendar_event`），不是活動模組。

理由是 Moodle 沒有對應「考試」的活動類型。可用的選項只有：

| 做法 | 問題 |
| --- | --- |
| 全部建成 `mod_assign` | 期中考會變成一個要學生繳交檔案的作業。語意錯得離譜，而且學生看到的是「未繳交」 |
| 作業類建 `mod_assign`、考試類建行事曆事件 | 語意對，但同一份課綱的事件在兩個地方，老師要修改時得先猜它在哪裡 |
| 全部建成行事曆事件 | 不是「活動」，**不會出現在學生的時間軸區塊** |

選第三個。取捨是：**寧可少一個顯示位置，不要製造語意錯誤的資料。** 「自動出現於課程行事曆」這半做到了，「學生時間軸」那半沒有——時間軸只顯示有待辦動作的活動模組。

> `_evtmap.targettype` 從第一階段就預留了 `event` 與 `cm` 兩個值（§3.2）。當時寫的是「course event 或 course module」，現在用到的是前者。這個欄位讓日後把作業類事件改建成 `mod_assign` 不需要改表——**第一階段那個看起來多餘的欄位，現在是這個決定可以被推翻的原因**。

### 8.3.10 `A-25`　填入課程由老師觸發，不由觀察者自動排入

§4.3 原本把 `task\populate_course` 列為由 `syllabus_confirmed` 觀察者排入的臨機任務。**實作時改為老師在確認之後另外按下「填入課程」。**

理由是 `FR-CRS-01` 的一條驗收條件：「課程既有欄位若已有內容且與課綱不同，須**先向老師呈現**將被覆寫的項目，不得靜默蓋掉」。背景任務沒有人可以呈現，也沒有人可以點頭——把差異寫進通知再等老師回來，等於發明一套審批流程來繞過一個本來就該同步做的決定。

代價是多一次點擊。換到的是老師在覆寫自己填的課程資料之前一定看得到將被覆寫的是什麼。實測那份示範課綱有 **2 個欄位**會被覆寫，介面上都標示出來了。

> §4.3 的表格因此與實作不符，已在此標明。`task\populate_course` 這個類別不存在，也不註冊——`db/tasks.php` 只註冊真的存在的類別（§4.2）。

### 8.4 驗證動作不得留下痕跡

`make selftest` 為了驗證通知路徑，會真的發一則「課綱解析失敗」給每位管理員。第一版就到此為止——結果是跑幾次之後，管理員的訊息中心堆著一排看起來很嚴重、卻一次也沒真正發生過的失敗警報。展示時（`NFR-MNT-05`）鈴鐺上掛著八則紅字，說服力是負的；更糟的是它會訓練人忽略真的警報。

現在 selftest 把自己造出來的東西全部收回：

| 產生的東西 | 收回方式 |
| --- | --- |
| `_ops` 的探測列 | 由 `prune_ops` 任務刪除，這本來就是該項檢查的一部分 |
| `notifications` 與 `message_popup_notifications` 的列 | 送出前記下最大 id，事後只刪高於該水位的列 |
| 信件攔截服務裡的信 | **刻意不刪**——那是這次驗證的證據，而且信件攔截服務沒有持久化，重建容器就清空 |

用「送出前的最大 id」而不是「刪掉所有 `component = local_universityai` 的通知」，是為了將來：真的有失敗通知時，它們不會被這支腳本順手掃掉。刪除後再數一次剩餘列數並回報成一項檢查，清理本身也才是被驗證過的。

> 這條原則不只適用於 selftest。任何為了驗證而寫入系統的資料，都要能說出它何時被移除；說不出來的話，那個驗證就在把測試資料混進真實資料裡。

---

## 9. AI 呼叫設計

**狀態：SRS §8.4 第一‧五階段的閘門已於 2026-08-18 通過。** 本章的每一項決定都建立在下方 §9.1 的實測結果上，不是讀文件推論的。重跑方式：`make ai-verify`（會呼叫 API 並產生費用，`RUNS=n` 可調次數）。

### 9.1 三項驗證的實際結果

環境：Moodle 5.2.2 / PHP 8.3.33 / `claude-haiku-4-5-20251001`，每項 3 次。

| 驗收條件 | 結果 | 量測 |
| --- | --- | --- |
| 1　供應商能被子系統識別並呼叫 | **通過** | 完整走 `\core_ai\manager::process_action()`，取回繁中回應。1.3–1.6 秒，入 117 / 出 59–67 token |
| 2　抽取介面能取得結構化輸出 | **通過** | 原生 `output_config.format` 3/3 通過 schema 驗證，平均 6.1 秒，出約 880 token |
| 3　通用實作的退路真的存在 | **通過** | 僅以提示詞要求 JSON，3/3 可用，平均 6.4 秒，出約 1300 token |

三項之外，向 Models API 查到的 `claude-haiku-4-5-20251001` 能力（不是憑印象，是即時查詢）：

| 能力 | 值 | 對本專案的意義 |
| --- | --- | --- |
| `structured_outputs` | 支援 | 第 2 條路徑成立的前提 |
| `pdf_input` | 支援 | **課綱 PDF 可直接送 API**，不必先自己抽文字。這一點會影響 `FR-SYL-02` 的實作選擇，見 §9.9 |
| `thinking.adaptive` | 不支援（只支援 `enabled`） | 若日後要開思考，這個模型得用 `budget_tokens` 而非 `adaptive` |
| `effort` | 不支援 | 不可送 `output_config.effort`，會被拒絕 |
| 上下文 / 輸出上限 | 200K / 64K token | 一份 18 週課綱遠在範圍內 |

**兩件與預期不同的事，值得記下來。**

其一，**第 3 項的退路比預期好**。原本的假設是提示詞路徑會經常產出不合 schema 的 JSON、需要多次重試，`OI-06` 的延後理由也建立在「這條路很脆弱」上。實測 3/3 一次就過，連程式碼圍籬都沒出現。但**這個結論的樣本很小**：n=3、一份 6 週的短課綱、schema 明確。不能據此宣稱退路穩定，只能說「這條路不是不可用」——而那正是第 3 項要問的。真正的失敗率要等第三階段用 `OI-03` 的測試集量。

其二，**原生結構化輸出比較慢但比較省**。6.1 秒 vs 6.4 秒差距不大，但輸出 token 少了三分之一（880 vs 1300）。少的部分推測是提示詞路徑會輸出較寬鬆的排版；沒有進一步驗證，先當觀察而非結論。

#### 失敗的部分：第一次跑實驗 2 是我的 schema 寫錯

三次全部以 HTTP 400 失敗：

```
output_config.format.schema: Invalid schema:
Enum value 'MON' does not match declared type '['string', 'null']'
```

原因是 `term.weekday` 同時宣告了 `type: ['string','null']` 與含 `null` 的 `enum`。**API 端的 schema 檢查比一般 JSON Schema 驗證器嚴格，不接受 union type 與 enum 併用。** 拿掉 `type` 只留 `enum` 之後三次全過。

這是平台的約束而不是本專案的錯，但值得寫下來：撰寫 `DM-SYLLABUS` 的完整 schema 時，每個可為 null 的列舉欄位都會撞到同一件事。另外兩條同時發現的約束：每個 object 都必須有 `additionalProperties: false` 與完整的 `required`，否則約束形同虛設。

### 9.2 `A-11`　兩條路徑的接縫：以站台設定指名抽取器類別

**這是本章最受約束的一個決定，因為 §1.2 的三條相依規則把顯而易見的做法全部擋掉了。**

問題：抽取路徑需要 `local_universityai` 呼叫 `aiprovider_claude` 的程式碼。但

- 規則 1 說 `aiprovider_claude` 不得引用另外兩個外掛的任何類別——所以它**不能** `implements local_universityai\extraction\extractor_interface`，那會讓它在沒有 UniversityAI 的站台上一載入就致命錯誤，而獨立可安裝正是它的價值。
- 規則 3 說 `local_universityai` 不得直接引用 `aiprovider_claude`——所以也不能寫個轉接類別去 `new \aiprovider_claude\extractor()`。

兩邊都不能指名對方，而 Moodle 的掛鉤機制也救不了：掛鉤監聽器必須型別提示掛鉤類別，那一樣是跨外掛引用。

**決定**：`local_universityai` 定義介面並程式設計於介面之上；實際的實作類別**由站台設定以字串指名**，執行時以 `class_exists()` 檢查後動態建立。預設值是 `aiprovider_claude\extractor`，但那是一個設定值，不是程式碼中的類別引用。

```
local_universityai\extraction\extractor_interface      介面（契約）
local_universityai\extraction\configured_extractor     轉接：讀設定 → 動態建立 → 委派
        │  設定 local_universityai/extractorclass
        ▼  預設 'aiprovider_claude\extractor'（字串）
aiprovider_claude\extractor                            實作，不引用上面任何類別
```

**代價要說清楚**：這條接縫沒有編譯期型別檢查，打錯類別名要到執行時才發現。三道防線補回來——`configured_extractor` 建立前檢查類別存在且具備約定的方法；回傳值做形狀檢查；PHPUnit 斷言隨附的實作確實符合介面的方法簽章（用反射，不用 `instanceof`）。

**考慮過但否決的替代方案**：讓 `aiprovider_claude\extractor` 直接 `implements` 介面，賭「沒有 UniversityAI 的站台永遠不會載入這個類別」。PHP 只在載入時才需要介面存在，理論上可行且型別安全。否決是因為它依賴「沒有人會去載入它」這個無法保證的前提——`core_component` 的掃描、PHPUnit 的涵蓋率蒐集、任何遍歷外掛類別的工具都可能踩到，而失敗形式是致命錯誤而不是降級。用一個確定的小代價換掉一個不確定的大風險。

### 9.3 抽取介面的簽章

```php
interface extractor_interface {
    /** 建立實例；供應商未設定完成時回傳 null。 */
    public static function create(): ?static;

    /** 抽取。$mode 為 'structured' 或 'prompted'（§9.4）。 */
    public function extract(string $text, array $schema, string $instruction, string $mode): array;
}
```

三個選擇的理由：

- **`create()` 是靜態工廠而不是建構子。** 實作需要金鑰與端點，而那些在供應商執行個體裡；讓實作自己去 `\core_ai\manager` 找自己的執行個體，呼叫端就不必知道供應商長什麼樣。找不到或未設定時回傳 `null`，呼叫端據此決定是跳過還是報錯。
- **`$schema` 是陣列而不是類別。** `DM-SYLLABUS` 的 schema 由 `local_universityai` 產生並持有，實作端只是把它轉成請求參數；定義一個 schema 值物件會讓實作端被迫引用它，違反規則 1。
- **回傳陣列而不是值物件。** 同一個理由。鍵為 `success`、`data`、`raw`、`error`、`model`、`prompttokens`、`completiontokens`、`durationms`——`raw` 一定要有，schema 驗證失敗時要靠它才知道模型到底吐了什麼。

**狀態：已於第三階段接線。** `aiprovider_claude\extractor` 現在同時提供 `create()`、`extract()`（契約）與 `extract_structured()`、`extract_prompted()`（兩種機制，閘門量測仍用得到）。`local_universityai` 這一側是 `extraction\configured_extractor`。

三道防線都有測試釘住（`tests/extraction_seam_test.php`）：類別不存在或簽章不符時拋出說得清楚的 `coding_exception`；回傳值缺鍵時整份當成失敗；而**契約本身由介面反射得出，不是寫死的清單**——寫死的話改了介面不會讓檢查跟著改，那正是這道防線唯一要防的事。

### 9.4 `A-12`　原生與提示詞兩種模式由站台設定切換，預設原生

`NFR-EXT-03` 要求「換一家不支援結構化輸出的供應商也能運作」。實測兩條路都通，因此切換點的設計重點不是「能不能」，而是「失敗時看不看得出來」。

**決定**：站台設定 `local_universityai/extractionmode`，值為 `structured`（預設）或 `prompted`，**不做自動回退**。

不自動回退的理由：模型不支援結構化輸出時，回應是 HTTP 400 且訊息明確（實測過，見 §9.1 的失敗紀錄）。若程式在收到 400 後自動改走提示詞路徑，站台會安靜地降級——正確率下降、token 用量上升，而管理員完全不知道發生了什麼。明確的失敗加上一行「請把 extractionmode 改為 prompted」比安靜降級好。

判斷某個模型支不支援，不需要試誤：Models API 的 `capabilities.structured_outputs.supported` 直接回答（§9.1 的表就是這樣查的）。這個查詢屬於管理者的一次性判斷，不放進執行路徑。

### 9.5 schema 驗證與重試的落點

**驗證放在呼叫端（`local_universityai`），不放在實作內。**

理由：兩種模式、將來可能的第三家供應商，都必須滿足同一份契約。驗證放實作裡就是每個實作各寫一份，而「兩份驗證行為不一致」是那種永遠不會有人發現的缺陷。放呼叫端則只有一份，且與 `DM-SYLLABUS` 的定義放在一起。

**重試分兩層，界線要清楚：**

| 情況 | 由誰處理 |
| --- | --- |
| 回應不是合法 JSON，或不符 schema | 呼叫端**立即重問一次**，把驗證錯誤附在提示詞後面 |
| 重問後仍不符、或 HTTP 錯誤、或逾時 | **不處理**，讓例外往上拋，由 `\core\task\adhoc_task` 的重試機制接手（§4.4） |

第一層之所以例外於「不要自行實作重試迴圈」那條規則：它不是重試同一個請求，而是帶著新資訊（驗證錯誤）重問，成本一次、資訊量不同。上限就是一次，第二次仍失敗即放棄——連續失敗代表 schema 或提示詞有問題，重試更多次只是燒錢。

第二層嚴格遵守 §4.4：不在 `execute()` 內 catch 後重跑，否則 Moodle 的失敗延遲機制看不到失敗，`_ops` 的 `attempt` 也失去意義。

### 9.6 `events[].event_id` 的推導規則

§3.2 標明了這是一個必須被滿足的約束：同一場期中考在第 1 版與第 2 版必須得到相同的 `event_id`，否則 `_evtmap` 的唯一鍵擋不住重複，`FR-DIF-01` 的比對也會把「日期改了的同一場考試」看成「刪掉一場、新增一場」。

**決定**：由事件的穩定特徵推導，**不含日期**。

```
event_id = sha1(type + '|' + normalise(title) + '|' + occurrence)  取前 32 字元
```

- **不含日期**是關鍵。日期正是最常改動的欄位，把它放進識別碼等於保證跨版本對不起來。
- `normalise(title)`：去除前後空白、把連續空白收成一個、全形數字轉半形。不做大小寫轉換以外的語意處理——中文沒有大小寫，而過度正規化會讓「作業一」與「作業二」有機會碰撞。
- `occurrence` 是同一份課綱內 `(type, normalise(title))` 重複時的出現序號，自 0 起算。沒有它的話，一門課裡兩次「小考」會得到同一個識別碼。
- 取 32 字元是為了留餘裕：`_evtmap.eventkey` 是 `char(64)`，將來若要在前面加上前綴仍有空間。

**已知的弱點，明講**：老師把「期中考」改名成「期中測驗」，這條規則會判定為不同事件。沒有純機械的方法能分辨「改名」與「換一場考試」——`FR-DIF-02` 的人工確認介面正是為這類情況存在的，比對結果交給老師決定，系統不猜。

### 9.7 生成類路徑的包裝層與外送過濾的收口

生成類（摘要、差異說明、課程問答）走 `\core_ai\manager::process_action()`。實驗 1 證明這條路通，但**不能讓各處直接呼叫它**——那樣 `NFR-SEC-06` 的外送過濾就沒有單一收口點，漏掉一處等於整條防線失效。

```
呼叫端（summary\generator / 將來的 chat）
   ├─ 1. redaction\filter::apply()  → filtered_payload   ← 唯一的外送收口
   └─► local_universityai\ai\text_client::generate(filtered_payload, …)
          ├─ 2. new \core_ai\aiactions\generate_text(...)
          ├─ 3. \core_ai\manager::process_action()
          └─ 4. ops::record()                            ← 成敗都記
```

**`A-13`　凡是離開本站台的文字，都必須先經過 `redaction\filter::apply()`，且只有這一個地方可以呼叫 `\core_ai\manager::process_action()`。**

兩條路徑的收口點不同但規則相同：生成類在 `text_client` 收口，抽取類在 `configured_extractor` 收口。兩者都在 `local_universityai` 內，因此 §8.2 的靜態檢查可以驗這件事——把「`classes/` 下除這兩個類別外不得出現 `process_action` 或 `extract`」加進 `make lint`。

> 上面那張圖與本節初稿有一處不同：初稿把 `filter::apply()` 畫在 `text_client` 內部（第 1 步）。實作時改成由呼叫端過濾、`text_client` 只接受 `filtered_payload`，因為 §6.1 的型別收口是兩者中較強的一個——若 `text_client` 同時也接受字串，型別保證就沒有了。步驟順序不變，只是第 1 步的位置在包裝層之外。

**狀態：兩者都已於第二階段建立**（`A-14`）。第二階段的呼叫端只有 `summary\generator` 一個，`ops::record()` 記的是 `weekly_summary`。

`text_client` 沿用「不在此重試」的規則（§4.4）：失敗時回傳失敗結果，由呼叫端決定要不要讓例外往上拋給 `\core\task` 的重試機制。`weekly_summary` 的選擇是不拋——單一課程失敗不得中止整批（`NFR-REL-03`），而摘要下週還會再跑一次，不值得為它啟動重試。

一個要知道的副作用：`generate_text` 動作必須帶 `userid`，而排程任務沒有互動使用者，所以一律掛在主管理員名下。**若站台在供應商設定中開啟了「每位使用者的速率限制」，全站的摘要會共用管理員這一個額度**，課程一多就會被擋。該項設定預設關閉。

### 9.8 AI 端點樁

分成兩層，因為 PHPUnit 與 Behat 的需求不同：

| 層 | 做法 |
| --- | --- |
| L1 PHPUnit | 以 `\core\di` 換掉 `http_client`，回傳固定的 Anthropic 形狀回應。核心自己的供應商測試就是這樣做的，不需要任何額外服務 |
| L2 Behat | 把供應商執行個體的 `endpoint` 指到樁服務。這正是 `endpoint` 這個設定欄位存在的理由（見 `hook_listener`），不是為了換 API 供應商 |

樁服務的具體形式**留到第三階段真的需要 Behat 場景時再定**。現在能確定的只有介面：它必須接受 `POST /v1/messages` 並回傳 Anthropic 的回應形狀。是獨立容器（放進 `test` profile，同 `A-07`）還是 `moodle` 容器內的一支腳本，取決於屆時的場景需要多少組固定回應——這個資訊現在還沒有。

### 9.9 尚未回答的問題

寫這一章的過程中新冒出來、且不該在這裡硬答的：

1. ~~**課綱 PDF 要不要直接送 API？**~~ **已結案，見 §9.10（`A-18`）：先抽成文字再送。** 實測兩條路的正確率完全打平，而 PDF 那條要用兩倍的輸入 token，並且同時放棄外送過濾與 `source_span` 驗證。原本擔心的「安全與正確率正面衝突」沒有發生——因為正確率那一邊根本沒有優勢。
2. **`DM-SYLLABUS` 完整 schema 已於第三階段撰寫**（`classes/extraction/schema.php`），四條資料不變式也在 `validator` 裡實作了。**仍未完成的是正確率量測本身**：`FR-SYL-02` 要求「不少於 20 份真實課綱，週次日期與事件日期正確率各 ≥ 80%」，而 `OI-03` 的測試集與人工標註都還沒建立。§9.10 用的是 5 份合成樣本，不能拿來充當那個數字。人工標註的工時通常比蒐集課綱本身更高，要及早開始。
3. **多供應商排序時的行為未驗。** `\core_ai\manager::process_action()` 會依序嘗試所有支援該動作的供應商，直到成功。站台若同時啟用 Claude 與其他供應商，摘要可能由不同供應商產生，而 `NFR-MNT-03` 要求記錄產生內容的模型。
   > 第二階段的處理：`text_client` 一律把回應中的 `model` 原樣傳回，`summary` 表逐列存下它——所以**由誰產生這件事是被記錄的**。真正未驗的是「多供應商同時啟用時的挑選順序是否穩定」，那需要第二個供應商才測得到，目前站台只有一個。降級為「已緩解但未驗證」。

### 9.10 `A-18`　課綱先抽成文字再送，不直接送 PDF

**這是 §9.9 第 1 題的結案。** 原文寫著「必須在第三階段開始前以測試集實測兩條路的正確率差距再決定，不能憑感覺」，以下是那個實測。重跑方式：`make fixtures && make syllabus-gate RUNS=n`。

#### 方法

5 份固定樣本 × 3 條路徑。三條路徑唯一的差別是送進模型的東西：

| 路徑 | 送什麼 |
| --- | --- |
| `pdf` | `document` 內容區塊，base64 的整份 PDF。模型同時看得到文字與版面 |
| `text` | 由 **pypdf**（Python 評測工具鏈）抽出的純文字 |
| `phptext` | 由**外掛自己的 `text_extractor`**（PdfParser）抽出的純文字 |

模型、schema、系統指令完全相同，否則量到的就不是版面。樣本刻意帶不同的版面陷阱：標準表格、備註欄大量留白的表格、純條列（對照組）、以及兩種雙欄。

**第三條路是後來補的，而且補得非必要不可。** 第一版只比 `pdf` 與 `text`，跑出 20 次全滿分的漂亮結果——但 `text` 用的是 **Python 端**的抽取結果，那不是產品實際會走的路。併入 PdfParser 之後實測才發現兩者的抽取品質並不相同（見下方）。**拿評測工具鏈的中間產物去代表產品行為，是這次量測裡最接近翻車的一步**，而它一開始完全沒被察覺，因為結果好看。

評分只看**可客觀比對**的欄位——週次日期、週次主題、事件日期、週數。`FR-SYL-02` 的兩條驗收條件正是前兩者裡的日期部分。主題用正規化後的字串相等比對，不做語意相似度：語意相似度需要另一個模型，那會讓量測結果依賴一個沒有被驗證過的東西。

#### 結果

| 路徑 | 週次日期 | 週次主題 | 事件日期 | 週數 | 平均耗時 | 平均輸入 token |
| --- | --- | --- | --- | --- | --- | --- |
| `pdf` | 100% | 100% | 100% | 100% | 7003 ms | 3344 |
| `text` | 100% | 100% | 100% | 100% | 4307 ms | 1732 |
| `phptext` | **100%** | **83%** | **100%** | 100% | 5358 ms | 1746 |

**`FR-SYL-02` 量的兩個數字——週次日期與事件日期——三條路都是 100%。** 版面資訊在這組樣本上沒有帶來任何可量測的優勢，包括 `f5-interleaved`：它的純文字順序是第 4、5、6 週在前、第 1、2、3 週在後，模型仍然全部還原，因為每一塊都帶著「第 N 週」的標籤。

`phptext` 的週次主題掉到 83%，原因不是版面，是**抽取器本身**——見下一節。

#### `A-19`　PdfParser 有中文解碼缺陷，但不影響驗收條件

`phptext` 的週次主題掉 17 個百分點，追下去發現是**併入的 PdfParser 對 TCPDF 產生的 CID-0 字型（`msungstdlight`）漏掉部分 UTF-16BE 解碼**。症狀很好認：

```
比例  →  kÔO‹        （U+6BD4 U+4F8B 的位元組 6B D4 4F 8B 被當成 Latin-1 讀）
週次  →  1k!          （U+9031 U+6B21 → 90 31 6B 21）
```

同一份檔案 pypdf 完全正常，所以這是函式庫的差異，不是 PDF 壞掉。

**為什麼日期沒受影響**：日期是 ASCII 數字，走的不是同一條解碼路徑。這也解釋了為什麼 `FR-SYL-02` 的兩個驗收數字仍是 100%——那兩條驗收條件量的都是日期。

**目前不修，理由三點：**

1. **可能只存在於合成樣本。** `msungstdlight` 是 Adobe 的 CID-0 字型，PDF 裡不嵌入字型檔。真實課綱多半由 Word 或 LibreOffice 匯出，用的是嵌入的 TrueType 子集，解碼路徑不同。**但我沒有真實課綱可以證實這一點**，所以這是推測不是結論。
2. **修法本身有風險。** 唯一可行的修法是在輸出端偵測「被誤讀的 UTF-16BE」再解碼，而那是啟發式的——判斷錯就會把原本正常的文字也毀掉。為一個可能不存在於正式資料的問題，引入一個會誤傷正常資料的修補，划不來。
3. **下游有人工確認。** 主題錯誤會出現在 `FR-SYL-04` 的確認介面上，而那個介面存在的理由正是這個。日期錯誤才是真正難以人工發現的，而日期沒錯。

**列為待驗**：`OI-03` 的真實課綱測試集建立之後，第一件事就是重跑 `make syllabus-gate --arms=phptext` 看這個缺陷還在不在。若真實課綱也中招，那時再修——屆時手上會有能證明修法沒有誤傷的樣本。

#### 決定

**先抽成文字再送。** 理由不是文字比較準，而是 PDF 那條路要用**兩倍的輸入 token 與 1.6 倍的延遲**，換取一個量不出來的正確率差距；而且它額外放棄兩件事：

1. **`NFR-SEC-06` 的外送過濾在二進位檔上做不了。** 送 PDF 等於整份原始檔外送，教師姓名與聯絡方式都在裡面。
2. **`FR-SYL-03` 的 `source_span` 驗不了。** 那條驗收條件是「`source_span` 的內容必須實際出現在課綱原文中」，而 PDF 路徑手上沒有本地原文可比對。這是整組驗證裡最有價值的一項——它擋的是模型憑空生成內容再附上一段同樣憑空生成的出處。

第 2 點在量測中不是理論推演：`f4-sparse-table` 的文字路徑有一次真的產生了捏造的出處，模型把表格重組成「週次1日期2025-09-12主題網路分層架構」當作原文引用，被驗證器抓到。**那一次失敗正是這條檢查存在的證明**，也是 §9.5 那個「帶著驗證錯誤重問一次」的設計要處理的情況。PDF 路徑不會抓到這種事——不是因為它不發生，是因為沒有東西可以比對。

#### 這個量測**沒有**證明的事

寫清楚，免得日後被當成比它更強的結論引用：

- **樣本是合成的，不是 `OI-03` 的測試集。** 5 份、每份一頁、全部由 TCPDF 產生。`FR-SYL-02` 要求的「不少於 20 份真實課綱，正確率 ≥ 80%」仍未完成。
- **n=2、單一模型。** 打平可能只是因為題目太簡單。真實課綱有跨頁表格、民國年、圖片式表格（第一版明確不支援），那些都沒測到。
- **每一份樣本都明確標註了週次編號。** 這很可能是兩條路打平的主因。不標週次的課綱是否仍然打平，這裡答不了。
- **`phptext` 的 83% 只有 5 個樣本、每項 1 次。** 那個數字用來說明「有這個缺陷」夠了，用來說明「缺陷有多大」不夠。

> 若日後改用不標週次的課綱、或正確率量測顯示文字路徑不足，這個決定要重新測而不是重新討論。腳本還在。

#### 兩件順帶記下的事

**第四條 API schema 約束**：`number` 型別**不支援 `minimum` 與 `maximum`**。`confidence` 原本宣告 0 到 1 的範圍，整個請求被 400 拒絕，訊息是「For 'number' type, properties maximum, minimum are not supported」。範圍檢查因此移進 `validator`——**搬過去反而更安全**，因為提示詞路徑本來就沒有 API 端的 schema 保證，現在兩條路受同一份範圍檢查約束。連同 §9.1 的三條，四條約束都由 `tests/extraction_validator_test.php` 釘住。

**模型對百分比的表示不一致**：`f5` 的一次執行把評分比例 40%／25%／35% 抽成 `0.4`／`0.25`／`0.35`，於是不變式四（加總為 100）判定為「加總是 1」。驗證器有抓到並記為附註（因為模型同時填了 `unresolved`），但這是提示詞要處理的事——`DM-SYLLABUS` 的 `grading[].weight` 語意是百分比數值而不是比例，系統指令要講明。第三階段接線時補。

**一個差點被當成已驗證的陷阱**：原本的雙欄樣本 `f2` 是用巢狀表格排的，預期抽出的文字會左右欄交錯。**實測沒有交錯**——TCPDF 依邏輯順序輸出儲存格，抽出來仍是第 1 到 6 週的正確順序。若沒有去讀那份抽出的文字，這份量測會宣稱「測過雙欄交錯」，而其實一次都沒測到。`f5` 改用絕對定位、刻意先寫右欄再寫左欄，抽出的文字才真的亂了順序。**版面陷阱要先驗過它真的存在，才能說測過它。**

---

## 10. 既有 Python 專案的移除（`OI-05`，已執行）

**狀態：已完成。** 團隊確認本分支為完全獨立的版本後執行，時機早於下方 §10.3 原本規劃的「第一階段完成之後」——環境檔案已寫齊但尚未實跑。此處保留原規劃與實際落差的紀錄。

### 10.1 抽出要保留的——實際結果與原規劃不同

| 原規劃保留           | 實際處置                                                     |
| -------------------- | ------------------------------------------------------------ |
| PDF 文字抽取邏輯     | **重寫而非搬移**。原邏輯是四行標準 pypdf 呼叫，沒有搬移價值；有價值的是「pypdf 這個選擇可用」這件事。`eval/` 下改為重新實作，並補上原版缺少的無文字層偵測（`FR-SYL-01` 的驗收條件之一） |
| 課綱資料結構的雛形   | **不保留**。`DM-SYLLABUS` 已完全取代它，且原結構沒有 `confidence`、`source_span`、`overridden` 這些關鍵欄位 |
| 中文場景寫法         | 不移轉，僅作為風格參考；bdd-guide §5.1 已吸收                |

另外查核了 `uploads/`（4 MB，五個檔案）是否含可用於 `OI-03` 測試集的課綱：**沒有**。內容是兩份論文、一份進度表與一份 ERP 題庫，都是開發期的測試上傳，與課綱無關。

> 一項提醒：原專案的 `app/routes/syllabus.py` 中，名為 `parse_syllabus_from_text()` 的函式**完全沒有解析**——它只是依起始日期產生 `Week 1`、`Week 2` 這樣的空白週次列，原文文字讀進來後從未被解讀。這是整個舊專案最容易造成誤判的地方：看起來 §3.1 課綱分析快完成了，實際上是 0%。記在這裡，以免日後有人想「參考一下舊的實作」。

### 10.2 移除清單（已執行）

- `app/` 全部（含 `main.py` 與 `app/main.py` 兩個並存的入口）
- `fastapi_search.py`、`N8N_INTEGRATION.md`、`requirements.txt`
- 舊的 `docker-compose.yml`（SearXNG + Redis），由本專案的 compose 取代並沿用同一檔名
- `searxng/`、`searxng-settings.yml`、`settings.yml`、`settings-from-container.yml`
- `tests/`（`features/`、`step_definitions/`、`conftest.py`）
- `data/`（舊的 JSON 儲存）、`uploads/`（開發期測試上傳）
- `structure.txt`（UTF-16 的 `tree` 傾印）、`gcm-diagnose.log`、`__pycache__/`
- `docs/structure.md`（僅有標題的殘骸，內容已由本文件 §2.1 與 §7.1 取代）

全部檔案在刪除前都已在 git 追蹤中，因此可自歷史復原（`git show <commit>:<path>`）。這是刪除前唯一需要確認的事，也是本次能放心一次刪完的原因。

### 10.3 原規劃的執行時機（保留供參照）

原訂**在第一階段完成之後、第三階段開始之前**。

不要更早：第一階段還在驗證 Moodle 環境跑不跑得起來，此時保留舊專案作為可對照的參考沒有成本。也不要更晚：第三階段開始寫課綱解析時，兩套並存的解析邏輯會造成混淆。

實際提前執行的理由是團隊確認此分支為完全獨立版本，且舊專案已無任何被參照的可能——`§10.1` 的查核顯示沒有值得搬移的內容。

---

## 11. 設計決定索引

| 編號   | 決定                                       | 章節  | 相關需求                          |
| ------ | ------------------------------------------ | ----- | --------------------------------- |
| `A-01` | 目標平台 Moodle 5.2 / PHP 8.3–8.4          | 標頭  | `D-09`                            |
| `A-02` | 課綱以整份 JSON 存放，事件對應抽表         | §3.1  | `FR-DIF-01`、`FR-CAL-01`、`NFR-REL-04` |
| `A-03` | 資料庫採 PostgreSQL 16                     | §3.4  | `D-04`                            |
| `A-04` | 排程任務採游標分批，單次處理量有上限       | §4.2  | `NFR-REL-03`、`NFR-PRF-02`        |
| `A-05` | 去識別化規則以資料共用，實作不共用         | §6.2  | `NFR-SEC-06`、`OI-03`             |
| `A-06` | Makefile 每行僅一個 compose 呼叫，邏輯放腳本 | §7.5  | `NFR-MNT-05`                      |
| `A-07` | `selenium` 與 `eval` 以 profile 排除於預設啟動；映像一律釘版本 | §7.3  | `NFR-MNT-05`                      |
| `A-08` | Moodle 核心置於具名磁碟區而非 bind mount   | §7.2.2 | `NFR-PRF-04`、`D-04`             |
| `A-09` | 快取／暫存目錄一併移出 bind mount          | §7.2.3 | `NFR-PRF-04`、`D-04`             |
| `A-10` | 通知內容只由語言字串與結構化參數組成       | §4.7  | `NFR-SEC-06`、`NFR-REL-02`        |
| `A-11` | 抽取器由站台設定以字串指名，不在程式碼中交叉引用 | §9.2  | `NFR-EXT-03`、`NFR-MNT-02`        |
| `A-12` | 原生與提示詞模式由設定切換，不自動回退   | §9.4  | `NFR-EXT-03`                      |
| `A-13` | 外送文字必經過濾，且 AI 呼叫只從單一收口點發出 | §9.7  | `NFR-SEC-06`                      |
| `A-14` | 外送過濾元件自第三階段提前到第二階段建立   | §6    | `NFR-SEC-06`、`FR-SUM-01`         |
| `A-15` | 過濾規則與樣本置於外掛目錄，不置於專案根目錄 | §6.2  | `NFR-SEC-06`、`NFR-MNT-02`        |
| `A-16` | 摘要只送結構化事實，不送課綱原文           | §8.3.2 | `FR-SUM-01`、`NFR-SEC-06`        |
| `A-17` | 冪等鍵為教學週而非日曆週；版本不覆蓋       | §3.2  | `FR-SUM-01`、`NFR-REL-04`         |
| `A-18` | 課綱先抽成文字再送，不直接送 PDF           | §9.10 | `FR-SYL-02`、`FR-SYL-03`、`NFR-SEC-06` |
| `A-19` | 併入 `smalot/pdfparser`；已知中文解碼缺陷不修，列為待驗 | §9.10 | `FR-SYL-01`、`NFR-MNT-02`、`NFR-MNT-04` |
| `A-20` | 不使用 filepicker 的 `accepted_types`，格式檢查與訊息自理 | §8.3.4 | `FR-SYL-01`、`NFR-USA-03` |
| `A-21` | 教師姓名由 Moodle 的課程角色取得，不由課綱推導；邊界外的姓名要看得見 | §8.3.5 | `NFR-SEC-06` |
| `A-22` | 自動修正一律經 `make fix`，排除併入的第三方目錄 | §8.3.6 | `NFR-MNT-04` |
| `A-23` | 確認介面不顯示信心數值，只顯示「需要確認」的標示 | §8.3.7 | `FR-SYL-03` |
| `A-24` | 課程事件建成行事曆事件而非活動模組         | §8.3.9 | `FR-CAL-01`、`NFR-REL-04`         |
| `A-25` | 填入課程由老師觸發，不由觀察者自動排入     | §8.3.10 | `FR-CRS-01`                      |

**待決**：§9.9 的第 2 題（`DM-SYLLABUS` 完整 schema 已於第三階段撰寫，剩下的是 `OI-03` 測試集的正確率量測）與第 3 題（多供應商排序，已緩解但未驗證）。第 1 題已由 `A-18` 結案。
