<?php
// wcma-calculator/tests/MsrLibTest.php
require_once __DIR__ . '/../msr-lib.php';

use PHPUnit\Framework\TestCase;

final class MsrLibTest extends TestCase
{
    private function fixture(string $name): string {
        return (string)file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    public function testFeedKeepsOnlyRaceEventsTidied(): void
    {
        $nascc = msrParseFeed($this->fixture('msr-nascc.json'));
        $this->assertSame(0, $nascc['skipped']);
        $names = array_column($nascc['events'], 'name');
        $this->assertContains('2026 NASCC Ice Race for Feb 21 & 22', $names);
        $this->assertContains('Subaru City Wkd 1 - 8h Enduro 3h Ironman 1.5h KoR', $names);   // trailing space trimmed
        $this->assertNotContains('2026 NASCC Club Membership', $names);                    // Membership
        $this->assertNotContains('Ice Race & Winter Driving School 2026', $names);         // HPDE
        $ice = $nascc['events'][array_search('2026 NASCC Ice Race for Feb 21 & 22', $names, true)];
        $this->assertSame('Ice Racing', $ice['type']);
        $this->assertSame('2026-02-21', $ice['start_date']);
        $this->assertSame('2026-02-22', $ice['end_date']);
        $this->assertSame("Alberta's Frozen Lakes", $ice['venue']);
        $this->assertSame(0, $ice['cancelled']);
        $this->assertMatchesRegularExpression(MSR_ID_PATTERN, $ice['msr_id']);
        $this->assertStringStartsWith('https://www.motorsportreg.com/events/2026-nascc-ice-race-for-feb-21-22', $ice['detail_url']);
        $this->assertStringNotContainsString('utm_', $ice['detail_url']);

        $wscc = msrParseFeed($this->fixture('msr-wscc.json'));
        $cancelled = array_values(array_filter($wscc['events'], fn($e) => str_contains($e['name'], 'CANCELED')));
        $this->assertSame(1, $cancelled[0]['cancelled']);
        $this->assertNotContains('Winnipeg Autocross / 2026 / Autocross 1', array_column($wscc['events'], 'name'));
    }

    public function testUnreadableFeedsAndEntries(): void
    {
        $this->assertNull(msrParseFeed('not json'));
        $this->assertNull(msrParseFeed('{"response":{}}'));
        $this->assertSame(['events' => [], 'skipped' => 0], msrParseFeed('{"response":{"events":[]}}'));
        $bad = json_encode(['response' => ['events' => [
            ['id' => 'nope', 'name' => 'X', 'start' => '2026-01-01', 'type' => 'Ice Racing'],
            ['id' => '2386B6E3-96BC-AE58-0812CF4B556BCBC2', 'name' => ' ', 'start' => '2026-01-01', 'type' => 'Ice Racing'],
            ['id' => '2386B6E3-96BC-AE58-0812CF4B556BCBC2', 'name' => 'Y', 'start' => 'soon', 'type' => 'Ice Racing'],
            'not an object',
            ['id' => '2386b6e3-96bc-ae58-0812cf4b556bcbc2', 'name' => 'Ok', 'start' => '2026-01-02', 'end' => '2025-01-01',
             'type' => 'Club Race', 'venue' => 'not an object', 'detailuri' => 'http://evil.example/x'],
        ]]]);
        $r = msrParseFeed($bad);
        $this->assertSame(4, $r['skipped']);
        $this->assertSame('2386B6E3-96BC-AE58-0812CF4B556BCBC2', $r['events'][0]['msr_id']);
        $this->assertSame('2026-01-02', $r['events'][0]['end_date']);   // end before start → start
        $this->assertSame('', $r['events'][0]['venue']);
        $this->assertSame('', $r['events'][0]['detail_url']);
    }

    public function testTidyUrl(): void
    {
        $this->assertSame('https://www.motorsportreg.com/events/a-1', msrTidyUrl('https://www.MotorsportReg.com/events/a-1?utm_source=apis&utm_content=json'));
        $this->assertSame('https://nascc.motorsportreg.com/x?keep=1', msrTidyUrl('https://nascc.motorsportreg.com/x?keep=1&utm_medium=m'));
        $this->assertSame('', msrTidyUrl('http://www.motorsportreg.com/events/a'));
        $this->assertSame('', msrTidyUrl('https://motorsportreg.com.evil.example/a'));
        $this->assertSame('', msrTidyUrl('javascript:alert(1)'));
    }

    public function testOrgIdFromPageAndInput(): void
    {
        $this->assertSame('2386B6E3-96BC-AE58-0812CF4B556BCBC2', msrOrgIdFromHtml($this->fixture('msr-org-page-nascc.html')));
        $this->assertNull(msrOrgIdFromHtml('<p>no id here</p>'));
        $this->assertNull(msrOrgIdFromHtml('2386B6E3-96BC-AE58-0812CF4B556BCBC2 and 4D45EE74-0A85-F011-ACBD5982F016139D'));   // ambiguous
        $never = function (string $url): array { throw new RuntimeException('must not fetch'); };
        $this->assertSame(['ok' => true, 'id' => '', 'error' => ''], msrOrgIdFromInput('  ', $never));
        $this->assertSame(['ok' => true, 'id' => '4D45EE74-0A85-F011-ACBD5982F016139D', 'error' => ''], msrOrgIdFromInput('4d45ee74-0a85-f011-acbd5982f016139d', $never));
        $this->assertFalse(msrOrgIdFromInput('www.motorsportreg.com/orgs/nascc', $never)['ok']);
        $this->assertFalse(msrOrgIdFromInput('https://example.com/orgs/nascc', $never)['ok']);
        $page = fn(string $url): array => ['ok' => true, 'status' => 200, 'body' => $this->fixture('msr-org-page-nascc.html'), 'error' => ''];
        $this->assertSame('2386B6E3-96BC-AE58-0812CF4B556BCBC2', msrOrgIdFromInput('https://www.motorsportreg.com/orgs/nascc', $page)['id']);
        $empty = fn(string $url): array => ['ok' => true, 'status' => 200, 'body' => '<html></html>', 'error' => ''];
        $this->assertSame(MSR_ORG_NOT_FOUND, msrOrgIdFromInput('https://www.motorsportreg.com/orgs/nascc', $empty)['error']);
        $down = fn(string $url): array => ['ok' => false, 'status' => 0, 'body' => '', 'error' => "Couldn't reach MotorsportReg (timeout)."];
        $this->assertSame("Couldn't reach MotorsportReg (timeout).", msrOrgIdFromInput('https://www.motorsportreg.com/orgs/nascc', $down)['error']);
    }

    public function testChangesAgainstTheSnapshot(): void
    {
        $row = ['status' => 'added', 'name' => 'Ice 2 - CANCELED', 'start_date' => '2026-02-21', 'venue' => 'Lake Shirley', 'cancelled' => 1,
                'snap_name' => 'Ice 2', 'snap_start' => '2026-01-24', 'snap_venue' => 'Lake Shirley', 'snap_cancelled' => 0];
        $this->assertSame([
            ['field' => 'name', 'old' => 'Ice 2', 'new' => 'Ice 2 - CANCELED'],
            ['field' => 'start_date', 'old' => '2026-01-24', 'new' => '2026-02-21'],
            ['field' => 'cancelled', 'old' => '0', 'new' => '1'],
        ], msrChanges($row));
        $this->assertSame([], msrChanges(['status' => 'new'] + $row));
        $this->assertSame([['field' => 'gone', 'old' => '', 'new' => '']], msrChanges(['status' => 'gone'] + $row));
        $same = ['name' => 'Ice 2', 'start_date' => '2026-01-24', 'cancelled' => 0] + $row;
        $this->assertSame([], msrChanges($same));
        $this->assertSame('Date: Jan 24 → Feb 21', msrChangeText(['field' => 'start_date', 'old' => '2026-01-24', 'new' => '2026-02-21']));
        $this->assertSame('Cancelled on MotorsportReg', msrChangeText(['field' => 'cancelled', 'old' => '0', 'new' => '1']));
        $this->assertSame('No longer cancelled on MotorsportReg', msrChangeText(['field' => 'cancelled', 'old' => '1', 'new' => '0']));
        $this->assertSame('No longer on MotorsportReg', msrChangeText(['field' => 'gone', 'old' => '', 'new' => '']));
        $this->assertSame('Name: Ice 2 → Ice 2 - CANCELED', msrChangeText(['field' => 'name', 'old' => 'Ice 2', 'new' => 'Ice 2 - CANCELED']));
    }

    public function testSuggestsTheSameClubsEventWithinThreeDays(): void
    {
        $hub = [
            ['id' => 1, 'host_club' => 'NASCC', 'event_date' => '2026-06-13', 'active' => 1],
            ['id' => 2, 'host_club' => 'NASCC', 'event_date' => '2026-06-20', 'active' => 1],
            ['id' => 3, 'host_club' => 'WSCC', 'event_date' => '2026-06-14', 'active' => 1],
            ['id' => 4, 'host_club' => 'NASCC', 'event_date' => '2026-06-14', 'active' => 0],
        ];
        $this->assertSame(1, msrSuggestEvent(['club_code' => 'NASCC', 'start_date' => '2026-06-14'], $hub));
        $this->assertNull(msrSuggestEvent(['club_code' => 'NASCC', 'start_date' => '2026-07-14'], $hub));
        $this->assertNull(msrSuggestEvent(['club_code' => 'ESCC', 'start_date' => '2026-06-13'], $hub));
    }

    public function testDateRange(): void
    {
        $this->assertSame('Sat, Feb 21 – Sun, Feb 22', msrDateRange('2026-02-21', '2026-02-22'));
        $this->assertSame('Sun, Jan 18', msrDateRange('2026-01-18', '2026-01-18'));
    }
}
