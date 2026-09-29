# MotorsportReg Calendar Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A daily check reads each connected club's public MotorsportReg calendar; admins review new race events (add to hub / attach to an existing event / ignore) and changes (apply / keep) on a "From MotorsportReg" page, with a count on the Events tab.

**Architecture:** `msr-lib.php` holds feed reading (pure), the sync (DB, fetcher injected) and the review actions (DB), so all behaviour is unit/DB-testable without calling MSR. `db.php` gets the `msr_events` table, `clubs.msr_org_id` and small DB helpers. `msr-sync.php` is the CLI run by `reminders-cron.sh`. `admin-msr.php` is the review page (list + modals, the admin UI pattern from 2026-09-29), and Clubs/Events gain the connection field, badge and strip.

**Tech Stack:** PHP 8.3 (curl), SQLite, vanilla JS/CSS; PHPUnit (`php phpunit.phar` from `wcma-calculator/`), node tests (`node --test tests/js/*.test.js`), phone audit (`bash wcma-calculator/tests/ux/run-audit.sh` from the repo root).

**Spec:** `docs/superpowers/specs/2026-09-29-msr-calendar-import-design.md`

## Global Constraints

- Stored MSR types: exactly `'Ice Racing'` and `'Club Race'`.
- MSR IDs match `/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{16}$/i`, stored upper case.
- Feed URL: `https://api.motorsportreg.com/rest/calendars/organization/{orgId}.json`; 15 s timeout; user agent `WCMA Hub (221racing.com)`.
- Stored links: `https://` on `motorsportreg.com` or a subdomain, host lower-cased, `utm_*` query parameters removed; anything else stored as `''`.
- Seed once (setting `msr_org_ids_seeded`): NASCC `2386B6E3-96BC-AE58-0812CF4B556BCBC2`, WSCC `4D45EE74-0A85-F011-ACBD5982F016139D`.
- Copy: stale action → "That MotorsportReg event was already handled."; org not found → "Couldn't find a MotorsportReg organization on that page. Check the address, or ask the club for its MotorsportReg organization ID."; attribution "Event data from MotorsportReg.com."
- The hub never changes a hub event without an admin click; failed fetches change nothing in `msr_events`.
- Every POST via `adminRequirePost()`; all feed text through `h()`; no test calls MSR (fixtures in `tests/fixtures/`).
- Always `require_once` (tests/RequireOnceGuardTest.php).
- Branch `msr-calendar-import` (exists; spec + fixtures committed). Commit messages end with the two attribution lines.
- Baseline: PHPUnit 997, node 59, audit passes.

## Review Focus

1. **A finished event looks "gone".** The feed only lists events that haven't ended; a missing row with `end_date < today` must be deleted silently, never flagged. Pinned in Task 2.
2. **Attached (non-primary) rows must never change the hub event** — no Apply for them. Pinned in Task 3 (`msrApply` refuses) and Task 6 (no Apply button).
3. **A failed or garbled fetch must not delete or flag anything.** Pinned in Task 2.
4. **Admin re-typing the club field must not refetch MSR on every save** (fetch only when the input changed). Pinned in Task 4 (source test).
5. **The badge query runs on every admin page** — it must be cheap and never call MSR. Pinned in Task 5 (source test: badge uses `msrPendingCount`, which is DB-only).

---

### Task 0: Baseline

- [ ] Confirm branch `msr-calendar-import`; from `wcma-calculator/`: `php phpunit.phar` → `OK (997 tests…)`; `node --test tests/js/*.test.js` → 59 pass. Commit this plan: `git add docs/superpowers/plans/2026-09-29-msr-calendar-import.md && git commit -m "docs: MSR calendar import plan"`.

---

### Task 1: Feed reading (pure)

**Files:** Create `wcma-calculator/msr-lib.php`, `wcma-calculator/tests/MsrLibTest.php`.

**Interfaces (produces):**
`const MSR_RACE_TYPES`, `const MSR_ID_PATTERN`, `const MSR_ORG_NOT_FOUND`, `const MSR_STALE`,
`msrFeedUrl(string $orgId): string`, `msrHttpGet(string $url): array{ok:bool,status:int,body:string,error:string}`,
`msrTidyUrl(string $url): string`, `msrIsDate(string $s): bool`,
`msrParseFeed(string $body): ?array{events: list<array{msr_id,name,start_date,end_date,type,venue,detail_url,cancelled:int}>, skipped:int}`,
`msrOrgIdFromHtml(string $html): ?string`, `msrOrgIdFromInput(string $input, callable $fetch): array{ok:bool,id:string,error:string}`,
`msrChanges(array $row): list<array{field:string,old:string,new:string}>`,
`msrSuggestEvent(array $row, array $hubEvents): ?int`, `msrDateRange(string $start, string $end): string`,
`msrChangeText(array $change): string`.

- [ ] **Step 1: Failing test** — `tests/MsrLibTest.php`:

```php
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
```

- [ ] **Step 2: Run** `php phpunit.phar tests/MsrLibTest.php` → FAIL (file missing).

- [ ] **Step 3: Implement** — `msr-lib.php` (this task adds only the pure part; Tasks 2–3 append to it):

```php
<?php
// wcma-calculator/msr-lib.php
//
// MotorsportReg calendar import (spec 2026-09-29-msr-calendar-import-design.md): reading a club's
// public calendar feed, syncing it into msr_events, and the admin review actions. Pure except
// msrHttpGet() and the functions that take a PDO; the sync takes its fetcher as a parameter so tests
// never call MotorsportReg. Callers must have loaded db.php.

const MSR_RACE_TYPES = ['Ice Racing', 'Club Race'];
const MSR_ID_PATTERN = '/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{16}$/i';
const MSR_ORG_NOT_FOUND = "Couldn't find a MotorsportReg organization on that page. Check the address, or ask the club for its MotorsportReg organization ID.";
const MSR_STALE = 'That MotorsportReg event was already handled.';

function msrFeedUrl(string $orgId): string {
    return 'https://api.motorsportreg.com/rest/calendars/organization/' . rawurlencode($orgId) . '.json';
}

/** GET $url with a 15-second timeout. Never throws. */
function msrHttpGet(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'WCMA Hub (221racing.com)',
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = $body === false ? curl_error($ch) : '';
    curl_close($ch);
    if ($body === false) return ['ok' => false, 'status' => 0, 'body' => '', 'error' => "Couldn't reach MotorsportReg ($error)."];
    if ($status !== 200) return ['ok' => false, 'status' => $status, 'body' => (string)$body, 'error' => "MotorsportReg answered with error $status."];
    return ['ok' => true, 'status' => 200, 'body' => (string)$body, 'error' => ''];
}

/** A link as stored: https on motorsportreg.com or a subdomain, host lower-cased, utm_* removed; else ''. */
function msrTidyUrl(string $url): string {
    $p = parse_url(trim($url));
    if (!is_array($p) || strtolower((string)($p['scheme'] ?? '')) !== 'https') return '';
    $host = strtolower((string)($p['host'] ?? ''));
    if ($host !== 'motorsportreg.com' && !str_ends_with($host, '.motorsportreg.com')) return '';
    parse_str((string)($p['query'] ?? ''), $query);
    $query = array_filter($query, fn($k): bool => !str_starts_with((string)$k, 'utm_'), ARRAY_FILTER_USE_KEY);
    return 'https://' . $host . ($p['path'] ?? '/') . ($query ? '?' . http_build_query($query) : '');
}

function msrIsDate(string $s): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
}

/**
 * The race events in a calendar feed response, tidied, and how many entries were unreadable
 * (no valid id, name or start). Other types are left out without counting. Null when the body is
 * not a calendar response at all.
 */
function msrParseFeed(string $body): ?array {
    $data = json_decode($body, true);
    $events = is_array($data) && is_array($data['response'] ?? null) ? ($data['response']['events'] ?? null) : null;
    if (!is_array($events)) return null;
    $out = [];
    $skipped = 0;
    foreach ($events as $e) {
        if (!is_array($e)) { $skipped++; continue; }
        $id = is_string($e['id'] ?? null) ? $e['id'] : '';
        $name = trim((string)preg_replace('/\s+/', ' ', is_string($e['name'] ?? null) ? $e['name'] : ''));
        $start = is_string($e['start'] ?? null) ? $e['start'] : '';
        if (!preg_match(MSR_ID_PATTERN, $id) || $name === '' || !msrIsDate($start)) { $skipped++; continue; }
        $type = is_string($e['type'] ?? null) ? $e['type'] : '';
        if (!in_array($type, MSR_RACE_TYPES, true)) continue;
        $end = is_string($e['end'] ?? null) ? $e['end'] : '';
        $venue = is_array($e['venue'] ?? null) && is_string($e['venue']['name'] ?? null) ? trim($e['venue']['name']) : '';
        $out[] = [
            'msr_id' => strtoupper($id), 'name' => mb_substr($name, 0, 200, 'UTF-8'),
            'start_date' => $start, 'end_date' => msrIsDate($end) && $end >= $start ? $end : $start,
            'type' => $type, 'venue' => mb_substr($venue, 0, 200, 'UTF-8'),
            'detail_url' => msrTidyUrl(is_string($e['detailuri'] ?? null) ? $e['detailuri'] : ''),
            'cancelled' => !empty($e['cancelled']) ? 1 : 0,
        ];
    }
    return ['events' => $out, 'skipped' => $skipped];
}

/** The organization ID in a club's MSR page: the uidClub/<ID> link, else the page's only ID; null if none or ambiguous. */
function msrOrgIdFromHtml(string $html): ?string {
    if (preg_match('~uidClub/([0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{16})~i', $html, $m)) return strtoupper($m[1]);
    preg_match_all('/[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{16}/i', $html, $all);
    $ids = array_values(array_unique(array_map('strtoupper', $all[0])));
    return count($ids) === 1 ? $ids[0] : null;
}

/**
 * What an admin typed in the club's MotorsportReg field: '' (not connected), an organization ID, or
 * the club's MSR page address (fetched with $fetch to find the ID).
 */
function msrOrgIdFromInput(string $input, callable $fetch): array {
    $input = trim($input);
    if ($input === '') return ['ok' => true, 'id' => '', 'error' => ''];
    if (preg_match(MSR_ID_PATTERN, $input)) return ['ok' => true, 'id' => strtoupper($input), 'error' => ''];
    if (msrTidyUrl($input) === '') {
        return ['ok' => false, 'id' => '', 'error' => "Enter the club's MotorsportReg page address (https://www.motorsportreg.com/orgs/…) or its organization ID."];
    }
    $page = $fetch($input);
    if (!$page['ok']) return ['ok' => false, 'id' => '', 'error' => (string)$page['error']];
    $id = msrOrgIdFromHtml((string)$page['body']);
    return $id !== null ? ['ok' => true, 'id' => $id, 'error' => ''] : ['ok' => false, 'id' => '', 'error' => MSR_ORG_NOT_FOUND];
}

/** What changed on MSR since an admin last added/applied/kept this row. Empty for new/ignored rows. */
function msrChanges(array $row): array {
    if ($row['status'] === 'gone') return [['field' => 'gone', 'old' => '', 'new' => '']];
    if ($row['status'] !== 'added') return [];
    $out = [];
    foreach (['name' => 'snap_name', 'start_date' => 'snap_start', 'venue' => 'snap_venue'] as $field => $snap) {
        if ((string)$row[$field] !== (string)$row[$snap]) $out[] = ['field' => $field, 'old' => (string)$row[$snap], 'new' => (string)$row[$field]];
    }
    if ((int)$row['cancelled'] !== (int)$row['snap_cancelled']) {
        $out[] = ['field' => 'cancelled', 'old' => (string)(int)$row['snap_cancelled'], 'new' => (string)(int)$row['cancelled']];
    }
    return $out;
}

function msrChangeText(array $c): string {
    switch ($c['field']) {
        case 'gone':       return 'No longer on MotorsportReg';
        case 'cancelled':  return $c['new'] === '1' ? 'Cancelled on MotorsportReg' : 'No longer cancelled on MotorsportReg';
        case 'start_date': return 'Date: ' . date('M j', strtotime($c['old'])) . ' → ' . date('M j', strtotime($c['new']));
        case 'venue':      return 'Venue: ' . ($c['old'] !== '' ? $c['old'] : '—') . ' → ' . ($c['new'] !== '' ? $c['new'] : '—');
        default:           return 'Name: ' . $c['old'] . ' → ' . $c['new'];
    }
}

/** The active hub event of the same club nearest the MSR start date, within 3 days; null if none. */
function msrSuggestEvent(array $row, array $hubEvents): ?int {
    $best = null;
    $bestDays = 4;
    $start = strtotime((string)$row['start_date']);
    foreach ($hubEvents as $e) {
        if ((int)$e['active'] !== 1 || (string)($e['host_club'] ?? '') !== (string)$row['club_code']) continue;
        $days = (int)round(abs(strtotime(substr((string)$e['event_date'], 0, 10)) - $start) / 86400);
        if ($days < $bestDays) { $best = (int)$e['id']; $bestDays = $days; }
    }
    return $best;
}

function msrDateRange(string $start, string $end): string {
    $s = date('D, M j', strtotime($start));
    return $end === $start ? $s : $s . ' – ' . date('D, M j', strtotime($end));
}
```

- [ ] **Step 4: Run** `php phpunit.phar tests/MsrLibTest.php` → PASS (7 tests).
- [ ] **Step 5: Commit** `git add wcma-calculator/msr-lib.php wcma-calculator/tests/MsrLibTest.php && git commit -m "feat(msr): read MotorsportReg calendar feeds and club pages"`

---

### Task 2: Storage and sync

**Files:** Modify `wcma-calculator/db.php` (schema in `db_init` after the clubs seed; helpers after the Clubs section), `wcma-calculator/msr-lib.php` (append). Create `wcma-calculator/tests/DbMsrTest.php`.

**Interfaces:**
- Consumes: Task 1.
- Produces (db.php): `const DB_MSR_ORG_SEED`, `db_get_msr_events(PDO $pdo, ?string $club = null): array`, `db_get_msr_event(PDO, string $id): ?array`, `db_upsert_msr_event(PDO, string $club, array $e, string $now): void`, `db_set_msr_status(PDO, string $id, string $status): void`, `db_delete_msr_event(PDO, string $id): void`, `db_mark_msr_added(PDO, string $id, int $eventId, bool $primary): void`, `db_snapshot_msr_event(PDO, string $id): void`, `db_set_club_msr_org_id(PDO, string $code, string $id): void`.
- Produces (msr-lib): `msrSyncClub(PDO $pdo, array $club, callable $fetch, string $today, string $now): array{ok:bool,error:string,found:int,skipped:int}`, `msrSyncAll(PDO, callable $fetch, string $today, string $now): list<array{code,name,ok,error,found,skipped}>`, `msrSyncStatus(PDO): list<array{code,name,ok_at:string,error:string}>`, `msrPendingCount(PDO): int`.

- [ ] **Step 1: Failing test** — `tests/DbMsrTest.php`:

```php
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
}
```

- [ ] **Step 2: Run** `php phpunit.phar tests/DbMsrTest.php` → FAIL.

- [ ] **Step 3: Schema** — in `db.php`, right after the clubs seed loop in `db_init` (settings table already exists, created earlier in `db_init`):

```php
    // ── MotorsportReg calendar import (2026-09-29 spec). Added in place: no reset. ──
    db_add_column_if_missing($pdo, 'clubs', 'msr_org_id', "TEXT NOT NULL DEFAULT ''");
    if (db_get_setting($pdo, 'msr_org_ids_seeded') === null) {
        // Once only, so an admin who disconnects a club isn't reconnected on the next page load.
        $seedOrg = $pdo->prepare("UPDATE clubs SET msr_org_id = :id WHERE code = :c AND msr_org_id = ''");
        foreach (DB_MSR_ORG_SEED as $code => $orgId) $seedOrg->execute([':id' => $orgId, ':c' => $code]);
        db_set_setting($pdo, 'msr_org_ids_seeded', '1');
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS msr_events (
            msr_id         TEXT PRIMARY KEY,
            club_code      TEXT NOT NULL,
            name           TEXT NOT NULL,
            start_date     DATE NOT NULL,
            end_date       DATE NOT NULL,
            type           TEXT NOT NULL,
            venue          TEXT NOT NULL DEFAULT '',
            detail_url     TEXT NOT NULL DEFAULT '',
            cancelled      INTEGER NOT NULL DEFAULT 0,
            status         TEXT NOT NULL DEFAULT 'new',
            hub_event_id   INTEGER,
            is_primary     INTEGER NOT NULL DEFAULT 0,
            snap_name      TEXT,
            snap_start     DATE,
            snap_venue     TEXT,
            snap_cancelled INTEGER,
            first_seen_at  DATETIME NOT NULL,
            last_seen_at   DATETIME NOT NULL
        )
    ");
```

  Next to `DB_ICE_CLUB_SEED` add:

```php
/** MotorsportReg organization IDs found on the clubs' MSR pages on 2026-09-29 (seeded once). */
const DB_MSR_ORG_SEED = ['NASCC' => '2386B6E3-96BC-AE58-0812CF4B556BCBC2', 'WSCC' => '4D45EE74-0A85-F011-ACBD5982F016139D'];
```

- [ ] **Step 4: DB helpers** — in `db.php` after `db_update_club()`:

```php
function db_set_club_msr_org_id(PDO $pdo, string $code, string $orgId): void {
    $pdo->prepare("UPDATE clubs SET msr_org_id = :o WHERE code = :c")->execute([':o' => $orgId, ':c' => $code]);
}

// ── MotorsportReg calendar import ─────────────────────────────────────────────

function db_get_msr_events(PDO $pdo, ?string $club = null): array {
    if ($club === null) return $pdo->query("SELECT * FROM msr_events ORDER BY start_date, name")->fetchAll();
    $stmt = $pdo->prepare("SELECT * FROM msr_events WHERE club_code = :c ORDER BY start_date, name");
    $stmt->execute([':c' => $club]);
    return $stmt->fetchAll();
}

function db_get_msr_event(PDO $pdo, string $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM msr_events WHERE msr_id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

/** Insert a feed event, or refresh its details; status, hub link and snapshot are kept. */
function db_upsert_msr_event(PDO $pdo, string $club, array $e, string $now): void {
    $pdo->prepare("
        INSERT INTO msr_events (msr_id, club_code, name, start_date, end_date, type, venue, detail_url, cancelled, first_seen_at, last_seen_at)
        VALUES (:id, :club, :name, :start, :end, :type, :venue, :url, :cancelled, :now, :now)
        ON CONFLICT(msr_id) DO UPDATE SET club_code = excluded.club_code, name = excluded.name,
            start_date = excluded.start_date, end_date = excluded.end_date, type = excluded.type,
            venue = excluded.venue, detail_url = excluded.detail_url, cancelled = excluded.cancelled,
            last_seen_at = excluded.last_seen_at
    ")->execute([':id' => $e['msr_id'], ':club' => $club, ':name' => $e['name'], ':start' => $e['start_date'],
                 ':end' => $e['end_date'], ':type' => $e['type'], ':venue' => $e['venue'], ':url' => $e['detail_url'],
                 ':cancelled' => $e['cancelled'], ':now' => $now]);
}

function db_set_msr_status(PDO $pdo, string $id, string $status): void {
    $pdo->prepare("UPDATE msr_events SET status = :s WHERE msr_id = :id")->execute([':s' => $status, ':id' => $id]);
}

function db_delete_msr_event(PDO $pdo, string $id): void {
    $pdo->prepare("DELETE FROM msr_events WHERE msr_id = :id")->execute([':id' => $id]);
}

/** The row now belongs to hub event $eventId; its current details become the snapshot. */
function db_mark_msr_added(PDO $pdo, string $id, int $eventId, bool $primary): void {
    $pdo->prepare("UPDATE msr_events SET status = 'added', hub_event_id = :e, is_primary = :p,
        snap_name = name, snap_start = start_date, snap_venue = venue, snap_cancelled = cancelled WHERE msr_id = :id")
        ->execute([':e' => $eventId, ':p' => $primary ? 1 : 0, ':id' => $id]);
}

/** The admin has seen the current details: they become the snapshot. */
function db_snapshot_msr_event(PDO $pdo, string $id): void {
    $pdo->prepare("UPDATE msr_events SET snap_name = name, snap_start = start_date, snap_venue = venue, snap_cancelled = cancelled
        WHERE msr_id = :id")->execute([':id' => $id]);
}
```

- [ ] **Step 5: Sync** — append to `msr-lib.php`:

```php
/** Fetch one club's feed and bring msr_events up to date. A failed fetch changes nothing but the club's error. */
function msrSyncClub(PDO $pdo, array $club, callable $fetch, string $today, string $now): array {
    $code = (string)$club['code'];
    $fail = function (string $error) use ($pdo, $code): array {
        db_set_setting($pdo, 'msr_sync_error_' . $code, $error);
        return ['ok' => false, 'error' => $error, 'found' => 0, 'skipped' => 0];
    };
    $res = $fetch(msrFeedUrl((string)$club['msr_org_id']));
    if (!$res['ok']) return $fail((string)$res['error']);
    $parsed = msrParseFeed((string)$res['body']);
    if ($parsed === null) return $fail("MotorsportReg sent something the hub couldn't read.");

    $pdo->beginTransaction();
    $seen = [];
    foreach ($parsed['events'] as $e) {
        db_upsert_msr_event($pdo, $code, $e, $now);
        $seen[$e['msr_id']] = true;
    }
    foreach (db_get_msr_events($pdo, $code) as $row) {
        $id = (string)$row['msr_id'];
        if (isset($seen[$id])) {
            if ($row['status'] === 'gone') db_set_msr_status($pdo, $id, 'added');
        } elseif ((string)$row['end_date'] < $today || in_array($row['status'], ['new', 'ignored'], true)) {
            db_delete_msr_event($pdo, $id);   // finished, or never used
        } elseif ($row['status'] === 'added') {
            db_set_msr_status($pdo, $id, 'gone');
        }
    }
    $pdo->commit();
    db_set_setting($pdo, 'msr_sync_ok_' . $code, $now);
    db_set_setting($pdo, 'msr_sync_error_' . $code, '');
    return ['ok' => true, 'error' => '', 'found' => count($parsed['events']), 'skipped' => (int)$parsed['skipped']];
}

/** Sync every club that has an organization ID. */
function msrSyncAll(PDO $pdo, callable $fetch, string $today, string $now): array {
    $out = [];
    foreach (db_get_clubs($pdo) as $club) {
        if ((string)($club['msr_org_id'] ?? '') === '') continue;
        $out[] = ['code' => (string)$club['code'], 'name' => (string)$club['name']] + msrSyncClub($pdo, $club, $fetch, $today, $now);
    }
    return $out;
}

/** "Last checked" per connected club. */
function msrSyncStatus(PDO $pdo): array {
    $out = [];
    foreach (db_get_clubs($pdo) as $club) {
        if ((string)($club['msr_org_id'] ?? '') === '') continue;
        $code = (string)$club['code'];
        $out[] = ['code' => $code, 'name' => (string)$club['name'],
                  'ok_at' => (string)db_get_setting($pdo, 'msr_sync_ok_' . $code, ''),
                  'error' => (string)db_get_setting($pdo, 'msr_sync_error_' . $code, '')];
    }
    return $out;
}

/** New events plus changed ones: the Events tab badge. DB only, never calls MSR. */
function msrPendingCount(PDO $pdo): int {
    $n = 0;
    foreach ($pdo->query("SELECT * FROM msr_events WHERE status IN ('new', 'added', 'gone')")->fetchAll() as $row) {
        if ($row['status'] === 'new' || msrChanges($row)) $n++;
    }
    return $n;
}
```

  Note: the NASCC fixture has 4 race events (1 Ice Racing, 3 Club Race); `today` 2025-10-01 keeps all of them.

- [ ] **Step 6: Run** `php phpunit.phar tests/DbMsrTest.php` → PASS; then `php phpunit.phar` → all green.
- [ ] **Step 7: Commit** `git add -A wcma-calculator && git commit -m "feat(msr): store and sync club calendars"`

---

### Task 3: Review actions

**Files:** Modify `wcma-calculator/msr-lib.php` (append). Test: `wcma-calculator/tests/DbMsrActionsTest.php`.

**Interfaces (produces):** each returns `null` on success or an error message:
`msrAddToHub(PDO $pdo, string $msrId, array $f): ?string` (`$f` = validated event fields `name, date, location, discipline, club, msr_url`), `msrAttach(PDO, string $msrId, int $eventId): ?string`, `msrIgnore(PDO, string $msrId): ?string`, `msrRestore(PDO, string $msrId): ?string`, `msrApply(PDO, string $msrId): ?string`, `msrKeep(PDO, string $msrId): ?string`.

- [ ] **Step 1: Failing test** — `tests/DbMsrActionsTest.php`:

```php
<?php
// wcma-calculator/tests/DbMsrActionsTest.php
require_once __DIR__ . '/../msr-lib.php';

use PHPUnit\Framework\TestCase;

final class DbMsrActionsTest extends TestCase
{
    private const EV = 'AAAAAAAA-BBBB-CCCC-DDDDDDDDDDDDDDDD';
    private const EV2 = 'BBBBBBBB-BBBB-CCCC-DDDDDDDDDDDDDDDD';

    private function pdoWithNewRows(): PDO {
        $pdo = make_temp_pdo();
        foreach ([self::EV => 'Wkd 1 Sprint', self::EV2 => 'Wkd 1 Time Attack'] as $id => $name) {
            db_upsert_msr_event($pdo, 'NASCC', ['msr_id' => $id, 'name' => $name, 'start_date' => '2027-06-13', 'end_date' => '2027-06-14',
                'type' => 'Club Race', 'venue' => 'Rad Torque Raceway', 'detail_url' => 'https://www.motorsportreg.com/events/' . $id, 'cancelled' => 0], '2026-10-01 06:00:00');
        }
        return $pdo;
    }

    private function fields(): array {
        return ['name' => 'Summer Weekend 1', 'date' => '2027-06-13', 'location' => 'Rad Torque Raceway', 'discipline' => 'summer',
                'club' => 'NASCC', 'msr_url' => 'https://www.motorsportreg.com/events/' . self::EV];
    }

    public function testAddToHubCreatesTheEventWithItsLinkAndMarksTheRowPrimary(): void
    {
        $pdo = $this->pdoWithNewRows();
        $this->assertNull(msrAddToHub($pdo, self::EV, $this->fields()));
        $row = db_get_msr_event($pdo, self::EV);
        $event = db_get_event($pdo, (int)$row['hub_event_id']);
        $this->assertSame('Summer Weekend 1', $event['name']);
        $this->assertSame('NASCC', $event['host_club']);
        $this->assertSame('https://www.motorsportreg.com/events/' . self::EV, $event['msr_url']);
        $this->assertSame(['added', 1, 'Wkd 1 Sprint'], [$row['status'], (int)$row['is_primary'], $row['snap_name']]);
        // Twice (double click / second admin): refused, nothing duplicated.
        $this->assertSame(MSR_STALE, msrAddToHub($pdo, self::EV, $this->fields()));
        $this->assertCount(1, db_get_all_events($pdo));
    }

    public function testACancelledNewEventCanOnlyBeIgnored(): void
    {
        $pdo = $this->pdoWithNewRows();
        $pdo->exec("UPDATE msr_events SET cancelled = 1");
        $this->assertSame(MSR_STALE, msrAddToHub($pdo, self::EV, $this->fields()));
        $this->assertNull(msrIgnore($pdo, self::EV));
    }

    public function testAttachToAnExistingEvent(): void
    {
        $pdo = $this->pdoWithNewRows();
        msrAddToHub($pdo, self::EV, $this->fields());
        $hub = (int)db_get_msr_event($pdo, self::EV)['hub_event_id'];
        $this->assertNull(msrAttach($pdo, self::EV2, $hub));
        $row = db_get_msr_event($pdo, self::EV2);
        $this->assertSame([$hub, 0, 'added'], [(int)$row['hub_event_id'], (int)$row['is_primary'], $row['status']]);
        $this->assertSame(MSR_STALE, msrAttach($pdo, self::EV2, $hub));   // already attached
    }

    public function testAttachChecksTheEventExists(): void
    {
        $pdo = $this->pdoWithNewRows();
        $this->assertSame('Choose a hub event.', msrAttach($pdo, self::EV2, 999));
        $this->assertSame('new', db_get_msr_event($pdo, self::EV2)['status']);
    }

    public function testIgnoreAndRestore(): void
    {
        $pdo = $this->pdoWithNewRows();
        $this->assertNull(msrIgnore($pdo, self::EV));
        $this->assertSame(MSR_STALE, msrIgnore($pdo, self::EV));
        $this->assertNull(msrRestore($pdo, self::EV));
        $this->assertSame('new', db_get_msr_event($pdo, self::EV)['status']);
        $this->assertSame(MSR_STALE, msrRestore($pdo, self::EV));
    }

    public function testApplyUpdatesOnlyWhatChangedAndDeactivatesOnCancel(): void
    {
        $pdo = $this->pdoWithNewRows();
        msrAddToHub($pdo, self::EV, $this->fields());   // admin renamed it "Summer Weekend 1"
        $hub = (int)db_get_msr_event($pdo, self::EV)['hub_event_id'];
        $pdo->exec("UPDATE msr_events SET start_date = '2027-06-20', end_date = '2027-06-21', cancelled = 1 WHERE msr_id = '" . self::EV . "'");
        $this->assertNull(msrApply($pdo, self::EV));
        $event = db_get_event($pdo, $hub);
        $this->assertSame(['Summer Weekend 1', '2027-06-20', 0], [$event['name'], $event['event_date'], (int)$event['active']]);
        $this->assertSame([], msrChanges(db_get_msr_event($pdo, self::EV)));
        $this->assertSame(MSR_STALE, msrApply($pdo, self::EV));   // nothing left to apply
    }

    public function testAttachedRowsNeverChangeTheHubEvent(): void
    {
        $pdo = $this->pdoWithNewRows();
        msrAddToHub($pdo, self::EV, $this->fields());
        $hub = (int)db_get_msr_event($pdo, self::EV)['hub_event_id'];
        msrAttach($pdo, self::EV2, $hub);
        $pdo->exec("UPDATE msr_events SET cancelled = 1 WHERE msr_id = '" . self::EV2 . "'");
        $this->assertSame(MSR_STALE, msrApply($pdo, self::EV2));
        $this->assertSame(1, (int)db_get_event($pdo, $hub)['active']);
        $this->assertNull(msrKeep($pdo, self::EV2));
        $this->assertSame([], msrChanges(db_get_msr_event($pdo, self::EV2)));
    }

    public function testKeepOnAGoneRowForgetsIt(): void
    {
        $pdo = $this->pdoWithNewRows();
        msrAddToHub($pdo, self::EV, $this->fields());
        db_set_msr_status($pdo, self::EV, 'gone');
        $this->assertSame(MSR_STALE, msrApply($pdo, self::EV));
        $this->assertNull(msrKeep($pdo, self::EV));
        $this->assertNull(db_get_msr_event($pdo, self::EV));
        $this->assertCount(1, db_get_all_events($pdo));   // the hub event stays
    }
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement** — append to `msr-lib.php`:

```php
/** Add a new, not cancelled MSR event as a hub event. $f is already validated (adminEventFromPost()). */
function msrAddToHub(PDO $pdo, string $msrId, array $f): ?string {
    $row = db_get_msr_event($pdo, $msrId);
    if ($row === null || $row['status'] !== 'new' || (int)$row['cancelled'] === 1) return MSR_STALE;
    $pdo->beginTransaction();
    $eventId = db_create_event($pdo, (string)$f['name'], (string)$f['date'], $f['location'] !== '' ? (string)$f['location'] : null,
        (string)$f['discipline'], $f['club']);
    db_set_event_msr_url($pdo, $eventId, (string)$f['msr_url']);
    db_mark_msr_added($pdo, $msrId, $eventId, true);
    $pdo->commit();
    return null;
}

/** Attach a new MSR event to an existing hub event (another part of the same weekend). */
function msrAttach(PDO $pdo, string $msrId, int $eventId): ?string {
    $row = db_get_msr_event($pdo, $msrId);
    if ($row === null || $row['status'] !== 'new') return MSR_STALE;
    if (db_get_event($pdo, $eventId) === null) return 'Choose a hub event.';
    db_mark_msr_added($pdo, $msrId, $eventId, false);
    return null;
}

function msrIgnore(PDO $pdo, string $msrId): ?string {
    $row = db_get_msr_event($pdo, $msrId);
    if ($row === null || $row['status'] !== 'new') return MSR_STALE;
    db_set_msr_status($pdo, $msrId, 'ignored');
    return null;
}

function msrRestore(PDO $pdo, string $msrId): ?string {
    $row = db_get_msr_event($pdo, $msrId);
    if ($row === null || $row['status'] !== 'ignored') return MSR_STALE;
    db_set_msr_status($pdo, $msrId, 'new');
    return null;
}

/** Copy what changed on MSR onto the hub event it created. Only for primary, still-listed rows. */
function msrApply(PDO $pdo, string $msrId): ?string {
    $row = db_get_msr_event($pdo, $msrId);
    $changes = $row !== null ? msrChanges($row) : [];
    if ($row === null || $row['status'] !== 'added' || (int)$row['is_primary'] !== 1 || !$changes) return MSR_STALE;
    $event = db_get_event($pdo, (int)$row['hub_event_id']);
    if ($event === null) return MSR_STALE;
    $name = (string)$event['name'];
    $date = (string)$event['event_date'];
    $location = $event['location'];
    foreach ($changes as $c) {
        if ($c['field'] === 'name') $name = $c['new'];
        if ($c['field'] === 'start_date') $date = $c['new'];
        if ($c['field'] === 'venue') $location = $c['new'] !== '' ? $c['new'] : null;
        if ($c['field'] === 'cancelled') db_set_event_active($pdo, (int)$event['id'], $c['new'] !== '1');
    }
    db_update_event($pdo, (int)$event['id'], $name, $date, $location, (string)$event['discipline'], $event['host_club']);
    db_snapshot_msr_event($pdo, $msrId);
    return null;
}

/** The admin has seen the change and keeps the hub event as it is. A gone row is forgotten. */
function msrKeep(PDO $pdo, string $msrId): ?string {
    $row = db_get_msr_event($pdo, $msrId);
    if ($row === null || !msrChanges($row)) return MSR_STALE;
    if ($row['status'] === 'gone') {
        db_delete_msr_event($pdo, $msrId);
    } else {
        db_snapshot_msr_event($pdo, $msrId);
    }
    return null;
}
```

- [ ] **Step 4: Run** `php phpunit.phar tests/DbMsrActionsTest.php` → PASS; full suite green.
- [ ] **Step 5: Commit** `git add -A wcma-calculator && git commit -m "feat(msr): review actions — add, attach, ignore, restore, apply, keep"`

---

### Task 4: Daily run and club connection

**Files:** Create `wcma-calculator/msr-sync.php`. Modify `wcma-calculator/reminders-cron.sh`, `wcma-calculator/README.md` (reminders section), `wcma-calculator/admin-clubs.php`, `wcma-calculator/admin.php` (require), `wcma-calculator/tests/AdminClubsPageTest.php`, `wcma-calculator/tests/AdminSourceTest.php`.

- [ ] **Step 1: Failing tests.**
  - Add to `tests/AdminSourceTest.php`:

```php
    public function testDailyCronRunsTheMotorsportRegCheckAfterTheReminders(): void
    {
        $cron = $this->src('reminders-cron.sh');
        $this->assertLessThan(strpos($cron, 'msr-sync.php'), strpos($cron, 'reminders.php'));
        $this->assertStringContainsString('"$PHP_BIN" msr-sync.php >> data/msr-sync.log 2>&1', $cron);
        $this->assertStringNotContainsString('exec "$PHP_BIN" reminders.php', $cron);   // must not end the script
        $sync = $this->src('msr-sync.php');
        $this->assertStringContainsString("if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }", $sync);
        $this->assertStringContainsString("msrSyncAll(\$pdo, 'msrHttpGet', ", $sync);
    }

    public function testClubSaveOnlyFetchesMotorsportRegWhenTheFieldChanged(): void
    {
        $body = $this->body('admin-clubs.php', 'handleClubSave');
        $this->assertStringContainsString("msrOrgIdFromInput(\$msrInput, 'msrHttpGet')", $body);
        $this->assertLessThan(strpos($body, 'msrOrgIdFromInput('), strpos($body, '$msrInput === $currentOrgId'));
        $this->assertStringContainsString("require_once __DIR__ . '/msr-lib.php';", $this->src('admin.php'));
    }
```

  - In `tests/AdminClubsPageTest.php`, set `'msr_org_id' => '2386B6E3-96BC-AE58-0812CF4B556BCBC2'` on NASCC and `'msr_org_id' => ''` on WSCC in `clubs()`, and add:

```php
    public function testClubsShowAndEditTheirMotorsportRegCalendarConnection(): void
    {
        $html = renderClubsPageHtml($this->clubs(), 'tok');
        $this->assertStringContainsString('<th>Calendar</th>', $html);
        $this->assertStringContainsString('<td data-label="Calendar"><span class="admin-chip admin-chip--ok">Connected</span></td>', $html);
        $this->assertStringContainsString('<td data-label="Calendar">—</td>', $html);
        $nascc = substr($html, strpos($html, 'id="club-dialog-nascc"'));
        $nascc = substr($nascc, 0, strpos($nascc, '</dialog>'));
        $this->assertStringContainsString('name="msr_org" value="2386B6E3-96BC-AE58-0812CF4B556BCBC2"', $nascc);
        $this->assertStringContainsString('MotorsportReg calendar (club page address or organization ID)', $nascc);
        $this->assertStringContainsString('name="msr_org"', substr($html, strpos($html, 'id="club-dialog-new"')));
    }
```

- [ ] **Step 2: Run** → the new tests FAIL.

- [ ] **Step 3: CLI** — `msr-sync.php`:

```php
<?php
// wcma-calculator/msr-sync.php — CLI only. The daily MotorsportReg calendar check (spec
// 2026-09-29-msr-calendar-import-design.md §2). reminders-cron.sh runs it after the reminders.
//   php msr-sync.php                     check every connected club
//   php msr-sync.php --today=2026-10-04  pretend it is that day (testing)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$today = null;
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--today=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) { fwrite(STDERR, "Unknown option: $arg\n"); exit(2); }
    $today = $m[1];
}
date_default_timezone_set('America/Denver');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/msr-lib.php';

$pdo = db_connect();
db_init($pdo);
$results = msrSyncAll($pdo, 'msrHttpGet', $today ?? date('Y-m-d'), date('Y-m-d H:i:s'));
$failed = 0;
foreach ($results as $r) {
    echo date('Y-m-d H:i:s') . ' ' . $r['code'] . ': ' . ($r['ok'] ? $r['found'] . ' race events' . ($r['skipped'] ? ', ' . $r['skipped'] . ' entries skipped' : '') : 'FAILED ' . $r['error']) . "\n";
    if (!$r['ok']) $failed++;
}
if (!$results) echo date('Y-m-d H:i:s') . " no clubs are connected to MotorsportReg\n";
exit($failed > 0 ? 1 : 0);
```

- [ ] **Step 4: Cron script** — in `reminders-cron.sh`, replace the last line `exec "$PHP_BIN" reminders.php >> data/reminders.log 2>&1` with:

```sh
"$PHP_BIN" reminders.php >> data/reminders.log 2>&1
# The MotorsportReg calendar check (msr-sync.php) runs even when the reminders fail, and vice versa.
"$PHP_BIN" msr-sync.php >> data/msr-sync.log 2>&1
```

  and update the header comment's first line to `# wcma-calculator/reminders-cron.sh — the daily cron job: reminder emails, then the MotorsportReg calendar check.`

- [ ] **Step 5: README** — under "## Reminder emails (daily cron)" append:

```markdown
The same cron job then runs `msr-sync.php`, which reads each connected club's public MotorsportReg
calendar (Admin → Clubs → MotorsportReg calendar) and lists new or changed race events under
Admin → Events → From MotorsportReg. It logs to `data/msr-sync.log`; `php msr-sync.php` runs it by hand.
```

- [ ] **Step 6: Clubs** — in `admin-clubs.php`:
  - `handleClubSave`: after the existing `$v = clubValidate(...)` and the not-found / invalid / duplicate checks (before `if ($isNew) { db_create_club`), add:

```php
    $msrInput = trim((string)($_POST['msr_org'] ?? ''));
    $currentOrgId = $isNew ? '' : (string)(db_get_club($pdo, $v['code'])['msr_org_id'] ?? '');
    $orgId = $currentOrgId;
    if ($msrInput !== $currentOrgId) {   // only ask MotorsportReg when the field changed
        $org = msrOrgIdFromInput($msrInput, 'msrHttpGet');
        if (!$org['ok']) {
            setFlash($org['error'], 'error');
            adminRedirect($reopen);
        }
        $orgId = $org['id'];
    }
```

    and after both `db_create_club(...)` and `db_update_club(...)` calls add `db_set_club_msr_org_id($pdo, $v['code'], $orgId);`.
  - `renderClubsPageHtml`: header `<th>Status</th>` becomes `<th>Calendar</th><th>Status</th>`, the empty row `colspan="5"` becomes `colspan="6"`, and each row gets, before Status:

```php
            . '<td data-label="Calendar">' . ((string)($c['msr_org_id'] ?? '') !== '' ? adminChip('Connected', 'ok') : '—') . '</td>'
```

  - In both the edit and the add modal forms, before `adminDialogActions(...)`:

```php
            . adminField($id . '-msr', 'MotorsportReg calendar (club page address or organization ID)',
                '<input type="text" id="' . h($id) . '-msr" name="msr_org" value="' . h((string)($c['msr_org_id'] ?? '')) . '" placeholder="https://www.motorsportreg.com/orgs/…">', true)
            . '<p class="form-hint admin-form-wide">New race events on this club\'s MotorsportReg calendar are listed under Events → From MotorsportReg. Leave blank to turn this off.</p>'
```

    (for the add form use id `club-new-msr` and an empty value).
  - `admin.php`: add `require_once __DIR__ . '/msr-lib.php';` after the `clubs-lib.php` require.

- [ ] **Step 7: Run** `php phpunit.phar` → green. Run by hand once against a scratch copy (never the real DB): `php -d auto_prepend_file=<scratch prepend defining DB_PATH> msr-sync.php` → two lines "NASCC: N race events", "WSCC: N race events".
- [ ] **Step 8: Commit** `git add -A wcma-calculator && git commit -m "feat(msr): daily check in the cron job; clubs connect their MotorsportReg calendar"`

---

### Task 5: Events tab badge and strip; shared event-field validation

**Files:** Modify `wcma-calculator/layout.php` (`adminSubnavHtml`), `wcma-calculator/admin-ui.php` (`adminEventsBadge`, `adminEditTarget` length, `adminRenderPage`), `wcma-calculator/admin.php` (set the badge), `wcma-calculator/admin-events.php` (`adminEventFromPost`, strip). Tests: `tests/AdminUiTest.php`, `tests/AdminEventsPageTest.php`, `tests/AdminSourceTest.php`.

**Interfaces (produces):** `adminSubnavHtml(string $current, int $eventsBadge = 0): string`, `adminEventsBadge(?int $set = null): int`, `adminEventFromPost(array $post, array $clubCodes): array{ok:bool,error:string,name,date,location,discipline,club,msr_url}`, `renderEventsPageHtml(..., ?string $edit, array $msr = ['pending' => 0, 'connected' => false])`.

- [ ] **Step 1: Failing tests.**
  - `AdminUiTest`: in `testEditTargetAcceptsIdsCodesAndNewOnly` add `$this->assertSame('2386B6E3-96BC-AE58-0812CF4B556BCBC2', adminEditTarget(['edit' => '2386B6E3-96BC-AE58-0812CF4B556BCBC2']));` and `$this->assertNull(adminEditTarget(['edit' => str_repeat('a', 41)]));`. Add:

```php
    public function testTheEventsTabCountsWhatIsWaitingOnMotorsportReg(): void
    {
        $this->assertStringContainsString('>Events</a>', adminSubnavHtml('users'));
        $this->assertStringContainsString('>Events (3)</a>', adminSubnavHtml('users', 3));
        $this->assertStringContainsString('aria-current="page">Events (3)</span>', adminSubnavHtml('events', 3));
    }
```

  - `AdminEventsPageTest`, add:

```php
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
```

  - `AdminSourceTest`:
    - In `testEventFormHasDisciplineAndHostClub` replace `'iceEventFields($_POST, '` with `'iceEventFields($post, '`.
    - Add:

```php
    public function testTheEventsBadgeIsSetOnceFromTheDatabase(): void
    {
        $admin = $this->src('admin.php');
        $this->assertStringContainsString('adminEventsBadge(msrPendingCount($pdo));', $admin);
        $this->assertLessThan(strpos($admin, 'switch ($action) {'), strpos($admin, 'adminEventsBadge(msrPendingCount($pdo));'));
        $this->assertLessThan(strpos($admin, 'adminEventsBadge('), strpos($admin, "require_role('admin');"));
        $this->assertStringContainsString('adminSubnavHtml($tab, adminEventsBadge())', $this->src('admin-ui.php'));
    }
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement.**
  - `layout.php`:

```php
function adminSubnavHtml(string $current, int $eventsBadge = 0): string {
    $tabs = ADMIN_TABS;
    if ($eventsBadge > 0) $tabs['events'][1] .= ' (' . $eventsBadge . ')';   // new or changed MotorsportReg events
    return hubSubnavHtml('Admin', $tabs, $current);
}
```

  - `admin-ui.php`: `adminEditTarget` regex `{1,20}` → `{1,40}` (update its doc comment to "1–40"); add

```php
/** The Events tab's MotorsportReg count, set once per request by admin.php. */
function adminEventsBadge(?int $set = null): int {
    static $n = 0;
    if ($set !== null) $n = $set;
    return $n;
}
```

    and in `adminRenderPage` change `adminSubnavHtml($tab)` to `adminSubnavHtml($tab, adminEventsBadge())`.
  - `admin.php`: right after `require_role('admin');` add `adminEventsBadge(msrPendingCount($pdo));`.
  - `admin-events.php`: add

```php
/**
 * The add-event fields from a form, validated: name and date required, discipline/club per
 * iceEventFields(), optional https MotorsportReg link. $clubCodes are the clubs allowed as host.
 */
function adminEventFromPost(array $post, array $clubCodes): array {
    $name = trim((string)($post['name'] ?? ''));
    $date = trim((string)($post['event_date'] ?? ''));
    $out = ['ok' => false, 'error' => '', 'name' => $name, 'date' => $date, 'location' => trim((string)($post['location'] ?? '')),
            'discipline' => 'summer', 'club' => null, 'msr_url' => trim((string)($post['msr_url'] ?? ''))];
    if ($name === '' || $date === '') return ['error' => 'Event name and date are required.'] + $out;
    $fields = iceEventFields($post, $clubCodes);
    if (!$fields['ok']) return ['error' => (string)$fields['error']] + $out;
    $urlError = eventMsrUrlError($out['msr_url']);
    if ($urlError !== null) return ['error' => $urlError] + $out;
    return ['ok' => true, 'discipline' => $fields['discipline'], 'club' => $fields['club']] + $out;
}
```

    and rewrite `handleEventCreate` to use it:

```php
function handleEventCreate(PDO $pdo): void {
    $f = adminEventFromPost($_POST, array_column(db_get_clubs($pdo, true), 'code'));
    if (!$f['ok']) {
        setFlash($f['error'], 'error');
        adminRedirect('admin.php?action=events&edit=new');
    }
    $id = db_create_event($pdo, $f['name'], $f['date'], $f['location'] !== '' ? $f['location'] : null, $f['discipline'], $f['club']);
    db_set_event_msr_url($pdo, $id, $f['msr_url']);
    setFlash('Event created.', 'success');
    adminRedirect('admin.php?action=events');
}
```

  - `renderEventsPageHtml`: add the parameter `array $msr = ['pending' => 0, 'connected' => false]` and start `$out` with:

```php
    $out = '';
    if ((int)$msr['pending'] > 0) {
        $n = (int)$msr['pending'];
        $out .= '<p class="admin-msr-strip"><strong>From MotorsportReg:</strong> ' . $n . ' ' . ($n === 1 ? 'event' : 'events')
            . ' to review <a class="hub-btn hub-btn--secondary" href="admin.php?action=msr">Review</a></p>';
    } elseif (!empty($msr['connected'])) {
        $out .= '<p class="admin-msr-strip"><a class="admin-link" href="admin.php?action=msr">From MotorsportReg</a></p>';
    }
```

    then change the existing first line `$out = '<div class="admin-toolbar">' . adminAddButton(...` to `$out .= '<div class="admin-toolbar">' . adminAddButton(...` (everything after it is unchanged).

  - `handleEventsList` passes `['pending' => adminEventsBadge(), 'connected' => msrSyncStatus($pdo) !== []]`.
  - CSS (`hub.css`, admin section): `.admin-msr-strip { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; background: var(--hub-card); border-left: 4px solid var(--hub-red); padding: 12px 16px; border-radius: 8px; margin: 0 0 16px; }`

- [ ] **Step 4: Run** `php phpunit.phar` → green.
- [ ] **Step 5: Commit** `git add -A wcma-calculator && git commit -m "feat(msr): Events tab count and review strip; event fields validated in one place"`

---

### Task 6: The "From MotorsportReg" review page

**Files:** Create `wcma-calculator/admin-msr.php`, `wcma-calculator/tests/AdminMsrPageTest.php`. Modify `wcma-calculator/admin.php` (require + routes), `wcma-calculator/css/hub.css`, `wcma-calculator/tests/AdminSourceTest.php`.

**Interfaces:** Consumes Tasks 1–5. Produces `renderMsrPageHtml(array $rows, array $hubEvents, array $clubs, array $status, string $csrf, ?array $dialogFlash, ?string $edit): string`, handlers `handleMsrList`, `handleMsrAdd`, `handleMsrAttach`, `handleMsrSimple(PDO, string $action)`, `handleMsrCheck`.

- [ ] **Step 1: Failing test** — `tests/AdminMsrPageTest.php`:

```php
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

    private function status(): array {
        return [['code' => 'NASCC', 'name' => 'Northern Alberta Sports Car Club', 'ok_at' => '2026-10-01 06:00:00', 'error' => '']];
    }

    public function testANewEventOffersAddAttachAndIgnoreWithPrefilledModals(): void
    {
        $html = renderMsrPageHtml([$this->row()], $this->hub(), $this->clubs(), $this->status(), 'tok', null, null);
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
        $html = renderMsrPageHtml([$this->row(['cancelled' => 1])], $this->hub(), $this->clubs(), $this->status(), 'tok', null, null);
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
        $html = renderMsrPageHtml([$new, $primary, $attached], $this->hub(), $this->clubs(), $this->status(), 'tok', null, null);
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
        $html = renderMsrPageHtml([$this->row()], $this->hub(), $this->clubs(), $this->status(), 'tok',
            ['type' => 'error', 'message' => 'Event name and date are required.'], 'AAAAAAAA-BBBB-CCCC-DDDDDDDDDDDDDDDD');
        $this->assertMatchesRegularExpression('/id="msr-add-aaaaaaaa-bbbb-cccc-dddddddddddddddd"[^>]*data-open-on-load>.*Event name and date are required\./s', $html);
    }
}
```

  Add to `tests/AdminSourceTest.php`:

```php
    public function testMotorsportRegRoutesArePostOnlyAndReturnToTheReviewPage(): void
    {
        $admin = $this->src('admin.php');
        foreach (['msr-add', 'msr-attach', 'msr-ignore', 'msr-restore', 'msr-apply', 'msr-keep', 'msr-check'] as $route) {
            $this->assertMatchesRegularExpression("/case '$route':\\s*adminRequirePost\\('admin.php\\?action=msr'\\);/", $admin, $route);
        }
        $this->assertStringContainsString("case 'msr':", $admin);
        $this->assertStringContainsString("require_once __DIR__ . '/admin-msr.php';", $admin);
    }
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Implement** — `admin-msr.php`:

```php
<?php
// wcma-calculator/admin-msr.php
//
// Admin → Events → From MotorsportReg (spec 2026-09-29-msr-calendar-import-design.md §3): new race
// events from the clubs' MSR calendars (add / attach / ignore) and changes to ones already in the hub
// (apply / keep). Loaded by admin.php, which has already checked the admin role; every action is a
// CSRF-checked POST through adminRequirePost(). The actions themselves live in msr-lib.php.

function msrDialogKey(string $msrId): string {
    return strtolower($msrId);
}

function handleMsrList(PDO $pdo): void {
    $rows = db_get_msr_events($pdo);
    $edit = adminEditTarget($_GET);
    $place = adminFlashPlacement(getFlash(), $edit, array_column($rows, 'msr_id'));
    $body = renderMsrPageHtml($rows, db_get_all_events($pdo), db_get_clubs($pdo), msrSyncStatus($pdo), generateCsrfToken(), $place['dialog'], $edit);
    adminRenderPage('From MotorsportReg', 'events', $body, $place['top']);
}

function adminMsrIdFromPost(): string {
    $id = strtoupper(trim((string)($_POST['msr_id'] ?? '')));
    return preg_match(MSR_ID_PATTERN, $id) ? $id : '';
}

function handleMsrAdd(PDO $pdo): void {
    $id = adminMsrIdFromPost();
    $f = adminEventFromPost($_POST, array_column(db_get_clubs($pdo, true), 'code'));
    if (!$f['ok']) {
        setFlash($f['error'], 'error');
        adminRedirect('admin.php?action=msr&edit=' . rawurlencode($id));
    }
    $error = msrAddToHub($pdo, $id, $f);
    setFlash($error ?? 'Added ' . $f['name'] . ' to the hub.', $error === null ? 'success' : 'error');
    adminRedirect('admin.php?action=msr');
}

function handleMsrAttach(PDO $pdo): void {
    $error = msrAttach($pdo, adminMsrIdFromPost(), is_scalar($_POST['event_id'] ?? null) ? (int)$_POST['event_id'] : 0);
    setFlash($error ?? 'Attached to the hub event.', $error === null ? 'success' : 'error');
    adminRedirect('admin.php?action=msr');
}

/** Ignore, restore, apply or keep: one MSR id, one lib call. */
function handleMsrSimple(PDO $pdo, string $action): void {
    $fn = ['msr-ignore' => 'msrIgnore', 'msr-restore' => 'msrRestore', 'msr-apply' => 'msrApply', 'msr-keep' => 'msrKeep'][$action];
    $done = ['msr-ignore' => 'Ignored.', 'msr-restore' => 'Restored.', 'msr-apply' => 'Hub event updated.', 'msr-keep' => 'Kept as is.'][$action];
    $error = $fn($pdo, adminMsrIdFromPost());
    setFlash($error ?? $done, $error === null ? 'success' : 'error');
    adminRedirect('admin.php?action=msr');
}

function handleMsrCheck(PDO $pdo): void {
    $results = msrSyncAll($pdo, 'msrHttpGet', date('Y-m-d'), date('Y-m-d H:i:s'));
    if (!$results) {
        setFlash('No clubs are connected to MotorsportReg yet. Add a club\'s MotorsportReg page on the Clubs tab.', 'error');
    } else {
        $failed = array_values(array_filter($results, fn(array $r): bool => !$r['ok']));
        setFlash($failed ? 'Checked MotorsportReg. ' . implode(' ', array_map(fn(array $r): string => $r['code'] . ': ' . $r['error'], $failed))
            : 'Checked MotorsportReg.', $failed ? 'error' : 'success');
    }
    adminRedirect('admin.php?action=msr');
}

/** One MSR event's cells: dates, name (+ chips), club, MSR link. */
function adminMsrEventCells(array $r): string {
    $chip = $r['type'] === 'Ice Racing' ? adminChip('Ice', 'info') : adminChip('Race', 'info');
    if ((int)$r['cancelled'] === 1) $chip .= adminChip('Cancelled', 'fail');
    return '<td data-label="Date">' . h(msrDateRange((string)$r['start_date'], (string)$r['end_date'])) . '</td>'
        . '<td data-label="Event"><strong>' . h((string)$r['name']) . '</strong> <span class="admin-chips">' . $chip . '</span>'
        . ((string)$r['venue'] !== '' ? '<span class="admin-sub">' . h((string)$r['venue']) . '</span>' : '')
        . ((string)$r['detail_url'] !== '' ? '<a class="admin-link" href="' . h((string)$r['detail_url']) . '" target="_blank" rel="noopener">Open on MotorsportReg ↗</a>' : '')
        . '</td><td data-label="Club">' . h((string)$r['club_code']) . '</td>';
}

function adminMsrPostForm(string $action, string $msrId, string $csrf, string $button, string $class = 'btn btn-secondary'): string {
    return '<form method="post" action="admin.php?action=' . h($action) . '">' . adminCsrfField($csrf)
        . '<input type="hidden" name="msr_id" value="' . h($msrId) . '"><button type="submit" class="' . h($class) . '">' . h($button) . '</button></form>';
}

/** The review page body. Pure. */
function renderMsrPageHtml(array $rows, array $hubEvents, array $clubs, array $status, string $csrf, ?array $dialogFlash, ?string $edit): string {
    $byId = [];
    foreach ($hubEvents as $e) $byId[(int)$e['id']] = $e;
    $changed = $new = $ignored = [];
    foreach ($rows as $r) {
        if ($r['status'] === 'new') $new[] = $r;
        elseif ($r['status'] === 'ignored') $ignored[] = $r;
        elseif (msrChanges($r)) $changed[] = $r;
    }
    $out = '<p><a class="hub-back-link" href="admin.php?action=events">&larr; Back to events</a></p>'
        . '<p class="admin-intro">Race events from the clubs\' MotorsportReg calendars. Add them to the hub, attach extra events to a '
        . 'weekend that\'s already in the hub, or ignore them. Drivers see nothing here until you add it.</p>';
    $dialogs = '';

    if ($changed) {
        $out .= '<h2>Changed on MotorsportReg</h2><table class="data-table admin-table" id="msr-changed"><thead><tr>'
            . '<th>Date</th><th>Event</th><th>Club</th><th>Hub event</th><th>What changed</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
        foreach ($changed as $r) {
            $changes = msrChanges($r);
            $hub = $byId[(int)$r['hub_event_id']] ?? null;
            $list = '<ul class="admin-msr-changes">';
            foreach ($changes as $c) $list .= '<li>' . h(msrChangeText($c)) . '</li>';
            $list .= '</ul>';
            if ((int)$r['is_primary'] !== 1) {
                $list .= '<p class="form-hint">One of several MotorsportReg events for this hub event — change the hub event by hand if needed.</p>';
            }
            $actions = '';
            if ((int)$r['is_primary'] === 1 && $r['status'] !== 'gone') {
                $cancels = in_array(['field' => 'cancelled', 'old' => '0', 'new' => '1'], $changes, true);
                $actions .= adminMsrPostForm('msr-apply', (string)$r['msr_id'], $csrf, $cancels ? 'Deactivate hub event' : 'Apply to hub event', 'btn btn-primary');
            }
            $actions .= adminMsrPostForm('msr-keep', (string)$r['msr_id'], $csrf, 'Keep as is');
            $out .= '<tr>' . adminMsrEventCells($r)
                . '<td data-label="Hub event">' . h($hub !== null ? (string)$hub['name'] : '—') . '</td>'
                . '<td data-label="What changed">' . $list . '</td>'
                . '<td class="admin-cell-actions"><div class="admin-row-actions">' . $actions . '</div></td></tr>';
        }
        $out .= '</tbody></table>';
    }

    if ($new) {
        $out .= '<h2>New on MotorsportReg</h2><table class="data-table admin-table" id="msr-new"><thead><tr>'
            . '<th>Date</th><th>Event</th><th>Club</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
        foreach ($new as $r) {
            $id = (string)$r['msr_id'];
            $key = msrDialogKey($id);
            $actions = '';
            if ((int)$r['cancelled'] !== 1) {
                $actions .= '<button type="button" class="btn btn-primary admin-edit" data-dialog-open="msr-add-' . h($key) . '">Add to hub</button>'
                    . '<button type="button" class="btn btn-secondary admin-edit" data-dialog-open="msr-attach-' . h($key) . '">Add to an existing event</button>';
                $prefill = ['name' => $r['name'], 'event_date' => $r['start_date'], 'location' => $r['venue'],
                            'discipline' => $r['type'] === 'Ice Racing' ? 'ice' : 'summer', 'host_club' => $r['club_code'], 'msr_url' => $r['detail_url']];
                $flash = $edit === $id ? $dialogFlash : null;
                $dialogs .= adminDialogHtml('msr-add-' . $key, 'Add to the hub',
                    '<form method="post" action="admin.php?action=msr-add" class="admin-form">' . adminCsrfField($csrf)
                    . '<input type="hidden" name="msr_id" value="' . h($id) . '">'
                    . adminEventFieldsHtml('msr-' . $key, $prefill, $clubs) . adminDialogActions('Add event') . '</form>', $flash, (string)$r['name']);
                $suggest = msrSuggestEvent($r, $hubEvents);
                $options = '<option value="">Choose an event</option>';
                foreach ($hubEvents as $e) {
                    if ((int)$e['active'] !== 1) continue;
                    $options .= '<option value="' . (int)$e['id'] . '"' . ((int)$e['id'] === $suggest ? ' selected' : '') . '>'
                        . h(date('M j, Y', strtotime((string)$e['event_date'])) . ' — ' . $e['name']) . '</option>';
                }
                $dialogs .= adminDialogHtml('msr-attach-' . $key, 'Add to an existing event',
                    '<form method="post" action="admin.php?action=msr-attach" class="admin-form">' . adminCsrfField($csrf)
                    . '<input type="hidden" name="msr_id" value="' . h($id) . '">'
                    . adminField('msr-attach-' . $key . '-event', 'Hub event', '<select id="msr-attach-' . h($key) . '-event" name="event_id" required>' . $options . '</select>', true)
                    . '<p class="form-hint admin-form-wide">For another part of a weekend that is already in the hub (e.g. its Time Attack or Enduro).</p>'
                    . adminDialogActions('Attach') . '</form>', null, (string)$r['name']);
            }
            $actions .= adminMsrPostForm('msr-ignore', $id, $csrf, 'Ignore', 'link-button');
            $out .= '<tr>' . adminMsrEventCells($r) . '<td class="admin-cell-actions"><div class="admin-row-actions">' . $actions . '</div></td></tr>';
        }
        $out .= '</tbody></table>';
    }

    if (!$changed && !$new) $out .= '<p>Nothing new on MotorsportReg.</p>';

    if ($ignored) {
        $out .= '<details class="admin-msr-ignored"><summary>' . count($ignored) . ' ignored</summary><ul>';
        foreach ($ignored as $r) {
            $out .= '<li>' . h(msrDateRange((string)$r['start_date'], (string)$r['end_date']) . ' — ' . $r['name'] . ' (' . $r['club_code'] . ')')
                . adminMsrPostForm('msr-restore', (string)$r['msr_id'], $csrf, 'Restore', 'link-button') . '</li>';
        }
        $out .= '</ul></details>';
    }

    $out .= '<section class="detail-card"><h2>Last checked</h2><ul class="admin-msr-status">';
    foreach ($status as $s) {
        $when = $s['ok_at'] !== '' ? date('M j, g:i a', strtotime($s['ok_at'])) : 'never';
        $out .= '<li><strong>' . h($s['name']) . ':</strong> '
            . h($s['error'] !== '' ? 'Failed: ' . $s['error'] . ' (last success ' . $when . ')' : $when) . '</li>';
    }
    if (!$status) $out .= '<li>No clubs are connected yet. Add a club\'s MotorsportReg page on the <a href="admin.php?action=clubs">Clubs</a> tab.</li>';
    $out .= '</ul><form method="post" action="admin.php?action=msr-check">' . adminCsrfField($csrf)
        . '<button type="submit" class="btn btn-secondary" data-loading-text="Checking…">Check now</button></form>'
        . '<p class="form-hint">Event data from MotorsportReg.com.</p></section>';

    return $out . '<div class="admin-dialogs">' . $dialogs . '</div>';
}
```

  `admin.php`: `require_once __DIR__ . '/admin-msr.php';` after the `admin-events.php` require, and routes before `default:`:

```php
    case 'msr':
        handleMsrList($pdo);
        break;

    case 'msr-add':
        adminRequirePost('admin.php?action=msr');
        handleMsrAdd($pdo);
        break;

    case 'msr-attach':
        adminRequirePost('admin.php?action=msr');
        handleMsrAttach($pdo);
        break;

    case 'msr-ignore':
        adminRequirePost('admin.php?action=msr');
        handleMsrSimple($pdo, $action);
        break;

    case 'msr-restore':
        adminRequirePost('admin.php?action=msr');
        handleMsrSimple($pdo, $action);
        break;

    case 'msr-apply':
        adminRequirePost('admin.php?action=msr');
        handleMsrSimple($pdo, $action);
        break;

    case 'msr-keep':
        adminRequirePost('admin.php?action=msr');
        handleMsrSimple($pdo, $action);
        break;

    case 'msr-check':
        adminRequirePost('admin.php?action=msr');
        handleMsrCheck($pdo);
        break;
```

  CSS (`hub.css`, admin section):

```css
.admin-msr-changes { margin: 0; padding-left: 18px; font-size: 16px; }
.admin-msr-status { padding-left: 18px; }
.admin-msr-ignored { margin: 16px 0; }
.admin-msr-ignored li { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; }
```

- [ ] **Step 4: Run** `php phpunit.phar` → green.
- [ ] **Step 5: Look at it** — scratch copy (seeded DB, admin prepend, see the admin UX plan's shots script), run `php msr-sync.php` there (real feeds), open `admin.php?action=events` and `admin.php?action=msr` at 1440 and 390, open an Add and an Attach modal. Check: strip and badge visible; rows readable; modal pre-filled; no sideways scroll.
- [ ] **Step 6: Commit** `git add -A wcma-calculator && git commit -m "feat(msr): From MotorsportReg review page"`

---

### Task 7: Audit and final check

**Files:** Modify `wcma-calculator/tests/ux/audit.mjs`.

- [ ] **Step 1:** In the admin section of `audit.mjs`, add `'msr'` to the tab loop (`for (const tab of ['users', 'events', 'clubs', 'season-links', 'settings', 'msr'])`). The audit's seeded DB has no MSR rows, so this checks the empty page, footer and Check now button (the audit must not call MSR: do not click Check now).
- [ ] **Step 2:** `bash wcma-calculator/tests/ux/run-audit.sh` → `All pages pass`. Full `php phpunit.phar`, `node --test tests/js/*.test.js` green. `git grep -n "api.motorsportreg.com" -- wcma-calculator/tests` → only fixture/URL-string assertions, no live calls.
- [ ] **Step 3: Commit** `git add wcma-calculator/tests/ux/audit.mjs && git commit -m "test(ux): phone audit covers the From MotorsportReg page"`
- [ ] **Step 4:** Final whole-branch review; then superpowers:finishing-a-development-branch. Tell the owner the cron needs no change if it already runs `reminders-cron.sh` (the check is appended to that script); if the cron isn't set up yet, point it at `reminders-cron.sh` per README.
