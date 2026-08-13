#!/bin/sh
# 清理。
#
# 用法：uai-clean.sh moodle   只刪 Moodle 原始碼（換版本時用）
#       uai-clean.sh data     只清三個資料目錄的內容
#
# ── 一個必須小心的地方 ──────────────────────────────────────────────
# 三個外掛目錄是 bind mount。對 mount point 執行 rm -rf 的行為是：先刪掉
# 裡面的檔案，最後才在刪除目錄本身時失敗。也就是說一個看似安全的
# 「刪不掉就算了」寫法，會先把我們的外掛原始碼刪光。
#
# 所以下面一律以名稱明確排除那三個路徑，而不是靠 rm 失敗來保護它們。
# ────────────────────────────────────────────────────────────────────

set -eu

MODE="${1:-}"
ROOT=/var/www/moodle

clean_moodle_src() {
    if [ ! -d "$ROOT" ]; then
        echo "找不到 $ROOT，無事可做。"
        return 0
    fi

    echo "刪除 Moodle 原始碼（保留三個外掛）…"

    # 根目錄：public 以外全刪（admin/、lib/、config.php 等）
    find "$ROOT" -mindepth 1 -maxdepth 1 ! -name public \
        -exec rm -rf {} + 2>/dev/null || true

    # public 之下：保留三個外掛所在的父目錄
    find "$ROOT/public" -mindepth 1 -maxdepth 1 \
        ! -name local ! -name blocks ! -name ai \
        -exec rm -rf {} + 2>/dev/null || true

    # 父目錄之下：只保留我們的外掛本身
    find "$ROOT/public/local" -mindepth 1 -maxdepth 1 ! -name universityai \
        -exec rm -rf {} + 2>/dev/null || true
    find "$ROOT/public/blocks" -mindepth 1 -maxdepth 1 ! -name universityai \
        -exec rm -rf {} + 2>/dev/null || true
    find "$ROOT/public/ai" -mindepth 1 -maxdepth 1 ! -name provider \
        -exec rm -rf {} + 2>/dev/null || true
    find "$ROOT/public/ai/provider" -mindepth 1 -maxdepth 1 ! -name claude \
        -exec rm -rf {} + 2>/dev/null || true

    if [ -f "$ROOT/public/version.php" ]; then
        echo "警告：public/version.php 仍存在，清理可能不完整。" >&2
        exit 1
    fi

    echo "完成。下次 make up 會重新取得 MOODLE_TAG 指定的版本。"
}

clean_data() {
    echo "清除資料目錄 …"
    for dir in /var/www/moodledata /var/www/behatdata /var/www/phpunitdata; do
        find "$dir" -mindepth 1 -maxdepth 1 -exec rm -rf {} + 2>/dev/null || true
    done
    echo "完成。db/dumps/ 下的傾印未動。"
}

case "$MODE" in
    moodle) clean_moodle_src ;;
    data)   clean_data ;;
    *)
        echo "用法：uai-clean.sh moodle|data" >&2
        exit 1
        ;;
esac
