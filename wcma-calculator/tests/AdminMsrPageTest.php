<?php
// wcma-calculator/tests/AdminMsrPageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../clubs-lib.php';
require_once __DIR__ . '/../ice-rules.php';
require_once __DIR__ . '/../msr-lib.php';
require_once __DIR__ . '/../admin-ui.php';
require_once __DIR__ . '/../admin-events.php';
require_once __DIR__ . '/../admin-msr.php';

use PHPUnit\Framework\TestCase;

final class AdminMsrPageTest extends TestCase
{
    private function row(array $o = []): array {
        return array_merge(['msr_id' => 'AAAAAAAA-BBBB-CCCC-DDDDDDDDDDDDDDDD', 'club_code' => 'NASCC', 'name' => 'Ice <Race> #1',
            'start_date' => '2027-01-16', 'end_date' => '2027-01-17', 'type' => 'Ice Racing', 'venue' => 'Lake Wabamun',
            'detail_url' => 'https://www.motorsportreg.com/events/ice-1', 'cancelled' => 0, 'status' => 'new', 'hub_event_id' => null,
            'is_primary' => 0, 'snap_name' => null, 'snap_start' => null, 'snap_venue' => null, 'snap_cancelled' => null], $o);
    }

    private function clubs(): array {
        return [['code' => 'NASCC', 'name' => 'Northern Alberta Sports Car Club', 'msr_url' => '', 'msr_org_id' => 'X', 'active' => 1]];
    }

    private function hub(): array {
        return [['id' => 7, 'name' => 'NASCC Ice #1', 'event_date' => '2027-01-16', 'host_club' => 'NASCC', 'active' => 1, 'discipline' => 'ice', 'location' => null]];
    }

    private function syncStatus(): array {
        return [['code' => 'NASCC', 'name' => 'Northern Alberta Sports Car Club', 'ok_at' => '2026-10-01 06:00:00', 'error' => '']];
    }

    public function testANewEventOffersAddAttachAndIgnoreWithPrefilledModals(): void
    {
        $html = renderMsrPageHtml([$this->row()], $this->hub(), $this->clubs(), $this->syncStatus(), 'tok', null, null);
        $this->assertStringContainsString('<h2>New on MotorsportReg</h2>', $html);
        $this->assertStringContainsString('Ice &lt;Race&gt; #1', $html);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--info">Ice</span>', $html);
        $this->assertStringContainsString('href="https://www.motorsportreg.com/events/ice-1" target="_blank" rel="noopener">Open on MotorsportReg ↗</a>', $html);
        $this->assertStringContainsString('data-dialog-open="msr-add-aaaaaaaa-bbbb-cccc-dddddddddddddddd"', $html);
        $this->assertStringContainsString('data-dialog-open="msr-attach-aaaaaaaa-bbbb-cccc-dddddddddddddddd"', $html);
        $this->assertStringContainsString('action="admin.php?action=msr-ignore"', $html);
        $add = substr($html, strpos($html, 'id="msr-add-aaaaaaaa-bbbb-cccc-dddddddddddddddd"'));
        $add = substr($add, 0, strpos($add, '</dialog>'));
        $this->assertStringContainsString('action="admin.php?action=msr-add"', $add);
        $this->assertStringContainsString('name="msr_id" value="AAAAAAAA-BBBB-CCCC-DDDDDDDDDDDDDDDD"', $add);
        $this->assertStringContainsString('name="name" required value="Ice &lt;Race&gt; #1"', $add);
        $this->assertStringContainsString('name="event_date" required value="2027-01-16"', $add);
        $this->assertStringContainsString('name="discipline" value="ice" checked', $add);
        $this->assertStringContainsString('<option value="NASCC" selected>', $add);
        $this->assertStringContainsString('value="https://www.motorsportreg.com/events/ice-1"', $add);
        $attach = substr($html, strpos($html, 'id="msr-attach-aaaaaaaa-bbbb-cccc-dddddddddddddddd"'));
        $attach = substr($attach, 0, strpos($attach, '</dialog>'));
        $this->assertStringContainsString('<option value="7" selected>', $attach);   // same club, same date
        $this->assertLessThan(strpos($html, '<dialog '), strpos($html, '</table>'));
    }

    public function testACancelledNewEventOnlyOffersIgnore(): void
    {
        $html = renderMsrPageHtml([$this->row(['cancelled' => 1])], $this->hub(), $this->clubs(), $this->syncStatus(), 'tok', null, null);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--fail">Cancelled</span>', $html);
        $this->assertStringNotContainsString('data-dialog-open="msr-add-', $html);
        $this->assertStringContainsString('action="admin.php?action=msr-ignore"', $html);
    }

    public function testChangedEventsComeFirstAndAttachedOnesCanOnlyBeKept(): void
    {
        $primary = $this->row(['status' => 'added', 'hub_event_id' => 7, 'is_primary' => 1, 'cancelled' => 1,
            'snap_name' => 'Ice <Race> #1', 'snap_start' => '2027-01-16', 'snap_venue' => 'Lake Wabamun', 'snap_cancelled' => 0]);
        $attached = $this->row(['msr_id' => 'BBBBBBBB-BBBB-CCCC-DDDDDDDDDDDDDDDD', 'status' => 'added', 'hub_event_id' => 7, 'is_primary' => 0,
            'start_date' => '2027-01-23', 'snap_name' => 'Ice <Race> #1', 'snap_start' => '2027-01-16', 'snap_venue' => 'Lake Wabamun', 'snap_cancelled' => 0]);
        $new = $this->row(['msr_id' => 'CCCCCCCC-BBBB-CCCC-DDDDDDDDDDDDDDDD']);
        $html = renderMsrPageHtml([$new, $primary, $attached], $this->hub(), $this->clubs(), $this->syncStatus(), 'tok', null, null);
        $this->assertLessThan(strpos($html, '<h2>New on MotorsportReg</h2>'), strpos($html, '<h2>Changed on MotorsportReg</h2>'));
        $this->assertStringContainsString('Cancelled on MotorsportReg', $html);
        $this->assertStringContainsString('<button type="submit" class="btn btn-primary">Deactivate hub event</button>', $html);
        $this->assertSame(1, substr_count($html, 'action="admin.php?action=msr-apply"'));   // not for the attached row
        $this->assertSame(2, substr_count($html, 'action="admin.php?action=msr-keep"'));
        $this->assertStringContainsString('One of several MotorsportReg events for this hub event — change the hub event by hand if needed.', $html);
        $this->assertStringContainsString('NASCC Ice #1', $html);   // names the hub event
    }

    public function testIgnoredListStatusAndAttribution(): void
    {
        $status = [['code' => 'NASCC', 'name' => 'Northern Alberta Sports Car Club', 'ok_at' => '2026-10-01 06:00:00', 'error' => "Couldn't reach MotorsportReg (timeout)."]];
        $html = renderMsrPageHtml([$this->row(['status' => 'ignored'])], [], $this->clubs(), $status, 'tok', null, null);
        $this->assertStringContainsString('<details class="admin-msr-ignored"><summary>1 ignored</summary>', $html);
        $this->assertStringContainsString('action="admin.php?action=msr-restore"', $html);
        $this->assertStringContainsString('Nothing new on MotorsportReg.', $html);
        $this->assertStringContainsString('Failed: Couldn&#039;t reach MotorsportReg (timeout).', $html);
        $this->assertStringContainsString('action="admin.php?action=msr-check"', $html);
        $this->assertStringContainsString('Event data from MotorsportReg.com.', $html);
    }

    public function testAnAddErrorReopensThatModal(): void
    {
        $html = renderMsrPageHtml([$this->row()], $this->hub(), $this->clubs(), $this->syncStatus(), 'tok',
            ['type' => 'error', 'message' => 'Event name and date are required.'], 'AAAAAAAA-BBBB-CCCC-DDDDDDDDDDDDDDDD');
        $this->assertMatchesRegularExpression('/id="msr-add-aaaaaaaa-bbbb-cccc-dddddddddddddddd"[^>]*data-open-on-load>.*Event name and date are required\./s', $html);
    }
}
