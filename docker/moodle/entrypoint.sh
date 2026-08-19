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

# 遞迴只在目錄是這次才建立時做。
#
# 理由是實測的啟動延遲：moodledata 累積到 2444 個項目時，遞迴 chown 加
# chmod 要 5.8 秒，而這段跑在 exec apache2-foreground 之前——也就是每次
# 重建容器後有 6 秒鐘網站是連不上的。症狀很難聯想：make up 之後立刻跑
# make selftest 會拿到「Failed to connect to 127.0.0.1 port 80」。
#
# 而且它只會愈來愈慢，moodledata 裝的是老師上傳的課綱與 Moodle 的檔案區。
# 對照組：同樣的操作在具名磁碟區上的 1068 個項目只要 6 毫秒——又是 A-08
# 與 A-09 那個每項約 2.4 毫秒的 bind mount 成本。
#
# 目錄已存在時裡面的檔案是 Moodle 自己以 www-data 身分建的，擁有者本來
# 就對，遞迴修一次沒有意義。只修頂層即可，那才是 bind mount 掛上來、可能
# 屬於主機使用者的那一層。
#
# 例外情況：若你從主機端手動把檔案複製進 moodledata，那些檔案的擁有者不會
# 被修正。此時手動跑一次遞迴，或把目錄刪掉讓這段重新建立。
for dir in /var/www/moodledata /var/www/behatdata /var/www/phpunitdata \
           /var/www/moodlecache/localcache /var/www/moodlecache/cache /var/www/moodlecache/temp; do
    if [ -d "$dir" ]; then
        chown www-data:www-data "$dir" 2>/dev/null || true
        chmod 0777 "$dir" 2>/dev/null || true
    else
        mkdir -p "$dir"
        chown -R www-data:www-data "$dir" 2>/dev/null || true
        chmod -R 0777 "$dir" 2>/dev/null || true
    fi
done

exec "$@"
