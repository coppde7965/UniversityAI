#!/bin/sh
# 執行 PHPUnit 單元測試（bdd-guide 的 L1 層）。
#
# 用法：uai-test.sh [外掛目錄名]
#   uai-test.sh                     全部外掛
#   uai-test.sh local_universityai  單一外掛

set -eu

cd /var/www/moodle
COMPONENT="${1:-}"

/usr/local/bin/uai-vendor.sh

# PHPUnit 使用獨立的 dataroot 與資料表前綴（見 docker/config-extra.php），
# 因此 init 不會動到開發資料。新增測試檔或外掛後都要重跑。
echo "重新產生 PHPUnit 環境 …"
php public/admin/tool/phpunit/cli/init.php

echo "執行 PHPUnit …"
if [ -n "$COMPONENT" ]; then
    vendor/bin/phpunit --testsuite "${COMPONENT}_testsuite"
else
    vendor/bin/phpunit
fi
