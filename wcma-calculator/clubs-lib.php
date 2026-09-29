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
 * or for an ice event without a row, the ice rules name. Null when the event has no club.
 * @return ?array{name: string, url: string}
 */
function clubForEvent(?array $clubRow, ?array $event): ?array {
    if ($clubRow !== null) {
        $url = (string)($clubRow['msr_url'] ?? '');
        return ['name' => (string)$clubRow['name'], 'url' => clubUrlOk($url) ? $url : ''];
    }
    $code = (string)($event['host_club'] ?? '');
    if ($code === '' || ($event['discipline'] ?? 'summer') !== 'ice') return null;
    $label = iceClubLabel($code);
    return $label !== null ? ['name' => $label, 'url' => ''] : null;
}
