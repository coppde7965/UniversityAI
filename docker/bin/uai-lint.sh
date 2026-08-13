#!/bin/sh
# 程式碼與 feature 檔檢查（NFR-MNT-04）。
#
# moodle-plugin-ci 的個別子命令以「命令 + 外掛路徑」的形式呼叫，
# 對已安裝好的 Moodle 直接跑靜態檢查即可，不需要它的 install 步驟
# ——那一步是給從零建站的 CI 用的。

set -eu

MOODLE_DIR=/var/www/moodle
export MOODLE_DIR
cd "$MOODLE_DIR"

PLUGINS="public/local/universityai public/blocks/universityai public/ai/provider/claude"
CI=/opt/plugin-ci/bin/moodle-plugin-ci
FAILED=0

for plugin in $PLUGINS; do
    if [ ! -f "$plugin/version.php" ]; then
        echo "── 略過 $plugin（尚未建立）"
        continue
    fi

    echo ""
    echo "── 檢查 $plugin"

    # 語法檢查一定跑得動，不依賴任何工具。
    find "$plugin" -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null || FAILED=1

    if [ ! -x "$CI" ]; then
        echo "   moodle-plugin-ci 不可用，只做了語法檢查。"
        echo "   重建 tools 映像可修復：make build"
        continue
    fi

    # 逐項執行而非一次跑完，這樣哪一項失敗一眼看得出來。
    #
    # 注意 moodle-plugin-ci **沒有** gherkinlint 命令（實測；完整清單可用
    # moodle-plugin-ci list 查）。Moodle 的 .gherkin-lintrc 是給它自己的
    # grunt 任務用的，要跑 feature 檔的格式檢查得走 moodle-plugin-ci grunt，
    # 而那需要先在 Moodle 目錄下 npm install，成本高且容易卡住。
    # 第四階段真的有 feature 檔之後再評估要不要納入。
    for check in phplint phpcs phpmd phpdoc mustache validate savepoints; do
        printf '   %-12s' "$check"
        if "$CI" "$check" "$plugin" > /tmp/uai-lint.out 2>&1; then
            echo "OK"
        else
            echo "FAILED"
            sed 's/^/      /' /tmp/uai-lint.out
            FAILED=1
        fi
    done
done

rm -f /tmp/uai-lint.out

if [ "$FAILED" -ne 0 ]; then
    echo ""
    echo "檢查未通過。" >&2
    exit 1
fi

echo ""
echo "全部通過。"
