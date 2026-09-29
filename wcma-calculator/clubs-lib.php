<?php
// wcma-calculator/clubs-lib.php
//
// Host clubs (clubs spec 2026-09-29 §1): validation and how an event's club is shown. Pure.
// Callers must have loaded ice-rules.php (iceClubLabel()) for clubForEvent().

const CLUB_URL_ERROR = 'Enter the MotorsportReg link as a full address starting with https://, or leave it blank.';

/** @return array{ok: bool, error: ?string, code: string, name: string, url: string} */
function clubValidate(string $code, string $name, string $url): array {
    $code = strtoupper(trim($code));
    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    $url = trim($url);
    $out = ['ok' => false, 'error' => null, 'code' => $code, 'name' => $name, 'url' => $url];
    if (!preg_match('/^[A-Z0-9-]{2,12}$/', $code)) return ['error' => 'Enter a short code of 2 to 12 letters, numbers or dashes.'] + $out;
    if ($name === '' || mb_strlen($name, 'UTF-8') > 120) return ['error' => 'Enter the club name (120 characters at most).'] + $out;
    if ($url !== '' && !clubUrlOk($url)) return ['error' => CLUB_URL_ERROR] + $out;
    return ['ok' => true] + $out;
}

function clubUrlOk(string $url): bool {
    return filter_var($url, FILTER_VALIDATE_URL) !== false && strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https';
}

/**
 * The club to name on an event's register step: its row (active or not — the event already uses it),
 * or for an ice event without a row, the ice rules name. The url is the event's own MotorsportReg
 * link when set, else the club's page. An event with a link but no club gets name ''. Null when
 * there is neither a club nor a link.
 * @return ?array{name: string, url: string}
 */
function clubForEvent(?array $clubRow, ?array $event): ?array {
    // The event's own MotorsportReg page beats the club's general one.
    $eventUrl = eventRegisterUrl($event);
    if ($clubRow !== null) {
        $url = (string)($clubRow['msr_url'] ?? '');
        return ['name' => (string)$clubRow['name'], 'url' => $eventUrl !== '' ? $eventUrl : (clubUrlOk($url) ? $url : '')];
    }
    $code = (string)($event['host_club'] ?? '');
    $label = $code !== '' && ($event['discipline'] ?? 'summer') === 'ice' ? iceClubLabel($code) : null;
    if ($label !== null) return ['name' => $label, 'url' => $eventUrl];
    return $eventUrl !== '' ? ['name' => '', 'url' => $eventUrl] : null;
}

const EVENT_MSR_URL_ERROR = 'Enter the MotorsportReg event link as a full address starting with https://, or leave it blank.';

/** The event's own MotorsportReg link when it is a valid https address, else ''. */
function eventRegisterUrl(?array $event): string {
    $url = (string)($event['msr_url'] ?? '');
    return $url !== '' && clubUrlOk($url) ? $url : '';
}

/** Why an event's MotorsportReg link can't be saved, or null ('' is fine: no link). */
function eventMsrUrlError(string $url): ?string {
    return $url === '' || clubUrlOk($url) ? null : EVENT_MSR_URL_ERROR;
}

/**
 * The host club options for one event's row picker (spec 2026-09-29 §1): active clubs (ice events:
 * only clubs with ice rules), plus the event's current club even when it is inactive, so saving the
 * row never changes or clears it by accident. Summer events also get "No host club".
 * @return list<array{code: string, label: string, selected: bool}>
 */
function eventClubOptions(array $clubs, array $event, array $iceCodes): array {
    $isIce = ($event['discipline'] ?? 'summer') === 'ice';
    $current = (string)($event['host_club'] ?? '');
    $out = $isIce ? [] : [['code' => '', 'label' => 'No host club', 'selected' => $current === '']];
    foreach ($clubs as $c) {
        $code = (string)$c['code'];
        $active = (int)$c['active'] === 1;
        if ($isIce && !in_array($code, $iceCodes, true)) continue;
        if (!$active && $code !== $current) continue;
        $out[] = ['code' => $code, 'label' => $code . ' — ' . $c['name'] . ($active ? '' : ' (inactive)'), 'selected' => $code === $current];
    }
    return $out;
}
