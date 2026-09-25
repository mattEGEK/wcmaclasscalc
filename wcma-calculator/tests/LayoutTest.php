<?php
// wcma-calculator/tests/LayoutTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../layout.php';

use PHPUnit\Framework\TestCase;

final class LayoutTest extends TestCase
{
    private function keys(?array $user): array {
        return array_column(hubNavItems($user), 'key');
    }

    public function testNavItemsByAudience(): void
    {
        $this->assertSame(['home', 'calculator', 'signin'], $this->keys(null));
        $this->assertSame(['home', 'garage', 'drivers', 'calculator'], $this->keys(['id' => 1, 'name' => 'Jordan Lee', 'role' => 'user']));
        $this->assertSame(['home', 'garage', 'drivers', 'calculator', 'staff'], $this->keys(['id' => 1, 'name' => 'Ivy Inspector', 'role' => 'inspector']));

        $staff = array_column(hubNavItems(['id' => 1, 'name' => 'Ivy Inspector', 'role' => 'inspector']), 'label', 'key');
        $this->assertSame('Inspector', $staff['staff']);
        $admin = array_column(hubNavItems(['id' => 1, 'name' => 'Site Admin', 'role' => 'admin']), 'label', 'key');
        $this->assertSame('Admin', $admin['staff']);
        $this->assertSame('Class Calculator', $admin['calculator']);
    }

    public function testCurrentSectionIsInertText(): void
    {
        $html = hubNavHtml(['id' => 1, 'name' => 'Jordan Lee', 'role' => 'user'], 'home');
        $this->assertStringContainsString('<span class="hub-nav-current" aria-current="page">Home</span>', $html);
        $this->assertStringNotContainsString('href="index.php"', $html);
        $this->assertStringContainsString('href="account.php"', $html);
        $this->assertStringContainsString('href="calculator.php"', $html);
        $this->assertStringContainsString('>Menu<', $html);                     // phone toggle is a labelled button
    }

    public function testAccountAndFooterAreEscapedAndComplete(): void
    {
        $html = hubAccountHtml(['id' => 1, 'name' => 'A <b>', 'role' => 'user']);
        $this->assertStringContainsString('A &lt;b&gt;', $html);
        $this->assertStringContainsString('href="profile.php"', $html);
        $this->assertStringContainsString('action=logout', $html);
        $this->assertSame('', hubAccountHtml(null));

        $footer = hubFooterHtml();
        foreach (['data-feedback-open', 'racing-regulations', 'https://www.wcma.ca'] as $needle) {
            $this->assertStringContainsString($needle, $footer);
        }
    }

    public function testPageShellLoadsBothStylesheetsInOrder(): void
    {
        ob_start();
        renderPageStart('Home', 'home', ['flash' => ['message' => 'Saved <ok>', 'type' => 'success']]);
        renderPageEnd();
        $html = ob_get_clean();

        $this->assertStringContainsString('<title>Home — WCMA Hub</title>', $html);
        $this->assertLessThan(strpos($html, 'css/hub.css'), strpos($html, 'css/calculator.css'));
        $this->assertStringContainsString('Saved &lt;ok&gt;', $html);
        $this->assertStringContainsString('js/nav.js', $html);
        $this->assertStringContainsString('class="hub-stripe"', $html);
        $this->assertStringContainsString('fonts.googleapis.com', $html);
    }
}
