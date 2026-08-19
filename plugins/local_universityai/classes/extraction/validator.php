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
 * DM-SYLLABUS 的驗證（FR-SYL-02、FR-SYL-03）。
 *
 * **驗證放在呼叫端而不是實作內**（§9.5）：兩種抽取模式、將來可能的第三家
 * 供應商，都必須滿足同一份契約。放實作裡就是每個實作各寫一份，而「兩份
 * 驗證行為不一致」是那種永遠不會有人發現的缺陷。
 *
 * 分成兩層，因為它們的性質不同：
 *
 *   1. **JSON Schema** 管得到型別、必填、列舉與範圍。
 *   2. **不變式**管的是欄位之間的關係，schema 表達不了——SRS §5 列了四條，
 *      再加上 FR-SYL-03 的「source_span 必須實際出現在原文中」。
 *
 * 第 2 層裡又有兩種嚴重度。週次連續、週數相符是**絕對的**：違反就是資料
 * 壞了。事件日期落在學期外、評分加總不為 100 則不是——真實課綱本來就會
 * 這樣，SRS 要求的是「須列入 unresolved，不得自動調整」。所以那兩條的
 * 判準是「有沒有誠實回報」，不是「有沒有發生」。
 *
 * 這個類別沒有內建通用的 JSON Schema 函式庫。Moodle 沒有帶，而為了本專案
 * 用到的六個關鍵字去引入 composer 相依不划算（上架規範也不歡迎）。實作的
 * 關鍵字：type、required、properties、additionalProperties、items、enum、
 * minimum、maximum。**它不是通用驗證器**，只驗得了 schema.php 產生的形狀。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validator {
    /** @var float 信心值低於此門檻的項目在確認介面上要標示（FR-SYL-03）。 */
    public const DEFAULT_CONFIDENCE_THRESHOLD = 0.6;

    /**
     * 完整驗證。
     *
     * @param array $data 待驗的抽取結果
     * @param string $sourcetext 課綱原文，用於檢查 source_span；留空則略過該項
     * @return array{errors: string[], notes: string[]} errors 非空即不得寫入
     */
    public function validate(array $data, string $sourcetext = ''): array {
        $errors = $this->validate_against_schema($data, schema::extraction_schema());

        // schema 不過就不必往下驗：不變式全都假設欄位存在且型別正確，
        // 在壞掉的結構上跑只會產生一堆衍生錯誤，把真正的原因淹掉。
        if ($errors !== []) {
            return ['errors' => $errors, 'notes' => []];
        }

        $notes = [];

        $errors = array_merge(
            $errors,
            $this->check_week_sequence($data),
            $this->check_week_count($data),
            $this->check_confidence_range($data),
            $this->check_source_spans($data, $sourcetext)
        );

        $unresolved = $data['parse_meta']['unresolved'] ?? [];
        foreach ([$this->check_event_dates($data), $this->check_grading_total($data)] as $findings) {
            foreach ($findings as $finding) {
                // 有回報就是可接受的結果，沒回報就是臆造。
                if ($unresolved === []) {
                    $errors[] = $finding . '，且 parse_meta.unresolved 是空的。';
                } else {
                    $notes[] = $finding;
                }
            }
        }

        return ['errors' => $errors, 'notes' => $notes];
    }

    /**
     * 依 schema 驗證，回傳所有違規路徑。
     *
     * @param mixed $data 待驗資料
     * @param array $schema JSON Schema
     * @param string $path 目前路徑，供錯誤訊息使用
     * @return string[]
     */
    public function validate_against_schema($data, array $schema, string $path = '$'): array {
        $errors = [];

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            if (!$this->type_matches($data, $types)) {
                $actual = is_object($data) ? 'object' : gettype($data);
                $names = array_map(static fn($t) => $t === null ? 'null' : $t, $types);

                // 型別不符就不再往下驗這個節點：拿字串去檢查 properties
                // 只會產生一堆看不懂的衍生錯誤。
                return [$path . ' 型別應為 ' . implode('|', $names) . '，實際為 ' . $actual];
            }
        }

        if (isset($schema['enum']) && !in_array($data, $schema['enum'], true)) {
            $errors[] = $path . ' 的值不在允許清單中：' . var_export($data, true);
        }

        if (is_numeric($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                $errors[] = $path . ' 小於下限 ' . $schema['minimum'] . '：' . $data;
            }
            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                $errors[] = $path . ' 超過上限 ' . $schema['maximum'] . '：' . $data;
            }
        }

        if (is_array($data) && isset($schema['properties'])) {
            foreach ($schema['required'] ?? [] as $key) {
                if (!array_key_exists($key, $data)) {
                    $errors[] = $path . ' 缺少必要欄位 ' . $key;
                }
            }
            if (($schema['additionalProperties'] ?? true) === false) {
                foreach (array_keys($data) as $key) {
                    if (!isset($schema['properties'][$key])) {
                        $errors[] = $path . ' 出現未定義的欄位 ' . $key;
                    }
                }
            }
            foreach ($schema['properties'] as $key => $subschema) {
                if (array_key_exists($key, $data)) {
                    $errors = array_merge(
                        $errors,
                        $this->validate_against_schema($data[$key], $subschema, $path . '.' . $key)
                    );
                }
            }
        }

        if (is_array($data) && isset($schema['items'])) {
            foreach ($data as $index => $item) {
                $errors = array_merge(
                    $errors,
                    $this->validate_against_schema($item, $schema['items'], $path . '[' . $index . ']')
                );
            }
        }

        return $errors;
    }

    /**
     * 判斷值是否符合任一允許型別。
     *
     * PHP 的關聯陣列與索引陣列都是 array，所以 object 與 array 得靠
     * array_is_list() 分辨。空陣列兩者皆可——JSON 的 [] 與 {} 解碼後
     * 都是空的 PHP 陣列，這裡無從分辨，也不需要分辨。
     *
     * @param mixed $data 值
     * @param array $types 允許的型別
     * @return bool
     */
    private function type_matches($data, array $types): bool {
        foreach ($types as $type) {
            $ok = match ($type) {
                'object' => is_array($data) && (empty($data) || !array_is_list($data)),
                'array' => is_array($data) && (empty($data) || array_is_list($data)),
                'string' => is_string($data),
                'integer' => is_int($data),
                'number' => is_int($data) || is_float($data),
                'boolean' => is_bool($data),
                'null', null => $data === null,
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * 不變式一：week_no 於同一課綱內唯一且連續，自 1 起算。
     *
     * @param array $data 抽取結果
     * @return string[]
     */
    private function check_week_sequence(array $data): array {
        $numbers = array_map(static fn($w) => (int) $w['week_no'], $data['weeks']);

        if ($numbers === []) {
            return ['weeks 是空的，課綱至少要有一週。'];
        }

        $expected = range(1, count($numbers));
        $sorted = $numbers;
        sort($sorted);

        if ($sorted !== $expected) {
            return ['weeks[].week_no 不是自 1 起算的連續整數，實際為 ' . implode('、', $numbers)];
        }

        return [];
    }

    /**
     * 不變式二：weeks 的長度等於 term.total_weeks。
     *
     * @param array $data 抽取結果
     * @return string[]
     */
    private function check_week_count(array $data): array {
        $actual = count($data['weeks']);
        $declared = (int) $data['term']['total_weeks'];

        if ($actual !== $declared) {
            return ["weeks 有 {$actual} 筆，但 term.total_weeks 是 {$declared}。"];
        }

        return [];
    }

    /**
     * FR-SYL-03：confidence 須介於 0 與 1 之間。
     *
     * 這條本來屬於 schema 的職責，但 API 端的 schema 檢查不接受 number 的
     * minimum/maximum（見 schema.php 的第 4 條約束），只好搬到這裡。
     * **搬過來反而更安全**：提示詞路徑本來就沒有 API 端的 schema 保證，
     * 兩條路現在受同一份範圍檢查約束。
     *
     * @param array $data 抽取結果
     * @return string[]
     */
    private function check_confidence_range(array $data): array {
        $errors = [];

        foreach (['weeks', 'events'] as $section) {
            foreach ($data[$section] as $index => $item) {
                $confidence = (float) $item['confidence'];
                if ($confidence < 0 || $confidence > 1) {
                    $errors[] = $section . '[' . $index . '] 的 confidence 是 ' . $confidence
                        . '，須介於 0 與 1 之間（FR-SYL-03）。';
                }
            }
        }

        return $errors;
    }

    /**
     * 不變式三：事件日期須落在學期起訖範圍內。
     *
     * 違反不必然是錯誤——補交期限落在學期結束後是常見的。SRS 要求的是
     * 「否則列入 unresolved」，所以這裡只回報事實，由呼叫端依有沒有回報
     * 決定嚴重度。
     *
     * 日期無法解析或學期起始日缺失時**不回報**：那是 schema 允許的 null，
     * 或是另一個不變式的問題，在這裡重複回報只會製造雜訊。
     *
     * @param array $data 抽取結果
     * @return string[]
     */
    private function check_event_dates(array $data): array {
        $start = $this->to_day((string) ($data['term']['start_date'] ?? ''));
        $totalweeks = (int) $data['term']['total_weeks'];

        if ($start === null || $totalweeks < 1) {
            return [];
        }

        $end = $start + ($totalweeks * 7 * DAYSECS);
        $findings = [];

        foreach ($data['events'] as $index => $event) {
            $due = $this->to_day((string) ($event['due_date'] ?? ''));
            if ($due === null) {
                continue;
            }
            if ($due < $start || $due >= $end) {
                $findings[] = 'events[' . $index . '] 的 due_date ' . $event['due_date']
                    . ' 落在學期範圍外';
            }
        }

        return $findings;
    }

    /**
     * 不變式四：grading[].weight 的總和須為 100。
     *
     * @param array $data 抽取結果
     * @return string[]
     */
    private function check_grading_total(array $data): array {
        if ($data['grading'] === []) {
            return [];
        }

        $total = 0.0;
        foreach ($data['grading'] as $item) {
            $total += (float) $item['weight'];
        }

        // 浮點數比較留一點餘裕：33.3 加三次不會剛好是 100。
        if (abs($total - 100.0) > 0.5) {
            return ['grading 的比例加總為 ' . rtrim(rtrim(number_format($total, 1), '0'), '.') . '，不是 100'];
        }

        return [];
    }

    /**
     * FR-SYL-03：source_span 的內容必須實際出現在課綱原文中。
     *
     * 這條驗收條件是整份 schema 裡最有價值的一項防線——它擋的是模型憑空
     * 生成內容然後附上一段同樣是憑空生成的「出處」。沒有它，confidence 與
     * source_span 只是兩個看起來很負責任的欄位。
     *
     * **比對前把兩邊的空白全部拿掉**，不是收成單一空格。理由是中文的斷行：
     * PDF 把「課程介紹與人工智慧發展簡史」折成兩行之後，抽出的文字中間會多
     * 一個空白，而模型引用時不會有。留著空白比對，每一個被折行的項目都會被
     * 判成幻覺——那是在量排版，不是在量幻覺。
     *
     * 代價是英文可能因此放寬（"a b" 與 "ab" 會被視為相同）。這條檢查的目的是
     * 擋住整段憑空生成的出處，不是逐字校對，放寬的方向是可接受的。
     *
     * @param array $data 抽取結果
     * @param string $sourcetext 課綱原文；留空表示略過
     * @return string[]
     */
    private function check_source_spans(array $data, string $sourcetext): array {
        if (trim($sourcetext) === '') {
            return [];
        }

        $haystack = $this->strip_whitespace($sourcetext);
        $errors = [];

        foreach (['weeks', 'events'] as $section) {
            foreach ($data[$section] as $index => $item) {
                $span = $this->strip_whitespace((string) $item['source_span']);
                if ($span === '') {
                    $errors[] = $section . '[' . $index . '] 的 source_span 是空的（FR-SYL-03）。';
                    continue;
                }
                if (!str_contains($haystack, $span)) {
                    $errors[] = $section . '[' . $index . '] 的 source_span 未出現在課綱原文中：'
                        . \core_text::substr($span, 0, 40);
                }
            }
        }

        return $errors;
    }

    /**
     * 移除全部空白，含全形空白與各種換行。
     *
     * @param string $text 原始文字
     * @return string
     */
    private function strip_whitespace(string $text): string {
        $text = str_replace("\u{3000}", ' ', $text);

        return (string) preg_replace('/[[:space:]]+/u', '', $text);
    }

    /**
     * 把 YYYY-MM-DD 轉成當日零時的時間戳。
     *
     * @param string $date 日期字串
     * @return int|null 無法解析時為 null
     */
    private function to_day(string $date): ?int {
        $parsed = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date,
            \core_date::get_server_timezone_object()
        );

        return $parsed === false ? null : $parsed->getTimestamp();
    }
}
