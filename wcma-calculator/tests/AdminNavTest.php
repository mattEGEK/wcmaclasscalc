<?php
// wcma-calculator/tests/AdminNavTest.php
require_once __DIR__ . '/../view_helpers.php';

use PHPUnit\Framework\TestCase;

final class AdminNavTest extends TestCase
{
    public function testEveryAdminDestinationIsListedOnce(): void
    {
        $html = renderAdminNav('users');
        foreach (['Submissions', 'Tech Sheets', 'Gear', 'Manage Users', 'Events', 'Settings', 'Feedback'] as $label) {
            $this->assertSame(1, substr_count($html, '>' . $label . '<'), $label);
        }
        foreach (['admin.php"', 'action=tech-sheets"', 'action=gear"', 'action=events"', 'action=settings"', 'action=feedback"'] as $href) {
            $this->assertStringContainsString($href, $html, $href);
        }
    }

    public function testCurrentPageIsInertTextNotALink(): void
    {
        $html = renderAdminNav('gear');
        $this->assertStringContainsString('<span class="nav-current" aria-current="page">Gear</span>', $html);
        $this->assertStringNotContainsString('action=gear"', $html);
        $this->assertStringContainsString('action=tech-sheets"', $html);
    }

    public function testUnknownCurrentLeavesEveryLinkActive(): void
    {
        $this->assertStringNotContainsString('nav-current', renderAdminNav('nope'));
    }
}
