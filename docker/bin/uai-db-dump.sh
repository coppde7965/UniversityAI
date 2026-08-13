#!/bin/sh
# 匯出資料庫傾印到專案目錄。
#
# 這是換機展示唯一支援的搬移方式（D-04）。資料庫的實體資料目錄刻意不掛在
# 專案目錄下——跨機器複製那些檔案在版本、位元組序與檔案鎖上都不可靠。
#
# 搬到另一台機器的完整步驟：
#   1. 這台：make db-dump
#   2. 整個專案目錄複製過去（含 db/dumps/ 與 moodledata/）
#   3. 那台：make up && make db-restore

set -eu

OUT_DIR=/dumps
STAMP=$(date +%Y%m%d-%H%M%S)
FILE="$OUT_DIR/universityai-${STAMP}.sql"

mkdir -p "$OUT_DIR"

echo "匯出資料庫 ${POSTGRES_DB} …"
PGPASSWORD="$POSTGRES_PASSWORD" pg_dump \
    --host=db \
    --username="$POSTGRES_USER" \
    --dbname="$POSTGRES_DB" \
    --clean --if-exists --no-owner --no-privileges \
    --file="$FILE"

# 指向最新一份，讓 make db-restore 不必指定檔名
ln -sf "$(basename "$FILE")" "$OUT_DIR/latest.sql" 2>/dev/null \
    || cp "$FILE" "$OUT_DIR/latest.sql"

echo "完成：db/dumps/$(basename "$FILE")"
echo ""
echo "提醒：moodledata/ 也要一起搬，裡面有上傳的課綱原檔。"
