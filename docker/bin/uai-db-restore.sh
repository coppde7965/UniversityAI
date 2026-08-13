#!/bin/sh
# 自傾印還原資料庫。
#
# 用法：uai-db-restore.sh [檔名]
#   不指定檔名時使用 db/dumps/latest.sql。
#
# 傾印以 --clean --if-exists 產生，因此會先移除既有物件再重建。
# 這代表還原會覆蓋目前的資料——刻意如此，換機展示要的就是完整取代。

set -eu

IN_DIR=/dumps
FILE="${1:-latest.sql}"

case "$FILE" in
    /*) PATH_TO_FILE="$FILE" ;;
    *)  PATH_TO_FILE="$IN_DIR/$FILE" ;;
esac

if [ ! -f "$PATH_TO_FILE" ]; then
    echo "找不到傾印檔：$PATH_TO_FILE" >&2
    echo "" >&2
    echo "db/dumps/ 目前有：" >&2
    ls -1 "$IN_DIR" 2>/dev/null | sed 's/^/  /' >&2 || echo "  （空的）" >&2
    exit 1
fi

echo "還原 $PATH_TO_FILE 至 ${POSTGRES_DB} …"
PGPASSWORD="$POSTGRES_PASSWORD" psql \
    --host=db \
    --username="$POSTGRES_USER" \
    --dbname="$POSTGRES_DB" \
    --quiet \
    --file="$PATH_TO_FILE"

echo "完成。"
echo ""
echo "若 moodledata/ 沒有一起搬過來，上傳過的課綱檔案會是壞的連結。"
