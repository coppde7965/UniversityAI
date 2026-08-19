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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * \local_universityai\notifier 的單元測試（bdd-guide L1）。
 *
 * @package    local_universityai
 * @copyright  2026 UniversityAI 專題團隊
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(notifier::class)]
final class notifier_test extends \advanced_testcase {
    /**
     * 失敗通知會送到每一位站台管理員，而且內容是解析過的字串。
     */
    public function test_notify_admins_of_failure_reaches_every_admin(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();

        $admincount = count(get_admins());
        $this->assertGreaterThan(0, $admincount);

        $sink = $this->redirectMessages();
        $sent = notifier::notify_admins_of_failure(
            ops::OP_SYLLABUS_PARSE,
            ops::TARGET_SYLLABUS,
            '7',
            3
        );
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertSame($admincount, $sent);
        $this->assertCount($admincount, $messages);

        $message = reset($messages);
        $this->assertSame('local_universityai', $message->component);
        $this->assertSame('opsfailure', $message->eventtype);
        $this->assertEquals(1, $message->notification);

        // 字串沒解析出來時 Moodle 會印 [[key]]，不會拋錯——這是通知這種
        // 少走到的路徑上最容易漏掉的缺陷。
        $this->assertStringNotContainsString('[[', $message->subject);
        $this->assertStringNotContainsString('[[', $message->fullmessage);

        // 參數確實有代進去。
        $this->assertStringContainsString('7', $message->fullmessage);
        $this->assertStringContainsString('3', $message->fullmessage);
    }

    /**
     * 通知內容不得夾帶自由文字。
     *
     * notifier 的介面刻意不接受錯誤訊息字串（NFR-SEC-06）：例外訊息常把
     * 整段請求內容帶進來，其中可能有課綱原文與老師的聯絡方式。這條測試
     * 是那個決定的守門員——若有人日後加了一個 $error 參數，簽章一變就
     * 會編譯不過，得回來重新想清楚。
     */
    public function test_notify_admins_of_failure_takes_no_free_text(): void {
        $method = new \ReflectionMethod(notifier::class, 'notify_admins_of_failure');

        $names = [];
        foreach ($method->getParameters() as $parameter) {
            $names[] = $parameter->getName();
        }

        $this->assertSame(
            ['operation', 'targettype', 'targetid', 'attempt'],
            $names,
            'notifier 的簽章改了。加入自由文字參數前請先接上 §6 的外送過濾。'
        );
    }
}
