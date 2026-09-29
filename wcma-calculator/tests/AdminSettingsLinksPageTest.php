<?php
// wcma-calculator/tests/AdminSettingsLinksPageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../admin-ui.php';
require_once __DIR__ . '/../admin-season-links.php';
require_once __DIR__ . '/../admin-settings.php';

use PHPUnit\Framework\TestCase;

final class AdminSettingsLinksPageTest extends TestCase
{
    public function testSeasonLinksEditInTheTableWithSaveAndRemoveApart(): void
    {
        $html = renderSeasonLinksPageHtml([
            ['id' => 4, 'label' => '2026 <Waiver>', 'url' => 'https://msr.example/w', 'sort_order' => 1, 'active' => 1],
        ], 'tok');
        $this->assertStringContainsString('class="data-table admin-table"', $html);
        $this->assertStringContainsString('<input form="link-4" type="url" name="url" value="https://msr.example/w"', $html);
        $this->assertStringContainsString('2026 &lt;Waiver&gt;', $html);
        $this->assertStringContainsString('<button type="submit" class="btn btn-secondary">Save</button>', $html);
        $this->assertStringContainsString('data-confirm="Remove “2026 &lt;Waiver&gt;”? Competitors will stop seeing it."', $html);
        $this->assertStringContainsString('<button type="submit" class="link-button">Remove</button>', $html);
        $this->assertStringContainsString('<form method="post" action="admin.php?action=season-link-save" class="admin-form">', $html);
        $this->assertStringNotContainsString('style=', $html);
    }

    public function testSeasonLinksEmptyStateAsksForTheFirstOne(): void
    {
        $this->assertStringContainsString('No links yet. Add one below.', renderSeasonLinksPageHtml([], 'tok'));
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
