<?php
// 第三階段的開場閘門：課綱 PDF 要直接送 API，還是先自行抽出文字？
//
// docs/architecture.md §9.9 的第 1 題，原文寫著「必須在第三階段開始前以測試集
// 實測兩條路的正確率差距再決定，不能憑感覺」。這支腳本就是那個實測。
//
// 三條路唯一的差別是送進模型的東西：
//   pdf      —— document 內容區塊，模型同時看得到文字與版面
//   text     —— 由 eval 的 pypdf 抽出的純文字，版面資訊已經消失
//   phptext  —— 由外掛自己的 text_extractor（PdfParser）抽出的純文字
// 其餘（模型、schema、系統指令、溫度預設）完全相同，否則量到的就不是版面。
//
// **phptext 這條是後來補的，補得有必要。** 第一版只比 pdf 與 text，而 text
// 用的是 Python 端的抽取結果——那不是產品實際會走的路。實測發現 PdfParser
// 對 TCPDF 產生的 CID-0 字型會漏掉部分 UTF-16BE 解碼，抽出的中文有一部分是
// 亂碼。用 pypdf 的文字下的結論，代表不了產品的行為。
//
// 評分只看**可客觀比對**的欄位：週次日期、週次主題、事件日期。FR-SYL-02 的
// 兩條驗收條件正是前兩者裡的日期部分。主題用正規化後的字串相等比對，不做
// 語意相似度——語意相似度需要另一個模型，那會讓量測結果依賴一個沒有被
// 驗證過的東西。
//
// 會實際呼叫 API 並產生費用。開發工具，不隨外掛發行。

define('CLI_SCRIPT', true);

require('/var/www/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');

use aiprovider_claude\extractor;
use aiprovider_claude\provider as claudeprovider;
use local_universityai\extraction\schema;
use local_universityai\extraction\text_extractor;
use local_universityai\extraction\validator;

const UAI_FIXTURE_FILE = '/opt/uai/eval/fixtures/syllabi.json';
const UAI_BUILD_DIR = '/opt/uai/eval/build';
const UAI_RESULT_FILE = '/opt/uai/eval/build/gate-result.json';

[$options] = cli_get_params([
    'runs' => 1,
    'only' => '',
    'arms' => 'pdf,text,phptext',
    'help' => false,
]);

if ($options['help']) {
    cli_writeln("第三階段閘門：PDF 直送 vs 先抽文字的正確率對照。會呼叫 API 並產生費用。

選項：
  --runs=N     每個樣本每條路徑跑幾次，預設 1
  --only=ID    只跑指定樣本
  --arms=a,b   要跑哪幾條路徑，預設 pdf,text
  -h, --help   顯示此說明
");
    exit(0);
}

$runs = max(1, (int) $options['runs']);
$arms = array_filter(array_map('trim', explode(',', $options['arms'])));

$definition = json_decode(file_get_contents(UAI_FIXTURE_FILE), true);
$fixtures = $definition['fixtures'] ?? [];
if (!$fixtures) {
    cli_error('讀不到樣本定義：' . UAI_FIXTURE_FILE);
}

$manager = \core\di::get(\core_ai\manager::class);
$instances = $manager->get_provider_instances(['provider' => claudeprovider::class]);
$claude = reset($instances);
if (!$claude) {
    cli_error('找不到 Claude 供應商執行個體。先執行 make install 並在 .env 中設定金鑰。');
}

$model = $claude->actionconfig[\core_ai\aiactions\generate_text::class]['settings']['model']
    ?? claudeprovider::DEFAULT_MODEL;
$ex = new extractor($claude, $model);
$schema = schema::extraction_schema();
$validator = new validator();

$instruction = '你是課綱結構化助理。從提供的課程大綱中抽取結構化資訊。'
    . '只根據內容作答，不要臆測；無法判定的項目請填 null，'
    . '並在 parse_meta.unresolved 中以簡短中文說明原因。'
    . 'confidence 依該欄位在原文中的明確程度給 0 到 1 之間的值，'
    . 'source_span 必須逐字引用原文片段，不得改寫。'
    . '日期一律輸出西元 YYYY-MM-DD 格式。';

/**
 * 正規化字串以便比對。
 *
 * 去除所有空白與常見的全形標點差異。刻意**不做**同義詞或語意處理——
 * 那需要另一個模型，會讓量測結果依賴一個沒有被驗證過的東西。
 *
 * @param string $value 原始字串
 * @return string
 */
function uai_norm(string $value): string {
    $value = str_replace(["\u{3000}", '：', '（', '）', '，'], [' ', ':', '(', ')', ','], $value);
    $value = preg_replace('/[[:space:]]+/u', '', $value);

    return \core_text::strtolower(trim((string) $value));
}

/**
 * 比對抽取結果與標準答案。
 *
 * 週次以 week_no 對位；事件以正規化後的標題對位，找不到同名事件就算漏抽。
 * 用標題而不是順序對位，是因為模型輸出的事件順序沒有理由與課綱一致，
 * 用順序對位會把「順序不同」誤記成「日期錯誤」。
 *
 * @param array $truth 樣本的標準答案
 * @param array $got 抽取結果
 * @return array 各項的命中數與總數
 */
function uai_score(array $truth, array $got): array {
    $score = [
        'weekdate' => [0, count($truth['weeks'])],
        'weektopic' => [0, count($truth['weeks'])],
        'eventdate' => [0, count($truth['events'])],
        'weekcount' => [count($got['weeks'] ?? []) === count($truth['weeks']) ? 1 : 0, 1],
    ];

    $byweek = [];
    foreach ($got['weeks'] ?? [] as $week) {
        $byweek[(int) ($week['week_no'] ?? 0)] = $week;
    }

    foreach ($truth['weeks'] as $expected) {
        $actual = $byweek[(int) $expected['week_no']] ?? null;
        if ($actual === null) {
            continue;
        }
        if ((string) ($actual['date'] ?? '') === $expected['date']) {
            $score['weekdate'][0]++;
        }
        if (uai_norm((string) ($actual['topic'] ?? '')) === uai_norm($expected['topic'])) {
            $score['weektopic'][0]++;
        }
    }

    foreach ($truth['events'] as $expected) {
        foreach ($got['events'] ?? [] as $actual) {
            if (uai_norm((string) ($actual['title'] ?? '')) !== uai_norm($expected['title'])) {
                continue;
            }
            if ((string) ($actual['due_date'] ?? '') === $expected['due_date']) {
                $score['eventdate'][0]++;
            }
            break;
        }
    }

    return $score;
}

/**
 * 把命中數格式化成百分比。
 *
 * @param array $pair 命中數與總數
 * @return string
 */
function uai_pct(array $pair): string {
    [$hit, $total] = $pair;

    return $total === 0 ? '  n/a' : sprintf('%3d%%', (int) round(100 * $hit / $total));
}

cli_separator();
cli_writeln(' 第三階段閘門　課綱 PDF 直送 vs 先抽文字');
cli_writeln(' 模型 ' . $model . '　樣本 ' . count($fixtures) . ' 份　每項 ' . $runs . ' 次');
cli_separator();

$totals = [];
$records = [];

foreach ($fixtures as $fixture) {
    if ($options['only'] !== '' && $fixture['id'] !== $options['only']) {
        continue;
    }

    $pdfpath = UAI_BUILD_DIR . '/' . $fixture['id'] . '.pdf';
    $textpath = UAI_BUILD_DIR . '/' . $fixture['id'] . '.txt';

    cli_writeln('');
    cli_writeln('── ' . $fixture['id'] . '　' . $fixture['trap']);

    foreach ($arms as $arm) {
        if (!is_readable($arm === 'text' ? $textpath : $pdfpath)) {
            cli_writeln('  ' . $arm . '：找不到來源檔，略過。');
            continue;
        }

        // phptext 走的是產品實際會走的那條路：外掛自己的 text_extractor。
        // 抽取失敗（無文字層、格式不支援）在這裡是一個結果，不是腳本的錯誤。
        $phptext = '';
        if ($arm === 'phptext') {
            try {
                $phptext = (new text_extractor())
                    ->extract(file_get_contents($pdfpath), $fixture['id'] . '.pdf');
            } catch (\moodle_exception $e) {
                cli_writeln('  phptext：本地抽取失敗，' . $e->getMessage());
                continue;
            }
        }

        for ($run = 1; $run <= $runs; $run++) {
            $result = match ($arm) {
                'pdf' => $ex->extract_structured_from_pdf(
                    file_get_contents($pdfpath),
                    $schema,
                    $instruction
                ),
                'phptext' => $ex->extract_structured($phptext, $schema, $instruction),
                default => $ex->extract_structured(file_get_contents($textpath), $schema, $instruction),
            };

            if (!$result['success'] || $result['data'] === null) {
                cli_writeln(sprintf('  %-7s 第 %d 次　請求失敗：%s', $arm, $run, $result['error']));
                $totals[$arm]['fail'] = ($totals[$arm]['fail'] ?? 0) + 1;
                continue;
            }

            // 驗證用的原文只有兩條文字路徑拿得到，而且各自要用自己那一份
            // ——拿 pypdf 的文字去驗 phptext 的抽取結果會把兩者的差異誤記成
            // 幻覺。PDF 路徑沒有本地文字，source_span 因此驗不了：這件事
            // 本身是一個結果，不是量測的瑕疵。
            $sourcetext = match ($arm) {
                'text' => file_get_contents($textpath),
                'phptext' => $phptext,
                default => '',
            };
            $verdict = $validator->validate($result['data'], $sourcetext);
            $score = uai_score($fixture, $result['data']);

            foreach ($score as $key => $pair) {
                $totals[$arm][$key][0] = ($totals[$arm][$key][0] ?? 0) + $pair[0];
                $totals[$arm][$key][1] = ($totals[$arm][$key][1] ?? 0) + $pair[1];
            }
            $totals[$arm]['ms'][] = $result['durationms'];
            $totals[$arm]['in'][] = (int) $result['prompttokens'];
            $totals[$arm]['out'][] = (int) $result['completiontokens'];
            $totals[$arm]['schemaerrors'] = ($totals[$arm]['schemaerrors'] ?? 0) + count($verdict['errors']);

            cli_writeln(sprintf(
                '  %-7s 第 %d 次　週次日期 %s　週次主題 %s　事件日期 %s　週數 %s　%5d ms　入 %5d 出 %4d　驗證 %s',
                $arm,
                $run,
                uai_pct($score['weekdate']),
                uai_pct($score['weektopic']),
                uai_pct($score['eventdate']),
                $score['weekcount'][0] ? '對' : '錯',
                $result['durationms'],
                (int) $result['prompttokens'],
                (int) $result['completiontokens'],
                $verdict['errors'] === [] ? '通過' : (count($verdict['errors']) . ' 項不符')
            ));

            foreach (array_slice($verdict['errors'], 0, 2) as $error) {
                cli_writeln('        違規：' . $error);
            }
            foreach (array_slice($verdict['notes'], 0, 2) as $note) {
                cli_writeln('        已回報：' . $note);
            }

            $records[] = [
                'fixture' => $fixture['id'],
                'arm' => $arm,
                'run' => $run,
                'score' => $score,
                'errors' => $verdict['errors'],
                'notes' => $verdict['notes'],
                'durationms' => $result['durationms'],
                'prompttokens' => $result['prompttokens'],
                'completiontokens' => $result['completiontokens'],
            ];
        }
    }
}

cli_writeln('');
cli_separator();
cli_writeln(' 合計');
cli_separator();
cli_writeln(sprintf(
    '  %-7s %-10s %-10s %-10s %-8s %-9s %-9s %s',
    '路徑',
    '週次日期',
    '週次主題',
    '事件日期',
    '週數',
    '平均耗時',
    '平均入',
    '平均出'
));

foreach ($totals as $arm => $t) {
    $avg = static fn(array $v): int => $v ? (int) round(array_sum($v) / count($v)) : 0;
    cli_writeln(sprintf(
        '  %-7s %-10s %-10s %-10s %-8s %-9s %-9s %s',
        $arm,
        uai_pct($t['weekdate'] ?? [0, 0]),
        uai_pct($t['weektopic'] ?? [0, 0]),
        uai_pct($t['eventdate'] ?? [0, 0]),
        uai_pct($t['weekcount'] ?? [0, 0]),
        $avg($t['ms'] ?? []) . ' ms',
        $avg($t['in'] ?? []),
        $avg($t['out'] ?? [])
    ));
}

file_put_contents(
    UAI_RESULT_FILE,
    json_encode([
        'model' => $model,
        'runs' => $runs,
        'generatedat' => date(DATE_ATOM),
        'records' => $records,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

cli_writeln('');
cli_writeln(' 逐次結果已寫入 ' . UAI_RESULT_FILE . '（不進版控）。');
cli_writeln(' 結論與決定請寫進 docs/architecture.md，不要只留在這支腳本的輸出裡。');
cli_separator();

exit(0);
