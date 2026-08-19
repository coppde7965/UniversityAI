# UniversityAI 開發環境映像
#
# build context 是 docker/（不是 docker/moodle/），因為 bin/ 下的腳本兩個 target 都要用。
#
# 兩個 target 共用同一個 base，確保網頁容器與測試容器的 PHP 版本與擴充完全一致。
# 若讓兩者各自 FROM 不同基底，會出現「網頁跑得動、PHPUnit 說缺擴充」這類難查的落差。
#
# 目標平台見 docs/architecture.md 標頭：Moodle 5.2 / PHP 8.3–8.4。

# ---------------------------------------------------------------------------
# base：PHP 與 Moodle 5.2 要求的全部擴充
# ---------------------------------------------------------------------------
FROM php:8.3-apache AS base

ENV DEBIAN_FRONTEND=noninteractive

# ── Moodle 5.2 要求的擴充，哪些要自己編 ────────────────────────────────
# php:8.3-apache 已經內建並載入這些，**不可以**再 docker-php-ext-install
# 一次，否則整個 RUN 會以 exit 2 失敗：
#   ctype  curl  dom  iconv  json  mbstring  openssl  pcre  SimpleXML
#   sodium  tokenizer  xml  xmlreader  zlib  OPcache
# 要自己編的只有下面六個。查法：docker run --rm php:8.3-apache php -m
# ────────────────────────────────────────────────────────────────────────
#
# apt 套件只留下這六個擴充實際需要的建置相依：
#   libpq-dev                              → pgsql / pdo_pgsql
#   libpng/libjpeg/libfreetype             → gd
#   libicu-dev                             → intl
#   libzip-dev                             → zip
#   libxml2-dev                            → soap
# （exif 不需要額外相依）
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl unzip \
        locales \
        libpq-dev \
        libzip-dev \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libicu-dev \
        libxml2-dev \
    && rm -rf /var/lib/apt/lists/*

# 兩個地區設定都要產生：
#
#   zh_TW.UTF-8  站台語言。沒有它，Moodle 每次安裝與排序都會噴
#                U_USING_FALLBACK_WARNING 說找不到這個地區設定。警告本身
#                不致命，但讓它每次都出現只會訓練人忽略輸出裡的警告。
#
#   en_AU.UTF-8  Moodle 的 PHPUnit 環境**硬性要求**這一個，缺了會在
#                init 階段直接中止："Required locale 'en_AU.UTF-8' is not
#                installed."。與站台語言無關，也不能改設定繞過——它寫死在
#                核心的測試環境檢查裡。
RUN sed -i 's/^# *\(zh_TW\.UTF-8\)/\1/; s/^# *\(en_AU\.UTF-8\)/\1/' /etc/locale.gen \
    && locale-gen
ENV LANG=zh_TW.UTF-8 LC_ALL=zh_TW.UTF-8

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pgsql pdo_pgsql gd intl zip soap exif

COPY moodle/php.ini /usr/local/etc/php/conf.d/zz-universityai.ini

WORKDIR /var/www/moodle

# ---------------------------------------------------------------------------
# web：Apache，document root 指向 public/
# ---------------------------------------------------------------------------
FROM base AS web

RUN a2enmod rewrite headers expires
COPY moodle/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY moodle/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# 實作腳本刻意放在**最後一層**，兩個 target 各自複製一次而不是放在 base。
#
# 放 base 的話，改一行 shell 腳本就會讓 base 之後的每一層失效，其中包括
# tools 階段那個要下載整套相依的 composer create-project。實際發生過：改了
# 一個 uai-*.php 之後 make lint 全項失敗，訊息是「Failed to find Composer's
# autoload file」——因為那次重建時 create-project 的相依安裝失敗，而它後面
# 接著 || echo，錯誤被吞掉了。
COPY bin/ /usr/local/bin/
RUN chmod +x /usr/local/bin/uai-*.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]

# ---------------------------------------------------------------------------
# tools：Composer、Node、moodle-plugin-ci
#
# 不跑網頁伺服器，長駐等待 exec。lint 與測試在這裡執行。
# ---------------------------------------------------------------------------
FROM base AS tools

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# postgresql-client 讓 make db-dump / db-restore 由這個容器經網路連到 db，
# 傾印檔因此直接落在專案目錄的掛載點上，不必再從 db 容器搬出來。
RUN apt-get update && apt-get install -y --no-install-recommends \
        git nodejs npm default-jre-headless postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# moodle-plugin-ci 裝在容器內的獨立目錄，不污染專案目錄。
#
# 失敗仍不阻擋建置——lint 之外的目標都不依賴它，為了一次網路問題就讓
# make up 整個失敗不划算。但**失敗必須留下痕跡**：原本只有 || echo，而
# create-project 會先把原始碼放好、再裝相依，相依那步失敗時 /opt/plugin-ci
# 看起來是存在的，bin/moodle-plugin-ci 也在，只有 vendor/ 不見。結果是
# make lint 每一項都以「Failed to find Composer's autoload file」失敗，
# 完全看不出跟映像建置有關。
#
# 現在改成：裝完檢查 vendor/autoload.php，缺了就寫一個標記檔，由
# uai-lint.sh 讀出來給明確指示。
RUN composer create-project moodlehq/moodle-plugin-ci /opt/plugin-ci ^4 \
        --no-progress --no-interaction \
    || echo "moodle-plugin-ci 安裝失敗" ; \
    if [ ! -f /opt/plugin-ci/vendor/autoload.php ]; then \
        echo "moodle-plugin-ci 的相依未安裝完成（缺 vendor/autoload.php）" \
            > /opt/plugin-ci-broken ; \
        echo "⚠ moodle-plugin-ci 安裝不完整，make lint 會提示如何修復。" ; \
    fi
ENV PATH="/opt/plugin-ci/bin:${PATH}"

# 理由同 web target：放在最後一層，改腳本不會讓上面那些昂貴的層失效。
COPY bin/ /usr/local/bin/
RUN chmod +x /usr/local/bin/uai-*.sh

CMD ["sleep", "infinity"]
