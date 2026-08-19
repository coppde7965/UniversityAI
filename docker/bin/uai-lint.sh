#!/bin/sh
# 程式碼與 feature 檔檢查（NFR-MNT-04）。
#
# moodle-plugin-ci 的個別子命令以「命令 + 外掛路徑」的形式呼叫，
# 對已安裝好的 Moodle 直接跑靜態檢查即可，不需要它的 install 步驟
# ——那一步是給從零建站的 CI 用的。
#
# phpcs 只有**錯誤**會讓這裡失敗，警告不會。直接執行 phpcs 會看到約四十則
# moodle.Commenting.InlineComment 警告，因為那條規則要求行內註解以大寫字母
# 開頭、以半形句點結尾，而本專案的註解一律用中文。這是已知且刻意接受的
# 落差（architecture.md §8.2），不要為了消掉它改寫註解或關掉整個 sniff。
#
# 大量的格式錯誤可以自動修正，但**一定要用 make fix，不要直接跑 phpcbf**。
#
# 理由：phpcs 經由 moodle-plugin-ci 呼叫時會讀 thirdpartylibs.xml 並排除
# 宣告過的路徑，**phpcbf 不會**。直接對整個外掛目錄跑 phpcbf 會去改
# thirdparty/pdfparser 底下的第三方原始碼——實測一次改了 49 個檔案、706 處，
# 而且那些改動不在 git 追蹤範圍內（併入的函式庫是新增檔案），還原只能重新
# 下載一份。make fix 帶了 --ignore，不會碰到那個目錄。

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

    # 只確認 bin 檔存在是不夠的。composer create-project 會先放好原始碼、
    # 再裝相依，相依那步失敗時 bin/moodle-plugin-ci 照樣在，只有 vendor/
    # 不見；此時每個子命令都以「Failed to find Composer's autoload file」
    # 失敗，看不出跟映像建置有關。所以這裡驗的是 autoload 檔本身。
    #
    # 這種情況一律視為未通過，不是略過——「lint 全綠」不能是「其實沒跑」。
    if [ ! -x "$CI" ] || [ ! -f /opt/plugin-ci/vendor/autoload.php ]; then
        echo "   moodle-plugin-ci 不可用，只做了語法檢查。"
        echo ""
        echo "   修復方式（重建 tools 映像，會重新下載相依）："
        echo "       docker compose build --no-cache tools && make up"
        echo ""
        FAILED=1
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
