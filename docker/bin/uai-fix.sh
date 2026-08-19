#!/bin/sh
# 自動修正可修正的程式碼格式問題（phpcbf）。
#
# **這一層存在的唯一理由是 --ignore。**
#
# phpcs 經由 moodle-plugin-ci 呼叫時會讀外掛的 thirdpartylibs.xml，把宣告過
# 的第三方目錄排除在檢查之外；**phpcbf 不會讀那個檔案**。直接對整個外掛目錄
# 跑 phpcbf 的後果是它去「修正」別人的原始碼——實測一次改了 pdfparser 底下
# 49 個檔案、706 處。那些改動不在 git 追蹤範圍內（併入的函式庫是新增檔案），
# 所以連 git checkout 都救不回來，只能重新下載一份。
#
# 排除清單以 thirdparty/ 為準：併入的第三方程式庫一律放在那個目錄下。

set -eu

MOODLE_DIR=/var/www/moodle
PHPCBF=/opt/plugin-ci/vendor/bin/phpcbf
PLUGINS="public/local/universityai public/blocks/universityai public/ai/provider/claude"

cd "$MOODLE_DIR"

if [ ! -x "$PHPCBF" ]; then
    echo "找不到 phpcbf。請重建 tools 映像：" >&2
    echo "    docker compose build --no-cache tools && make up" >&2
    exit 1
fi

for plugin in $PLUGINS; do
    if [ ! -f "$plugin/version.php" ]; then
        continue
    fi

    echo ""
    echo "── 修正 $plugin"

    # phpcbf 在「有修正」時回傳 1、「無事可做」時回傳 0，兩者都不是錯誤。
    # set -e 會把 1 當成失敗，所以這裡明確吞掉回傳值。
    "$PHPCBF" --standard=moodle-extra --ignore="*/thirdparty/*" "$plugin" || true
done

echo ""
echo "修正完成。請執行 make lint 確認，並以 git diff 檢查改了什麼——"
echo "自動修正不會問你同不同意。"
