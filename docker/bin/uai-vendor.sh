#!/bin/sh
# 確保 Moodle 的 Composer 相依已安裝。
#
# Behat 與 PHPUnit 本身是 Moodle 的開發相依，位於 <moodle>/vendor 之下，
# 不是外掛的相依。沒有這一步，behat 的 init 會以一個不易理解的訊息失敗。
#
# 被 uai-behat.sh 與 uai-test.sh 呼叫，可獨立執行。

set -eu

cd /var/www/moodle

if [ -f vendor/bin/behat ] && [ -f vendor/bin/phpunit ]; then
    exit 0
fi

echo "安裝 Moodle 的開發相依（第一次會花幾分鐘）…"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress
