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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * 課綱檔案的文字抽取（FR-SYL-01、A-18、A-19）。
 *
 * 測試檔案都在測試裡即時產生，不依賴 `eval/build/` 下的樣本——那些是
 * `make fixtures` 的產物，不進版控，PHPUnit 不該依賴它們存在。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(text_extractor::class)]
#[CoversClass(no_text_layer_exception::class)]
#[CoversClass(unsupported_format_exception::class)]
final class text_extractor_test extends \advanced_testcase {
    /**
     * 每個測試都在交易中執行並於結束時回捲。
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * 產生一份含足量文字的 PDF。
     *
     * 內容刻意用英文與數字：這裡要驗的是「併入的函式庫接得起來」，
     * 不是中文 CID 字型的解碼品質——後者是 §9.10 記下的已知限制，
     * 拿它來當單元測試的判準會讓這條測試在修好那個限制之前一直是紅的。
     *
     * @return string PDF 的原始位元組
     */
    private function make_pdf(): string {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');

        $pdf = new \pdf();
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 11);
        $pdf->writeHTML(
            '<h2>Syllabus 2025-09-09</h2>'
            . '<p>Week 1 2025-09-09 Introduction to the course and its history.</p>'
            . '<p>Week 2 2025-09-16 Search algorithms including BFS and DFS.</p>'
            . '<p>Week 3 2025-09-23 Knowledge representation and reasoning methods.</p>'
            . '<p>Assignment one is due on 2025-09-30 at 23:59 without exception.</p>',
            true,
            false,
            true,
            false,
            ''
        );

        return $pdf->Output('', 'S');
    }

    /**
     * 產生一份最小的 DOCX。
     *
     * DOCX 就是 ZIP，正文在 word/document.xml。只放正文足以驗證抽取邏輯，
     * 不必產生一份 Word 真的打得開的檔案。
     *
     * @param string $body word/document.xml 的內容
     * @return string DOCX 的原始位元組
     */
    private function make_docx(string $body): string {
        $path = tempnam(make_temp_directory('local_universityai_test'), 'docx');

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $body);
        $zip->close();

        $content = file_get_contents($path);
        unlink($path);

        return $content;
    }

    /**
     * 併入的 PdfParser 真的能在 Moodle 環境裡抽出文字。
     *
     * 這條測試守的是 A-19 的整合面：自訂的 PSR-4 自動載入器接得上、
     * 函式庫的相依（iconv、zlib）在映像裡存在。三者任一不成立，
     * 這裡就會紅，而不是等到老師上傳課綱那一刻才發現。
     */
    public function test_pdf_text_is_extracted(): void {
        $text = (new text_extractor())->extract($this->make_pdf(), 'syllabus.pdf');

        $this->assertStringContainsString('Week 1', $text);
        $this->assertStringContainsString('2025-09-09', $text);
        $this->assertStringContainsString('Assignment one', $text);
    }

    /**
     * DOCX 的段落與儲存格邊界要保留下來。
     *
     * 不換行的話整份文件會變成一行，週次表的每一列就黏在一起——那正是
     * 下游最不需要的輸入。
     */
    public function test_docx_paragraph_and_cell_boundaries_survive(): void {
        // 文字量要超過無文字層的門檻，否則這條測試會被那道檢查擋下，
        // 失敗訊息指向的也不是它真正要驗的東西。
        $body = '<w:document><w:body>'
            . '<w:p><w:r><w:t>' . str_repeat('課程大綱總覽說明文字。', 30) . '</w:t></w:r></w:p>'
            . '<w:tbl><w:tr>'
            . '<w:tc><w:p><w:r><w:t>第 1 週</w:t></w:r></w:p></w:tc>'
            . '<w:tc><w:p><w:r><w:t>2025-09-09</w:t></w:r></w:p></w:tc>'
            . '</w:tr></w:tbl>'
            . '</w:body></w:document>';

        $text = (new text_extractor())->extract($this->make_docx($body), 'syllabus.docx');

        $this->assertStringContainsString('第 1 週', $text);
        $this->assertStringContainsString('2025-09-09', $text);

        // 儲存格之間要有分隔，否則「第 1 週」與日期會黏成一個詞。
        $this->assertMatchesRegularExpression('/第 1 週[\s\t]+2025-09-09/u', $text);
    }

    /**
     * 不支援的格式要說出支援哪些，不能只說「上傳失敗」。
     *
     * 這是 FR-SYL-01 的驗收條件原文，也是最容易被寫成一句通用錯誤訊息的
     * 地方。支援清單由 text_extractor 提供，語言檔裡不另寫一份。
     */
    public function test_unsupported_format_names_the_supported_ones(): void {
        try {
            (new text_extractor())->extract('anything', 'syllabus.pptx');
            $this->fail('不支援的格式應該被擋下。');
        } catch (unsupported_format_exception $e) {
            $this->assertStringContainsString('pptx', $e->getMessage());
            foreach (text_extractor::SUPPORTED_EXTENSIONS as $extension) {
                $this->assertStringContainsString($extension, $e->getMessage());
            }
        }
    }

    /**
     * 沒有文字層的檔案要在解析前就被擋下。
     *
     * FR-SYL-01 明文要求這件事。不擋的話會抽出一個空字串，在下游變成一個
     * 難以理解的失敗——而老師看到的會是「解析失敗」，完全不知道是因為
     * 自己上傳了掃描檔。
     */
    public function test_a_file_without_a_text_layer_is_rejected(): void {
        $extractor = new text_extractor();

        // 壞掉的 PDF 與掃描檔對老師來說是同一件事：這份檔案用不了。
        try {
            $extractor->extract('%PDF-1.4 這不是一份真的 PDF', 'scan.pdf');
            $this->fail('無法解析的 PDF 應該被擋下。');
        } catch (no_text_layer_exception $e) {
            $this->assertStringContainsString('pdf', $e->getMessage());
        }

        // 文字量低於門檻的 DOCX 同樣擋下。
        $short = '<w:document><w:body><w:p><w:r><w:t>課程大綱</w:t></w:r></w:p></w:body></w:document>';
        try {
            $extractor->extract($this->make_docx($short), 'short.docx');
            $this->fail('文字量不足的檔案應該被擋下。');
        } catch (no_text_layer_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    /**
     * 錯誤訊息不得夾帶函式庫的內部細節。
     *
     * 那些訊息對老師沒有意義，而且有可能夾帶檔案內容（NFR-SEC-06）。
     */
    public function test_error_messages_do_not_leak_library_internals(): void {
        try {
            (new text_extractor())->extract('%PDF-1.4 broken', 'x.pdf');
            $this->fail('應該被擋下。');
        } catch (no_text_layer_exception $e) {
            $this->assertStringNotContainsString('Smalot', $e->getMessage());
            $this->assertStringNotContainsString('.php', $e->getMessage());
        }
    }

    /**
     * 副檔名的判斷不分大小寫。
     */
    public function test_extension_check_is_case_insensitive(): void {
        $this->assertTrue(text_extractor::is_supported('SYLLABUS.PDF'));
        $this->assertTrue(text_extractor::is_supported('a.DocX'));
        $this->assertFalse(text_extractor::is_supported('a.pages'));
        $this->assertFalse(text_extractor::is_supported('noextension'));
    }

    /**
     * 門檻值與 Python 端保持一致。
     *
     * 兩邊對「這份檔案能不能用」的判斷若不同，評測結果就代表不了正式流程
     * 的行為——而那正是 A-05 那條原則在這裡的形式。
     */
    public function test_threshold_matches_the_python_side(): void {
        // 評測工具鏈不隨外掛發行，所以它可能不存在——外掛必須能獨立安裝
        // （NFR-MNT-02）。不存在就略過，不是失敗。
        $path = '/opt/uai/eval/evaluation/pdf_text.py';
        if (!is_readable($path)) {
            $this->markTestSkipped('評測工具鏈不在此環境中。');
        }

        $source = file_get_contents($path);

        $this->assertMatchesRegularExpression(
            '/MIN_TEXT_LENGTH\s*=\s*' . text_extractor::MIN_TEXT_LENGTH . '\b/',
            $source,
            'PHP 與 Python 兩端的無文字層門檻不一致。'
        );
    }
}
