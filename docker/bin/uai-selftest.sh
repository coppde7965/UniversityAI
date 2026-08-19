#!/bin/sh
# 第一階段自我檢查的外殼。
#
# 實際內容在 uai-selftest.php。這一層存在的理由與 uai-install.sh 相同：
# Moodle 的 CLI 會在快取目錄下建立檔案，以 root 執行會讓那些檔案變成 root
# 所有，之後 Apache（www-data）就寫不進去。所以一律降權執行。

set -eu

cd /var/www/moodle

if [ ! -f config.php ]; then
    echo "站台尚未安裝。先執行 make install。" >&2
    exit 1
fi

# 帳密傳給腳本是為了最後一項檢查：以真實登入抓一個管理頁面回來。
# 其餘檢查都走 CLI，而 CLI 綠燈不代表網頁打得開（architecture.md §7.4.1）。
su -s /bin/sh www-data -c "php /usr/local/bin/uai-selftest.php \
    --adminuser='${MOODLE_ADMIN_USER}' \
    --adminpass='${MOODLE_ADMIN_PASSWORD}'"
