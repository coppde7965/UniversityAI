# UniversityAI — 唯一操作入口
#
# 對應 docs/SRS.md §2.5 與 docs/architecture.md §7.5。
# 主機端只需 Docker 與 GNU Make。
#
# ── 一條刻意遵守的規則 ──────────────────────────────────────────────
# 每一行 recipe 都是單一個 docker compose 呼叫，不含 shell 迴圈、條件判斷
# 或管線。所有實際邏輯都寫在 docker/bin/ 下的 POSIX sh 腳本裡，在 Linux
# 容器內執行。
#
# 理由是 Windows 上的 GNU Make 會依環境選擇 sh.exe 或 cmd.exe，兩者的
# 引號與跳脫規則不同。把邏輯留在 Makefile 裡就得同時對兩套規則負責；
# 推進容器則完全不必。這也讓每個目標的行為在三種主機作業系統上一致。
# ────────────────────────────────────────────────────────────────────

DC := docker compose

# 可覆寫的參數
TAGS      ?=
COMPONENT ?=
FILE      ?=
SERVICE   ?=
RUNS      ?= 3

.DEFAULT_GOAL := help

.PHONY: help build up down restart ps logs shell shell-db shell-eval \
        install selftest ai-verify summary lint fix test behat cron purge db-dump db-restore \
        fixtures syllabus-gate syllabus eval clean-moodle clean

# ---------------------------------------------------------------------------
help: ## 顯示可用指令
	@echo ""
	@echo "  UniversityAI 開發環境"
	@echo ""
	@echo "  第一次使用："
	@echo "    cp .env.example .env   # 先改掉裡面的密碼"
	@echo "    make up"
	@echo "    make install"
	@echo ""
	@echo "  環境"
	@echo "    make up            啟動全部服務，並在首次執行時取得 Moodle 原始碼"
	@echo "    make down          停止服務（資料保留）"
	@echo "    make restart       重啟服務"
	@echo "    make ps            檢視服務狀態"
	@echo "    make logs          追蹤記錄（SERVICE=moodle 可指定單一服務）"
	@echo "    make shell         進入 tools 容器"
	@echo "    make shell-db      進入資料庫的 psql"
	@echo ""
	@echo "  安裝與維運"
	@echo "    make install       安裝站台、外掛與示範課程（可重複執行）"
	@echo "    make selftest      逐項驗證外掛、資料表、管理選單、排程任務與通知"
	@echo "    make ai-verify     第一·五階段閘門：AI 呼叫可行性（會呼叫 API 並產生費用）"
	@echo "    make summary       第二階段端到端實跑：植入課綱→跑排程→讀回摘要（會呼叫 API）"
	@echo "    make cron          手動跑一次 Moodle 排程"
	@echo "    make purge         清除 Moodle 快取"
	@echo ""
	@echo "  品質"
	@echo "    make lint          程式碼與 feature 檔檢查（NFR-MNT-04）"
	@echo "    make fix           自動修正格式問題（不要直接跑 phpcbf）"
	@echo "    make test          PHPUnit（COMPONENT=local_universityai 可指定）"
	@echo "    make behat         Behat（TAGS=@FR-SYL-02 可指定）"
	@echo "    make fixtures      產生課綱固定樣本（PDF 與抽出的文字）"
	@echo "    make syllabus-gate 第三階段閘門：PDF 直送 vs 先抽文字（會呼叫 API）"
	@echo "    make syllabus      課綱上傳與解析的端到端實跑（會呼叫 API）"
	@echo "    make eval          課綱解析正確率量測"
	@echo ""
	@echo "  資料庫"
	@echo "    make db-dump       匯出傾印至 db/dumps/"
	@echo "    make db-restore    自傾印還原（FILE=xxx.sql 可指定）"
	@echo ""
	@echo "  清理"
	@echo "    make clean-moodle  刪除 Moodle 原始碼（保留資料庫與外掛）"
	@echo "    make clean         刪除容器、磁碟區與全部本機資料"
	@echo ""

# ---------------------------------------------------------------------------
# 環境
# ---------------------------------------------------------------------------

# .env 缺席時 Docker Compose 會以一句難懂的 GetFileAttributesEx 錯誤中止。
# 讓 Make 自己補上，並提醒改密碼——這比讓人去讀 Docker 的錯誤訊息好。
.env:
	@echo ""
	@echo "  找不到 .env，正在從 .env.example 建立 …"
	@echo "  ★ 請打開 .env 改掉 POSTGRES_PASSWORD 與 MOODLE_ADMIN_PASSWORD"
	@echo ""
	cp .env.example .env

build: .env ## 重建映像
	$(DC) build

up: .env ## 啟動全部服務，首次執行時一併取得 Moodle 原始碼
	$(DC) up -d --build
	$(DC) exec -T tools uai-fetch-moodle.sh

down: ## 停止服務，資料保留
	$(DC) down

restart: ## 重啟服務
	$(DC) restart

ps: ## 檢視服務狀態
	$(DC) ps

logs: ## 追蹤記錄
	$(DC) logs -f --tail=100 $(SERVICE)

shell: ## 進入 tools 容器
	$(DC) exec tools bash

shell-db: ## 進入資料庫的 psql
	$(DC) exec db psql -U $${POSTGRES_USER:-moodle} -d $${POSTGRES_DB:-moodle}

shell-eval: ## 進入評測工具容器
	$(DC) exec eval bash

# ---------------------------------------------------------------------------
# 安裝與維運
# ---------------------------------------------------------------------------

install: .env ## 安裝站台、外掛與示範課程（冪等，可重複執行）
	$(DC) exec -T moodle uai-install.sh

# SRS §8.4 第一階段的驗收。失敗時以非零狀態結束，所以可以放進 CI。
selftest: ## 驗證外掛的安裝、資料表、排程任務與通知
	$(DC) exec -T moodle uai-selftest.sh

# SRS §8.4 第一·五階段的閘門。RUNS=5 可改每項實驗的次數。
ai-verify: ## AI 呼叫可行性驗證（會實際呼叫 API）
	$(DC) exec -T moodle uai-ai-verify.sh --runs=$(RUNS)

# SRS §8.4 第二階段的驗收。刻意把「植入課綱」也包進來：沒有課綱時排程任務
# 會安靜地什麼都不做並回報成功，那種綠燈驗不到任何東西（§4.8 的教訓）。
summary: ## 每週摘要的端到端實跑（會實際呼叫 API）
	$(DC) exec -T moodle uai-summary.sh

cron: ## 手動跑一次 Moodle 排程
	$(DC) exec -T moodle php admin/cli/cron.php

purge: ## 清除 Moodle 快取
	$(DC) exec -T moodle php admin/cli/purge_caches.php

# ---------------------------------------------------------------------------
# 品質
#
# 三個層級的對應見 docs/bdd-guide.md §2：
#   lint  → 靜態檢查（NFR-MNT-04、NFR-MNT-06）
#   test  → L1 PHPUnit
#   behat → L2 Behat
#   eval  → L3 評測腳本
# ---------------------------------------------------------------------------

lint: ## 程式碼與 feature 檔檢查
	$(DC) exec -T tools uai-lint.sh

# 一定要用這個而不是直接跑 phpcbf：phpcbf 不讀 thirdpartylibs.xml，
# 會去「修正」併入的第三方程式庫。理由詳見 uai-fix.sh。
fix: ## 自動修正可修正的格式問題
	$(DC) exec -T tools uai-fix.sh

test: ## PHPUnit 單元測試
	$(DC) exec -T tools uai-test.sh $(COMPONENT)

# selenium 不在預設啟動集合中（映像近 2 GB，第一、二階段與展示機都用不到），
# 所以這裡先把它拉起來。第一次執行會下載，之後就只是啟動。
behat: ## Behat 驗收測試
	$(DC) --profile test up -d selenium
	$(DC) exec -T tools uai-behat.sh $(TAGS)

# 兩步：PDF 由 moodle 容器的 TCPDF 產生（環境裡唯一排得出中文 PDF 的工具），
# 抽文字由 eval 容器的 pypdf 做。事實來源是 eval/fixtures/syllabi.json，
# 產出的 PDF 與 txt 都是衍生物，不進版控。
fixtures: ## 產生課綱固定樣本
	$(DC) exec -T moodle php /usr/local/bin/uai-fixture-build.php
	$(DC) --profile eval up -d eval
	$(DC) exec -T eval uv run python -m evaluation.extract_text

# architecture.md §9.9 第 1 題的實測。先跑 make fixtures。
syllabus-gate: ## PDF 直送 vs 先抽文字的正確率對照（會呼叫 API）
	$(DC) exec -T moodle php /usr/local/bin/uai-syllabus-gate.php --runs=$(RUNS)

# FR-SYL-01 與 FR-SYL-02 的端到端驗收。上傳走真實的網頁表單、解析走 Moodle
# 的臨機任務執行器——繞過任一邊，驗到的就不是使用者實際會走的路。
syllabus: ## 課綱上傳與解析的端到端實跑（會呼叫 API）
	$(DC) exec -T moodle uai-syllabus-e2e.sh

eval: ## 課綱解析正確率量測
	$(DC) --profile eval up -d eval
	$(DC) exec -T eval uv run python -m evaluation.accuracy

# ---------------------------------------------------------------------------
# 資料庫
#
# 傾印是換機展示唯一支援的搬移方式（D-04）。資料庫的實體資料目錄用具名
# 磁碟區，不掛在專案目錄下，因此 clean 會連帶清掉它——搬機器前務必先 dump。
# ---------------------------------------------------------------------------

db-dump: ## 匯出資料庫傾印至 db/dumps/
	$(DC) exec -T tools uai-db-dump.sh

db-restore: ## 自傾印還原資料庫
	$(DC) exec -T tools uai-db-restore.sh $(FILE)

# ---------------------------------------------------------------------------
# 清理
# ---------------------------------------------------------------------------

clean-moodle: ## 刪除 Moodle 原始碼，保留資料庫與外掛（換版本時用）
	$(DC) exec -T tools uai-clean.sh moodle

clean: ## 刪除容器、磁碟區與全部本機資料
	$(DC) exec -T tools uai-clean.sh data
	$(DC) down -v --remove-orphans
	@echo "已清除。db/dumps/ 下的傾印保留未動。"
