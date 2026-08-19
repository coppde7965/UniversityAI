#!/bin/sh
# 第二階段的端到端實跑：植入課綱 → 跑排程任務 → 印出產生的摘要。
#
# 三步刻意放在同一支腳本裡，因為它們只有連著跑才有意義：單獨跑排程任務在
# 沒有課綱的站台上會安靜地什麼都不做並回報成功，那種綠燈毫無資訊量。
#
# 排程任務走的是 Moodle 的 scheduled_task.php，不是另寫一條捷徑——「手動跑
# 得出來、排程跑不出來」是最難查的那種缺陷，所以這裡跑的必須是排程本身。
#
# 降權執行的理由同 uai-install.sh：Moodle 的 CLI 會在快取目錄下建檔案。

set -eu

cd /var/www/moodle

if [ ! -f config.php ]; then
    echo "站台尚未安裝。先執行 make install。" >&2
    exit 1
fi

TASK='\local_universityai\task\weekly_summary'

echo "── 1/3　植入示範課綱（開發工具，不隨外掛發行）"
su -s /bin/sh www-data -c "php /usr/local/bin/uai-seed-syllabus.php \
    --shortname='${DEMO_COURSE_SHORTNAME}'"

echo ""
echo "── 2/3　執行排程任務 weekly_summary"
# --force 讓任務即使還沒到排定時間也跑一次。它繞過的是「時間到了沒」，
# 不是任務本身的任何邏輯——冪等判斷仍然在任務內生效。
su -s /bin/sh www-data -c "php admin/cli/scheduled_task.php --execute='${TASK}' --force"

echo ""
echo "── 3/3　讀回產生的摘要"
su -s /bin/sh www-data -c "php /usr/local/bin/uai-show-summary.php \
    --shortname='${DEMO_COURSE_SHORTNAME}'"
