<?php
// wcma-calculator/tests/DbMsrTest.php
require_once __DIR__ . '/../msr-lib.php';

use PHPUnit\Framework\TestCase;

final class DbMsrTest extends TestCase
{
    private const NASCC = '2386B6E3-96BC-AE58-0812CF4B556BCBC2';
    private const EV = 'AAAAAAAA-BBBB-CCCC-DDDDDDDDDDDDDDDD';

    private function feed(array $events): callable {
        $body = json_encode(['response' => ['events' => $events]]);
        return fn(string $url): array => ['ok' => true, 'status' => 200, 'body' => $body, 'error' => ''];
    }

    private function ev(array $o = []): array {
        return array_merge(['id' => self::EV, 'name' => 'Ice Race #1', 'start' => '2027-01-16', 'end' => '2027-01-17',
            'type' => 'Ice Racing', 'venue' => ['name' => 'Lake Wabamun'], 'cancelled' => false,
            'detailuri' => 'https://www.motorsportreg.com/events/ice-1?utm_source=apis'], $o);
    }

    private function nascc(PDO $pdo): array {
        return db_get_club($pdo, 'NASCC');
    }

    public function testClubsGetTheirOrgIdsSeededOnceAndNeverAgain(): void
    {
        $pdo = make_temp_pdo();
        $this->assertSame(self::NASCC, $this->nascc($pdo)['msr_org_id']);
        $this->assertSame('4D45EE74-0A85-F011-ACBD5982F016139D', db_get_club($pdo, 'WSCC')['msr_org_id']);
        db_set_club_msr_org_id($pdo, 'NASCC', '');   // an admin disconnects the club
        db_init($pdo);
        $this->assertSame('', $this->nascc($pdo)['msr_org_id']);
    }

    public function testFirstSyncStoresRaceEventsAsNewAndRecordsSuccess(): void
    {
        $pdo = make_temp_pdo();
        $fixture = (string)file_get_contents(__DIR__ . '/fixtures/msr-nascc.json');
        $r = msrSyncClub($pdo, $this->nascc($pdo), fn($u) => ['ok' => true, 'status' => 200, 'body' => $fixture, 'error' => ''], '2025-10-01', '2025-10-01 06:00:00');
        $this->assertTrue($r['ok']);
        $this->assertSame(4, $r['found']);
        $rows = db_get_msr_events($pdo);
        $this->assertCount(4, $rows);
        $this->assertSame(['new'], array_values(array_unique(array_column($rows, 'status'))));
        $this->assertSame(4, msrPendingCount($pdo));
        $status = msrSyncStatus($pdo);
        $this->assertSame('2025-10-01 06:00:00', $status[0]['ok_at']);
        $this->assertSame('', $status[0]['error']);
    }

    public function testResyncUpdatesDetailsButKeepsTheAdminsDecisions(): void
    {
        $pdo = make_temp_pdo();
        msrSyncClub($pdo, $this->nascc($pdo), $this->feed([$this->ev()]), '2026-10-01', '2026-10-01 06:00:00');
        db_set_msr_status($pdo, self::EV, 'ignored');
        msrSyncClub($pdo, $this->nascc($pdo), $this->feed([$this->ev(['name' => 'Ice Race #1 (new time)'])]), '2026-10-02', '2026-10-02 06:00:00');
        $row = db_get_msr_event($pdo, self::EV);
        $this->assertSame('ignored', $row['status']);
        $this->assertSame('Ice Race #1 (new time)', $row['name']);
        $this->assertSame('https://www.motorsportreg.com/events/ice-1', $row['detail_url']);
        $this->assertSame(0, msrPendingCount($pdo));
    }

    public function testAnAddedEventThatChangesIsFlagged(): void
    {
        $pdo = make_temp_pdo();
        msrSyncClub($pdo, $this->nascc($pdo), $this->feed([$this->ev()]), '2026-10-01', '2026-10-01 06:00:00');
        $hub = db_create_event($pdo, 'Ice Race #1', '2027-01-16', 'Lake Wabamun', 'ice', 'NASCC');
        db_mark_msr_added($pdo, self::EV, $hub, true);
        $this->assertSame(0, msrPendingCount($pdo));
        msrSyncClub($pdo, $this->nascc($pdo), $this->feed([$this->ev(['start' => '2027-01-23', 'end' => '2027-01-24', 'cancelled' => true])]), '2026-10-02', '2026-10-02 06:00:00');
        $this->assertSame(['start_date', 'cancelled'], array_column(msrChanges(db_get_msr_event($pdo, self::EV)), 'field'));
        $this->assertSame(1, msrPendingCount($pdo));
        $this->assertSame(1, (int)db_get_event($pdo, $hub)['active']);   // never changed without an admin
    }

    public function testMissingEventsAreDroppedFlaggedOrTreatedAsFinished(): void
    {
        $pdo = make_temp_pdo();
        $other = 'BBBBBBBB-BBBB-CCCC-DDDDDDDDDDDDDDDD';
        $past = 'CCCCCCCC-BBBB-CCCC-DDDDDDDDDDDDDDDD';
        msrSyncClub($pdo, $this->nascc($pdo), $this->feed([$this->ev(), $this->ev(['id' => $other]), $this->ev(['id' => $past, 'start' => '2026-10-03', 'end' => '2026-10-04'])]), '2026-10-01', '2026-10-01 06:00:00');
        $hub = db_create_event($pdo, 'Ice Race #1', '2027-01-16', null, 'ice', 'NASCC');
        db_mark_msr_added($pdo, self::EV, $hub, true);
        db_mark_msr_added($pdo, $past, $hub, false);
        msrSyncClub($pdo, $this->nascc($pdo), $this->feed([]), '2026-10-10', '2026-10-10 06:00:00');
        $this->assertNull(db_get_msr_event($pdo, $other));                     // new, gone from MSR → dropped
        $this->assertNull(db_get_msr_event($pdo, $past));                      // finished → dropped quietly
        $this->assertSame('gone', db_get_msr_event($pdo, self::EV)['status']); // added, gone → flagged
        // Back in the feed: tracked again.
        msrSyncClub($pdo, $this->nascc($pdo), $this->feed([$this->ev()]), '2026-10-11', '2026-10-11 06:00:00');
        $this->assertSame('added', db_get_msr_event($pdo, self::EV)['status']);
        $this->assertSame(0, msrPendingCount($pdo));
    }

    public function testAFailedOrGarbledFetchChangesNothing(): void
    {
        $pdo = make_temp_pdo();
        msrSyncClub($pdo, $this->nascc($pdo), $this->feed([$this->ev()]), '2026-10-01', '2026-10-01 06:00:00');
        $down = fn($u) => ['ok' => false, 'status' => 0, 'body' => '', 'error' => "Couldn't reach MotorsportReg (timeout)."];
        $r = msrSyncClub($pdo, $this->nascc($pdo), $down, '2026-10-02', '2026-10-02 06:00:00');
        $this->assertFalse($r['ok']);
        $this->assertNotNull(db_get_msr_event($pdo, self::EV));
        $garbled = fn($u) => ['ok' => true, 'status' => 200, 'body' => '<html>maintenance</html>', 'error' => ''];
        $r2 = msrSyncClub($pdo, $this->nascc($pdo), $garbled, '2026-10-03', '2026-10-03 06:00:00');
        $this->assertSame("MotorsportReg sent something the hub couldn't read.", $r2['error']);
        $this->assertNotNull(db_get_msr_event($pdo, self::EV));
        $status = msrSyncStatus($pdo)[0];
        $this->assertSame('2026-10-01 06:00:00', $status['ok_at']);
        $this->assertSame("MotorsportReg sent something the hub couldn't read.", $status['error']);
    }

    public function testSyncAllSkipsClubsWithoutAnOrgId(): void
    {
        $pdo = make_temp_pdo();
        db_set_club_msr_org_id($pdo, 'WSCC', '');
        $urls = [];
        $fetch = function (string $url) use (&$urls): array { $urls[] = $url; return ['ok' => true, 'status' => 200, 'body' => '{"response":{"events":[]}}', 'error' => '']; };
        $results = msrSyncAll($pdo, $fetch, '2026-10-01', '2026-10-01 06:00:00');
        $this->assertSame(['NASCC'], array_column($results, 'code'));
        $this->assertSame([msrFeedUrl(self::NASCC)], $urls);
    }

    public function testAFeedWithMorePagesChangesNothing(): void
    {
        $pdo = make_temp_pdo();
        msrSyncClub($pdo, $this->nascc($pdo), $this->feed([$this->ev()]), '2026-10-01', '2026-10-01 06:00:00');
        db_set_msr_status($pdo, self::EV, 'ignored');
        $body = json_encode(['response' => ['recordset' => ['total' => 120, 'remaining' => 20, 'page' => 1], 'events' => []]]);
        $r = msrSyncClub($pdo, $this->nascc($pdo), fn($u) => ['ok' => true, 'status' => 200, 'body' => $body, 'error' => ''], '2026-10-02', '2026-10-02 06:00:00');
        $this->assertFalse($r['ok']);
        $this->assertSame('MotorsportReg sent only part of the calendar.', $r['error']);
        $this->assertSame('ignored', db_get_msr_event($pdo, self::EV)['status']);   // the admin's decision survives
    }

    public function testUnreadableEntriesStopTheCleanupForThatRun(): void
    {
        $pdo = make_temp_pdo();
        $other = 'BBBBBBBB-BBBB-CCCC-DDDDDDDDDDDDDDDD';
        msrSyncClub($pdo, $this->nascc($pdo), $this->feed([$this->ev(), $this->ev(['id' => $other])]), '2026-10-01', '2026-10-01 06:00:00');
        db_set_msr_status($pdo, $other, 'ignored');
        $hub = db_create_event($pdo, 'Ice Race #1', '2027-01-16', null, 'ice', 'NASCC');
        db_mark_msr_added($pdo, self::EV, $hub, true);
        // MSR changes its date format: every entry is unreadable.
        $r = msrSyncClub($pdo, $this->nascc($pdo), $this->feed([$this->ev(['start' => '2027-01-16T08:00']), $this->ev(['id' => $other, 'start' => '2027-01-16T08:00'])]), '2026-10-02', '2026-10-02 06:00:00');
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['skipped']);
        $this->assertSame('added', db_get_msr_event($pdo, self::EV)['status']);   // not flagged "gone"
        $this->assertSame('ignored', db_get_msr_event($pdo, $other)['status']);  // not deleted
    }

    public function testDisconnectingAClubForgetsItsPendingEvents(): void
    {
        $pdo = make_temp_pdo();
        $ids = ['AAAAAAAA-BBBB-CCCC-DDDDDDDDDDDDDDDD', 'BBBBBBBB-BBBB-CCCC-DDDDDDDDDDDDDDDD', 'CCCCCCCC-BBBB-CCCC-DDDDDDDDDDDDDDDD', 'DDDDDDDD-BBBB-CCCC-DDDDDDDDDDDDDDDD'];
        msrSyncClub($pdo, $this->nascc($pdo), $this->feed(array_map(fn($id) => $this->ev(['id' => $id]), $ids)), '2026-10-01', '2026-10-01 06:00:00');
        $hub = db_create_event($pdo, 'Ice Race #1', '2027-01-16', null, 'ice', 'NASCC');
        db_set_msr_status($pdo, $ids[1], 'ignored');
        db_mark_msr_added($pdo, $ids[2], $hub, true);
        db_mark_msr_added($pdo, $ids[3], $hub, false);
        db_set_msr_status($pdo, $ids[3], 'gone');
        msrForgetClub($pdo, 'NASCC');
        $this->assertSame([$ids[2]], array_column(db_get_msr_events($pdo), 'msr_id'));   // only the one a hub event came from
        $this->assertSame(0, msrPendingCount($pdo));
    }

    public function testAnErrorWhileSavingRollsBackAndTheNextClubStillSyncs(): void
    {
        // Bug list 2026-10-02 #2: an exception for one club left the transaction open and stopped every club.
        $pdo = make_temp_pdo();
        msrSyncClub($pdo, $this->nascc($pdo), $this->feed([$this->ev()]), '2026-10-01', '2026-10-01 06:00:00');
        $pdo->exec("CREATE TRIGGER msr_boom BEFORE UPDATE ON msr_events WHEN NEW.club_code = 'NASCC' BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $other = 'BBBBBBBB-BBBB-CCCC-DDDDDDDDDDDDDDDD';
        $nascc = $this->feed([$this->ev(['name' => 'Renamed']), $this->ev(['id' => 'CCCCCCCC-BBBB-CCCC-DDDDDDDDDDDDDDDD'])]);
        $wscc = $this->feed([$this->ev(['id' => $other])]);
        $fetch = fn(string $url): array => $url === msrFeedUrl(self::NASCC) ? $nascc($url) : $wscc($url);
        $log = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        $results = msrSyncAll($pdo, $fetch, '2026-10-02', '2026-10-02 06:00:00');
        ini_set('error_log', (string)$log);

        $byCode = array_column($results, null, 'code');
        $this->assertFalse($byCode['NASCC']['ok']);
        $this->assertSame("Something went wrong while saving this club's calendar. Nothing was changed.", $byCode['NASCC']['error']);
        $this->assertFalse($pdo->inTransaction(), 'the failed save was rolled back');
        $this->assertSame('Ice Race #1', db_get_msr_events($pdo, 'NASCC')[0]['name'], 'NASCC kept its old data');
        $this->assertCount(1, db_get_msr_events($pdo, 'NASCC'), 'the new NASCC event was rolled back too');
        $this->assertTrue($byCode['WSCC']['ok'], 'the next club still synced');
        $this->assertSame([$other], array_column(db_get_msr_events($pdo, 'WSCC'), 'msr_id'));
        $status = array_column(msrSyncStatus($pdo), null, 'code');
        $this->assertSame($byCode['NASCC']['error'], $status['NASCC']['error']);
    }

    public function testAFetchThatThrowsIsRecordedAsTheClubsError(): void
    {
        $pdo = make_temp_pdo();
        $log = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        $r = msrSyncClub($pdo, $this->nascc($pdo), function (string $u): array { throw new RuntimeException('dns'); }, '2026-10-01', '2026-10-01 06:00:00');
        ini_set('error_log', (string)$log);
        $this->assertFalse($r['ok']);
        $this->assertFalse($pdo->inTransaction());
    }
}
