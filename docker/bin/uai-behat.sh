#!/bin/sh
# 執行 Behat 驗收測試。
#
# 關鍵是先重新產生 Behat 設定再執行：新增 feature 檔之後不重生就找不到新場景，
# 而且這件事不能靠人記得（bdd-guide §10.1），所以包在這裡。
#
# 用法：uai-behat.sh [tags]
#   uai-behat.sh                      全部
#   uai-behat.sh @local_universityai  單一外掛
#   uai-behat.sh @FR-SYL-02           對應某條需求的場景
#   uai-behat.sh "~@endtoend"         排除端到端場景

set -eu

cd /var/www/moodle
TAGS="${1:-}"

/usr/local/bin/uai-vendor.sh

# 等 selenium 就緒。Behat 啟動時若瀏覽器還沒起來，錯誤訊息會指向場景本身，
# 很容易被誤判成測試寫錯。
echo "等待 selenium …"
i=0
while [ "$i" -lt 30 ]; do
    if curl -fsS http://selenium:4444/status >/dev/null 2>&1; then
        break
    fi
    i=$((i + 1))
    sleep 2
done
if [ "$i" -ge 30 ]; then
    echo "selenium 未在 60 秒內就緒。檢查 make logs。" >&2
    exit 1
fi

# Behat 與 PHPUnit 的 CLI 工具在 public/admin/tool/ 之下，
# 不在專案根目錄的 admin/cli/——後者只有核心的那幾支。這個不對稱很容易寫錯。
echo "重新產生 Behat 設定 …"
php public/admin/tool/behat/cli/init.php

echo "執行 Behat …"
if [ -n "$TAGS" ]; then
    php public/admin/tool/behat/cli/run.php --tags="$TAGS"
else
    php public/admin/tool/behat/cli/run.php
fi
