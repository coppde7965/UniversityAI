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

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * 填入課程前的差異確認（FR-CRS-01）。
 *
 * 這個表單存在的唯一理由是那條驗收條件：「課程既有欄位若已有內容且與課綱
 * 不同，須先向老師呈現將被覆寫的項目，**不得靜默蓋掉**」。
 *
 * 所以它沒有任何可輸入的欄位——老師唯一的決定是「套用」或「取消」。
 * 把差異做成可編輯的表單反而糟：那等於讓老師在這裡改課綱，而課綱的修改
 * 有自己的地方（`review.php`），兩處都能改會讓「哪一份才算數」變得不清楚。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class populate_form extends \moodleform {
    /**
     * 表單定義。
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $preview = $this->_customdata['preview'];

        $mform->addElement('hidden', 'id', $this->_customdata['syllabusid']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('html', $this->diff_table($preview['fields']));

        $mform->addElement(
            'static',
            'sections',
            get_string('populate:sections', 'local_universityai'),
            get_string('populate:sectionscount', 'local_universityai', $preview['weeks'])
        );
        $mform->addElement(
            'static',
            'events',
            get_string('populate:events', 'local_universityai'),
            get_string('populate:eventscount', 'local_universityai', $preview['events'])
        );

        $this->add_action_buttons(true, get_string('populate:apply', 'local_universityai'));
    }

    /**
     * 現值與課綱值的對照表。
     *
     * **會被覆寫的列要標出來。** 空欄位被填上不算覆寫——那是這個功能存在的
     * 目的；真正需要老師點頭的是「這一欄原本有東西，而且和課綱不一樣」。
     * 兩者用同一種樣式呈現的話，老師就得自己去比對每一列，那等於沒有呈現。
     *
     * @param array $fields 欄位差異
     * @return string HTML
     */
    private function diff_table(array $fields): string {
        $rows = '';

        foreach ($fields as $field) {
            $badge = '';
            $class = '';

            if ($field['overwrites']) {
                $badge = ' ' . \html_writer::span(
                    get_string('populate:willoverwrite', 'local_universityai'),
                    'badge bg-warning text-dark'
                );
                $class = 'table-warning';
            } else if ($field['unchanged']) {
                $badge = ' ' . \html_writer::span(
                    get_string('populate:unchanged', 'local_universityai'),
                    'badge bg-light text-dark'
                );
            }

            $rows .= \html_writer::tag(
                'tr',
                \html_writer::tag('th', s($field['label']) . $badge, ['scope' => 'row'])
                . \html_writer::tag('td', $field['current'] !== ''
                    ? s($field['current'])
                    : \html_writer::span(get_string('populate:empty', 'local_universityai'), 'text-muted'))
                . \html_writer::tag('td', s($field['proposed'])),
                ['class' => $class]
            );
        }

        $head = \html_writer::tag(
            'tr',
            \html_writer::tag('th', get_string('populate:colfield', 'local_universityai'), ['scope' => 'col'])
            . \html_writer::tag('th', get_string('populate:colcurrent', 'local_universityai'), ['scope' => 'col'])
            . \html_writer::tag('th', get_string('populate:colproposed', 'local_universityai'), ['scope' => 'col'])
        );

        return \html_writer::div(
            \html_writer::tag(
                'table',
                \html_writer::tag('caption', get_string('populate:difftitle', 'local_universityai'))
                . \html_writer::tag('thead', $head)
                . \html_writer::tag('tbody', $rows),
                ['class' => 'table table-sm']
            ),
            'table-responsive'
        );
    }
}
