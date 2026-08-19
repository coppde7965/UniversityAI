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

namespace local_universityai;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * 語言字串的完整性（NFR-MNT-04）。
 *
 * 缺字串在 Moodle 不會拋例外，只會在畫面上印出 [[some:key]]。人工測試時
 * 很容易看過去，尤其是在通知信或極少走到的錯誤路徑上——那正是最需要
 * 字串正確的地方。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class lang_test extends \advanced_testcase {
    /**
     * 直接讀語言檔本身，不經字串管理員。
     *
     * get_string_manager()->load_component_strings() 會自動套上父語言與
     * 英文的退路，於是 zh_tw 缺的鍵會被英文補齊而永遠比對相等——這個
     * 測試就白寫了。
     *
     * @param string $lang 語言目錄名
     * @return array<string, string>
     */
    private function load_lang_file(string $lang): array {
        global $CFG;

        $string = [];
        $path = $CFG->dirroot . "/local/universityai/lang/{$lang}/local_universityai.php";
        $this->assertFileExists($path);
        include($path);

        return $string;
    }

    /**
     * 英文與繁體中文的鍵完全一致。
     */
    public function test_english_and_traditional_chinese_have_the_same_keys(): void {
        $en = array_keys($this->load_lang_file('en'));
        $zhtw = array_keys($this->load_lang_file('zh_tw'));

        sort($en);
        sort($zhtw);

        $this->assertSame(
            $en,
            $zhtw,
            'lang/en 與 lang/zh_tw 的鍵不一致。缺少：'
            . implode(', ', array_merge(array_diff($en, $zhtw), array_diff($zhtw, $en)))
        );
    }

    /**
     * 每個作業類型與對象類型都有對應的顯示字串。
     *
     * 這兩組值會直接組進通知內容（notifier），少一個就是使用者收到
     * 一封寫著 [[operation:xxx]] 的信。
     */
    public function test_every_enum_value_has_a_display_string(): void {
        foreach (['en', 'zh_tw'] as $lang) {
            $strings = $this->load_lang_file($lang);

            foreach (ops::operations() as $operation) {
                $this->assertArrayHasKey(
                    "operation:{$operation}",
                    $strings,
                    "lang/{$lang} 缺少 operation:{$operation}。"
                );
            }
            foreach (ops::targettypes() as $targettype) {
                $this->assertArrayHasKey(
                    "targettype:{$targettype}",
                    $strings,
                    "lang/{$lang} 缺少 targettype:{$targettype}。"
                );
            }
        }
    }

    /**
     * db/access.php 宣告的每一項能力都有說明字串。
     *
     * Moodle 的權限設定頁會逐項顯示這些字串，缺了就是管理員在授權時
     * 看到一串 [[universityai:xxx]]，根本不知道自己在勾什麼。
     */
    public function test_every_capability_has_a_description_string(): void {
        global $CFG;

        $capabilities = [];
        include($CFG->dirroot . '/local/universityai/db/access.php');
        $this->assertNotEmpty($capabilities);

        foreach (['en', 'zh_tw'] as $lang) {
            $strings = $this->load_lang_file($lang);

            foreach (array_keys($capabilities) as $capability) {
                // local/universityai:upload 的字串鍵是 universityai:upload。
                $key = substr($capability, strpos($capability, '/') + 1);
                $this->assertArrayHasKey(
                    $key,
                    $strings,
                    "lang/{$lang} 缺少能力 {$capability} 的說明字串（鍵應為 {$key}）。"
                );
            }
        }
    }

    /**
     * db/messages.php 宣告的每一個訊息提供者都有名稱字串。
     */
    public function test_every_message_provider_has_a_name_string(): void {
        global $CFG;

        $messageproviders = [];
        include($CFG->dirroot . '/local/universityai/db/messages.php');
        $this->assertNotEmpty($messageproviders);

        foreach (['en', 'zh_tw'] as $lang) {
            $strings = $this->load_lang_file($lang);

            foreach (array_keys($messageproviders) as $provider) {
                $this->assertArrayHasKey(
                    "messageprovider:{$provider}",
                    $strings,
                    "lang/{$lang} 缺少 messageprovider:{$provider}。"
                );
            }
        }
    }
}
