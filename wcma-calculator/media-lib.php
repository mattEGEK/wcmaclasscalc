<?php
// wcma-calculator/media-lib.php
//
// Pure rules for driver media profiles (spec 2026-09-27): consent, validation, public review
// status, where a profile may be used, which drivers a roster car shows, and the media kit's
// text and CSV. No DB, session or echo. Callers must have loaded roles.php (mediaCanAccess()).

const MEDIA_CONSENT_WORDING_VERSION = 1;
const MEDIA_CONSENT_MEDIA_TEXT = 'WCMA and its affiliated clubs may use this profile, photo and sponsors for event announcing and club promotion.';
const MEDIA_CONSENT_PUBLIC_TEXT = 'Also show it on a public web page anyone can view.';
const MEDIA_CONSENT_MINOR_TEXT = 'This driver is under 18. I am their parent or guardian and I give this consent for them.';
const MEDIA_ON_BEHALF_TEXT = 'I confirm %s agreed to the above.';
const MEDIA_BLURB_MAX = 500;
const MEDIA_MAX_SPONSORS = 6;

/** @return array{media: bool, public: bool} */
function mediaCurrentConsent(?array $row): array {
    $media = $row !== null && (int)$row['consent_media'] === 1;
    return ['media' => $media, 'public' => $media && (int)$row['consent_public'] === 1];
}

function mediaHasContent(?array $profile): bool {
    return $profile !== null && ((string)($profile['photo_path'] ?? '') !== '' || trim((string)($profile['blurb'] ?? '')) !== '');
}

/** 'club' = announcer sheet and media kit; 'public' = the public page. */
function mediaUsable(?array $profile, ?array $consentRow, string $output): bool {
    if ($profile === null || !empty($profile['hidden_at'])) return false;
    $c = mediaCurrentConsent($consentRow);
    if (!$c['media'] || !mediaHasContent($profile)) return false;
    return $output === 'public' ? ($c['public'] && ($profile['public_status'] ?? '') === 'accepted') : true;
}

function mediaPhotoAllowed(?array $user, array $driver, ?array $profile, ?array $consent): bool {
    if ($profile === null || (string)($profile['photo_path'] ?? '') === '') return false;
    if ($user !== null && (mediaCanAccess($user) || (int)$driver['owner_user_id'] === (int)$user['id'])) return true;
    return mediaUsable($profile, $consent, 'public');
}

function mediaOneLine(mixed $v): string {
    return is_string($v) ? trim((string)preg_replace('/\s+/u', ' ', $v)) : '';
}

/** @return array{ok: bool, error: ?string, consent: array} */
function mediaConsentInput(array $post, bool $isSelf, bool $needsConfirm = true): array {
    $media = ($post['consent_media'] ?? '') === '1';
    $minor = ($post['is_minor'] ?? '') === '1';
    $guardian = mediaOneLine($post['guardian_name'] ?? '');
    $consent = [
        'consent_media' => $media ? 1 : 0,
        'consent_public' => $media && ($post['consent_public'] ?? '') === '1' ? 1 : 0,
        'is_minor' => $minor ? 1 : 0,
        'guardian_name' => $minor && $guardian !== '' ? $guardian : null,
        'on_behalf' => $isSelf ? 0 : 1,
    ];
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'consent' => $consent];
    if ($media && $minor && $guardian === '') return $fail("Enter the parent or guardian's name.");
    if (mb_strlen($guardian, 'UTF-8') > 100) return $fail("The parent or guardian's name is too long (100 characters at most).");
    if ($media && !$isSelf && $needsConfirm && ($post['on_behalf_confirm'] ?? '') !== '1') return $fail('Confirm that this driver agreed, or untick the consent box.');
    return ['ok' => true, 'error' => null, 'consent' => $consent];
}

function mediaConsentChanged(?array $latest, array $consent): bool {
    if ($latest === null) return (int)$consent['consent_media'] === 1;
    foreach (['consent_media', 'consent_public', 'is_minor'] as $k) {
        if ((int)$latest[$k] !== (int)$consent[$k]) return true;
    }
    return ($latest['guardian_name'] ?? null) !== ($consent['guardian_name'] ?? null);
}

/** @return array{errors: string[], fields: array, sponsors: array} */
function mediaValidateFields(array $post, int $currentYear): array {
    $errors = [];
    $blurb = is_string($post['blurb'] ?? null) ? trim(str_replace("\r\n", "\n", $post['blurb'])) : '';
    if (mb_strlen($blurb, 'UTF-8') > MEDIA_BLURB_MAX) $errors[] = 'Keep the blurb to 500 characters or fewer.';

    $short = [];
    foreach (['pronunciation' => 'Pronunciation', 'hometown' => 'Hometown', 'social_handle' => 'Social handle'] as $k => $label) {
        $v = mediaOneLine($post[$k] ?? '');
        if ($k === 'social_handle') $v = ltrim($v, '@');
        if (mb_strlen($v, 'UTF-8') > 60) $errors[] = $label . ' is too long (60 characters at most).';
        $short[$k] = $v === '' ? null : $v;
    }

    $since = mediaOneLine($post['racing_since'] ?? '');
    $sinceInt = null;
    if ($since !== '') {
        if (!ctype_digit($since) || (int)$since < 1950 || (int)$since > $currentYear) {
            $errors[] = 'Racing since must be a year from 1950 to ' . $currentYear . '.';
        } else {
            $sinceInt = (int)$since;
        }
    }

    $names = is_array($post['sponsor_name'] ?? null) ? array_values($post['sponsor_name']) : [];
    $urls = is_array($post['sponsor_url'] ?? null) ? array_values($post['sponsor_url']) : [];
    $sponsors = [];
    $count = max(count($names), count($urls));
    $filled = 0;
    for ($i = 0; $i < $count; $i++) {
        $name = mediaOneLine($names[$i] ?? '');
        $url = mediaOneLine($urls[$i] ?? '');
        if ($name === '' && $url === '') continue;
        $filled++;
        $n = $i + 1;
        if ($name === '') { $errors[] = "Sponsor $n needs a name as well as a website."; continue; }
        if (mb_strlen($name, 'UTF-8') > 80) { $errors[] = "Sponsor $n name is too long (80 characters at most)."; continue; }
        if ($url !== '') {
            if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) $url = 'https://' . $url;
            if (!preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                $errors[] = "Sponsor $n website must start with http:// or https://."; continue;
            }
            if (strlen($url) > 200) { $errors[] = "Sponsor $n website is too long (200 characters at most)."; continue; }
        }
        $sponsors[] = ['name' => $name, 'url' => $url === '' ? null : $url];
    }
    if ($filled > MEDIA_MAX_SPONSORS) $errors = ['Add at most 6 sponsors.'];

    return ['errors' => $errors, 'sponsors' => $sponsors,
            'fields' => ['blurb' => $blurb, 'pronunciation' => $short['pronunciation'], 'hometown' => $short['hometown'],
                         'racing_since' => $sinceInt, 'social_handle' => $short['social_handle']]];
}

function mediaContentChanged(?array $profile, array $sponsorsBefore, array $fields, array $sponsors, bool $photoReplaced): bool {
    if ($photoReplaced || $profile === null) return true;
    foreach (['blurb', 'pronunciation', 'hometown', 'racing_since', 'social_handle'] as $k) {
        if ((string)($profile[$k] ?? '') !== (string)($fields[$k] ?? '')) return true;
    }
    $pair = fn(array $s): array => [(string)$s['name'], (string)($s['url'] ?? '')];
    return array_map($pair, $sponsorsBefore) !== array_map($pair, $sponsors);
}

function mediaNextPublicStatus(string $current, bool $wantsPublic, bool $contentChanged): string {
    if (!$wantsPublic) return 'none';
    if ($current === 'none') return 'pending_review';
    if ($contentChanged && in_array($current, ['accepted', 'sent_back'], true)) return 'pending_review';
    return $current;
}

/** @return array{state: string, label: string, class: string} */
function mediaProfileStatus(?array $profile, ?array $consent): array {
    $s = fn(string $state, string $label, string $class): array => ['state' => $state, 'label' => $label, 'class' => $class];
    if ($profile !== null && !empty($profile['hidden_at'])) return $s('hidden', 'Hidden by WCMA', 'hub-status--todo');
    $c = mediaCurrentConsent($consent);
    if (!$c['media'] || !mediaHasContent($profile)) return $s('none', 'Not set up', 'hub-status--warn');
    if (!$c['public']) return $s('shared', 'Shared with clubs', 'hub-status--ok');
    switch ($profile['public_status'] ?? 'none') {
        case 'accepted':  return $s('accepted', 'Public page live', 'hub-status--ok');
        case 'sent_back': return $s('sent_back', 'Public page sent back', 'hub-status--todo');
        default:          return $s('pending_review', 'Public page: with media staff', 'hub-status--info');
    }
}

/**
 * What the Home media card shows, using the same states as the Drivers page. Any managed driver
 * whose public page was sent back or who was hidden by WCMA comes first ('attention', no dismiss);
 * otherwise the one-time invitation when $inviteEligible; otherwise nothing.
 *
 * @param array $drivers driver rows (id, name) the account manages
 * @param array $bundles driver id => db_get_media_bundle() entry
 * @return ?array{kind: string, items?: array}
 */
function mediaHomePrompt(bool $inviteEligible, array $drivers, array $bundles): ?array {
    $items = [];
    foreach ($drivers as $d) {
        $b = $bundles[(int)$d['id']] ?? null;
        if ($b === null) continue;
        $state = mediaProfileStatus($b['profile'], $b['consent'])['state'];
        if ($state !== 'sent_back' && $state !== 'hidden') continue;
        $note = $state === 'hidden' ? ($b['profile']['hidden_reason'] ?? '') : ($b['profile']['public_note'] ?? '');
        $items[] = ['driverId' => (int)$d['id'], 'name' => (string)$d['name'], 'state' => $state, 'note' => (string)$note];
    }
    if ($items) return ['kind' => 'attention', 'items' => $items];
    return $inviteEligible ? ['kind' => 'invite'] : null;
}

/**
 * The drivers to show for one roster car: this event's sheets for the car, else the car's latest
 * sheet, else the car owner's own profile. Sheets carry driver 1 in driver_id; $sheetDrivers maps
 * sheet id => tech_sheet_drivers rows (additional drivers). @return int[]
 */
function mediaRosterDriverIds(array $carEventSheets, ?array $latestSheet, array $sheetDrivers, ?int $ownerSelfDriverId): array {
    $fromSheets = function (array $sheets) use ($sheetDrivers): array {
        $ids = [];
        foreach ($sheets as $sheet) {
            if (!empty($sheet['driver_id'])) $ids[] = (int)$sheet['driver_id'];
            foreach ($sheetDrivers[(int)$sheet['id']] ?? [] as $d) {
                if (!empty($d['driver_id'])) $ids[] = (int)$d['driver_id'];
            }
        }
        return array_values(array_unique($ids));
    };
    $ids = $fromSheets($carEventSheets);
    if (!$ids && $latestSheet !== null) $ids = $fromSheets([$latestSheet]);
    if (!$ids && $ownerSelfDriverId !== null) $ids = [$ownerSelfDriverId];
    return $ids;
}

function mediaAcceptedClass(array $declarationsNewestFirst): string {
    foreach ($declarationsNewestFirst as $d) {
        if (($d['review_status'] ?? '') === 'accepted') return (string)$d['calculated_class'];
    }
    return '';
}

function mediaCarLabel(array $c): string {
    $parts = array_filter([
        (string)($c['year'] ?? ''), (string)($c['make'] ?? $c['car_make'] ?? ''), (string)($c['model'] ?? $c['car_model'] ?? ''),
    ], fn(string $p): bool => trim($p) !== '');
    $label = implode(' ', array_map('trim', $parts));
    $colour = trim((string)($c['colour'] ?? $c['car_colour'] ?? ''));
    return $colour !== '' && $label !== '' ? "$label ($colour)" : $label;
}

function mediaEntry(array $driver, array $profile, array $sponsors, string $number, string $car, string $class, bool $publicLive): array {
    return [
        'driver_id' => (int)$driver['id'], 'name' => (string)$driver['name'],
        'pronunciation' => $profile['pronunciation'] ?? null, 'hometown' => $profile['hometown'] ?? null,
        'racing_since' => isset($profile['racing_since']) ? (int)$profile['racing_since'] : null,
        'blurb' => (string)($profile['blurb'] ?? ''), 'social_handle' => $profile['social_handle'] ?? null,
        'sponsors' => array_map(fn(array $s): array => ['name' => (string)$s['name'], 'url' => $s['url'] ?? null], $sponsors),
        'has_photo' => (string)($profile['photo_path'] ?? '') !== '',
        'number' => $number, 'car' => $car, 'class' => $class, 'public_live' => $publicLive,
    ];
}

function mediaFactsLine(array $e): string {
    return implode(' · ', array_filter([(string)($e['hometown'] ?? ''), $e['racing_since'] ? 'Racing since ' . $e['racing_since'] : '']));
}

function mediaCopyText(array $e): string {
    $head = ($e['number'] !== '' ? '#' . $e['number'] . ' ' : '') . $e['name'] . ($e['car'] !== '' ? ' — ' . $e['car'] : '');
    $lines = [$head, mediaFactsLine($e), trim($e['blurb'])];
    if ($e['sponsors']) $lines[] = 'Supported by: ' . implode(', ', array_column($e['sponsors'], 'name'));
    return implode("\n", array_filter($lines, fn(string $l): bool => $l !== ''));
}

function mediaSlug(string $s): string {
    // Apply accent map first (for Windows iconv compatibility)
    $normalized = strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','à'=>'a','ç'=>'c','ö'=>'o','ü'=>'u','ñ'=>'n']);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii === false ? $normalized : $ascii)), '-');
    return $slug === '' ? 'driver' : $slug;
}

/** Guards against CSV formula injection: a cell a spreadsheet would treat as a formula gets a leading
 *  apostrophe so it opens as plain text instead of executing. */
function mediaCsvEscapeCell(string $v): string {
    return preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : $v;
}

function mediaCsvRows(array $entries, string $baseUrl): array {
    $rows = [['number', 'name', 'pronunciation', 'hometown', 'racing_since', 'car', 'class', 'blurb', 'sponsors', 'social_handle', 'public_url']];
    foreach ($entries as $e) {
        $sponsors = implode('; ', array_map(fn(array $s): string => $s['name'] . ($s['url'] ? ' (' . $s['url'] . ')' : ''), $e['sponsors']));
        $row = [$e['number'], $e['name'], (string)$e['pronunciation'], (string)$e['hometown'], (string)($e['racing_since'] ?? ''),
                $e['car'], $e['class'], $e['blurb'], $sponsors, (string)$e['social_handle'],
                $e['public_live'] ? rtrim($baseUrl, '/') . '/driver.php?id=' . $e['driver_id'] : ''];
        $rows[] = array_map('mediaCsvEscapeCell', $row);
    }
    return $rows;
}

/** Active events for the announcer/kit picker: upcoming ($event_date >= $today) ascending first,
 *  then recent past descending. Pure; callers pass today's date so it can be tested deterministically. */
function mediaPickerEvents(array $events, string $today): array {
    $active = array_values(array_filter($events, fn(array $e): bool => (int)($e['active'] ?? 0) === 1));
    $upcoming = array_values(array_filter($active, fn(array $e): bool => (string)$e['event_date'] >= $today));
    $past = array_values(array_filter($active, fn(array $e): bool => (string)$e['event_date'] < $today));
    usort($upcoming, fn(array $a, array $b): int => (string)$a['event_date'] <=> (string)$b['event_date']);
    usort($past, fn(array $a, array $b): int => (string)$b['event_date'] <=> (string)$a['event_date']);
    return array_merge($upcoming, $past);
}
