<?php
// wcma-calculator/tests/AdminEventsPageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../clubs-lib.php';
require_once __DIR__ . '/../ice-rules.php';
require_once __DIR__ . '/../admin-ui.php';
require_once __DIR__ . '/../admin-events.php';

use PHPUnit\Framework\TestCase;

final class AdminEventsPageTest extends TestCase
{
    private function clubs(): array {
        return [
            ['code' => 'ESCC', 'name' => 'Edmonton', 'msr_url' => '', 'active' => 0],
            ['code' => 'NASCC', 'name' => 'Northern Alberta', 'msr_url' => '', 'active' => 1],
            ['code' => 'WSCC', 'name' => 'Winnipeg', 'msr_url' => '', 'active' => 1],
        ];
    }

    private function events(): array {
        return [
            ['id' => 7, 'name' => 'Fire on Ice', 'event_date' => '2026-11-19', 'location' => 'Lake Shirley', 'discipline' => 'ice', 'host_club' => 'NASCC', 'active' => 1],
            ['id' => 8, 'name' => 'Fall Sprint', 'event_date' => '2026-10-15', 'location' => null, 'discipline' => 'summer', 'host_club' => 'ESCC', 'active' => 0],
        ];
    }

    public function testListHasAnAddButtonAndOneEditButtonPerEventWithModalsAfterTheTable(): void
    {
        $html = renderEventsPageHtml($this->events(), [7 => 1], $this->clubs(), 'tok', null, null);
        $this->assertStringContainsString('data-dialog-open="event-dialog-new"', $html);
        $this->assertSame(2, substr_count($html, 'class="btn btn-secondary admin-edit"'));
        $this->assertSame(3, substr_count($html, '<dialog '));
        $this->assertLessThan(strpos($html, '<dialog '), strpos($html, '</table>'));
        $this->assertSame(1, substr_count($html, 'action="admin.php?action=event-create"'));
        $this->assertSame(2, substr_count($html, 'action="admin.php?action=event-update"'));
        $this->assertStringNotContainsString('action=event-club', $html);
        $this->assertStringContainsString('<th>Going</th>', $html);
        $this->assertStringContainsString('1 car', $html);
    }

    public function testRowShowsIceAndHostClubWithoutRepeatingIt(): void
    {
        $html = renderEventsPageHtml($this->events(), [], $this->clubs(), 'tok', null, null);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--pending">Ice</span>', $html);
        $this->assertStringContainsString('<td data-label="Host club">NASCC</td>', $html);
        $this->assertStringNotContainsString('Ice · NASCC', $html);
        $this->assertStringContainsString('<td data-label="Location">—</td>', $html);
        $this->assertDoesNotMatchRegularExpression('/<td[^>]*class="badge-/', $html);
    }

    public function testEditModalIsFilledInAndKeepsAnInactiveClub(): void
    {
        $html = renderEventsPageHtml($this->events(), [], $this->clubs(), 'tok', null, null);
        $sprint = substr($html, strpos($html, 'id="event-dialog-8"'));
        $sprint = substr($sprint, 0, strpos($sprint, '</dialog>'));
        $this->assertStringContainsString('name="name" required value="Fall Sprint"', $sprint);
        $this->assertStringContainsString('name="event_date" required value="2026-10-15"', $sprint);
        $this->assertStringContainsString('name="discipline" value="summer" checked', $sprint);
        $this->assertStringContainsString('<option value="ESCC" selected>ESCC — Edmonton (inactive)</option>', $sprint);
        $this->assertStringContainsString('action="admin.php?action=event-activate"', $sprint);
        $ice = substr($html, strpos($html, 'id="event-dialog-7"'));
        $ice = substr($ice, 0, strpos($ice, '</dialog>'));
        $this->assertStringContainsString('name="discipline" value="ice" checked', $ice);
        $this->assertStringContainsString('data-confirm="Deactivate Fire on Ice?', $ice);
    }

    public function testAddModalStartsBlankAsSummerWithNoClub(): void
    {
        $html = renderEventsPageHtml([], [], $this->clubs(), 'tok', null, null);
        $add = substr($html, strpos($html, 'id="event-dialog-new"'));
        $this->assertStringContainsString('name="discipline" value="summer" checked', $add);
        $this->assertStringContainsString('<option value="" selected>No host club</option>', $add);
        $this->assertStringNotContainsString('value="ESCC"', $add);   // inactive clubs can't host new events
        $this->assertStringContainsString('No events yet.', $html);
    }

    public function testEventsTakeAnOptionalMotorsportRegLink(): void
    {
        $events = $this->events();
        $events[0]['msr_url'] = 'https://msr.example/e/<7>';
        $html = renderEventsPageHtml($events, [], $this->clubs(), 'tok', null, null);
        $this->assertStringContainsString('<th>Registration</th>', $html);
        $this->assertStringContainsString('<td data-label="Registration"><a class="admin-link" href="https://msr.example/e/&lt;7&gt;" target="_blank" rel="noopener">Open ↗</a></td>', $html);
        $this->assertStringContainsString('<td data-label="Registration">—</td>', $html);
        $fire = substr($html, strpos($html, 'id="event-dialog-7"'));
        $fire = substr($fire, 0, strpos($fire, '</dialog>'));
        $this->assertStringContainsString('<input type="url" id="event-7-msr" name="msr_url" placeholder="https://www.motorsportreg.com/events/…" value="https://msr.example/e/&lt;7&gt;">', $fire);
        $add = substr($html, strpos($html, 'id="event-dialog-new"'));
        $this->assertStringContainsString('name="msr_url" placeholder="https://www.motorsportreg.com/events/…" value="">', $add);
    }

    public function testAnAddErrorReopensTheAddModal(): void
    {
        $html = renderEventsPageHtml($this->events(), [], $this->clubs(), 'tok', ['type' => 'error', 'message' => 'Event name and date are required.'], 'new');
        $this->assertSame(1, substr_count($html, 'data-open-on-load'));
        $this->assertMatchesRegularExpression('/id="event-dialog-new"[^>]*data-open-on-load>.*Event name and date are required\./s', $html);
    }

    public function testEventsListInvitesAReviewOfWhatIsWaitingOnMotorsportReg(): void
    {
        $waiting = renderEventsPageHtml($this->events(), [], $this->clubs(), 'tok', null, null, ['pending' => 4, 'connected' => true]);
        $this->assertStringContainsString('<p class="admin-msr-strip"><strong>From MotorsportReg:</strong> 4 events to review <a class="hub-btn hub-btn--secondary" href="admin.php?action=msr">Review</a></p>', $waiting);
        $quiet = renderEventsPageHtml($this->events(), [], $this->clubs(), 'tok', null, null, ['pending' => 0, 'connected' => true]);
        $this->assertStringContainsString('<a class="admin-link" href="admin.php?action=msr">From MotorsportReg</a>', $quiet);
        $none = renderEventsPageHtml($this->events(), [], $this->clubs(), 'tok', null, null);
        $this->assertStringNotContainsString('action=msr', $none);
    }

    public function testEventFieldsAreValidatedInOnePlace(): void
    {
        $post = ['name' => ' Fall Sprint ', 'event_date' => '2026-10-11', 'location' => '', 'discipline' => 'summer', 'host_club' => '', 'msr_url' => ''];
        $ok = adminEventFromPost($post, ['NASCC']);
        $this->assertSame([true, 'Fall Sprint', '2026-10-11', '', 'summer', null, ''], [$ok['ok'], $ok['name'], $ok['date'], $ok['location'], $ok['discipline'], $ok['club'], $ok['msr_url']]);
        $this->assertSame('Event name and date are required.', adminEventFromPost(['name' => ''] + $post, [])['error']);
        $this->assertSame(EVENT_MSR_URL_ERROR, adminEventFromPost(['msr_url' => 'http://x'] + $post, [])['error']);
        $this->assertFalse(adminEventFromPost(['discipline' => 'ice', 'host_club' => ''] + $post, ['NASCC'])['ok']);
    }
}
