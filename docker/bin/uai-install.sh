#!/bin/sh
# 安裝 Moodle 站台、注入補充設定、安裝三個外掛、建立示範課程。
#
# 全程冪等：重複執行不會重裝，可以安全地在任何時候再跑一次。

set -eu

DEST=/var/www/moodle
EXTRA=/opt/uai/config-extra.php
cd "$DEST"

if [ ! -f public/version.php ]; then
    echo "找不到 Moodle 原始碼。先執行 make up。" >&2
    exit 1
fi

# Moodle 的 CLI 會在 moodledata 下建立檔案。以 root 執行會讓那些檔案變成
# root 所有，之後 Apache（www-data）就寫不進去——症狀是安裝完一切正常，
# 但上傳檔案或清快取時才失敗。所以一律降權執行。
as_web() {
    su -s /bin/sh www-data -c "$1"
}

# ---------------------------------------------------------------------------
# 1. 站台安裝
# ---------------------------------------------------------------------------
# config.php 存在**不代表安裝完成**——install.php 可能在建完 config.php
# 之後、跑外掛升級時才失敗（例如掛載了缺 version.php 的空外掛目錄），
# 留下一個半安裝的站台。只看 config.php 就略過會讓人得到一個壞掉的環境
# 卻以為裝好了，所以這裡再確認一次站台是否真的可用。
if [ -f config.php ] && ! php admin/cli/cfg.php --name=version >/dev/null 2>&1; then
    echo "偵測到未完成的安裝：config.php 存在，但站台沒有安裝完成。" >&2
    echo "" >&2
    echo "多半是上一次安裝中途失敗。請先清除再重來：" >&2
    echo "    make clean && make up && make install" >&2
    exit 1
fi

if [ -f config.php ]; then
    echo "[1/4] config.php 已存在，略過站台安裝。"
else
    echo "[1/4] 安裝 Moodle 站台 …"
    # 注意 CLI 腳本在專案根目錄的 admin/cli/，不在 public/ 之下。
    as_web "php admin/cli/install.php \
        --lang='${MOODLE_LANG}' \
        --wwwroot='${MOODLE_WWWROOT}' \
        --dataroot=/var/www/moodledata \
        --dbtype=pgsql \
        --dbhost=db \
        --dbport=5432 \
        --dbname='${POSTGRES_DB}' \
        --dbuser='${POSTGRES_USER}' \
        --dbpass='${POSTGRES_PASSWORD}' \
        --fullname='${MOODLE_SITE_FULLNAME}' \
        --shortname='${MOODLE_SITE_SHORTNAME}' \
        --adminuser='${MOODLE_ADMIN_USER}' \
        --adminpass='${MOODLE_ADMIN_PASSWORD}' \
        --adminemail='${MOODLE_ADMIN_EMAIL}' \
        --non-interactive \
        --agree-license"
fi

# ---------------------------------------------------------------------------
# 2. 注入補充設定
#
# 安裝程式產生的 config.php 以 require_once(__DIR__ . '/lib/setup.php') 結尾，
# 必須插在那一行之前——setup.php 一旦載入，設定就來不及生效。
# ---------------------------------------------------------------------------
if grep -q 'config-extra.php' config.php 2>/dev/null; then
    echo "[2/4] 補充設定已注入，略過。"
else
    echo "[2/4] 注入補充設定 …"
    line=$(grep -n "lib/setup\.php" config.php | head -1 | cut -d: -f1 || true)
    if [ -z "$line" ]; then
        echo "config.php 的結構與預期不符，找不到 lib/setup.php 這一行。" >&2
        echo "請手動在該檔結尾加入：require_once('$EXTRA');" >&2
        exit 1
    fi
    head -n "$((line - 1))" config.php > /tmp/uai-config.new
    {
        echo "// UniversityAI：補充設定，內容見 docker/config-extra.php"
        echo "require_once('$EXTRA');"
        echo ""
    } >> /tmp/uai-config.new
    tail -n "+$line" config.php >> /tmp/uai-config.new
    # 用 cat 而非 mv，保留原檔的擁有者與權限
    cat /tmp/uai-config.new > config.php
    rm -f /tmp/uai-config.new
fi

# ---------------------------------------------------------------------------
# 3. 安裝／升級外掛
#
# 掛載進來的三個外掛只要有 version.php 就會被這一步認出來並安裝。
# 目前只有空目錄時，Moodle 會直接忽略（外掛掃描以 version.php 為準）。
# ---------------------------------------------------------------------------
echo "[3/4] 安裝／升級外掛 …"
as_web "php admin/cli/upgrade.php --non-interactive"

# ---------------------------------------------------------------------------
# 4. 示範課程
#
# D-10 之後課程外殼必須先存在才能上傳課綱。每次重建環境都手動建一次很煩，
# 而且展示前忘記建會很尷尬，所以放進安裝流程。
# ---------------------------------------------------------------------------
echo "[4/4] 建立示範課程 …"
as_web "php /usr/local/bin/uai-democourse.php \
    --shortname='${DEMO_COURSE_SHORTNAME}' \
    --fullname='${DEMO_COURSE_FULLNAME}'"

echo ""
echo "───────────────────────────────────────────────"
echo " 安裝完成"
echo "   站台      ${MOODLE_WWWROOT}"
echo "   管理員    ${MOODLE_ADMIN_USER} / ${MOODLE_ADMIN_PASSWORD}"
echo "   信件攔截  http://localhost:${MAILPIT_PORT}"
echo "───────────────────────────────────────────────"
