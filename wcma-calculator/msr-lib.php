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
