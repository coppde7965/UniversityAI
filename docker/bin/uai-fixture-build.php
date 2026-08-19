<?php
// 由 eval/fixtures/syllabi.json 產生課綱 PDF。
//
// 結構化資料是唯一的事實來源：PDF 從它渲染而來，標準答案也是它。手寫兩份
// 一定會漂移，而漂移的那一天，量到的正確率會變成在量標準答案的錯誤。
//
// 產出的 PDF 不進版控（eval/build/ 已列入 .gitignore）——它是可重新產生的
// 衍生物，把二進位檔放進 repo 只會讓 diff 變得無法閱讀。
//
// 為什麼用 TCPDF：它是這個環境裡唯一排得出中文 PDF 的工具，且 Moodle 核心
// 已經帶著它（public/lib/pdflib.php）。msungstdlight 是 Adobe 的繁體中文
// CID 字型，TCPDF 內建，產出的 PDF 帶得出可抽取的文字層——這件事實測過，
// 沒有它整個對照實驗就不成立。
//
// 開發工具，不隨外掛發行。

define('CLI_SCRIPT', true);

require('/var/www/moodle/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/pdflib.php');

const UAI_FIXTURE_FILE = '/opt/uai/eval/fixtures/syllabi.json';
const UAI_BUILD_DIR = '/opt/uai/eval/build';

[$options] = cli_get_params([
    'only' => '',
    'help' => false,
]);

if ($options['help']) {
    cli_writeln("由固定樣本定義產生課綱 PDF。

選項：
  --only=ID   只產生指定的樣本
  -h, --help  顯示此說明
");
    exit(0);
}

$definition = json_decode(file_get_contents(UAI_FIXTURE_FILE), true);
if (!is_array($definition['fixtures'] ?? null)) {
    cli_error('讀不到樣本定義：' . UAI_FIXTURE_FILE);
}

if (!is_dir(UAI_BUILD_DIR)) {
    mkdir(UAI_BUILD_DIR, 0777, true);
}

/**
 * 表頭：課程基本資料與評分方式。
 *
 * 四種版面共用同一段開頭，差別只在週次表怎麼排——這樣量到的差異才是版面
 * 造成的，不是因為某一份剛好少寫了教師姓名。
 *
 * @param array $fixture 樣本定義
 * @return string HTML
 */
function uai_render_header(array $fixture): string {
    $course = $fixture['course'];
    $term = $fixture['term'];

    $html = '<h2>國立範例大學　' . s($course['semester']) . ' 學期課程大綱</h2>'
        . '<p>課程名稱：' . s($course['title']) . '　　課程代碼：' . s($course['code']) . '</p>'
        . '<p>授課教師：' . s($course['teacher']) . '　　聯絡信箱：' . s($course['teacher_email']) . '</p>'
        . '<p>學分數：' . (int) $course['credits']
        . '　　學期起始：' . s($term['start_date'])
        . '　　共 ' . (int) $term['total_weeks'] . ' 週</p>';

    $html .= '<h3>評分方式</h3><table border="1" cellpadding="3"><tr><th>項目</th><th>比例</th></tr>';
    foreach ($fixture['grading'] as $item) {
        $html .= '<tr><td>' . s($item['item']) . '</td><td>' . (int) $item['weight'] . '%</td></tr>';
    }
    $html .= '</table>';

    return $html;
}

/**
 * 標準表格版面。
 *
 * @param array $weeks 週次
 * @return string HTML
 */
function uai_render_table(array $weeks): string {
    $html = '<h3>課程進度</h3><table border="1" cellpadding="3">'
        . '<tr><th>週次</th><th>日期</th><th>主題</th><th>備註</th></tr>';
    foreach ($weeks as $week) {
        $html .= '<tr><td>' . (int) $week['week_no'] . '</td>'
            . '<td>' . s($week['date']) . '</td>'
            . '<td>' . s($week['topic']) . '</td>'
            . '<td>' . s($week['note']) . '</td></tr>';
    }

    return $html . '</table>';
}

/**
 * 備註欄大量留白的表格。
 *
 * 與 table 版的差別只在 note 多半是空的，以及有一列是停課。刻意不改資料，
 * 由樣本自己帶——渲染器不該偷偷竄改事實來源。
 *
 * @param array $weeks 週次
 * @return string HTML
 */
function uai_render_sparse_table(array $weeks): string {
    return uai_render_table($weeks);
}

/**
 * 兩欄版面：左欄前半、右欄後半。
 *
 * 這是版面資訊消失最典型的失敗場景——抽成文字時多數工具會逐列橫向讀取，
 * 於是第 1 週後面接的是第 4 週。用巢狀表格模擬雙欄，因為 TCPDF 的
 * writeHTML 不支援 CSS 多欄。
 *
 * @param array $weeks 週次
 * @return string HTML
 */
function uai_render_two_column(array $weeks): string {
    $half = (int) ceil(count($weeks) / 2);
    $left = array_slice($weeks, 0, $half);
    $right = array_slice($weeks, $half);

    $cell = function (array $subset): string {
        $out = '';
        foreach ($subset as $week) {
            $out .= '<p>第 ' . (int) $week['week_no'] . ' 週　' . s($week['date']) . '<br />'
                . s($week['topic'])
                . ($week['note'] !== '' ? '<br />（' . s($week['note']) . '）' : '')
                . '</p>';
        }

        return $out;
    };

    return '<h3>課程進度</h3><table border="0" cellpadding="6"><tr>'
        . '<td width="48%">' . $cell($left) . '</td>'
        . '<td width="48%">' . $cell($right) . '</td>'
        . '</tr></table>';
}

/**
 * 純條列版面。對照組，沒有版面資訊。
 *
 * @param array $weeks 週次
 * @return string HTML
 */
function uai_render_plain_list(array $weeks): string {
    $html = '<h3>課程進度</h3>';
    foreach ($weeks as $week) {
        $html .= '<p>第 ' . (int) $week['week_no'] . ' 週　' . s($week['date']) . '　' . s($week['topic'])
            . ($week['note'] !== '' ? '　（' . s($week['note']) . '）' : '')
            . '</p>';
    }

    return $html;
}

/**
 * 課程事件的敘述段落。
 *
 * 事件刻意以散文形式寫在週次表之外，而不是從備註欄推導：真實課綱就是這樣，
 * 而且這讓「事件日期抽取正確率」量到的是理解能力，不是抄表格的能力。
 *
 * @param array $events 事件
 * @return string HTML
 */
function uai_render_events(array $events): string {
    $html = '<h3>作業與評量說明</h3>';
    foreach ($events as $event) {
        $when = $event['due_date'] . ($event['due_time'] !== null ? ' ' . $event['due_time'] : '');
        $html .= '<p>' . s($event['title']) . '：請於 ' . s($when) . ' 前完成。</p>';
    }

    return $html;
}

$built = 0;

foreach ($definition['fixtures'] as $fixture) {
    if ($options['only'] !== '' && $fixture['id'] !== $options['only']) {
        continue;
    }

    $weeks = $fixture['weeks'];
    $reversed = $fixture['layout'] === 'two_column_reversed';

    $body = $reversed ? '' : match ($fixture['layout']) {
        'table' => uai_render_table($weeks),
        'sparse_table' => uai_render_sparse_table($weeks),
        'two_column' => uai_render_two_column($weeks),
        'plain_list' => uai_render_plain_list($weeks),
        default => cli_error('未知的版面：' . $fixture['layout']),
    };

    $pdf = new pdf();
    $pdf->SetCreator('UniversityAI fixture builder');
    $pdf->SetTitle($fixture['course']['title']);
    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('msungstdlight', '', 11);
    $pdf->writeHTML(uai_render_header($fixture) . $body, true, false, true, false, '');

    if ($reversed) {
        // 兩欄，但**右欄先寫入內容流**。
        //
        // 這是本組樣本裡唯一真正會讓抽出的文字順序與閱讀順序不一致的版面。
        // f2 用巢狀表格模擬雙欄，實測抽出來仍是第 1 到 6 週的正確順序——
        // TCPDF 依邏輯順序輸出儲存格，所以那份樣本沒有製造出預期的陷阱。
        // 用 writeHTMLCell 絕對定位、刻意先右後左，才問得到「版面資訊消失時
        // 會發生什麼」這個問題。真實世界的雙欄 PDF 確實常是這樣。
        $half = (int) ceil(count($weeks) / 2);
        $columns = [array_slice($weeks, $half), array_slice($weeks, 0, $half)];
        $x = [110, 15];
        $y = $pdf->GetY() + 4;

        foreach ($columns as $i => $subset) {
            $html = '';
            foreach ($subset as $week) {
                $html .= '<p>第 ' . (int) $week['week_no'] . ' 週　' . s($week['date']) . '<br />'
                    . s($week['topic'])
                    . ($week['note'] !== '' ? '<br />（' . s($week['note']) . '）' : '')
                    . '</p>';
            }
            $pdf->writeHTMLCell(85, 0, $x[$i], $y, $html, 0, 0, false, true, '', true);
        }

        $pdf->SetY($y + 60);
    }

    $pdf->writeHTML(uai_render_events($fixture['events']), true, false, true, false, '');

    $path = UAI_BUILD_DIR . '/' . $fixture['id'] . '.pdf';
    $pdf->Output($path, 'F');

    cli_writeln(sprintf(
        '  %-18s %-13s %6d bytes　%s',
        $fixture['id'],
        $fixture['layout'],
        filesize($path),
        $fixture['trap']
    ));
    $built++;
}

cli_writeln('');
cli_writeln('已產生 ' . $built . ' 份樣本於 ' . UAI_BUILD_DIR . '（不進版控）。');

exit(0);
