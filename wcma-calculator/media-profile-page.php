<?php
// wcma-calculator/media-profile-page.php
//
// Markup for a driver's media profile form. Pure: no DB, no session, no echo. Callers must have
// loaded view_helpers.php (h()) and media-lib.php.

function mediaFormValue(array $vm, string $key): string {
    if ($vm['input'] !== null) return is_string($vm['input'][$key] ?? null) ? $vm['input'][$key] : '';
    return (string)($vm['profile'][$key] ?? '');
}

function mediaFormChecked(array $vm, string $key): bool {
    if ($vm['input'] !== null) return ($vm['input'][$key] ?? '') === '1';
    $c = $vm['consent'];
    if ($c === null) return false;
    return match ($key) {
        'consent_media' => (int)$c['consent_media'] === 1,
        'consent_public' => (int)$c['consent_media'] === 1 && (int)$c['consent_public'] === 1,
        'is_minor' => (int)$c['is_minor'] === 1,
        default => false,
    };
}

function mediaTextField(array $vm, string $key, string $label, string $hint, int $max, string $type = 'text'): string {
    return '<label for="mp-' . $key . '">' . h($label) . '</label>'
        . ($hint !== '' ? '<p class="form-hint" id="mp-' . $key . '-hint">' . h($hint) . '</p>' : '')
        . '<input type="' . $type . '" id="mp-' . $key . '" name="' . $key . '" maxlength="' . $max . '" value="' . h(mediaFormValue($vm, $key)) . '"'
        . ($hint !== '' ? ' aria-describedby="mp-' . $key . '-hint"' : '') . '>';
}

function renderMediaProfileHtml(array $vm): string {
    $d = $vm['driver'];
    $id = (int)$d['id'];
    $name = (string)$d['name'];
    $csrf = h((string)$vm['csrf']);
    $p = $vm['profile'];
    $status = $vm['status'];
    $hidden = fn(string $n, string $v): string => '<input type="hidden" name="' . $n . '" value="' . h($v) . '">';

    $out = '<p class="hub-back"><a class="hub-back-link" href="drivers.php">&larr; Back to Drivers</a></p>'
        . '<h1>Media profile: ' . h($name) . '</h1>'
        . '<p class="hub-intro">Clubs use this for announcing at events and for promotion. You choose whether it also goes on a public web page.</p>'
        . '<p>Status: <span class="hub-status ' . h($status['class']) . '">' . h($status['label']) . '</span>';
    if ($status['state'] === 'accepted') $out .= ' <a href="driver.php?id=' . $id . '">View public page</a>';
    $out .= '</p>';
    if ($status['state'] === 'sent_back' && (string)($p['public_note'] ?? '') !== '') {
        $out .= '<div class="hub-card media-note"><strong>WCMA media staff sent your public page back:</strong> ' . h((string)$p['public_note']) . '</div>';
    }
    if ($status['state'] === 'hidden') {
        $out .= '<div class="hub-card media-note"><strong>Hidden by WCMA.</strong> ' . h((string)($p['hidden_reason'] ?? ''))
            . ' It is not used anywhere until WCMA media staff unhide it.</div>';
    }
    if ($vm['errors']) {
        $out .= '<div class="form-messages show error" role="alert"><ul>';
        foreach ($vm['errors'] as $e) $out .= '<li>' . h($e) . '</li>';
        $out .= '</ul></div>';
    }

    $out .= '<form method="post" action="media-profile.php?driver_id=' . $id . '" enctype="multipart/form-data" class="hub-card media-form" id="media-form">'
        . $hidden('csrf_token', (string)$vm['csrf']) . $hidden('action', 'save') . $hidden('driver_id', (string)$id)
        . '<h2>Photo</h2>'
        . '<img class="media-photo-preview" id="mp-preview" alt="' . h($name) . '"'
        . ((string)($p['photo_path'] ?? '') !== '' ? ' src="media-photo.php?driver_id=' . $id . '"' : ' hidden') . '>'
        . '<label for="mp-photo">' . ((string)($p['photo_path'] ?? '') !== '' ? 'Replace photo' : 'Add a photo') . '</label>'
        . '<p class="form-hint" id="mp-photo-hint">A clear head-and-shoulders shot or a photo of you with the car. JPEG, PNG or WebP.</p>'
        . '<input type="file" id="mp-photo" name="photo" accept="image/jpeg,image/png,image/webp" aria-describedby="mp-photo-hint">'
        . '<h2>About you</h2>'
        . '<label for="mp-blurb">Blurb</label>'
        . '<p class="form-hint" id="mp-blurb-hint">Written so an announcer can read it out. Up to 500 characters.</p>'
        . '<textarea id="mp-blurb" name="blurb" rows="5" maxlength="500" aria-describedby="mp-blurb-hint mp-blurb-count">' . h(mediaFormValue($vm, 'blurb')) . '</textarea>'
        . '<p class="form-hint" id="mp-blurb-count" aria-live="polite"></p>'
        . mediaTextField($vm, 'pronunciation', 'How to say your name (optional)', 'For example "Sin-field".', 60)
        . mediaTextField($vm, 'hometown', 'Hometown (optional)', '', 60)
        . mediaTextField($vm, 'racing_since', 'Racing since (optional)', 'A year, like 2015.', 4)
        . mediaTextField($vm, 'social_handle', 'Social media handle (optional)', 'Shown on the public page and in the media kit.', 60)
        . '<h2>Sponsors (optional)</h2><p class="form-hint">Up to ' . MEDIA_MAX_SPONSORS . '. The website is optional.</p><div class="media-sponsors" id="mp-sponsors">';
    $sponsors = $vm['sponsors'];
    for ($i = 0; $i < MEDIA_MAX_SPONSORS; $i++) {
        $nameVal = $vm['input'] !== null ? (string)(($vm['input']['sponsor_name'] ?? [])[$i] ?? '') : (string)($sponsors[$i]['name'] ?? '');
        $urlVal = $vm['input'] !== null ? (string)(($vm['input']['sponsor_url'] ?? [])[$i] ?? '') : (string)($sponsors[$i]['url'] ?? '');
        $n = $i + 1;
        $out .= '<div class="media-sponsor-row">'
            . '<label for="mp-sn-' . $n . '">Sponsor ' . $n . '</label><input type="text" id="mp-sn-' . $n . '" name="sponsor_name[]" maxlength="80" value="' . h($nameVal) . '">'
            . '<label for="mp-su-' . $n . '">Website</label><input type="text" id="mp-su-' . $n . '" name="sponsor_url[]" maxlength="200" inputmode="url" value="' . h($urlVal) . '">'
            . '</div>';
    }
    $check = fn(string $n, string $text, bool $on, string $extra = ''): string => '<label class="media-check"><input type="checkbox" name="' . $n . '" value="1"'
        . ($on ? ' checked' : '') . $extra . '> ' . h($text) . '</label>';
    $out .= '</div><p><button type="button" class="hub-btn hub-btn--secondary" id="mp-add-sponsor" hidden>Add another sponsor</button></p><h2>Consent</h2>'
        . $check('consent_media', MEDIA_CONSENT_MEDIA_TEXT, mediaFormChecked($vm, 'consent_media'))
        . $check('consent_public', MEDIA_CONSENT_PUBLIC_TEXT, mediaFormChecked($vm, 'consent_public'))
        . '<p class="form-hint" id="mp-public-note">Reviewed by WCMA media staff before it appears.</p>'
        . $check('is_minor', MEDIA_CONSENT_MINOR_TEXT, mediaFormChecked($vm, 'is_minor'))
        . '<div id="mp-guardian"><label for="mp-guardian_name">Parent or guardian name</label>'
        . '<input type="text" id="mp-guardian_name" name="guardian_name" maxlength="100" value="' . h($vm['input'] !== null ? mediaFormValue($vm, 'guardian_name') : (string)($vm['consent']['guardian_name'] ?? '')) . '"></div>';
    if (!$vm['isSelf']) {
        $out .= $check('on_behalf_confirm', sprintf(MEDIA_ON_BEHALF_TEXT, $name), false);
    }
    $out .= '<p class="form-hint">Without the first box ticked, your profile is saved but not used anywhere.</p>'
        . '<button type="submit" class="hub-btn">Save profile</button></form>';

    if (mediaCurrentConsent($vm['consent'])['media']) {
        $out .= '<form method="post" action="media-profile.php?driver_id=' . $id . '" class="hub-line" data-confirm="Withdraw consent? Your profile stops being used straight away.">'
            . $hidden('csrf_token', (string)$vm['csrf']) . $hidden('action', 'withdraw') . $hidden('driver_id', (string)$id)
            . '<button type="submit" class="hub-btn hub-btn--secondary">Withdraw consent</button></form>';
    }
    if ($p !== null) {
        $out .= '<form method="post" action="media-profile.php?driver_id=' . $id . '" class="hub-line" data-confirm="Delete this media profile and its photo? This can\'t be undone.">'
            . $hidden('csrf_token', (string)$vm['csrf']) . $hidden('action', 'delete') . $hidden('driver_id', (string)$id)
            . '<button type="submit" class="hub-btn hub-btn--link">Delete profile</button></form>';
    }
    return $out;
}
