<?php
// wcma-calculator/tests/UnsubscribeSourceTest.php — unsubscribe.php needs a request, so these are source-level checks.
use PHPUnit\Framework\TestCase;

final class UnsubscribeSourceTest extends TestCase
{
    private function src(): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../unsubscribe.php'));
    }

    public function testOnlyAPostWithAValidTokenTurnsRemindersOff(): void
    {
        $src = $this->src();
        $valid = strpos($src, '$valid = reminderTokenValid($uid, $token, reminderSecret($pdo)) && db_find_user_by_id($pdo, $uid) !== null;');
        $post = strpos($src, "if (\$valid && \$_SERVER['REQUEST_METHOD'] === 'POST') {\n    db_set_user_reminders(\$pdo, \$uid, false);");
        $this->assertNotFalse($valid);
        $this->assertNotFalse($post);
        $this->assertLessThan($post, $valid);
        $this->assertSame(1, substr_count($src, 'db_set_user_reminders('));
    }

    public function testInvalidLinksGetA400AndTheIdMustBeDigits(): void
    {
        $src = $this->src();
        $this->assertStringContainsString('http_response_code(400);', $src);
        $this->assertStringContainsString("\$uid = ctype_digit(\$param('u')) ? (int)\$param('u') : 0;", $src);
    }
}
