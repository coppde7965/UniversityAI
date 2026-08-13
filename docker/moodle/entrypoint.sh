#!/bin/sh
# UniversityAI 網頁容器的進入點
#
# 職責只有兩件：確保 document root 存在（否則 Apache 啟動時會抱怨，
# 而第一次 make up 時 Moodle 原始碼還沒取得），以及把三個資料目錄準備好。
#
# 資料目錄是 bind mount 進來的，在 Linux 主機上建立時屬於主機使用者，
# 容器內的 www-data 寫不進去——這是 bind mount 最常見的第一個錯誤，
# 症狀是安裝畫面說 dataroot 不可寫。
#
# 在 Windows 主機上 chown 是無效操作（檔案系統不支援），但也不會失敗，
# 因此不分平台一律執行並忽略錯誤。

set -eu

mkdir -p /var/www/moodle/public

for dir in /var/www/moodledata /var/www/behatdata /var/www/phpunitdata; do
    mkdir -p "$dir"
    chown -R www-data:www-data "$dir" 2>/dev/null || true
    chmod -R 0777 "$dir" 2>/dev/null || true
done

exec "$@"
