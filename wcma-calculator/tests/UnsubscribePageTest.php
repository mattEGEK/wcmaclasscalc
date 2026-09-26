<?php
// wcma-calculator/tests/UnsubscribePageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../reminders-lib.php';

use PHPUnit\Framework\TestCase;

final class UnsubscribePageTest extends TestCase
{
    public function testConfirmPostsTheIdAndTokenBack(): void
    {
        $html = renderUnsubscribeHtml('confirm', 5, 'ab"cd');
        $this->assertStringContainsString('Stop reminder emails?', $html);
        $this->assertStringContainsString('<form method="post" action="unsubscribe.php">', $html);
        $this->assertStringContainsString('<input type="hidden" name="u" value="5">', $html);
        $this->assertStringContainsString('<input type="hidden" name="t" value="ab&quot;cd">', $html);
        $this->assertStringContainsString('<button type="submit" class="hub-btn">Unsubscribe</button>', $html);
    }

    public function testDoneAndInvalid(): void
    {
        $done = renderUnsubscribeHtml('done', 5, 'x');
        $this->assertStringContainsString('You won&#039;t get reminder emails any more.', $done);
        $this->assertStringContainsString('href="profile.php"', $done);
        $this->assertStringNotContainsString('<form', $done);

        $bad = renderUnsubscribeHtml('invalid', 0, 'x');
        $this->assertStringContainsString('This link isn&#039;t valid', $bad);
        $this->assertStringNotContainsString('<form', $bad);
        $this->assertSame($bad, renderUnsubscribeHtml('anything-else', 0, 'x'));
    }
}
