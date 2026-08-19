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

namespace local_universityai;

/**
 * 通知的送出點。
 *
 * 一切通知都走 Moodle 既有的訊息機制（db/messages.php），不自建寄信程式碼。
 *
 * **通知內容一律由語言字串與結構化參數組成，不接受自由文字。** 這不只是
 * 為了 NFR-MNT-04 的多語系，更是為了 NFR-SEC-06：失敗通知最自然的寫法是
 * 把例外訊息塞進信裡，而例外訊息經常夾帶請求內容——課綱原文、老師的
 * 聯絡方式都可能在裡面。通知只說「哪個作業對哪個對象失敗了」，細節留在
 * local_universityai_ops，由管理者到站台內查看。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notifier {
    /**
     * 通知全體站台管理員某項作業已失敗且不再重試（NFR-REL-02）。
     *
     * 對象取自 get_admins() 而非能力查詢。architecture.md §5.2 的最後一列
     * 說明了理由：任務跑在無使用者的脈絡下，has_capability() 會以 admin
     * 身分求值而全部通過，用它篩選對象等於沒篩選。
     *
     * @param string $operation 見 \local_universityai\ops::operations()
     * @param string $targettype 見 \local_universityai\ops::targettypes()
     * @param string $targetid 對象識別
     * @param int $attempt 已嘗試次數
     * @return int 實際送出的封數
     */
    public static function notify_admins_of_failure(
        string $operation,
        string $targettype,
        string $targetid,
        int $attempt
    ): int {

        $a = (object) [
            'operation' => get_string('operation:' . $operation, 'local_universityai'),
            'targettype' => get_string('targettype:' . $targettype, 'local_universityai'),
            'targetid' => $targetid,
            'attempt' => $attempt,
        ];

        $subject = get_string('notification:opsfailure:subject', 'local_universityai', $a);
        $body = get_string('notification:opsfailure:body', 'local_universityai', $a);

        $sent = 0;
        foreach (get_admins() as $admin) {
            $message = new \core\message\message();
            $message->component = 'local_universityai';
            $message->name = 'opsfailure';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $admin;
            $message->subject = $subject;
            $message->fullmessage = $body;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = $subject;
            $message->notification = 1;

            // courseid 是必填。省略不會失敗，但會在開發模式下噴 debugging 警告。
            // 這類作業不屬於任何一門課，所以是站台。
            $message->courseid = SITEID;

            // 刻意不設 contexturl：營運面板（FR-DSH-02）於第三階段才建立，
            // 現在指過去只會得到 404。面板做好後在此補上。

            if (message_send($message)) {
                $sent++;
            }
        }

        return $sent;
    }
}
