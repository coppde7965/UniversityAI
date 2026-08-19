<?php
// 第一‧五階段的驗收：AI 呼叫可行性。
//
// SRS §8.4 把這裡定為一道實作閘門——未通過不得撰寫 docs/architecture.md
// 的 AI 相關章節。三項驗收條件逐項對應本腳本的三個實驗：
//
//   1. 供應商能被 AI 子系統識別並呼叫（D-05 的生成類路徑、NFR-EXT-03 前半）
//   2. 抽取介面能取得結構化輸出（D-05 的抽取路徑、FR-SYL-02 的地基）
//   3. 通用實作的退路真的存在（NFR-EXT-03 後半、OI-06 延後的理由）
//
// 會真的呼叫 Anthropic API，**會產生費用**。次數由 --runs 控制。
//
// 只用於開發與驗證，不隨外掛發行。

define('CLI_SCRIPT', true);

require('/var/www/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');

use aiprovider_claude\extractor;
use aiprovider_claude\provider as claudeprovider;
use core_ai\aiactions\generate_text;

[$options] = cli_get_params([
    'runs' => 3,
    'help' => false,
]);

if ($options['help']) {
    cli_writeln("第一‧五階段的 AI 可行性驗證。會實際呼叫 API 並產生費用。

選項：
  --runs=N    實驗 2 與 3 各跑幾次，預設 3
  -h, --help  顯示此說明
");
    exit(0);
}

$runs = max(1, (int) $options['runs']);
$failures = [];

/**
 * 印出一個段落標題。
 *
 * @param string $title 標題
 */
function uai_section(string $title): void {
    cli_writeln('');
    cli_separator();
    cli_writeln(' ' . $title);
    cli_separator();
}

/**
 * 回報一項驗收條件的結果。
 *
 * @param bool $ok 是否通過
 * @param string $what 條件描述
 */
function uai_verdict(bool $ok, string $what): void {
    global $failures;
    cli_writeln('');
    cli_writeln(($ok ? '  ✔ 通過　' : '  ✘ 未通過　') . $what);
    if (!$ok) {
        $failures[] = $what;
    }
}

// ---------------------------------------------------------------------------
// 測試用課綱
//
// 刻意做成 6 週而不是真實的 18 週：實驗要跑很多次，週數直接影響輸出 token
// 數與費用，而 6 週已足以測出「陣列元素是否逐一符合結構」。真實長度的課綱
// 屬於第三階段的正確率量測（OI-03 的測試集），不是這裡的目的。
//
// 內容刻意留了兩個模糊處，用來觀察模型會不會誠實地放進 unresolved：
//   - 第 4 週「期中考（暫定）」沒有明確日期
//   - 評分比例加起來是 90 而不是 100
// ---------------------------------------------------------------------------
$syllabus = <<<'SYLLABUS'
國立範例大學　114 學年度第 1 學期　課程大綱

課程名稱：人工智慧導論
課程代碼：CS3025
授課教師：陳怡君
學分數：3
上課時間：每週二 13:20-16:20
學期起始：2025-09-09（共 6 週課程進度如下）

週次進度
第 1 週　9/9　課程介紹與人工智慧發展簡史
第 2 週　9/16　搜尋演算法：BFS、DFS、A*
第 3 週　9/23　知識表示與推理　（本週需繳交作業一）
第 4 週　9/30　期中考（暫定，日期另行公告）
第 5 週　10/7　機器學習概論
第 6 週　10/14　期末專題報告　專題報告書面資料於 10/14 23:59 前上傳

評分方式
作業　30%
期中考　25%
期末專題　35%
SYLLABUS;

$instruction = '你是課綱結構化助理。從使用者提供的課程大綱原文中抽取結構化資訊。'
    . '只根據原文內容作答，不要臆測；無法從原文判定的項目，'
    . '請放進 parse_meta.unresolved 並以簡短中文說明原因。'
    . 'confidence 請依該欄位在原文中的明確程度給 0 到 1 之間的值，'
    . 'source_span 請填寫該欄位所依據的原文片段。';

// ---------------------------------------------------------------------------
// DM-SYLLABUS 的 JSON Schema
//
// 對應 SRS §5 的 DM-SYLLABUS。這裡只放抽取階段模型該產出的部分——
// syllabus_id、course_ref、version、status 由系統指派而非模型抽取，
// 放進 schema 只會誘導模型憑空捏造識別碼。
//
// 每個 object 都設 additionalProperties=false 並列出完整 required：
// 這是原生結構化輸出能真正約束形狀的前提，少了它模型可以自由增減欄位。
// ---------------------------------------------------------------------------
$nullablestring = ['type' => ['string', 'null']];

$schema = [
    'type' => 'object',
    'additionalProperties' => false,
    'required' => ['course', 'term', 'grading', 'weeks', 'events', 'parse_meta'],
    'properties' => [
        'course' => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title', 'code', 'teacher', 'semester', 'credits'],
            'properties' => [
                'title' => ['type' => 'string'],
                'code' => $nullablestring,
                'teacher' => $nullablestring,
                'semester' => $nullablestring,
                'credits' => ['type' => ['number', 'null']],
            ],
        ],
        'term' => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['start_date', 'total_weeks', 'weekday'],
            'properties' => [
                'start_date' => ['type' => 'string'],
                'total_weeks' => ['type' => 'integer'],
                // 只給 enum、不併 type：API 端的 schema 檢查會拒絕
                // 「union type 加 enum」的組合，錯誤訊息是
                // "Enum value 'MON' does not match declared type ['string','null']"。
                // enum 本身已經把取值約束完整了，type 是多餘的。
                'weekday' => [
                    'enum' => ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN', null],
                ],
            ],
        ],
        'grading' => [
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
        ],
    ],
];

$schema['properties']['weeks'] = [
    'type' => 'array',
    'items' => [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['week_no', 'date', 'topic', 'note', 'confidence', 'source_span'],
        'properties' => [
            'week_no' => ['type' => 'integer'],
            'date' => $nullablestring,
            'topic' => ['type' => 'string'],
            'note' => ['type' => 'string'],
            'confidence' => ['type' => 'number'],
            'source_span' => ['type' => 'string'],
        ],
    ],
];

$schema['properties']['events'] = [
    'type' => 'array',
    'items' => [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['event_id', 'type', 'title', 'due_date', 'due_time', 'week_no', 'confidence', 'source_span'],
        'properties' => [
            'event_id' => ['type' => 'string'],
            'type' => ['type' => 'string', 'enum' => ['assignment', 'exam', 'project', 'other']],
            'title' => ['type' => 'string'],
            'due_date' => $nullablestring,
            'due_time' => $nullablestring,
            'week_no' => ['type' => ['integer', 'null']],
            'confidence' => ['type' => 'number'],
            'source_span' => ['type' => 'string'],
        ],
    ],
];

$schema['properties']['parse_meta'] = [
    'type' => 'object',
    'additionalProperties' => false,
    'required' => ['unresolved'],
    'properties' => [
        'unresolved' => ['type' => 'array', 'items' => ['type' => 'string']],
    ],
];

// ---------------------------------------------------------------------------
// 最小 JSON Schema 驗證器
//
// Moodle 沒有內建 JSON Schema 函式庫，而為了一個可行性實驗去引入 composer
// 相依不划算（外掛上架規範也不歡迎）。這裡只實作本 schema 真正用到的關鍵字：
// type、required、properties、additionalProperties、items、enum。
//
// 它**不是**通用驗證器，正式的驗證元件放在哪一層是 §9 要回答的問題之一，
// 現在刻意不預先決定，所以這段留在驗證腳本裡而不是任一個外掛裡。
// ---------------------------------------------------------------------------

/**
 * 依 schema 驗證資料，回傳所有違規路徑。
 *
 * @param mixed $data 待驗資料
 * @param array $schema JSON Schema
 * @param string $path 目前路徑，供錯誤訊息使用
 * @return string[] 違規描述，空陣列表示通過
 */
function uai_validate($data, array $schema, string $path = '$'): array {
    $errors = [];

    if (isset($schema['type'])) {
        $types = (array) $schema['type'];
        if (!uai_type_matches($data, $types)) {
            $actual = is_object($data) ? 'object' : gettype($data);
            $errors[] = $path . ' 型別應為 ' . implode('|', array_map(
                static fn($t) => $t === null ? 'null' : $t,
                $types
            )) . '，實際為 ' . $actual;

            return $errors;
        }
    }

    if (isset($schema['enum']) && !in_array($data, $schema['enum'], true)) {
        $errors[] = $path . ' 的值不在允許清單中：' . var_export($data, true);
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
                $errors = array_merge($errors, uai_validate($data[$key], $subschema, $path . '.' . $key));
            }
        }
    }

    if (is_array($data) && isset($schema['items'])) {
        foreach ($data as $index => $item) {
            $errors = array_merge($errors, uai_validate($item, $schema['items'], $path . '[' . $index . ']'));
        }
    }

    return $errors;
}

/**
 * 判斷值是否符合任一允許型別。
 *
 * @param mixed $data 值
 * @param array $types 允許的型別
 * @return bool
 */
function uai_type_matches($data, array $types): bool {
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

// ---------------------------------------------------------------------------
// 實驗 1：供應商能被 AI 子系統識別並呼叫
//
// 走的是完整的核心路徑 \core_ai\manager::process_action()，不是直接打 API。
// 這一項要證明的正是「core_ai 認得我們的供應商」，繞過子系統就沒有意義。
// ---------------------------------------------------------------------------
uai_section('實驗 1　供應商能被 AI 子系統識別並呼叫');

$manager = \core\di::get(\core_ai\manager::class);
$instances = $manager->get_provider_instances(['provider' => claudeprovider::class]);
cli_writeln('  已註冊的 Claude 供應商執行個體：' . count($instances) . ' 個');

$providers = $manager->get_providers_for_actions([generate_text::class], true);
$available = count($providers[generate_text::class] ?? []);
cli_writeln('  子系統認定可處理 generate_text 的供應商：' . $available . ' 個');

$action = new generate_text(
    contextid: \context_system::instance()->id,
    userid: get_admin()->id,
    prompttext: '用一句話說明什麼是課程大綱。請以繁體中文回答。',
);

$started = microtime(true);
$response = $manager->process_action($action);
$elapsed = (int) round((microtime(true) - $started) * 1000);

$data = $response->get_response_data();
if ($response->get_success()) {
    cli_writeln('  回應：' . trim((string) $data['generatedcontent']));
    cli_writeln('  模型：' . $data['model']
        . '　token：入 ' . $data['prompttokens'] . ' 出 ' . $data['completiontokens']
        . '　耗時 ' . $elapsed . ' ms');
} else {
    cli_writeln('  失敗：' . $response->get_errormessage());
}

uai_verdict(
    $response->get_success() && $available > 0 && trim((string) $data['generatedcontent']) !== '',
    '供應商能被子系統識別並呼叫（generate_text 取回一則回應）'
);

// ---------------------------------------------------------------------------
// 實驗 2 與 3：兩條抽取路徑
// ---------------------------------------------------------------------------
$claude = null;
foreach ($instances as $instance) {
    $claude = $instance;
    break;
}

if ($claude === null) {
    uai_verdict(false, '抽取介面能取得結構化輸出（找不到供應商執行個體）');
    uai_verdict(false, '通用實作的退路真的存在（找不到供應商執行個體）');
} else {
    $model = $claude->actionconfig[generate_text::class]['settings']['model'] ?? claudeprovider::DEFAULT_MODEL;
    $ex = new extractor($claude, $model);

    /**
     * 跑一組抽取實驗並統計結果。
     *
     * @param extractor $ex 抽取器
     * @param string $mode structured 或 prompted
     * @param int $runs 次數
     * @param string $syllabus 課綱原文
     * @param array $schema JSON Schema
     * @param string $instruction 系統指令
     * @return array 統計結果
     */
    function uai_run_extraction(
        extractor $ex,
        string $mode,
        int $runs,
        string $syllabus,
        array $schema,
        string $instruction
    ): array {
        $passed = 0;
        $jsonfailed = 0;
        $schemafailed = 0;
        $durations = [];
        $firsterrors = [];
        $sample = null;

        for ($i = 1; $i <= $runs; $i++) {
            $result = $mode === 'structured'
                ? $ex->extract_structured($syllabus, $schema, $instruction)
                : $ex->extract_prompted($syllabus, $schema, $instruction);

            $durations[] = $result['durationms'];
            $label = '  第 ' . $i . ' 次　' . $result['durationms'] . ' ms';

            if (!$result['success']) {
                $jsonfailed++;
                cli_writeln($label . '　請求失敗：' . $result['error']);
                continue;
            }
            if ($result['data'] === null) {
                $jsonfailed++;
                cli_writeln($label . '　回應不是合法 JSON（前 80 字：'
                    . core_text::substr(trim($result['raw']), 0, 80) . '）');
                continue;
            }

            $errors = uai_validate($result['data'], $schema);
            if ($errors === []) {
                $passed++;
                $sample ??= $result['data'];
                cli_writeln($label . '　通過　token 出 ' . $result['completiontokens']);
            } else {
                $schemafailed++;
                $firsterrors = $firsterrors ?: array_slice($errors, 0, 3);
                cli_writeln($label . '　schema 不符 ' . count($errors) . ' 處，例如：' . $errors[0]);
            }
        }

        return [
            'passed' => $passed,
            'jsonfailed' => $jsonfailed,
            'schemafailed' => $schemafailed,
            'avgms' => $durations ? (int) round(array_sum($durations) / count($durations)) : 0,
            'firsterrors' => $firsterrors,
            'sample' => $sample,
        ];
    }

    uai_section('實驗 2　抽取介面能取得結構化輸出（原生 json_schema）');
    cli_writeln('  模型 ' . $model . '　次數 ' . $runs);
    $structured = uai_run_extraction($ex, 'structured', $runs, $syllabus, $schema, $instruction);
    cli_writeln('');
    cli_writeln('  通過 ' . $structured['passed'] . ' / ' . $runs
        . '　非 JSON ' . $structured['jsonfailed']
        . '　schema 不符 ' . $structured['schemafailed']
        . '　平均 ' . $structured['avgms'] . ' ms');

    if ($structured['sample'] !== null) {
        $s = $structured['sample'];
        cli_writeln('  抽取內容抽樣：課名「' . ($s['course']['title'] ?? '?')
            . '」　教師「' . ($s['course']['teacher'] ?? '?')
            . '」　週次 ' . count($s['weeks'] ?? [])
            . '　事件 ' . count($s['events'] ?? [])
            . '　未決 ' . count($s['parse_meta']['unresolved'] ?? []));
        foreach ($s['parse_meta']['unresolved'] ?? [] as $item) {
            cli_writeln('    未決項目：' . $item);
        }
    }

    uai_verdict(
        $structured['passed'] === $runs,
        '抽取介面能取得結構化輸出（' . $runs . ' 次全部通過 schema 驗證）'
    );

    uai_section('實驗 3　通用實作的退路（僅以提示詞要求 JSON）');
    cli_writeln('  同一份課綱、同一個 schema，差別只在不使用 output_config。');
    $prompted = uai_run_extraction($ex, 'prompted', $runs, $syllabus, $schema, $instruction);
    cli_writeln('');
    cli_writeln('  通過 ' . $prompted['passed'] . ' / ' . $runs
        . '　非 JSON ' . $prompted['jsonfailed']
        . '　schema 不符 ' . $prompted['schemafailed']
        . '　平均 ' . $prompted['avgms'] . ' ms');
    foreach ($prompted['firsterrors'] as $error) {
        cli_writeln('    典型違規：' . $error);
    }

    // 這一項的通過條件刻意不是「100% 成功」——退路本來就會失敗，SRS 要問的
    // 是「這條路是否完全不可用」。只要有辦法產出可用結果，退路就成立；
    // 失敗率高只是代表需要重試，那是設計要處理的，不是可行性問題。
    uai_verdict(
        $prompted['passed'] > 0,
        '通用實作的退路存在（' . $prompted['passed'] . ' / ' . $runs . ' 次可用）'
    );
}

// ---------------------------------------------------------------------------
uai_section('結果');

if ($failures) {
    foreach ($failures as $failure) {
        cli_writeln('  ✘ ' . $failure);
    }
    cli_writeln('');
    cli_writeln(' 閘門未通過。依 SRS §8.4，此時不得撰寫 architecture.md 的 AI 章節，');
    cli_writeln(' 應回頭檢視 D-05 與 NFR-EXT-03 的前提是否仍成立。');
    cli_separator();
    exit(1);
}

cli_writeln(' 三項驗收條件全部通過，第一‧五階段的閘門解除。');
cli_writeln(' 下一步：依實測結果撰寫 docs/architecture.md §9。');
cli_separator();

exit(0);
