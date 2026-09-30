<?php
// wcma-calculator/tests/AdminSettingsLinksPageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../admin-ui.php';
require_once __DIR__ . '/../admin-season-links.php';
require_once __DIR__ . '/../admin-settings.php';

use PHPUnit\Framework\TestCase;

final class AdminSettingsLinksPageTest extends TestCase
{
    // UX review 2026-09-30 §L5: a read-only list with Add and Edit dialogs, like the other admin tabs.
    public function testSeasonLinksAreAListWithAnEditDialogPerLink(): void
    {
        $html = renderSeasonLinksPageHtml([
            ['id' => 4, 'label' => '2026 <Waiver>', 'url' => 'https://msr.example/w', 'sort_order' => 1, 'active' => 1],
        ], 'tok');
        $this->assertStringContainsString('class="data-table admin-table"', $html);
        $this->assertStringNotContainsString('<input form="link-4"', $html);   // the row is read-only
        $this->assertStringContainsString('<td data-label="Label">2026 &lt;Waiver&gt;</td>', $html);
        $this->assertStringContainsString('data-dialog-open="link-dialog-4" aria-label="Edit 2026 &lt;Waiver&gt;">Edit</button>', $html);
        $this->assertStringContainsString('data-dialog-open="link-dialog-new">Add link</button>', $html);
        $this->assertStringContainsString('<dialog class="admin-dialog" id="link-dialog-4"', $html);
        $this->assertStringContainsString('name="url" required value="https://msr.example/w"', $html);
        $this->assertStringContainsString('<input type="checkbox" name="active" value="1" checked> Shown to competitors</label>', $html);
        $this->assertStringContainsString('data-confirm="Remove “2026 &lt;Waiver&gt;”? Competitors will stop seeing it."', $html);
        $this->assertStringContainsString('<form method="post" action="admin.php?action=season-link-save" class="admin-form">', $html);
        $this->assertStringNotContainsString('style=', $html);
    }

    public function testSeasonLinksEmptyStateAsksForTheFirstOne(): void
    {
        $this->assertStringContainsString('No links yet. Add one with the button above.', renderSeasonLinksPageHtml([], 'tok'));
    }

    public function testASeasonLinkErrorReopensItsDialog(): void
    {
        $links = [['id' => 4, 'label' => 'Waiver', 'url' => 'https://msr.example/w', 'sort_order' => 1, 'active' => 0]];
        $html = renderSeasonLinksPageHtml($links, 'tok', ['type' => 'error', 'message' => 'Enter a full web address starting with https://.'], '4');
        $this->assertMatchesRegularExpression('/<dialog class="admin-dialog" id="link-dialog-4"[^>]* data-open-on-load>/', $html);
        $this->assertStringContainsString('Enter a full web address starting with https://.', $html);
        $this->assertStringContainsString('>Hidden</span>', $html);
    }

    public function testSettingsGroupEmailAndNameUnderEachRecipient(): void
    {
        $html = renderSettingsPageHtml([
            'classing_recipient_email' => 'c@example.com', 'classing_recipient_name' => 'Classing',
            'tech_sheet_recipient_email' => 't@example.com', 'tech_sheet_recipient_name' => 'Tech',
            'feedback_recipient_email' => 'f@example.com', 'feedback_recipient_name' => 'Feedback <Desk>',
        ], 'tok');
        foreach (['Class calculator', 'Tech sheets', 'Feedback'] as $legend) {
            $this->assertStringContainsString('<legend>' . $legend . '</legend>', $html);
        }
        $this->assertSame(6, substr_count($html, '<label for="'));   // Email + Name per recipient
        $this->assertStringContainsString('name="feedback_recipient_name" value="Feedback &lt;Desk&gt;"', $html);
        $this->assertStringContainsString('class="detail-card admin-settings"', $html);
        $this->assertSame(1, substr_count($html, 'type="submit"'));
        $this->assertStringNotContainsString('— Email', $html);
    }
}
