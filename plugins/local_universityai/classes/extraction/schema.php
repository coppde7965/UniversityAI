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
 * DM-SYLLABUS 的 JSON Schema（SRS §5）。
 *
 * schema 由 local_universityai 產生並持有，實作端只是把它轉成請求參數
 * （§9.3）。以陣列而非類別表達，是為了不讓供應商外掛被迫引用本外掛的
 * 型別——那會違反 §1.2 的第 1 條規則。
 *
 * **三條 API 端的 schema 約束**，全部在第一‧五階段實測撞到過（§9.1）：
 *
 *   1. union type 與 enum 不得併用。`type: ['string','null']` 加上含 null
 *      的 enum 會被拒絕，訊息是「Enum value 'MON' does not match declared
 *      type」。enum 本身已經把取值約束完整，type 是多餘的。
 *   2. 每個 object 都必須有 `additionalProperties: false`。
 *   3. 每個 object 都必須列出完整的 `required`。
 *   4. `number` 型別**不支援 minimum 與 maximum**。第三階段撞到的：
 *      confidence 原本宣告 0 到 1 的範圍，整個請求被 400 拒絕，訊息是
 *      「For 'number' type, properties maximum, minimum are not supported」。
 *      範圍檢查因此移到 validator——那本來就是 §9.5 說驗證該在的地方。
 *
 * 少了第 2、3 點，schema 看起來有約束但模型可以自由增減欄位——那比沒有
 * schema 更糟，因為它會讓人以為結構已經被保證了。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schema {
    /** @var string[] 課程事件的類型，對應 DM-SYLLABUS 的 events[].type。 */
    public const EVENT_TYPES = ['assignment', 'exam', 'project', 'other'];

    /** @var string[] 上課星期，null 表示課綱未載明。 */
    public const WEEKDAYS = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];

    /**
     * 完整的抽取 schema。
     *
     * **不含由系統指派的欄位**：`syllabus_id`、`course_ref`、`version`、
     * `status` 由本外掛決定，放進 schema 只會誘導模型憑空捏造識別碼。
     *
     * **也不含 `events[].event_id`。** 第一‧五階段的實驗 schema 有這一欄，
     * 是錯的：§9.6 決定 event_id 由 (type, 正規化標題, 出現序號) 推導，
     * 讓它跨版本穩定。交給模型產生就拿不到那個穩定性——同一場期中考在
     * 第 2 版會得到不同的識別碼，`_evtmap` 的唯一鍵就擋不住重複。
     *
     * **也不含 `overridden`。** 那是老師修改後由系統標記的（§3.3），
     * 抽取當下一律為 false，問模型沒有意義。
     *
     * @return array JSON Schema
     */
    public static function extraction_schema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['course', 'term', 'grading', 'weeks', 'events', 'parse_meta'],
            'properties' => [
                'course' => self::course(),
                'term' => self::term(),
                'grading' => self::grading(),
                'weeks' => self::weeks(),
                'events' => self::events(),
                'parse_meta' => self::parse_meta(),
            ],
        ];
    }

    /**
     * 可為 null 的字串。
     *
     * 抽出這個輔助函式不是為了少打字，而是為了讓「哪些欄位允許 null」
     * 一眼看得出來——DM-SYLLABUS 裡可為 null 的欄位比不可為 null 的多，
     * 逐個手寫很容易漏掉一個，而漏掉的那個會在模型誠實回報「課綱沒寫」
     * 時炸掉，也就是最不該炸的時候。
     *
     * @return array
     */
    private static function nullable_string(): array {
        return ['type' => ['string', 'null']];
    }

    /**
     * course 區塊。
     *
     * @return array
     */
    private static function course(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title', 'code', 'teacher', 'semester', 'credits'],
            'properties' => [
                'title' => ['type' => 'string'],
                'code' => self::nullable_string(),
                'teacher' => self::nullable_string(),
                'semester' => self::nullable_string(),
                'credits' => ['type' => ['number', 'null']],
            ],
        ];
    }

    /**
     * term 區塊。
     *
     * @return array
     */
    private static function term(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['start_date', 'total_weeks', 'weekday'],
            'properties' => [
                'start_date' => ['type' => 'string'],
                'total_weeks' => ['type' => 'integer'],

                // 只給 enum、不併 type——見類別說明的第 1 條約束。
                'weekday' => ['enum' => array_merge(self::WEEKDAYS, [null])],
            ],
        ];
    }

    /**
     * grading 區塊。
     *
     * @return array
     */
    private static function grading(): array {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['item', 'weight', 'note'],
                'properties' => [
                    'item' => ['type' => 'string'],
                    'weight' => ['type' => 'number'],
                    'note' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /**
     * weeks 區塊。
     *
     * @return array
     */
    private static function weeks(): array {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['week_no', 'date', 'topic', 'note', 'confidence', 'source_span'],
                'properties' => [
                    'week_no' => ['type' => 'integer'],
                    'date' => self::nullable_string(),
                    'topic' => ['type' => 'string'],
                    'note' => ['type' => 'string'],

                    // FR-SYL-03：每個元素都要有信心值與原文出處。
                    // 0 到 1 的範圍寫不進 schema（見類別說明第 4 條約束），
                    // 與「source_span 必須實際出現在原文中」一起由 validator 檢查。
                    'confidence' => ['type' => 'number'],
                    'source_span' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /**
     * events 區塊。
     *
     * @return array
     */
    private static function events(): array {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['type', 'title', 'due_date', 'due_time', 'week_no', 'confidence', 'source_span'],
                'properties' => [
                    'type' => ['enum' => self::EVENT_TYPES],
                    'title' => ['type' => 'string'],
                    'due_date' => self::nullable_string(),
                    'due_time' => self::nullable_string(),
                    'week_no' => ['type' => ['integer', 'null']],
                    'confidence' => ['type' => 'number'],
                    'source_span' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /**
     * parse_meta 區塊。
     *
     * 只問 unresolved。source_file、parsed_at、model 三者本外掛都比模型
     * 清楚，問了只會得到猜測。
     *
     * @return array
     */
    private static function parse_meta(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['unresolved'],
            'properties' => [
                'unresolved' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }
}
