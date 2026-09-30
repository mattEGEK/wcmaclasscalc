<?php
// wcma-calculator/tests/MsrTaDriftTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../clubs-lib.php';
require_once __DIR__ . '/../ice-rules.php';
require_once __DIR__ . '/../msr-lib.php';
require_once __DIR__ . '/../admin-ui.php';
require_once __DIR__ . '/../admin-events.php';
require_once __DIR__ . '/../admin-msr.php';

use PHPUnit\Framework\TestCase;

final class MsrTaDriftTest extends TestCase
{
    private function feed(array $types): string {
        $events = [];
        foreach ($types as $i => $type) {
            $events[] = ['id' => sprintf('2386B6E3-96BC-AE58-0812CF4B556BCB%02d', $i), 'name' => "Event $i", 'start' => '2026-07-12', 'type' => $type];
        }
        return json_encode(['response' => ['events' => $events]]);
    }

    public function testTimeTrialAndDriftAreKept(): void
    {
        $parsed = msrParseFeed($this->feed(['Time Trial', 'Drift', 'HPDE', 'Autocross/Solo', 'Club Race']));
        $this->assertSame(['Time Trial', 'Drift', 'Club Race'], array_column($parsed['events'], 'type'));
    }

    public function testChips(): void
    {
        $this->assertSame(['Ice', 'Race', 'TA', 'Drift', 'Race'],
            [msrTypeChip('Ice Racing'), msrTypeChip('Club Race'), msrTypeChip('Time Trial'), msrTypeChip('Drift'), msrTypeChip('Something new')]);
    }

    public function testATimeTrialIsAddedAsASummerEventForItsClub(): void
    {
        $row = ['msr_id' => 'AAAAAAAA-BBBB-CCCC-DDDDDDDDDDDDDDDD', 'club_code' => 'WSCC', 'name' => 'WSCC Time Attack #1',
            'start_date' => '2026-07-12', 'end_date' => '2026-07-12', 'type' => 'Time Trial', 'venue' => 'Gimli',
            'detail_url' => '', 'cancelled' => 0, 'status' => 'new', 'hub_event_id' => null,
            'is_primary' => 0, 'snap_name' => null, 'snap_start' => null, 'snap_venue' => null, 'snap_cancelled' => null];
        $clubs = [['code' => 'WSCC', 'name' => 'Winnipeg Sports Car Club', 'msr_url' => '', 'msr_org_id' => 'X', 'active' => 1]];
        $html = renderMsrPageHtml([$row], [], $clubs, [], 'tok', null, null);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--info">TA</span>', $html);
        $this->assertStringContainsString('Race, time attack and drift events', $html);
        $add = substr($html, strpos($html, 'id="msr-add-aaaaaaaa-bbbb-cccc-dddddddddddddddd"'));
        $add = substr($add, 0, strpos($add, '</dialog>'));
        $this->assertMatchesRegularExpression('/name="discipline" value="summer" checked/', $add);
    }
}
