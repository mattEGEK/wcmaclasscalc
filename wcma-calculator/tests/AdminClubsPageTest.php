<?php
// wcma-calculator/tests/AdminClubsPageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../admin-clubs.php';

use PHPUnit\Framework\TestCase;

final class AdminClubsPageTest extends TestCase
{
    public function testListsClubsWithEditableNameLinkAndActiveAndAnAddForm(): void
    {
        $html = renderClubsPageHtml([
            ['code' => 'NASCC', 'name' => 'Northern <Alberta>', 'msr_url' => 'https://msr.example/n', 'active' => 1],
            ['code' => 'WSCC', 'name' => 'Winnipeg', 'msr_url' => '', 'active' => 0],
        ], 'tok');
        $this->assertStringContainsString('Northern &lt;Alberta&gt;', $html);
        $this->assertStringContainsString('value="https://msr.example/n"', $html);
        $this->assertStringContainsString('<input type="hidden" name="code" value="NASCC">', $html);
        $this->assertStringContainsString('<input type="hidden" name="is_new" value="1">', $html);
        $this->assertStringContainsString('name="csrf_token" value="tok"', $html);
        $this->assertSame(3, substr_count($html, 'action="admin.php?action=club-save"'));
        $this->assertMatchesRegularExpression('/name="active" value="1"(?![^>]*checked)[^>]*>\s*Shown/', $html);   // WSCC unticked
    }
}
