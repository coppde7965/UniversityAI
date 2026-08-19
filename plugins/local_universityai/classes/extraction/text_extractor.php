<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_universityai\extraction;

/**
 * 課綱檔案的文字抽取（FR-SYL-01、A-18）。
 *
 * A-18 決定課綱先在本地抽成文字再送模型，而 Moodle 核心沒有 PDF 解析器
 * ——`lib/pdflib.php` 是 TCPDF，只能產生不能解析。因此外掛自帶
 * `thirdparty/pdfparser`（見 `thirdpartylibs.xml` 與 `A-19`）。
 *
 * DOCX 不需要任何函式庫：它就是一個 ZIP，正文在 `word/document.xml`。
 * 為了一個解壓縮加去標籤去引入第二個第三方相依不划算。
 *
 * **第一版只支援含文字層的 PDF 與 DOCX**（SRS §4.1）。掃描影像 PDF 由
 * `MIN_TEXT_LENGTH` 擋下——`FR-SYL-01` 明文要求「在解析前即偵測並告知原因」，
 * 因為不擋的話會抽出一個空字串，然後在下游變成一個難以理解的失敗。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_extractor {
    /**
     * 低於這個字元數就視為沒有文字層。
     *
     * 不設 0 的理由：掃描版 PDF 仍常抽得出少量頁首頁尾或 OCR 殘留。門檻值
     * 與 Python 端的 `eval/evaluation/pdf_text.py` 刻意保持一致——兩邊對
     * 「這份檔案能不能用」的判斷若不同，評測結果就代表不了正式流程的行為。
     *
     * @var int
     */
    public const MIN_TEXT_LENGTH = 200;

    /** @var string[] 支援的副檔名，用於錯誤訊息與上傳表單的白名單。 */
    public const SUPPORTED_EXTENSIONS = ['pdf', 'docx'];

    /**
     * 自檔案內容抽取文字。
     *
     * @param string $content 檔案的原始位元組
     * @param string $filename 原始檔名，用於判斷格式
     * @return string 抽出的文字
     * @throws unsupported_format_exception 副檔名不在支援清單中
     * @throws no_text_layer_exception 抽出的文字量低於門檻
     */
    public function extract(string $content, string $filename): string {
        $extension = \core_text::strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $text = match ($extension) {
            'pdf' => $this->from_pdf($content),
            'docx' => $this->from_docx($content),
            default => throw new unsupported_format_exception($extension),
        };

        $text = $this->tidy($text);

        if (\core_text::strlen(trim($text)) < self::MIN_TEXT_LENGTH) {
            throw new no_text_layer_exception($extension);
        }

        return $text;
    }

    /**
     * 判斷副檔名是否受支援，不實際抽取。
     *
     * 上傳表單先用這個擋掉明顯不支援的格式，才能回一句明確的訊息而不是
     * 「上傳失敗」（FR-SYL-01 的驗收條件）。
     *
     * @param string $filename 檔名
     * @return bool
     */
    public static function is_supported(string $filename): bool {
        $extension = \core_text::strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($extension, self::SUPPORTED_EXTENSIONS, true);
    }

    /**
     * 抽取 PDF。
     *
     * @param string $content PDF 的原始位元組
     * @return string
     * @throws no_text_layer_exception 檔案根本無法解析時
     */
    private function from_pdf(string $content): string {
        self::load_pdfparser();

        try {
            $document = (new \Smalot\PdfParser\Parser())->parseContent($content);
        } catch (\Throwable $e) {
            // 解析失敗與「沒有文字層」對老師來說是同一件事：這份檔案用不了。
            // 把函式庫的內部例外訊息原樣丟給使用者沒有意義，而且它有可能
            // 夾帶檔案內容（NFR-SEC-06）。
            throw new no_text_layer_exception('pdf');
        }

        return $document->getText();
    }

    /**
     * 抽取 DOCX。
     *
     * DOCX 是 ZIP，正文在 word/document.xml。段落結束標籤換成換行後再去標籤
     * ——不換的話整份文件會變成一行，而週次表的每一列就黏在一起了。
     *
     * @param string $content DOCX 的原始位元組
     * @return string
     * @throws no_text_layer_exception 不是合法的 DOCX 時
     */
    private function from_docx(string $content): string {
        $temp = tempnam(make_temp_directory('local_universityai'), 'syllabus');
        file_put_contents($temp, $content);

        $zip = new \ZipArchive();
        if ($zip->open($temp) !== true) {
            unlink($temp);
            throw new no_text_layer_exception('docx');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($temp);

        if ($xml === false) {
            throw new no_text_layer_exception('docx');
        }

        // 段落與換行標籤先換成真正的換行，表格儲存格換成定位字元——
        // 儲存格邊界若整個消失，「日期」與「主題」兩欄就會黏成一個詞。
        $xml = preg_replace('#</w:p>#', "\n", $xml);
        $xml = preg_replace('#<w:br[^>]*/?>#', "\n", $xml);
        $xml = preg_replace('#</w:tc>#', "\t", $xml);

        return html_entity_decode(strip_tags((string) $xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * 收拾抽出的文字。
     *
     * 只做三件保守的事：統一換行、把三個以上的連續換行收成兩個、去掉行尾
     * 空白。**不合併行內空白**——PDF 的欄位之間就是靠空白分隔的，收掉它們
     * 等於再毀一次版面，而 A-18 的量測正是建立在「模型看得懂這種文字」上。
     *
     * @param string $text 原始抽出的文字
     * @return string
     */
    private function tidy(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+\n/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", (string) $text);

        return trim((string) $text);
    }

    /**
     * 註冊 PdfParser 的自動載入。
     *
     * 沒有用 composer 產生的 `vendor/autoload.php`：那會多帶 composer 自己的
     * 目錄，而且與 Moodle 核心的 autoload 疊在一起只是多一層。函式庫是
     * 標準 PSR-4，十行就夠。
     *
     * 只在第一次真的要解析 PDF 時註冊，不放 `lib.php`——`lib.php` 每個頁面
     * 請求都會載入，為了一個絕大多數請求用不到的函式庫付那個成本不值得。
     *
     * @return void
     */
    private static function load_pdfparser(): void {
        static $registered = false;

        if ($registered) {
            return;
        }

        $base = dirname(__DIR__, 2) . '/thirdparty/pdfparser/src/';

        spl_autoload_register(static function (string $class) use ($base): void {
            if (!str_starts_with($class, 'Smalot\\PdfParser\\')) {
                return;
            }
            $path = $base . str_replace('\\', '/', $class) . '.php';
            if (is_readable($path)) {
                require_once($path);
            }
        });

        $registered = true;
    }
}
