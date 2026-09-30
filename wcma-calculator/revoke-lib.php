<?php
// wcma-calculator/revoke-lib.php
//
// Why an inspector revoked an acceptance (TA/Drift spec §3): a required note, stored on the tech sheet
// or gear record and shown to its owner until it is accepted again. revokeNoticeHtml() needs h()
// (view_helpers.php) loaded by the caller.

const REVOKE_NOTE_MAX = 500;
const REVOKE_NOTE_REQUIRED = 'Say why you are revoking this acceptance. The owner sees this note.';

/** The note trimmed, with whitespace collapsed and capped at REVOKE_NOTE_MAX characters; null when missing or blank. */
function revokeNoteClean(mixed $note): ?string {
    if (!is_string($note)) return null;
    $note = trim((string)preg_replace('/\s+/u', ' ', $note));
    return $note === '' ? null : mb_substr($note, 0, REVOKE_NOTE_MAX, 'UTF-8');
}

/** The owner's notice ("Tech revoked: …" / "Gear revoked: …"), or '' when there is no note. */
function revokeNoticeHtml(?string $note, string $what): string {
    $note = trim((string)$note);
    if ($note === '') return '';
    return '<div class="form-messages show error" role="status"><strong>' . h($what) . ' revoked:</strong> ' . h($note) . '</div>';
}
