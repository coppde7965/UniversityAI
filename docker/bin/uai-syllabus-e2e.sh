#!/bin/sh
# 第三階段的端到端實跑：上傳課綱 → 解析任務 → 讀回結果。
#
# 上傳走的是**真實的網頁表單**，不是直接寫資料庫：FR-SYL-01 的驗收條件講的
# 是上傳介面的行為（權限、格式拒絕、無文字層偵測），繞過表單就一項都驗不到。
#
# 解析走的是 Moodle 的臨機任務執行器，不是直接呼叫 execute()——「手動跑得
# 出來、排程跑不出來」是最難查的那種缺陷。
#
# 會呼叫 API 並產生費用。

set -eu

cd /var/www/moodle

if [ ! -f config.php ]; then
    echo "站台尚未安裝。先執行 make install。" >&2
    exit 1
fi

echo "── 1/5　以真實表單上傳課綱"
su -s /bin/sh www-data -c "php /usr/local/bin/uai-syllabus-upload.php \
    --shortname='${DEMO_COURSE_SHORTNAME}' \
    --adminuser='${MOODLE_ADMIN_USER}' \
    --adminpass='${MOODLE_ADMIN_PASSWORD}'"

echo ""
echo "── 2/5　執行臨機任務"
# --keep-alive=0 讓執行器把佇列清空就結束，不要常駐。
su -s /bin/sh www-data -c "php admin/cli/adhoc_task.php --execute --keep-alive=0"

echo ""
echo "── 3/5　讀回解析結果"
su -s /bin/sh www-data -c "php /usr/local/bin/uai-show-syllabus.php \
    --shortname='${DEMO_COURSE_SHORTNAME}'"

echo ""
echo "── 4/5　以真實表單確認（FR-SYL-03、FR-SYL-04）"
su -s /bin/sh www-data -c "php /usr/local/bin/uai-syllabus-confirm.php \
    --shortname='${DEMO_COURSE_SHORTNAME}' \
    --adminuser='${MOODLE_ADMIN_USER}' \
    --adminpass='${MOODLE_ADMIN_PASSWORD}'"

echo ""
echo "── 5/5　以真實表單填入課程（FR-CRS-01、FR-CRS-02、FR-CAL-01）"
su -s /bin/sh www-data -c "php /usr/local/bin/uai-syllabus-populate.php \
    --shortname='${DEMO_COURSE_SHORTNAME}' \
    --adminuser='${MOODLE_ADMIN_USER}' \
    --adminpass='${MOODLE_ADMIN_PASSWORD}'"
