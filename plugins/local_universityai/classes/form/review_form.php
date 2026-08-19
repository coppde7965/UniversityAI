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

namespace local_universityai\form;

use local_universityai\confirmation_service;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * 課綱確認與逐項修正（FR-SYL-03、FR-SYL-04）。
 *
 * 表單的欄位由解析結果的長度決定：六週的課綱就是六組欄位。**不使用
 * `repeat_elements()`**——那是給「使用者可以自己增減筆數」的情境用的，
 * 而這裡的筆數由課綱決定，老師能改的是內容不是數量。用 repeat 會讓欄位名
 * 多一層索引，也讓 `confirmation_service` 的套用邏輯難寫。
 *
 * 欄位名一律由 `confirmation_service::field_name()` 產生。表單與套用邏輯
 * 若各自拼名稱，老師的修改會安靜地不生效——表單正常、送出成功、什麼都沒改，
 * 而且沒有任何錯誤訊息。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class review_form extends \moodleform {
    /**
     * 表單定義。
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $annotated = $this->_customdata['annotated'];

        $mform->addElement('hidden', 'id', $this->_customdata['syllabusid']);
        $mform->setType('id', PARAM_INT);

        $this->add_unresolved($annotated['unresolved']);

        $mform->addElement('header', 'weeksheader', get_string('review:weeks', 'local_universityai'));
        $mform->setExpanded('weeksheader', true);
        foreach ($annotated['weeks'] as $week) {
            $this->add_week($week);
        }

        $mform->addElement('header', 'eventsheader', get_string('review:events', 'local_universityai'));
        $mform->setExpanded('eventsheader', true);
        foreach ($annotated['events'] as $event) {
            $this->add_event($event);
        }

        $this->add_action_buttons(true, get_string('review:confirm', 'local_universityai'));
    }

    /**
     * `parse_meta.unresolved` 的項目。
     *
     * `FR-SYL-04` 要求它們「在介面上有明顯標示」。放在表單最上方而不是各項
     * 旁邊，是因為 unresolved 是**整份課綱層級**的說明（「期中考日期未定」），
     * 對不到特定的週次——硬對到某一項反而會誤導。
     *
     * @param string[] $unresolved 未決項目
     * @return void
     */
    private function add_unresolved(array $unresolved): void {
        if ($unresolved === []) {
            return;
        }

        $items = '';
        foreach ($unresolved as $item) {
            $items .= \html_writer::tag('li', s($item));
        }

        $this->_form->addElement('html', \html_writer::div(
            \html_writer::tag('h4', get_string('review:unresolved', 'local_universityai'), ['class' => 'h6'])
            . \html_writer::tag('ul', $items, ['class' => 'mb-0']),
            'alert alert-warning'
        ));
    }

    /**
     * 一個週次的欄位組。
     *
     * @param array $week 已標註的週次
     * @return void
     */
    private function add_week(array $week): void {
        $mform = $this->_form;
        $index = $week['index'];

        $mform->addElement('static', 'weekmeta_' . $index, '', $this->meta_html(
            get_string('summary:weeklabel', 'local_universityai', $week['week_no']),
            $week
        ));

        foreach (['date' => PARAM_RAW_TRIMMED, 'topic' => PARAM_TEXT, 'note' => PARAM_TEXT] as $field => $type) {
            $name = confirmation_service::field_name('week', $index, $field);
            $mform->addElement('text', $name, get_string('review:week' . $field, 'local_universityai'));
            $mform->setType($name, $type);
            $mform->setDefault($name, $week[$field]);
        }
    }

    /**
     * 一個事件的欄位組。
     *
     * `type` 不開放修改：它是列舉值，改錯會讓 schema 驗證失敗，而老師沒有
     * 理由需要把「考試」改成「作業」——真的抽錯類型的話，那是解析的問題，
     * 該重新上傳而不是在這裡改。
     *
     * @param array $event 已標註的事件
     * @return void
     */
    private function add_event(array $event): void {
        $mform = $this->_form;
        $index = $event['index'];

        $mform->addElement('static', 'eventmeta_' . $index, '', $this->meta_html(
            get_string('review:eventtype:' . $event['type'], 'local_universityai'),
            $event
        ));

        foreach (['title' => PARAM_TEXT, 'due_date' => PARAM_RAW_TRIMMED, 'due_time' => PARAM_RAW_TRIMMED] as $f => $t) {
            $name = confirmation_service::field_name('event', $index, $f);
            $mform->addElement('text', $name, get_string('review:event' . str_replace('_', '', $f), 'local_universityai'));
            $mform->setType($name, $t);
            $mform->setDefault($name, $event[$f]);
        }
    }

    /**
     * 一個項目的標題列：名稱、信心標示、原文出處。
     *
     * **信心值本身不顯示成數字**，只顯示「需要確認」的徽章。理由是實測看到
     * 的：模型即使在自己回報有編碼問題的情況下，仍然給出 1.00 的信心值
     * （architecture.md §8.3.3）。把那個數字直接呈現給老師，等於請他相信一個
     * 已知不可靠的指標。徽章只回答「系統覺得這一項可疑嗎」，那是它能承擔的
     * 全部語意。
     *
     * `source_span` 一律顯示：`FR-SYL-03` 要求標註來源，而老師判斷一項對不對
     * 最快的方式就是看原文怎麼寫。
     *
     * @param string $label 項目名稱
     * @param array $item 已標註的項目
     * @return string HTML
     */
    private function meta_html(string $label, array $item): string {
        $badges = '';

        if ($item['islowconfidence']) {
            $badges .= ' ' . \html_writer::span(
                get_string('review:needscheck', 'local_universityai'),
                'badge bg-warning text-dark'
            );
        }
        if ($item['isoverridden']) {
            $badges .= ' ' . \html_writer::span(
                get_string('review:overridden', 'local_universityai'),
                'badge bg-info text-dark'
            );
        }

        $source = $item['source_span'] !== ''
            ? \html_writer::div(
                get_string('review:sourcespan', 'local_universityai') . '：' . s($item['source_span']),
                'text-muted small'
            )
            : '';

        return \html_writer::tag('strong', s($label)) . $badges . $source;
    }
}
