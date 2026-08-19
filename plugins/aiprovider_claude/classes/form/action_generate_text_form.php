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

namespace aiprovider_claude\form;

use aiprovider_claude\provider;
use core_ai\form\action_settings_form;

/**
 * 單一動作的設定表單。
 *
 * 刻意做成純文字欄位，不做核心供應商那套 model chooser（下拉選單 + AMD
 * 模組 + 每個模型一個類別 + 自訂參數 JSON）。理由是模型清單變動很快，
 * 維護一份會過期的硬編碼清單不划算；而本專案的模型只有站台管理員會改，
 * 直接填 id 反而不會被清單綁住。
 *
 * @package    aiprovider_claude
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action_generate_text_form extends action_settings_form {
    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;

        $actionconfig = $this->_customdata['actionconfig']['settings'] ?? [];
        $actionname = $this->_customdata['actionname'];
        $action = $this->_customdata['action'];

        $mform->addElement('header', 'generalsettingsheader', get_string('general', 'core'));

        $mform->addElement('text', 'model', get_string('model', 'aiprovider_claude'), ['size' => 40]);
        $mform->setType('model', PARAM_TEXT);
        $mform->setDefault('model', $actionconfig['model'] ?? provider::DEFAULT_MODEL);
        $mform->addRule('model', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('model', 'model', 'aiprovider_claude');

        $mform->addElement('text', 'max_tokens', get_string('maxtokens', 'aiprovider_claude'), ['size' => 10]);
        $mform->setType('max_tokens', PARAM_INT);
        $mform->setDefault('max_tokens', $actionconfig['max_tokens'] ?? 4096);
        $mform->addHelpButton('max_tokens', 'maxtokens', 'aiprovider_claude');

        $mform->addElement(
            'textarea',
            'systeminstruction',
            get_string('systeminstruction', 'aiprovider_claude'),
            'wrap="virtual" rows="5" cols="60"',
        );
        $mform->setType('systeminstruction', PARAM_TEXT);
        $mform->setDefault('systeminstruction', $actionconfig['systeminstruction'] ?? $action::get_system_instruction());

        if (!empty($this->_customdata['returnurl'])) {
            $mform->addElement('hidden', 'returnurl', $this->_customdata['returnurl']);
            $mform->setType('returnurl', PARAM_LOCALURL);
        }

        $mform->addElement('hidden', 'action', $action);
        $mform->setType('action', PARAM_TEXT);

        $mform->addElement('hidden', 'provider', $this->_customdata['providername']);
        $mform->setType('provider', PARAM_TEXT);

        $mform->addElement('hidden', 'providerid', $this->_customdata['providerid'] ?? 0);
        $mform->setType('providerid', PARAM_INT);

        unset($actionname);

        $this->set_data($actionconfig);
    }
}
