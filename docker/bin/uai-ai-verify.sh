#!/bin/sh
# 第一‧五階段 AI 可行性驗證的外殼。
#
# 降權執行的理由與 uai-install.sh 相同：Moodle 的 CLI 會在快取目錄下建立
# 檔案，以 root 執行會讓 Apache 之後寫不進去。

set -eu

cd /var/www/moodle

if [ ! -f config.php ]; then
    echo "站台尚未安裝。先執行 make install。" >&2
    exit 1
fi

su -s /bin/sh www-data -c "php /usr/local/bin/uai-ai-verify.php ${*:-}"
