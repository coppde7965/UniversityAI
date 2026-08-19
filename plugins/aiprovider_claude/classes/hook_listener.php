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

namespace aiprovider_claude;

use core_ai\hook\after_ai_provider_form_hook;

/**
 * 供應商執行個體設定表單的欄位。
 *
 * @package    aiprovider_claude
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_listener {
    /**
     * 在建立／編輯供應商執行個體的表單上加入本外掛的欄位。
     *
     * @param after_ai_provider_form_hook $hook 由 core_ai 派發的掛鉤
     */
    public static function set_form_definition(after_ai_provider_form_hook $hook): void {
        if ($hook->plugin !== 'aiprovider_claude') {
            return;
        }

        $mform = $hook->mform;

        // passwordunmask 而非 text：金鑰在畫面上必須遮蔽（NFR-SEC-05）。
        $mform->addElement(
            'passwordunmask',
            'apikey',
            get_string('apikey', 'aiprovider_claude'),
            ['size' => 75],
        );
        $mform->addHelpButton('apikey', 'apikey', 'aiprovider_claude');
        $mform->addRule('apikey', get_string('required'), 'required', null, 'client');

        // 端點可覆寫，主要用途是把請求導到測試樁（bdd-guide §9.1）。
        $mform->addElement(
            'text',
            'endpoint',
            get_string('endpoint', 'aiprovider_claude'),
            ['size' => 75],
        );
        $mform->setType('endpoint', PARAM_URL);
        $mform->setDefault('endpoint', provider::DEFAULT_ENDPOINT);
        $mform->addHelpButton('endpoint', 'endpoint', 'aiprovider_claude');
    }
}
