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
 * `events[].event_id` 的推導（§9.6）。
 *
 * §3.2 把這件事列為**必須被滿足的約束**：同一場期中考在第 1 版與第 2 版
 * 必須得到相同的識別碼，否則 `_evtmap` 的唯一鍵擋不住重複（`NFR-REL-04`），
 * `FR-DIF-01` 的比對也會把「日期改了的同一場考試」看成「刪掉一場、新增一場」。
 *
 * 因此**識別碼由本外掛推導，不問模型**。模型每次產生的值沒有理由跨版本
 * 一致，而這裡要的正是那個一致性。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class event_key {
    /** @var int 取雜湊的前幾字元。`_evtmap.eventkey` 是 char(64)，留餘裕給將來的前綴。 */
    public const LENGTH = 32;

    /**
     * 為一份課綱的所有事件補上識別碼。
     *
     * 出現序號在這裡算：同一份課綱裡兩次「小考」若沒有序號會得到同一個
     * 識別碼，`_evtmap` 的唯一鍵就會把第二次當成第一次的重複。
     *
     * @param array $events DM-SYLLABUS 的 events[]
     * @return array 補上 event_id 的 events[]
     */
    public static function assign(array $events): array {
        $seen = [];

        foreach ($events as $index => $event) {
            $type = (string) ($event['type'] ?? 'other');
            $title = self::normalise((string) ($event['title'] ?? ''));

            $bucket = $type . '|' . $title;
            $occurrence = $seen[$bucket] ?? 0;
            $seen[$bucket] = $occurrence + 1;

            $events[$index]['event_id'] = self::derive($type, $title, $occurrence);
        }

        return $events;
    }

    /**
     * 推導單一識別碼。
     *
     * **刻意不含日期。** 日期正是最常改動的欄位，把它放進識別碼等於保證
     * 跨版本對不起來——而跨版本對得起來正是這個識別碼存在的唯一理由。
     *
     * @param string $type 事件類型
     * @param string $title 已正規化的標題
     * @param int $occurrence 同一份課綱內的出現序號，自 0 起算
     * @return string
     */
    public static function derive(string $type, string $title, int $occurrence): string {
        return substr(sha1($type . '|' . $title . '|' . $occurrence), 0, self::LENGTH);
    }

    /**
     * 標題正規化。
     *
     * 去除前後空白、把連續空白收成一個、全形數字轉半形。**不做大小寫轉換
     * 以外的語意處理**——中文沒有大小寫，而過度正規化會讓「作業一」與
     * 「作業二」有機會碰撞，那比不正規化糟得多。
     *
     * 已知的弱點，明講：老師把「期中考」改名成「期中測驗」，這條規則會判定
     * 為不同事件。沒有純機械的方法能分辨「改名」與「換一場考試」——
     * `FR-DIF-02` 的人工確認介面正是為這類情況存在的，系統不猜。
     *
     * @param string $title 原始標題
     * @return string
     */
    public static function normalise(string $title): string {
        $halfwidth = str_replace(
            ['０', '１', '２', '３', '４', '５', '６', '７', '８', '９'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $title
        );

        $collapsed = preg_replace('/[[:space:]\x{3000}]+/u', ' ', $halfwidth);

        return trim((string) $collapsed);
    }
}
