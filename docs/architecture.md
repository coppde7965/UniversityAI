# UniversityAI 系統架構

| 項目     | 內容                                                                 |
| -------- | -------------------------------------------------------------------- |
| 版本     | v0.9（AI 章節待補）                                                  |
| 狀態     | 待評審                                                               |
| 上游文件 | [SRS.md](SRS.md) v1.4                                                |
| 下游文件 | [bdd-guide.md](bdd-guide.md) v1.0                                    |
| 目標平台 | Moodle 5.2 / PHP 8.3–8.4（`D-09`、`A-01`）／PostgreSQL 16（`A-03`） |

## 0. 本文件的範圍與缺口

本文件回答「怎麼做」。每個設計決定都應能追溯到 SRS 的需求編號或 §2.2 的決策；若此處出現 SRS 沒有的功能，代表 SRS 漏寫，回頭補 SRS。

**§9 的 AI 呼叫設計目前是空的。** `D-05` 的兩條呼叫路徑是讀 Moodle 5.2 原始碼推導出來的——讀原始碼能確定子系統不支援什麼，但確定不了自製供應商實際跑起來的行為。SRS §8.4 第一‧五階段的三項驗證是這一章的前置條件，未完成前不寫，寫了也只是猜測。

其餘章節不受此阻擋，因為它們的相依方向是單向的：資料表、任務、權限、環境都不依賴 AI 呼叫怎麼實作，只依賴它「會被呼叫」與「可能失敗」。

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
├── index.php                          進入點：課程內的課綱總覽
├── upload.php                         進入點：上傳（FR-SYL-01）
├── review.php                         進入點：確認介面（FR-SYL-04）
├── ops.php                            進入點：營運狀態（FR-DSH-02）
├── db/
│   ├── install.xml                    資料表定義（§3）
│   ├── access.php                     能力定義（§5）
│   ├── tasks.php                      排程任務註冊（§4）
│   ├── events.php                     事件觀察者註冊（§4.3）
│   └── upgrade.php
├── classes/
│   ├── api.php                        門面（§1.2）
│   ├── syllabus.php                   Model：\core\persistent
│   ├── syllabus_repository.php        Model：版本查詢
│   ├── diff_engine.php                Model：純函式比對（FR-DIF-01）
│   ├── event_mapper.php               Model：課綱事件↔Moodle 事件（FR-CAL-01）
│   ├── ical_builder.php               Model：訂閱內容（FR-CAL-02）
│   ├── redaction/
│   │   ├── filter.php                 Model：外送過濾（NFR-SEC-06，§6）
│   │   └── ruleset.php                Model：規則載入
│   ├── extraction/
│   │   ├── extractor_interface.php    抽取介面（§9，待補）
│   │   └── …
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
├── lang/en/local_universityai.php     字串（NFR-MNT-04）
└── tests/                             見 bdd-guide §4
```

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

**`A-04`　排程任務一律採游標分批，單次處理量有上限。**

`weekly_summary` 不得一次處理全站課程。設計為：以 `courseid` 為游標，單次處理不超過 `batchsize`（站台設定，預設 50）門課，處理完的游標存於外掛設定，下次自本次結束處記錄處續跑；一輪跑完後游標歸零。

理由有二。其一是逾時：PHP 的執行時間與記憶體都有限，全站兩百門課乘上一次語言模型呼叫必然撞牆。其二是 `NFR-REL-03` 的故障隔離——單一課程失敗不得使整個排程任務中止，所以每門課的處理各自包在 try/catch 內，失敗寫 `_ops` 後繼續下一門，而不是讓例外往上拋。

> 這個設計的代價是「每週摘要」實際上不保證在同一分鐘內全部發出。這是可接受的：摘要是週期性資訊，不是即時通知。

### 4.3 臨機任務

| 類別                     | 由誰排入                       | 職責                                     |
| ------------------------ | ------------------------------ | ---------------------------------------- |
| `task\parse_syllabus`    | `syllabus_uploaded` 觀察者     | 文字抽取 → 結構化抽取 → 寫入（`FR-SYL-02`） |
| `task\populate_course`   | `syllabus_confirmed` 觀察者    | 填課程 → 建章節 → 建事件（`FR-CRS-*`、`FR-CAL-01`） |
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

- **課程與章節**：`populate_course` 以「目標狀態」寫入而非「新增」——章節依 `week_no` 對位更新，數量不足才新增。
- **課程事件**：靠 `_evtmap` 的唯一鍵（§3.2）。
- **通知**：`FR-NTF-02` 的去重需要一份「已對誰發過什麼」的紀錄。以 `_ops` 中 `operation='notify'` 的既有紀錄作為去重依據，查詢條件為 `(targettype, targetid)` 加通知類型——這正是該索引存在的第二個理由。

### 4.6 事件觀察者（`db/events.php`）

| 事件                                    | 來源     | 動作                                            |
| --------------------------------------- | -------- | ----------------------------------------------- |
| `\local_universityai\event\syllabus_uploaded` | 自訂 | 排入 `parse_syllabus`                           |
| `\local_universityai\event\syllabus_confirmed` | 自訂 | 排入 `populate_course`                          |
| `\core\event\user_enrolment_deleted`    | 核心     | 停止對該使用者的行事曆推送與通知（`FR-AUT-02`） |
| `\core\event\course_deleted`            | 核心     | 清除該課程的外掛資料（`NFR-SEC-07` 的一部分）   |

自訂事件同時滿足 `NFR-SEC-04` 的操作紀錄要求——Moodle 事件會自動進入標準記錄檔，含操作者與時間，且一般使用者無法刪改。**不要另建一套稽核表**。

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

### 6.1 位置與形狀

`classes/redaction/filter.php`，純函式，無 IO、無資料庫存取、無設定讀取（規則由建構子注入）。這讓它在 bdd-guide 的 L1 層可被完整覆蓋。

所有送往語言模型的內容都必須先經過它。收口的實作方式：**抽取介面與生成呼叫的包裝層都只接受 `filtered_payload` 型別的參數**，而 `filtered_payload` 只能由 `filter::apply()` 產生。型別系統因此保證了無繞過路徑——比「約定大家記得呼叫」可靠。

### 6.2 `A-05`　規則以資料共用，實作不共用

SRS 的 `NFR-SEC-06` 寫「與 `OI-03` 測試集去識別化共用同一份實作」。**這句話在跨語言的現實下無法照字面做到**：過濾在 PHP（產品），測試集去識別化在 Python（評測工具鏈，SRS §2.5）。

可行且達成同樣目的的形式是：

1. 規則定義為一份與語言無關的資料檔（`redaction-rules.json`），置於專案根目錄，PHP 與 Python 各自讀取。
2. 一組共用的黃金樣本：輸入含個資的文字，與期望的過濾結果。PHP 端以 PHPUnit 驗、Python 端以其測試框架驗，**兩邊跑同一組樣本**。
3. 任一邊新增規則時必須同步新增樣本，否則另一邊會因未實作而測試失敗——這使不同步變成一個會被自動發現的錯誤，而不是靠人記得。

這比字面上的「同一份實作」更弱，但它是可執行的；字面版本不可執行。**此處與 SRS 的措辭有落差，已在此標明**，SRS 的該句應於下次修訂時改為「共用同一份規則定義與驗證樣本」。

### 6.3 錯誤訊息也要過濾

`_ops.error` 與記錄檔的內容須經同一元件。外部服務的錯誤回應經常把整段請求內容回貼在訊息裡，這是最容易漏掉的外送路徑——它不是「送出」，但它會把內容寫進記錄檔，而記錄檔會被匯出、被截圖、被貼進問題回報。

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

> `moodledata` 仍在專案目錄下（`D-04` 的可攜性要求，裡面有老師上傳的課綱原檔）。它的每請求 I/O 量遠小於核心，目前未成為瓶頸；若日後成為問題，`$CFG->localcachedir` 與 `$CFG->tempdir` 可單獨移出。

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

**全部映像一律釘版本，不用 `latest`。** 這與 `MOODLE_TAG` 釘標籤是同一個理由（`NFR-MNT-05`）：`latest` 會讓兩台機器在不同時間建出不同的環境，而環境差異造成的測試失敗是最難查的一類。

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

### 7.5 Makefile

**`A-06`　Makefile 的每一行 recipe 都是單一個 `docker compose` 呼叫，全部邏輯放在 `docker/bin/` 下的 POSIX sh 腳本裡。**

理由是 Windows 上的 GNU Make 會依環境選擇 `sh.exe` 或 `cmd.exe`，兩者的引號與跳脫規則不同。把 shell 迴圈或條件判斷寫進 Makefile，就得同時對兩套規則負責；推進容器則完全不必，且三種主機作業系統的行為完全一致。

這條規則不只是潔癖。第一版的 `clean-moodle` 曾經寫成 Makefile 裡的一行 `find ... -exec rm -rf`，而它有個真實的危險：對 bind mount 執行 `rm -rf` 的行為是**先刪掉裡面的檔案，最後才在刪除目錄本身時失敗**——一個看似安全的「刪不掉就算了」寫法會先把外掛原始碼刪光。移進 `uai-clean.sh` 之後才有空間以名稱明確排除那三個路徑，並寫下為什麼。

例外只有 `.env` 這條規則：`.env` 缺席時 Docker Compose 會以一句難懂的 `GetFileAttributesEx` 錯誤中止，因此由 Make 自己從 `.env.example` 補上並提醒改密碼。

SRS §2.5 已定下目標清單。幾個目標的實質內容：

| 目標               | 做什麼                                                       |
| ------------------ | ------------------------------------------------------------ |
| `make up`          | 起全部服務；首次執行時若 `moodle/` 不存在則取得釘住的 5.2 版本 |
| `make install`     | 跑 Moodle 的 CLI 安裝，安裝三個外掛，建立示範課程外殼（`D-10` 之後展示需要它） |
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
| `ical_builder`        | 純函式，輸入事件集合輸出 iCal 字串（`FR-CAL-02` 的主防線） |
| `event_mapper`        | 唯一鍵保證冪等，可用重複呼叫驗證                  |
| ViewModel             | `export_for_template()` 回傳純資料，可直接斷言    |

這不是巧合，是 §2.2 分層規則的結果——Model 層不碰輸出，自然就可測。

### 8.2 靜態檢查

`NFR-MNT-06` 的三條規則可用檢查執行，納入 `make lint`：

- `classes/` 下除 `output/` 外，不得出現 `use core\output`、`renderer`、`html_writer`、`moodle_url`。
- `templates/*.mustache` 不得出現比較運算（Mustache 本身即不支援，此項為防止以 helper 繞過）。
- 每個 `templatable` 實作的 `export_for_template()` 回傳型別須為 `stdClass` 或 `array`。

`moodle-plugin-ci` 涵蓋其餘的上架規範檢查（`NFR-MNT-04`）。

---

## 9. AI 呼叫設計（待補）

**本章在 SRS §8.4 第一‧五階段的三項驗證完成前不撰寫。**

完成後本章須回答：

1. 抽取介面 `extractor_interface` 的簽章——輸入是什麼型別、輸出是 `DM-SYLLABUS` 陣列還是值物件、失敗如何表達。
2. `aiprovider_claude` 的原生實作與通用實作如何切換，切換點是站台設定的哪一項（`NFR-EXT-03`）。
3. JSON Schema 驗證與重試放在哪一層——介面之上（呼叫端）或之下（實作內）。放在呼叫端的好處是兩個實作共用同一份驗證，這也是目前的傾向，但須由驗證結果確認。
4. `events[].event_id` 的推導規則（§3.2 的 `eventkey` 穩定性約束）。
5. 生成類呼叫經 `\core_ai\manager` 的包裝層長什麼樣，以及 `filtered_payload`（§6.1）如何在該路徑上收口。
6. AI 端點樁的形式：獨立容器或 `moodle` 容器內路徑（bdd-guide §9.1）。
7. 第一‧五階段三項驗證的實際結果，含失敗的部分。

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

**待決**：§9 全章，以及其中列出的七個問題。
