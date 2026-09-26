<?php
// wcma-calculator/tests/InspectPageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../gear-chips.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../garage-lib.php';
require_once __DIR__ . '/../garage-page.php';
require_once __DIR__ . '/../declaration-review-lib.php';
require_once __DIR__ . '/../inspect-lib.php';
require_once __DIR__ . '/../inspect-page.php';

use PHPUnit\Framework\TestCase;

final class InspectPageTest extends TestCase
{
    private function link(int $n, string $name): array {
        return ['driver_number' => $n, 'name' => $name, 'name_norm' => db_driver_name_norm($name), 'gear' => null, 'status' => ['state' => 'none', 'via' => null]];
    }

    private function row(?array $sheet, array $links, ?array $decl = null): array {
        return [
            'car' => ['id' => 1, 'owner_user_id' => 10, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'owner_name' => 'Jordan <Lee>', 'tagged' => 1],
            'sheet' => $sheet, 'class' => ['current' => $decl, 'earlierAccepted' => null],
            'status' => ['state' => 'none', 'via' => null, 'sheet_id' => null], 'gear_links' => $links,
        ];
    }

    private function vm(array $rows, array $o = []): array {
        return array_merge([
            'events' => [['id' => 3, 'name' => 'Fall Sprint', 'event_date' => date('Y') . '-10-04']], 'eventId' => 3,
            'filter' => 'all', 'rows' => $rows, 'counts' => inspectRosterCounts($rows), 'season' => (int)date('Y'), 'csrf' => 'tok',
        ], $o);
    }

    public function testRowShowsTheCarOwnerClassSheetAndCarTech(): void
    {
        $decl = ['id' => 8, 'review_status' => 'submitted', 'accepted_at' => null, 'calculated_class' => 'IT1'];
        $html = renderInspectRosterHtml($this->vm([$this->row(['id' => 5, 'status' => 'submitted'], [], $decl)]));
        foreach (['<span class="hub-plate">42</span>', '2004 Honda S2000', 'Jordan &lt;Lee&gt;', 'IT1', 'With an inspector',
                  'href="inspect.php?action=declaration&amp;id=8">Review</a>', 'href="inspect.php?action=tech-sheet&amp;id=5">Review</a>',
                  'Needs tech at the track', '<option value="3" selected>Fall Sprint', 'All cars (1)'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function testADriverWithoutGearOnASheetGetsTheOneTapButton(): void
    {
        $html = renderInspectRosterHtml($this->vm([$this->row(['id' => 5, 'status' => 'submitted'], [$this->link(1, 'Jordan Lee')])], ['filter' => 'needs_tech']));
        $this->assertStringContainsString('action=gear-create-accept', $html);
        $this->assertStringContainsString('name="sheet_id" value="5"', $html);
        $this->assertStringContainsString('name="back" value="roster"', $html);
        $this->assertStringContainsString('name="filter" value="needs_tech"', $html);
    }

    public function testACarWithoutASheetSaysSoAndHasNoOneTapButton(): void
    {
        $html = renderInspectRosterHtml($this->vm([$this->row(null, [$this->link(1, 'Casey Moss')])]));
        $this->assertStringContainsString('No sheet yet', $html);
        $this->assertStringContainsString('Casey Moss', $html);
        $this->assertStringNotContainsString('gear-create-accept', $html);
    }

    public function testEmptyStates(): void
    {
        $this->assertStringContainsString('No events yet.', renderInspectRosterHtml($this->vm([], ['events' => []])));
        $this->assertStringContainsString('No cars are tagged for this event and no tech sheets are in yet.', renderInspectRosterHtml($this->vm([])));
        $one = [$this->row(null, [])];
        $this->assertStringContainsString('No cars match this filter.', renderInspectRosterHtml($this->vm([], ['counts' => inspectRosterCounts($one)])));
        $this->assertStringContainsString('No drivers', renderInspectRosterHtml($this->vm($one)));
    }

    private function decl(array $o = []): array {
        return array_merge([
            'id' => 7, 'car_id' => 3, 'user_id' => 1, 'name' => 'Jordan <Lee>', 'email' => 'jordan@example.com',
            'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'comments' => null, 'calculated_class' => 'GT3',
            'competition_weight' => 2860, 'declared_hp' => 240, 'dyno_hp' => null, 'base_ratio' => 11.92, 'modified_ratio' => 12.42,
            'weight_factor' => 0, 'chassis_display' => null, 'chassis_value' => 0, 'body_mods_display' => null, 'body_mods_value' => 0,
            'transmission_display' => null, 'transmission_value' => 0, 'drivetrain_display' => null, 'drivetrain_value' => 0,
            'tires_display' => 'R-compound', 'tires_value' => 0.5, 'brake_suspension' => '[]', 'brake_suspension_value' => 0,
            'submitted_at' => '2026-03-03 10:00:00', 'review_status' => 'submitted', 'reviewer_note' => null,
            'reviewed_by_user_id' => null, 'reviewed_at' => null, 'accepted_at' => null,
            'car_image_path' => 'uploads/7/car.jpg', 'dyno_chart_path' => null, 'dyno_table_path' => 'uploads/7/dyno.pdf',
            'email_sent' => 1, 'email_send_count' => 1, 'last_emailed_at' => '2026-03-03 10:01:00', 'car_number' => '42',
        ], $o);
    }

    private function declVm(array $o = [], array $vm = []): array {
        $s = $this->decl($o);
        return array_merge([
            'sub' => $s,
            'car' => ['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'colour' => 'Silver'],
            'owner' => ['name' => 'Jordan Lee', 'email' => 'jordan@example.com'], 'reviewer' => null,
            'history' => [$s, $this->decl(['id' => 5, 'review_status' => 'superseded', 'calculated_class' => 'GT2'])],
            'isAdmin' => false, 'csrf' => 'tok',
        ], $vm);
    }

    public function testASubmittedDeclarationOffersAcceptAndSendBack(): void
    {
        $html = renderInspectDeclarationHtml($this->declVm());
        foreach (['action="inspect.php?action=declaration-accept"', 'action="inspect.php?action=declaration-send-back"',
                  '<textarea id="declaration-note" name="note" rows="4" maxlength="1000" required>', 'With an inspector',
                  'Jordan &lt;Lee&gt;', '#42 2004 Honda S2000', 'GT3 (10.00 – 11.99)', 'R-compound', '+0.50', '12.42',
                  'inspect.php?action=declaration-file&amp;id=7&amp;field=car_image', 'data-lightbox', 'Open dyno.pdf',
                  'href="inspect.php?action=declaration&amp;id=5">View</a>', 'This one', 'href="inspect.php?action=classing&amp;car=3"',
                  'action="inspect.php?action=declaration-resend"', 'sent 1 time'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringNotContainsString('declaration-delete', $html);
        $this->assertStringNotContainsString('declaration-update-contact', $html);
    }

    public function testAnAcceptedDeclarationCanOnlyBeSentBackAndNamesTheReviewer(): void
    {
        $html = renderInspectDeclarationHtml($this->declVm(
            ['review_status' => 'accepted', 'reviewed_at' => '2026-03-04 09:30:00', 'accepted_at' => '2026-03-04 09:30:00'],
            ['reviewer' => ['name' => 'Ivy Inspector']]
        ));
        $this->assertStringContainsString('Reviewed by Ivy Inspector on Mar 4, 2026 9:30 AM.', $html);
        $this->assertStringContainsString('action=declaration-send-back"', $html);
        $this->assertStringNotContainsString('action=declaration-accept"', $html);
    }

    public function testASentBackDeclarationShowsTheNoteAndCanBeAccepted(): void
    {
        $html = renderInspectDeclarationHtml($this->declVm(['review_status' => 'needs_changes', 'reviewer_note' => "Attach <dyno>.\nThanks", 'reviewed_at' => '2026-03-04 09:30:00']));
        $this->assertStringContainsString('Attach &lt;dyno&gt;.<br />', $html);
        $this->assertStringContainsString('action=declaration-accept"', $html);
        $this->assertStringNotContainsString('action=declaration-send-back"', $html);
    }

    public function testASupersededDeclarationCannotBeReviewed(): void
    {
        $html = renderInspectDeclarationHtml($this->declVm(['review_status' => 'superseded']));
        $this->assertStringContainsString('no longer the current one', $html);
        $this->assertStringNotContainsString('action=declaration-accept"', $html);
        $this->assertStringNotContainsString('action=declaration-send-back"', $html);
    }

    public function testAdminsAlsoGetEditAndDelete(): void
    {
        $html = renderInspectDeclarationHtml($this->declVm([], ['isAdmin' => true]));
        $this->assertStringContainsString('action="inspect.php?action=declaration-update-contact"', $html);
        $this->assertStringContainsString('action="inspect.php?action=declaration-delete"', $html);
        $this->assertStringContainsString('value="Jordan &lt;Lee&gt;"', $html);
    }

    private function classingVm(array $rows, array $filters = [], array $o = []): array {
        return array_merge(['filters' => inspectClassingFilters($filters), 'rows' => $rows, 'total' => count($rows), 'pages' => 1, 'isAdmin' => false, 'csrf' => 'tok'], $o);
    }

    public function testClassingListForAnInspector(): void
    {
        $html = renderInspectClassingHtml($this->classingVm([$this->decl()], ['class' => 'GT3', 'q' => 'hon"da']));
        foreach (['<h1 class="hub-page-title">Classing</h1>', '#42 2004 Honda S2000', 'Jordan &lt;Lee&gt;',
                  'href="inspect.php?action=declaration&amp;id=7">Review</a>', '1 declaration', '<option value="GT3" selected>GT3</option>',
                  'value="hon&quot;da"', 'href="inspect.php?action=declarations-export"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringNotContainsString('bulk-delete-form', $html);
        $this->assertStringNotContainsString('submission-select', $html);
    }

    public function testClassingListForAnAdminHasBulkDelete(): void
    {
        $html = renderInspectClassingHtml($this->classingVm([$this->decl()], [], ['isAdmin' => true]));
        $this->assertStringContainsString('id="bulk-delete-form"', $html);
        $this->assertStringContainsString('class="submission-select" form="bulk-delete-form" name="ids[]" value="7"', $html);
        $this->assertStringContainsString('id="classing-select-all"', $html);
    }

    public function testClassingPaginationKeepsTheFilters(): void
    {
        $html = renderInspectClassingHtml($this->classingVm([$this->decl()], ['q' => 'honda', 'page' => '2'], ['pages' => 3, 'total' => 120]));
        $this->assertStringContainsString('href="inspect.php?action=classing&amp;q=honda">&larr; Previous</a>', $html);
        $this->assertStringContainsString('href="inspect.php?action=classing&amp;q=honda&amp;page=3">Next &rarr;</a>', $html);
        $this->assertStringContainsString('Page 2 of 3', $html);
    }

    public function testClassingEmptyAndOneCar(): void
    {
        $html = renderInspectClassingHtml($this->classingVm([], ['car' => '3']));
        $this->assertStringContainsString('No declarations match.', $html);
        $this->assertStringContainsString('0 declarations', $html);
        $this->assertStringContainsString("Showing one car's declarations.", $html);
        $this->assertStringContainsString('<input type="hidden" name="car" value="3">', $html);
    }
}
