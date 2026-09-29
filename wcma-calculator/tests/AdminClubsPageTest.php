<?php
// wcma-calculator/tests/AdminClubsPageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../admin-ui.php';
require_once __DIR__ . '/../admin-clubs.php';

use PHPUnit\Framework\TestCase;

final class AdminClubsPageTest extends TestCase
{
    private function clubs(): array {
        return [
            ['code' => 'NASCC', 'name' => 'Northern <Alberta>', 'msr_url' => 'https://msr.example/n', 'active' => 1],
            ['code' => 'WSCC', 'name' => 'Winnipeg', 'msr_url' => '', 'active' => 0],
        ];
    }

    public function testTableListsClubsWithOneEditEachAndModalsAfterIt(): void
    {
        $html = renderClubsPageHtml($this->clubs(), 'tok');
        $this->assertStringContainsString('Northern &lt;Alberta&gt;', $html);
        $this->assertStringContainsString('<a class="admin-link" href="https://msr.example/n" target="_blank" rel="noopener">Open ↗</a>', $html);
        $this->assertStringContainsString('<td data-label="MotorsportReg">—</td>', $html);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--fail">Inactive</span>', $html);
        $this->assertSame(2, substr_count($html, 'class="btn btn-secondary admin-edit"'));
        $this->assertStringContainsString('data-dialog-open="club-dialog-new"', $html);
        $this->assertSame(3, substr_count($html, 'action="admin.php?action=club-save"'));
        $this->assertLessThan(strpos($html, '<dialog '), strpos($html, '</table>'));
        $this->assertStringContainsString('name="csrf_token" value="tok"', $html);
    }

    public function testEditModalKeepsTheCodeFixedAndNamesTheActiveBox(): void
    {
        $html = renderClubsPageHtml($this->clubs(), 'tok');
        $wscc = substr($html, strpos($html, 'id="club-dialog-wscc"'));
        $wscc = substr($wscc, 0, strpos($wscc, '</dialog>'));
        $this->assertStringContainsString('Edit WSCC', $wscc);
        $this->assertStringContainsString('<input type="hidden" name="code" value="WSCC">', $wscc);
        $this->assertStringContainsString('type="url"', $wscc);
        $this->assertMatchesRegularExpression('/name="active" value="1"(?![^>]*checked)[^>]*>\s*Active — can host new events/', $wscc);
        $new = substr($html, strpos($html, 'id="club-dialog-new"'));
        $this->assertStringContainsString('<input type="hidden" name="is_new" value="1">', $new);
        $this->assertStringContainsString('name="code"', $new);
    }

    public function testAnErrorReopensThatClubsModal(): void
    {
        $html = renderClubsPageHtml($this->clubs(), 'tok', ['type' => 'error', 'message' => 'Enter the club name.'], 'NASCC');
        $this->assertMatchesRegularExpression('/id="club-dialog-nascc"[^>]*data-open-on-load>.*Enter the club name\./s', $html);
        $this->assertSame(1, substr_count($html, 'data-open-on-load'));
    }
}
