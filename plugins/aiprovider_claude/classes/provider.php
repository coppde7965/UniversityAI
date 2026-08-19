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

use core_ai\form\action_settings_form;
use Psr\Http\Message\RequestInterface;

/**
 * Claude 供應商。
 *
 * @package    aiprovider_claude
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider extends \core_ai\provider {
    /** @var string Anthropic Messages API 的預設端點。 */
    public const DEFAULT_ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /** @var string 預設模型。用別名而非帶日期的完整 id，隨上游滾動。 */
    public const DEFAULT_MODEL = 'claude-haiku-4-5';

    /**
     * API 版本標頭。
     *
     * 這是 Anthropic 的 API 契約版本，不是模型版本，兩者無關。
     * 每個請求都必須帶，缺了會得到 400。
     *
     * @var string
     */
    public const API_VERSION = '2023-06-01';

    #[\Override]
    public static function get_action_list(): array {
        // 只宣告本專案真的會用到的兩個。explain_text 與 generate_image
        // 刻意不支援：宣告了就得維護，而 SRS 沒有任何需求用到它們。
        return [
            \core_ai\aiactions\generate_text::class,
            \core_ai\aiactions\summarise_text::class,
        ];
    }

    #[\Override]
    public static function get_action_settings(
        string $action,
        array $customdata = [],
    ): action_settings_form|bool {
        $actionname = $action::get_basename();
        $customdata['actionname'] = $actionname;
        $customdata['action'] = $action;
        $customdata['providername'] = 'aiprovider_claude';

        if ($actionname === 'generate_text' || $actionname === 'summarise_text') {
            return new form\action_generate_text_form(customdata: $customdata);
        }

        return false;
    }

    #[\Override]
    public static function get_action_setting_defaults(string $action): array {
        $mform = self::get_action_settings($action, []);
        if ($mform === false) {
            return [];
        }

        return $mform->get_defaults();
    }

    #[\Override]
    public function add_authentication_headers(RequestInterface $request): RequestInterface {
        // Anthropic 用 x-api-key，不是 Authorization: Bearer——照著 OpenAI
        // 的樣子寫會得到 401，而錯誤訊息不會告訴你標頭名稱錯了。
        return $request
            ->withAddedHeader('x-api-key', $this->config['apikey'] ?? '')
            ->withAddedHeader('anthropic-version', self::API_VERSION);
    }

    #[\Override]
    public function is_provider_configured(): bool {
        return !empty($this->config['apikey']);
    }

    /**
     * 取得端點，未設定時用預設值。
     *
     * @return string
     */
    public function get_endpoint(): string {
        return !empty($this->config['endpoint']) ? $this->config['endpoint'] : self::DEFAULT_ENDPOINT;
    }
}
