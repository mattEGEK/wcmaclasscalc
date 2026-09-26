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
}
