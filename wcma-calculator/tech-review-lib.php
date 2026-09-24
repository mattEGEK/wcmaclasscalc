<?php
// wcma-calculator/tech-review-lib.php
//
// Inspector review actions on a tech sheet. Session-free (the admin handler injects the
// reviewer id and base directory) so it is unit-testable. Callers must have loaded db.php
// and tech-sheet-files.php.

/**
 * Accept a submitted sheet in person: the inspector's canvas signature is required.
 * Order: validate signature -> atomic DB update -> write the file. A second inspector who
 * loses the race is refused before any file is written, so the winner's signature stays intact.
 *
 * @return array{ok: bool, error: ?string}
 */
function techReviewAcceptInPerson(PDO $pdo, string $baseDir, int $sheetId, int $reviewerUserId, string $signatureDataUrl): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg];

    $sheet = db_get_tech_sheet($pdo, $sheetId);
    if ($sheet === null) return $fail('Tech sheet not found.');
    if ($sheet['status'] === 'teched') return $fail('This sheet has already been accepted.');

    $binary = techSheetDecodeSignature($signatureDataUrl);
    if ($binary === null) return $fail('A tech representative signature is required.');

    $relative = techSheetSignatureRelativePath($sheetId, 'tech');
    if (!db_accept_tech_sheet_in_person($pdo, $sheetId, $reviewerUserId, $relative)) {
        return $fail('This sheet has already been accepted.');
    }

    if (techSheetWriteSignature($baseDir, $sheetId, 'tech', $binary) === null) {
        db_revoke_tech_sheet_acceptance($pdo, $sheetId);
        return $fail('Could not save the signature. Please try again.');
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Undo an acceptance (for example an inspector accepted the wrong car): the sheet returns to
 * 'submitted' and its inspector signature is removed.
 *
 * @return array{ok: bool, error: ?string}
 */
function techReviewRevoke(PDO $pdo, string $baseDir, int $sheetId): array {
    $sheet = db_get_tech_sheet($pdo, $sheetId);
    if ($sheet === null) return ['ok' => false, 'error' => 'Tech sheet not found.'];

    $signaturePath = $sheet['tech_signature_path'] ?? null;
    if (!db_revoke_tech_sheet_acceptance($pdo, $sheetId)) {
        return ['ok' => false, 'error' => 'This sheet has not been accepted.'];
    }
    techSheetDeleteSignature($baseDir, $signaturePath);
    return ['ok' => true, 'error' => null];
}
