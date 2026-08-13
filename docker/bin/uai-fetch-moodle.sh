#!/bin/sh
# 取得釘住版本的 Moodle 原始碼。
#
# 釘標籤而非分支：MOODLE_502_STABLE 這類分支會持續前進，釘它就無法保證
# 「換一台機器重建會得到同一套系統」（NFR-MNT-05）。
#
# 用 tarball 而非 git clone：不需要 .git，解壓快，而且在 Windows 主機的
# bind mount 上少寫幾萬個小檔案差別很明顯。

set -eu

TAG="${MOODLE_TAG:-v5.2.2}"
DEST=/var/www/moodle
STAMP="$DEST/.uai-moodle-version"

if [ -f "$DEST/public/version.php" ]; then
    have=$(cat "$STAMP" 2>/dev/null || echo '版本未知')
    echo "Moodle 原始碼已存在（$have），略過取得。"
    echo "要換版本：make clean-moodle 之後再 make up。"
    exit 0
fi

echo "取得 Moodle $TAG …"
mkdir -p "$DEST"

# 直接解到掛載點內。plugins/ 的三個掛載點此時已由 Docker 建成空目錄，
# tar 只新增檔案、不刪除既有目錄，因此可以安全合併。
curl -fsSL "https://github.com/moodle/moodle/archive/refs/tags/${TAG}.tar.gz" \
    | tar xz --strip-components=1 -C "$DEST"

if [ ! -f "$DEST/public/version.php" ]; then
    echo "取得失敗：找不到 public/version.php。請確認 MOODLE_TAG=$TAG 是有效的標籤。" >&2
    exit 1
fi

echo "$TAG" > "$STAMP"

# 只改目錄本身的擁有者，不是 -R。
# 安裝程式要在這個目錄下建立 config.php，而 www-data 必須寫得進去；
# 但 -R 會連帶把 plugins/ 底下你自己的原始碼也改掉，那是不必要的干擾。
chown www-data:www-data "$DEST" 2>/dev/null || true

echo "完成：Moodle $TAG"
