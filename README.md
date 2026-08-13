# UniversityAI

大學課程 AI 助理，以 **Moodle 5.2 外掛套組**的形式交付。

老師上傳課綱，系統解析出週次、課程事件與評分方式，經老師確認後自動填入課程資訊、建立週次章節與行事曆事件，並提供每週摘要、課綱更新比對與課程問答。

| 外掛                 | 作用                                                     |
| -------------------- | -------------------------------------------------------- |
| `local_universityai` | 核心：課綱處理、課程填入、行事曆、通知、摘要、比對、排程 |
| `block_universityai` | 課程頁面內的聊天區塊                                     |
| `aiprovider_claude`  | Claude API 的 Moodle AI 供應商                           |

## 快速開始

主機端只需 **Docker** 與 **GNU Make**，不安裝 PHP、Composer、Node 或 Python——全部在容器裡。

```
make up        # 起服務並取得釘住版本的 Moodle
make install   # 安裝站台、外掛與示範課程
```

首次執行 `make up` 會自動從 `.env.example` 建立 `.env`。**進去改掉 `POSTGRES_PASSWORD` 與 `MOODLE_ADMIN_PASSWORD` 再繼續。**

跑起來之後：

| 服務           | 位置                    |
| -------------- | ----------------------- |
| Moodle 站台    | http://localhost:8080   |
| 信件攔截       | http://localhost:8025   |
| Behat 瀏覽器畫面 | http://localhost:7900（`make behat` 之後才啟動） |

`make` 不帶參數會列出全部可用指令。

> 瀏覽器容器（近 2 GB）與 Python 評測容器不在預設啟動集合中，`make behat` 與 `make eval` 會各自把需要的拉起來。展示只需要 `make up` 與 `make install`。

實測從零開始：`make up` 約 20 秒（另加首次建映像的時間），`make install` 約 4 分鐘。

> **Windows 兩件事**
>
> GNU Make 不隨系統內建，需另行安裝（`winget install ezwinports.make`，或 scoop、WSL）。這是主機端唯一在 Docker 之外的安裝項目。
>
> 若你想直接在編輯器裡讀 Moodle 核心原始碼，把整個專案放到 **WSL2 的檔案系統**下（用 VSCode 的 WSL remote 開啟，路徑是 `\\wsl$\...` 而不是 `D:\...`）。Moodle 核心目前放在具名磁碟區裡（原因見 architecture.md `A-08`：Windows 的 bind mount 讀 400 個 PHP 檔要 10 秒，頁面因此載入 14 秒），在 WSL2 下 bind mount 是快的，可以改回掛在專案目錄下同時保留主機可見性。不想搬也沒關係，`make shell` 進容器一樣讀得到。

## 常用指令

```
make lint      # 程式碼與 feature 檔檢查
make test      # PHPUnit 單元測試
make behat     # Behat 驗收測試（TAGS=@FR-SYL-02 可指定）
make db-dump   # 匯出資料庫傾印，換機展示用
make logs      # 追蹤記錄
make shell     # 進入工具容器
```

## 文件

先讀 [docs/README.md](docs/README.md)，它說明四份文件的關係與閱讀順序。

| 文件                                     | 內容                                             |
| ---------------------------------------- | ------------------------------------------------ |
| [docs/SRS.md](docs/SRS.md)               | 需求規格。需求編號、驗收條件、資料模型、架構決策 |
| [docs/architecture.md](docs/architecture.md) | 架構設計。外掛邊界、分層、資料表、任務、環境 |
| [docs/bdd-guide.md](docs/bdd-guide.md)   | BDD 規範。四層驗證體系與需求追溯                 |

**動手改任何東西之前先讀 SRS。** 三份文件是有方向的：SRS 定義要做什麼，架構文件定義怎麼做，BDD 規範定義怎麼驗證。若架構文件出現 SRS 沒有的功能，代表 SRS 漏寫，回頭補 SRS，而不是在架構文件裡新增需求。

## 目前進度

環境與文件已就緒，外掛本體尚未開始。實作順序見 SRS §8.4：

| 階段         | 內容                                                  | 狀態   |
| ------------ | ----------------------------------------------------- | ------ |
| 環境與文件   | compose、Makefile、SRS、架構、BDD 規範                | 完成   |
| 第一階段     | `local_universityai` 骨架，跑通排程任務與一則通知     | 未開始 |
| 第一‧五階段 | `aiprovider_claude` 可行性驗證（三項，見 SRS §8.4）   | 未開始 |
| 第二階段     | 每週摘要，取得第一條端到端鏈路                        | 未開始 |
| 第三階段     | 課綱解析與確認，連帶完成課程、行事曆、比對            | 未開始 |
| 第四階段     | 聊天助理                                              | 未開始 |

architecture.md 的 §9（AI 呼叫設計）刻意留空，等第一‧五階段的實測結果再寫——它依賴的是實跑資料，不是推測。

## 授權

與 Moodle 核心介接的檔案採 GPL v3 或更新版本，這是 Moodle 外掛目錄的上架要求（SRS `D-07`）。
