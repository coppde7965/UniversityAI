# UniversityAI BDD 規範

| 項目     | 內容                                                         |
| -------- | ------------------------------------------------------------ |
| 版本     | v1.0                                                         |
| 狀態     | 待評審                                                       |
| 上游文件 | [SRS.md](SRS.md) v1.4（需求編號、驗收條件、`D-07`、`D-09`）  |
| 框架     | Behat（驗收）＋ PHPUnit（單元），皆為 Moodle 內建            |
| 適用範圍 | `local_universityai`、`block_universityai`、`aiprovider_claude` |

## 1. 這份文件的定位

SRS 定義**要做什麼**與**怎樣算做完**，這份文件定義**怎麼驗證做完了**。

它不重複 SRS 的驗收條件，而是回答四個問題：每一條驗收條件該用哪一種測試來驗、feature 檔怎麼寫與放哪、外部相依（語言模型、Google、寄信、時間）怎麼替換掉、以及測試與需求編號如何互相追溯。

> **框架已隨 `D-07` 改變**：由 pytest-bdd 改為 Behat。既有的 `tests/features/syllabi.feature` 與 `tests/step_definitions/` 屬 Python 專案，隨 `OI-05` 移除，不需遷移其內容——但其中「英文關鍵字、繁體中文場景」的寫法沿用（§5.1）。

---

## 2. 四個驗證層級

SRS §9 原本只給了 Behat 與 PHPUnit 兩層，但實際盤點後有四類驗收條件落在這兩層之外。若不先把層級講清楚，寫 feature 檔時會出現「試圖用 Behat 驗正確率」這種根本寫不出來的場景。

| 層級   | 工具                          | 驗什麼                                             | 何時跑              |
| ------ | ----------------------------- | -------------------------------------------------- | ------------------- |
| **L1** | PHPUnit                       | 純資料轉換、規則判定、邊界條件、不可觀察的內部約束 | 每次提交（`make test`） |
| **L2** | Behat                         | 使用者可觀察的行為、權限、跨頁面流程               | 每次提交（`make behat`） |
| **L3** | Python 評測工具鏈             | 語言模型輸出的品質——正確率、拒答適當性、延遲分布   | 里程碑（`make eval`） |
| **L4** | 人工                          | 需要真人判斷或真實外部服務的項目                   | 驗收會與口試前      |

### 2.1 選哪一層

依序問三個問題，第一個答「是」的就是該層：

1. **這條件的真偽能不能只看資料判定，不需要開瀏覽器？** → L1。差異嚴重度判定、schema 驗證、去識別化過濾、冪等性，全都屬於這類。**L1 能驗的就不要寫成 L2**——Behat 場景慢一到兩個數量級，而且失敗時的訊息遠不如 PHPUnit 精確。
2. **這條件描述的是使用者看得到或做得到的事嗎？** → L2。權限被擋、按鈕出現、低信心項目被標示、通知出現在站內。
3. **這條件的判定標準是統計量或人的主觀感受嗎？** → L3 或 L4。

### 2.2 一條硬規則

**L3 與 L4 不能單獨滿足「必要」需求。** 每一條版本標記為「必要」的需求，至少要有一個 L1 或 L2 的自動化驗證；L3、L4 只能疊加在其上。

理由是 L3 與 L4 都不在提交流程裡跑——正確率量測要花錢與時間，人工驗收一學期只做幾次。若某條必要需求的唯一防線是 L3，那它在日常開發中等於沒有防線，改壞了不會有人知道。

以 `FR-SYL-02` 為例：L3 負責「正確率 ≥ 80%」，但 L1 必須同時守住「輸出通過 schema 驗證、失敗會重試、重試耗盡會記錄失敗」。前者衡量做得好不好，後者保證不會壞掉。

> 這一節的結論須回填一句到 SRS §9，把原本的兩層敘述改為四層。

---

## 3. 需求與層級的對照

「主」欄是該需求的主要防線，「輔」欄是補強。空白表示不適用。

### 3.1 功能需求

| 需求         | 版本 | 主   | 輔     | 說明                                                         |
| ------------ | ---- | ---- | ------ | ------------------------------------------------------------ |
| `FR-SYL-01` | 必要 | L2   | L1     | L2 驗權限與格式拒絕的訊息；L1 驗無文字層 PDF 的偵測          |
| `FR-SYL-02` | 必要 | L1   | L3     | L1 驗 schema 驗證與重試（用假供應商）；L3 量測正確率         |
| `FR-SYL-03` | 必要 | L1   | L2     | L1 驗 `source_span` 確實出現在原文、`confidence` 落在 0–1；L2 驗低信心項目在介面上被標示 |
| `FR-SYL-04` | 必要 | L2   | L1     | 確認關卡是典型的使用者流程；L1 驗「未確認即拒絕下游呼叫」    |
| `FR-CRS-01` | 必要 | L2   | L1     | L1 驗冪等性與覆寫提示的判定                                  |
| `FR-CRS-02` | 必要 | L1   | L2     | 章節數量與名稱對應是資料判定；L2 驗老師看得到結果            |
| `FR-CAL-01` | 必要 | L1   | L2     | 事件建立與去重是資料判定                                     |
| `FR-CAL-02` | 必要 | L1   | L2     | **L1 直接抓訂閱網址比對 `VEVENT`**——這是本需求的核心；L2 驗介面取得網址與重設權杖 |
| `FR-CAL-03` | 必要 | L1   | L2、L4 | L1 用假的 Google 用戶端驗推送與去重；L2 驗授權入口與撤銷提示；L4 做一次真實 OAuth 貫通 |
| `FR-CAL-04` | 必要 | L1   |        | 純紀錄寫入                                                   |
| `FR-NTF-01` | 必要 | L1   | L2、L4 | L1 用 Moodle 的訊息攔截驗收件者與內容；L2 驗站內通知；L4 目視信件攔截服務 |
| `FR-NTF-02` | 必要 | L1   | L2     | 對象限定與去重是資料判定，且**是最不該只靠人工檢查的一項**   |
| `FR-NTF-03` | 必要 | L1   |        | 觀察者是否排入臨機任務、是否不阻塞，皆為內部行為             |
| `FR-NTF-04` | 目標 | L1   | L2     | severity 決定通知對象，屬規則判定                            |
| `FR-SUM-01` | 必要 | L2   | L1     | L2 驗摘要發布後師生看得到；L1 驗三個區塊齊備與「本週無安排」 |
| `FR-CHT-01` | 目標 | L2   | L3     | L2 驗區塊出現、可提問、回答附出處；L3 量測題組正確率         |
| `FR-CHT-02` | 目標 | L3   | L2     | 「五題無解全數拒答」本質是題組量測；L2 只取一個代表場景      |
| `FR-CHT-03` | 必要 | L2   | L1     | **權限需求，L2 是主防線**：未選課者、被退選者、竄改課程 id 者皆須被擋 |
| `FR-DIF-01` | 目標 | L1   |        | SRS §9 已明示屬純資料轉換                                    |
| `FR-DIF-02` | 目標 | L1   | L2     | L2 驗老師逐項決定的介面                                      |
| `FR-DIF-03` | 目標 | L1   | L3     | L1 驗「清單為空則不產生說明」；L3 驗說明未增添未發生的變更   |
| `FR-DSH-01` | 必要 | L2   |        | 使用 Moodle 既有儀表板，驗的是事件確實出現                    |
| `FR-DSH-02` | 目標 | L2   | L1     | L2 驗權限與呈現；L1 驗統計數字                               |
| `FR-AUT-01` | 必要 | L2   |        | 登入與角色可見性，典型的 Behat 題材                          |
| `FR-AUT-02` | 必要 | L2   | L1     | 取消選課後即失去存取，須以 L2 走完整流程                     |

### 3.2 非功能需求

| 群組                      | 主要層級 | 備註                                                         |
| ------------------------- | -------- | ------------------------------------------------------------ |
| `NFR-USA-01`              | L4       | 三位受測者的可用性測試，無法自動化；須留下逐字紀錄與待改清單 |
| `NFR-USA-02`、`NFR-USA-03` | L2       | 確認步驟與錯誤訊息的可行動性                                 |
| `NFR-PRF-01`              | L2       | 驗「上傳後立即回到處理中狀態」，不驗絕對秒數                 |
| `NFR-PRF-02`~`04`         | L3／L4   | 延遲分布與頁面載入須實測，不進提交流程                       |
| `NFR-SEC-01`              | L2       | 每個進入點各一個「未授權者被擋」場景                         |
| `NFR-SEC-02`              | L2       | 重設權杖後舊網址失效                                         |
| `NFR-SEC-03`~`05`         | L1       | 含金鑰不出現在記錄檔的斷言                                   |
| **`NFR-SEC-06`**          | **L1**   | **以刻意含個資的樣本驗過濾輸出不含黑名單項目**，見 SRS 的具體規則一節 |
| `NFR-SEC-07`              | L1       | Moodle 提供 privacy provider 的測試基底類別，照用即可        |
| `NFR-REL-01`~`04`         | L1       | 重試、故障隔離、可重跑，全部是資料判定                       |
| `NFR-EXT-01`~`03`         | L1       | `NFR-EXT-03` 的驗法：以兩個不同實作跑同一組抽取測試，結果皆通過 schema |
| `NFR-MNT-04`              | lint     | `moodle-plugin-ci` 的程式碼檢查，不是測試                    |
| `NFR-MNT-05`              | L4       | 換機實測：在乾淨機台上 `make up` 與 `make install`           |
| `NFR-MNT-06`              | lint     | 靜態檢查領域類別未引用 renderer、模板不含業務判斷            |
| 其餘 `NFR-MNT-*`          | L1       |                                                              |

---

## 4. 檔案位置與命名

三個外掛各自帶自己的測試，不設共用測試目錄——外掛必須能獨立安裝與獨立測試（`NFR-MNT-02`）。

```
local_universityai/
  tests/
    behat/
      syllabus_upload.feature          ← L2
      syllabus_confirm.feature
      course_populate.feature
      calendar.feature
      notification.feature
      weekly_summary.feature
      dashboard.feature
      behat_local_universityai.php     ← 自訂 step definition
    generator/
      lib.php                          ← PHPUnit 用資料產生器
      behat_local_universityai_generator.php   ← Behat 用資料產生器
    fixtures/
      syllabus_sample.pdf
      extraction_response.json         ← 假供應商的回放內容
    syllabus_parser_test.php           ← L1
    diff_engine_test.php
    privacy/provider_test.php
block_universityai/
  tests/behat/chat.feature
  tests/behat/behat_block_universityai.php
aiprovider_claude/
  tests/provider_test.php
```

**命名規則**

- feature 檔一檔一個功能面向，檔名為小寫底線，不含需求編號——需求編號放在 tag，因為一個 feature 常橫跨多條需求，寫進檔名反而綁死。
- step definition 類別名為 `behat_<frankenstyle>`，檔案同名，繼承 `behat_base`。這是 Moodle 的硬性規定，不能自訂。
- 資料產生器類別為 `behat_<frankenstyle>_generator`，繼承 `behat_generator_base`，放在 `tests/generator/` 下。

> **路徑對應**：Moodle 5.2 的外掛實際安裝位置是 `public/local/universityai/`（`D-09`）。上方以外掛根目錄為基準表示，掛載對應關係見架構文件。

---

## 5. 撰寫慣例

### 5.1 語言

**Gherkin 關鍵字用英文，場景內容用繁體中文。**

```gherkin
@local_universityai @FR-SYL-04
Feature: 課綱解析結果的確認
  身為授課教師
  我需要在系統動用解析結果之前逐項確認
  以免 AI 的誤判直接寫進課程

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | 王         | 小明      |
    And the following "courses" exist:
      | fullname     | shortname |
      | 人工智慧導論 | AI101     |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | AI101  | editingteacher |

  Scenario: 未確認的課綱不得用於填入課程
    Given 課程 "AI101" 有一份狀態為 "待確認" 的課綱
    When 我以 "teacher1" 身分登入並進入課程 "AI101" 的課綱頁面
    Then 我不應該看到 "填入課程資訊" 按鈕
```

關鍵字保持英文有兩個實際原因：Moodle 核心提供的數百個 step 全是英文，混用中文關鍵字會讓自訂 step 與核心 step 在同一個檔案裡風格分裂；而 `moodle-plugin-ci` 的 gherkin lint 規則是針對英文關鍵字設定的。

### 5.2 名詞一律取自 SRS §1.6

課綱、課綱版本、課程、週次、課程事件、解析結果、已確認課綱、通知、排程任務、臨機任務。

這不是文件潔癖。Behat 的 step 是**以字串比對**綁定實作的：「已確認的課綱」與「確認過的課綱」會被當成兩個不同的 step，各需一份實作。同義詞就是重複程式碼。

### 5.3 三段式的分工

- **Given** 建立狀態，不做被測行為。一律用資料產生器，不要用一連串 When 點擊去「操作出」前置狀態——那會讓一個場景失敗時，你分不清是前置壞了還是被測行為壞了。
- **When** 只放**一個**被測動作。
- **Then** 只做斷言，不改變狀態。

### 5.4 gherkin lint 的硬性規則

Moodle 倉庫根目錄有一份 `.gherkin-lintrc`，以下規則違反即失敗：

- 縮排固定：`Feature` 0 格、`Background` 與 `Scenario` 2 格、`Step` 4 格。
- 每個 Feature 與 Scenario 都必須有名稱。
- 全站不得有重名的 Feature。
- 檔案不得為空、不得沒有任何 Scenario、不得有連續空行或行尾空白。
- 檔尾必須有換行。
- `Scenario Outline` 必須附 `Examples`。

> **執行方式與原先寫的不同。** `moodle-plugin-ci` **沒有** `gherkinlint` 子命令（實測確認，完整清單可用 `moodle-plugin-ci list` 查）。這份設定是給 Moodle 自己的 grunt 任務用的，要跑 feature 檔的格式檢查得經 `moodle-plugin-ci grunt`，而那需要先在 Moodle 目錄下 `npm install`。因此 `make lint` 目前**不含**這一項，規則仍然有效但靠人遵守；第四階段真的有 feature 檔之後再評估要不要把 grunt 納入。

### 5.5 場景的粒度

一個場景驗**一件事**。與其寫一個十五步的「完整流程」場景，不如拆成五個各驗一個判定點的場景——前者失敗時你只知道「流程壞了」，後者直接告訴你壞在哪一步。

例外是刻意的端到端場景，每個外掛至多一個，標記 `@endtoend`，用來確認各部分接得起來。它不取代細粒度場景。

---

## 6. Tag 規範

每個 feature 檔的檔頭至少兩個 tag：

```gherkin
@local_universityai @FR-SYL-02 @FR-SYL-03
Feature: ...
```

| Tag              | 用途                                                       |
| ---------------- | ---------------------------------------------------------- |
| `@<外掛名稱>`    | **必要**。Moodle 用它篩選要跑哪個外掛的測試                |
| `@FR-xxx-nn`     | **必要**。追溯到 SRS 需求；一個 feature 可掛多個           |
| `@NFR-xxx-nn`    | 該場景同時驗證某條非功能需求時加上                         |
| `@javascript`    | 場景需要真實瀏覽器執行 JS 時**必加**，否則用無頭驅動會失敗 |
| `@endtoend`      | 端到端場景，可在快速回合中排除                             |

**`@javascript` 是最常被忘記的一個。** 聊天區塊、確認介面的逐項編輯、任何 AJAX 更新的斷言，全都需要它。忘了加的症狀是「本機看畫面明明有，測試說找不到元素」。加了它才會走 `selenium` 容器（SRS §2.5）。

需求編號 tag 可掛在 Feature 層或 Scenario 層。掛 Feature 表示整份 feature 都服務該需求；某個場景另外驗到別條需求時，掛在該 Scenario 上。

---

## 7. Step definition 的組織

### 7.1 先找核心有沒有

Moodle 核心提供大量現成 step——登入、導覽、表單填寫、點擊、可見性斷言、權限設定、行事曆、通知。**寫任何自訂 step 之前先查核心是否已有**，重複實作會造成 step 定義衝突，Behat 會直接報錯而不是選一個用。

查法：`vendor/bin/behat --config <behat.yml> --definitions=i`（列出所有可用 step）。

### 7.2 自訂 step 的界線

只在下列情況自訂：

- 建立本外掛專屬的前置狀態，且資料產生器表達不了（多數情況資料產生器就夠了，見 §8）。
- 斷言本外掛專屬的資料狀態（例如「課綱狀態應為已確認」）。
- 操作本外掛專屬的 UI 元件。

自訂 step 一律放在該外掛的 `behat_<frankenstyle>.php`，不得跨外掛共用——`block_universityai` 需要 `local_universityai` 的 step 時，代表該狀態應該由資料產生器建立，而不是由另一個外掛的 step 建立。

### 7.3 自訂 step 的寫法

```php
/**
 * @Given /^課程 "([^"]*)" 有一份狀態為 "([^"]*)" 的課綱$/
 */
public function course_has_syllabus_with_status(string $shortname, string $status): void {
    // 直接呼叫外掛的 API 建立狀態，不要透過 UI 操作。
}
```

正規表示式中的中文直接寫，不需跳脫。參數一律用 `"([^"]*)"` 包起來，讓場景讀起來能一眼看出哪些是可變值。

---

## 8. 測試資料

### 8.1 優先使用 Moodle 內建產生器

使用者、課程、選課、角色、活動一律用核心產生器，不要自己寫 step：

```gherkin
Given the following "users" exist:
  | username | firstname | lastname |
  | student1 | 陳         | 大文      |
And the following "course enrolments" exist:
  | user     | course | role    |
  | student1 | AI101  | student |
```

### 8.2 外掛專屬資料用自訂產生器

在 `tests/generator/behat_local_universityai_generator.php` 中宣告可建立的實體，即可在場景中這樣用：

```gherkin
Given the following "local_universityai > syllabuses" exist:
  | course | version | status    | title        |
  | AI101  | 1       | confirmed | 人工智慧導論 |
```

產生器類別繼承 `behat_generator_base`，實作 `get_creatable_entities()` 宣告每個實體的 `datagenerator`、`required` 欄位與 `switchids`（把 `course` 這類人類可讀的值換成 id）。核心的 `behat_core_ai_generator` 是可直接參考的範例。

**這比自訂 step 好**：宣告式、可讀性高、且同一份產生器 PHPUnit 也能用。

### 8.3 AI 供應商執行個體

核心的 `core_ai` 自帶 Behat 產生器，可直接宣告式建立供應商執行個體：

```gherkin
Given the following "core_ai > ai providers" exist:
  | provider         | name        | enabled |
  | aiprovider_claude | 測試用供應商 | 1       |
```

必填欄位為 `provider`、`name`、`enabled`。這省下了自己寫「啟用 AI 供應商」step 的功夫。

---

## 9. 外部相依的替換

這一節是本規範中最容易被做錯的部分。原則只有一條：**測試絕不接觸真實外部服務**。

### 9.1 語言模型

Behat 場景**不得呼叫真實 Claude API**——不可重現、要花錢、需要網路，且回應不穩定會讓測試隨機失敗。

`D-05` 的兩條呼叫路徑各有替換方式：

| 路徑                        | 替換方式                                                     |
| --------------------------- | ------------------------------------------------------------ |
| 生成類（經 AI 子系統）      | 建立 `aiprovider_claude` 執行個體，並將其 API 端點設定指向回放固定回應的樁服務。走完整的 manager → provider → process class 鏈路，只換掉最外層的 HTTP 目標 |
| 抽取類（經自訂介面）        | 以站台設定切換至回放 fixture 的實作。因為介面本來就設計為可替換（`NFR-EXT-03`），這不是測試後門而是既有的擴充點 |

生成類刻意不直接抽換 provider 類別，而是換端點——這樣 `aiprovider_claude` 本身的請求組裝與回應解析仍在受測範圍內，否則等於整個供應商外掛沒被測到。

> **待架構文件決定**：端點樁要做成 compose 中的獨立容器，還是由 `moodle` 容器內的固定路徑提供。獨立容器較乾淨但多一個服務；容器內路徑較簡便但要小心不隨外掛發行。

### 9.2 Google Calendar

`FR-CAL-03` 的推送不得在測試中真的呼叫 Google。用戶端須可注入，L1 以假用戶端驗證推送內容、更新與去重邏輯；L2 只驗授權入口與撤銷後的提示；真實 OAuth 貫通屬 L4，一學期做一次並留下紀錄即可。

### 9.3 寄信與站內通知

L1 用 Moodle 內建的訊息與郵件攔截（`redirectMessages()`、`redirectEmails()`）驗收件者、主旨與內文。L2 驗站內通知的呈現。信件攔截服務（SRS §2.5 的 `mail` 容器）是給人看的展示工具，屬 L4，**不是自動化測試的斷言來源**。

### 9.4 時間

「事件截止前通知」「每週摘要」這類需求依賴時間推移。不得以 `sleep` 或改系統時鐘處理——場景中一律以資料產生器把事件日期設在相對於當下的位置（例如「三天後到期」），再直接觸發對應的排程任務。

Moodle 提供以 CLI 執行單一排程任務的機制，L2 場景中觸發任務後再斷言結果，比等待 cron 可靠得多。

---

## 10. 執行方式

所有指令經 Makefile（SRS §2.5），不直接下 `docker compose`。

| 指令                                    | 作用                                       |
| --------------------------------------- | ------------------------------------------ |
| `make behat`                            | 跑全部 Behat 場景                          |
| `make behat TAGS=@local_universityai`   | 只跑單一外掛                               |
| `make behat TAGS=@FR-SYL-02`            | 只跑對應某條需求的場景，改需求時很有用     |
| `make behat TAGS="~@endtoend"`          | 排除端到端場景，快速回合                   |
| `make test`                             | PHPUnit                                    |
| `make lint`                             | `moodle-plugin-ci` 的程式碼與 gherkin 檢查 |
| `make eval`                             | L3 的正確率量測                            |

### 10.1 環境前提

- **Behat 需要獨立的 dataroot 與資料表前綴。** 設定 `$CFG->behat_dataroot`、`$CFG->behat_prefix`、`$CFG->behat_wwwroot`，與開發用的完全分開，否則跑一次測試就把開發資料清掉。
- **初始化**：首次執行與每次新增 feature 檔後都須重新產生 Behat 設定，這一步要包進 `make behat`，不能靠人記得。
- **`selenium` 容器是必要的**，凡 `@javascript` 場景都走它。
- Moodle 5.2 的 CLI 腳本位於 `public/` 之下（`D-09`），Makefile 的路徑須照此。

### 10.2 開發順序建議

先讓 `make lint` 通過，再寫第一個場景，再讓 `make behat` 跑起來。**不要累積十個 feature 檔才第一次執行**——Behat 的環境問題（設定未重生、缺 `@javascript`、step 定義衝突）都在第一次執行時集中爆發，越晚遇到越難拆解。

---

## 11. 需求追溯表

欄位依 SRS §9 約定。`FR-SYL-02` 那一列示範填法，其餘於各場景寫成後回填。

| 需求編號    | 層級 | 外掛                | 檔案／測試                                   | 場景或方法名稱                   | 架構章節 |
| ----------- | ---- | ------------------- | -------------------------------------------- | -------------------------------- | -------- |
| `FR-SYL-02` | L1   | `local_universityai` | `tests/syllabus_parser_test.php`             | `test_invalid_schema_triggers_retry` | 待填     |
| `FR-SYL-02` | L3   | —                   | `eval/accuracy.py`                           | 週次日期與事件日期正確率         | 待填     |
| `FR-SYL-01` | L2   | `local_universityai` | `tests/behat/syllabus_upload.feature`        | 待填                             | 待填     |
| `FR-SYL-03` | L1   | `local_universityai` | 待填                                         | 待填                             | 待填     |
| `FR-SYL-04` | L2   | `local_universityai` | `tests/behat/syllabus_confirm.feature`       | 待填                             | 待填     |
| `FR-CRS-01` | L2   | `local_universityai` | `tests/behat/course_populate.feature`        | 待填                             | 待填     |
| `FR-CRS-02` | L1   | `local_universityai` | 待填                                         | 待填                             | 待填     |
| `FR-CAL-01` | L1   | `local_universityai` | 待填                                         | 待填                             | 待填     |
| `FR-CAL-02` | L1   | `local_universityai` | 待填                                         | 待填                             | 待填     |
| `FR-CAL-03` | L1   | `local_universityai` | 待填                                         | 待填                             | 待填     |
| `FR-CAL-04` | L1   | `local_universityai` | 待填                                         | 待填                             | 待填     |
| `FR-NTF-01` | L1   | `local_universityai` | 待填                                         | 待填                             | 待填     |
| `FR-NTF-02` | L1   | `local_universityai` | 待填                                         | 待填                             | 待填     |
| `FR-NTF-03` | L1   | `local_universityai` | 待填                                         | 待填                             | 待填     |
| `FR-SUM-01` | L2   | `local_universityai` | `tests/behat/weekly_summary.feature`         | 待填                             | 待填     |
| `FR-CHT-03` | L2   | `block_universityai` | `tests/behat/chat.feature`                   | 待填                             | 待填     |
| `FR-DSH-01` | L2   | `local_universityai` | `tests/behat/dashboard.feature`              | 待填                             | 待填     |
| `FR-AUT-01` | L2   | `local_universityai` | 待填                                         | 待填                             | 待填     |
| `FR-AUT-02` | L2   | `local_universityai` | 待填                                         | 待填                             | 待填     |

上表只列「必要」需求（18 條），是完整性的最低標準。「目標」需求的場景寫成後一併加入。

**維護規則**

- 需求變更時，以編號搜尋 tag，同步檢查引用該編號的所有 feature 檔與測試方法。
- 新增場景時同步補一列，不要留到最後補——最後補等於重讀所有測試檔。
- 某條「必要」需求在此表中沒有 L1 或 L2 的列，即違反 §2.2 的硬規則，視為未完成。

---

## 12. 反模式

以下每一項都在真實專案中反覆出現，且都有明確代價：

| 反模式                                | 為什麼是問題                                                 |
| ------------------------------------- | ------------------------------------------------------------ |
| 用 Behat 驗證資料轉換規則             | 慢一到兩個數量級，失敗訊息模糊。差異嚴重度、schema 約束一律 L1 |
| 用一連串 When 建立前置狀態            | 場景失敗時分不清是前置還是被測行為壞了                       |
| 場景中出現 CSS 選擇器或元素 id        | 改版面就全部失效。應以使用者看得到的文字定位                 |
| 場景之間互相依賴執行順序              | Behat 不保證順序，且單獨重跑一個場景會失敗                   |
| 忘記 `@javascript`                    | 症狀是「畫面明明有，測試找不到」，浪費時間最多的一種         |
| 讓測試呼叫真實 Claude API 或 Google   | 不可重現、花錢、隨機失敗，且外部服務故障時整條 CI 掛掉        |
| 以 `sleep` 處理非同步                 | 慢且不穩。應直接觸發任務再斷言                               |
| 一個場景驗五件事                      | 失敗時只知道流程壞了                                         |
| 場景名稱寫「測試課綱功能」            | 名稱應描述被驗證的行為與預期結果                             |
| 需求編號 tag 與 SRS 不同步            | 追溯表失效，需求變更時漏改                                   |

---

## 13. 尚待決定

| 項目                                    | 由誰決定             | 影響                             |
| --------------------------------------- | -------------------- | -------------------------------- |
| AI 端點樁做成獨立容器或容器內路徑       | `architecture.md`    | §9.1，影響 compose 與 Makefile   |
| 外掛專屬資料產生器的實體與欄位          | `architecture.md`    | §8.2，須先定案資料表設計         |
| L3 評測腳本的介面與輸出格式             | `architecture.md`    | §11 追溯表的 L3 欄位             |
| SRS §9 補上四層驗證的敘述               | 本文件作者           | §2.2 的結論回填                  |

另有一項不阻擋撰寫、但會影響場景數量的事實：`FR-CHT-01` 與 `FR-CHT-02` 屬「目標」版本，時程不足時採縮小版（SRS §8.4 第四階段）。屆時 `block_universityai` 的場景範圍隨之縮小，但 `FR-CHT-03` 的權限場景是「必要」，不得一併縮減。
