<?php
// wcma-calculator/inspection-lib.php
//
// Pure(ish) logic for inspection photos: validation, storage, authorisation.
// No session, headers or HTTP here — inspection.php injects those — so it is
// unit-testable (same pattern as feedback-lib.php). Callers must have loaded
// db.php and photo-requirements.php.

const INSPECTION_MAX_BYTES = 2 * 1024 * 1024;
const INSPECTION_MAX_EDGE = 4000;

/**
 * Which requirement scope each subject type stores photos for.
 * Adding a key here REQUIRES a matching loader branch in inspectionLoadSubject()
 * (inspection.php); without one the endpoint returns 404 for that type.
 */
const INSPECTION_SUBJECT_SCOPE = ['tech_sheet' => 'car'];

/**
 * Checks an uploaded file really is a JPEG/PNG/WebP of acceptable size and
 * dimensions. Sniffs the bytes (never trusts the client's MIME or filename).
 *
 * @return array{ok: bool, error: ?string, mime: ?string, ext: ?string}
 */
function inspectionValidateImage(string $path): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'mime' => null, 'ext' => null];

    if (!is_file($path)) return $fail('No photo received.');
    $size = filesize($path);
    if ($size === false || $size === 0) return $fail('The photo is empty.');
    if ($size > INSPECTION_MAX_BYTES) return $fail('The photo is too large (2 MB maximum).');

    $info = @getimagesize($path);
    if ($info === false) return $fail('That file is not a photo we can read.');

    $extByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = $info['mime'] ?? '';
    if (!isset($extByMime[$mime])) return $fail('Photos must be JPEG, PNG or WebP.');

    if ($info[0] > INSPECTION_MAX_EDGE || $info[1] > INSPECTION_MAX_EDGE) {
        return $fail('The photo dimensions are too large.');
    }

    return ['ok' => true, 'error' => null, 'mime' => $mime, 'ext' => $extByMime[$mime]];
}

/** Path (relative to the app directory) where a photo is stored. */
function inspectionPhotoRelativePath(string $subjectType, int $subjectId, string $requirementKey, string $ext): string {
    return 'uploads/inspection/' . $subjectType . '/' . $subjectId . '/' . $requirementKey . '.' . $ext;
}

/**
 * Owners may read their own sheet's photos. They may write until the sheet is accepted
 * ('teched') or its photo set is under/after review (photo_status 'submitted' or 'accepted'),
 * when it locks. Admins may always read and write.
 */
function inspectionCanAccess(array $user, array $sheet, bool $forWrite): bool {
    if (($user['role'] ?? '') === 'admin') return true;
    if ((int)$user['id'] !== (int)$sheet['user_id']) return false;
    if (!$forWrite) return true;
    return ($sheet['status'] ?? '') !== 'teched'
        && !in_array($sheet['photo_status'] ?? null, ['submitted', 'accepted'], true);
}

/**
 * Validates and stores one photo, replacing any previous photo for the same
 * requirement (a retake). $mover defaults to move_uploaded_file; tests pass
 * 'rename'.
 *
 * @return array{ok: bool, error: ?string, photo: ?array}
 */
function inspectionSavePhoto(
    PDO $pdo, string $baseDir, string $subjectType, int $subjectId, string $requirementKey,
    string $tmpPath, array $typedInput, ?callable $mover = null
): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'photo' => null];

    if (!isset(INSPECTION_SUBJECT_SCOPE[$subjectType])) return $fail('Unknown photo subject.');
    $requirement = photoRequirementByKey($requirementKey);
    if ($requirement === null || $requirement['scope'] !== INSPECTION_SUBJECT_SCOPE[$subjectType]) {
        return $fail('Unknown photo type.');
    }

    $typed = photoValidateTypedValue($requirement, $typedInput);
    if ($typed === null) return $fail('One of the details entered for this photo is not valid.');

    $image = inspectionValidateImage($tmpPath);
    if (!$image['ok']) return $fail($image['error']);

    $relative = inspectionPhotoRelativePath($subjectType, $subjectId, $requirementKey, $image['ext']);
    $absolute = $baseDir . '/' . $relative;
    $dir = dirname($absolute);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return $fail('Could not store the photo.');

    $priorPath = (db_get_inspection_photos($pdo, $subjectType, $subjectId)[$requirementKey] ?? null)['file_path'] ?? null;

    $mover = $mover ?? 'move_uploaded_file';
    if (!$mover($tmpPath, $absolute)) return $fail('Could not store the photo.');

    try {
        $previous = db_upsert_inspection_photo($pdo, [
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'requirement_key' => $requirementKey,
            'requirement_version' => PHOTO_REQUIREMENTS_VERSION, 'file_path' => $relative,
            'typed_value' => $typed ? json_encode($typed) : null,
        ]);
    } catch (Throwable $e) {
        // Don't leave an orphan file; if a row already pointed at this same path, the file is still referenced.
        if ($priorPath !== $relative && is_file($absolute)) unlink($absolute);
        throw $e;
    }
    if ($previous !== null && $previous !== '' && $previous !== $relative && is_file($baseDir . '/' . $previous)) {
        unlink($baseDir . '/' . $previous);
    }

    if ($subjectType === 'tech_sheet') db_mark_tech_sheet_photos_draft($pdo, $subjectId);

    $stored = db_get_inspection_photos($pdo, $subjectType, $subjectId)[$requirementKey];
    return ['ok' => true, 'error' => null, 'photo' => $stored];
}

/** Removes a photo's file and row. False if the photo does not exist. */
function inspectionDeletePhoto(PDO $pdo, string $baseDir, int $photoId): bool {
    $photo = db_get_inspection_photo($pdo, $photoId);
    if ($photo === null) return false;
    $abs = $baseDir . '/' . $photo['file_path'];
    if ($photo['file_path'] !== '' && is_file($abs)) unlink($abs);
    db_delete_inspection_photo($pdo, $photoId);
    return true;
}

/** The shape sent to the browser: no server file path, plus the authenticated URL. */
function inspectionPublicPhoto(array $row): array {
    return [
        'id' => (int)$row['id'],
        'requirement_key' => $row['requirement_key'],
        'typed' => $row['typed_value'] !== null && $row['typed_value'] !== '' ? (json_decode($row['typed_value'], true) ?: []) : [],
        'review_status' => $row['review_status'],
        'reviewer_note' => $row['reviewer_note'] ?? null,
        'url' => 'inspection.php?action=photo&id=' . (int)$row['id'],
    ];
}

/**
 * Marks a conditional photo as applying to the car or not. Turning it off removes any photo already
 * uploaded for it (file and row); turning it on creates an empty placeholder and puts a tech sheet's
 * photo set into 'draft'.
 *
 * @return array{ok: bool, error: ?string}
 */
function inspectionSetApplies(PDO $pdo, string $baseDir, string $subjectType, int $subjectId, string $requirementKey, bool $applies): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg];

    if (!isset(INSPECTION_SUBJECT_SCOPE[$subjectType])) return $fail('Unknown photo subject.');
    $requirement = photoRequirementByKey($requirementKey);
    if ($requirement === null || $requirement['scope'] !== INSPECTION_SUBJECT_SCOPE[$subjectType]) {
        return $fail('Unknown photo type.');
    }
    if ($requirement['tier'] !== 'conditional') return $fail('That photo is not optional.');

    $previous = db_set_conditional_photo_applies($pdo, $subjectType, $subjectId, $requirementKey, PHOTO_REQUIREMENTS_VERSION, $applies);
    if ($previous !== null && $previous !== '' && is_file($baseDir . '/' . $previous)) {
        unlink($baseDir . '/' . $previous);
    }
    if ($applies && $subjectType === 'tech_sheet') db_mark_tech_sheet_photos_draft($pdo, $subjectId);

    return ['ok' => true, 'error' => null];
}

/**
 * Edits the typed details (dates, standards) of an existing photo without re-uploading it.
 *
 * @return array{ok: bool, error: ?string, photo?: array}
 */
function inspectionUpdateTyped(PDO $pdo, int $photoId, array $typedInput): array {
    $photo = db_get_inspection_photo($pdo, $photoId);
    if ($photo === null) return ['ok' => false, 'error' => 'Photo not found.'];
    if ($photo['file_path'] === '') return ['ok' => false, 'error' => 'Add the photo first, then its details.'];

    $requirement = photoRequirementByKey($photo['requirement_key']);
    $typed = $requirement === null ? null : photoValidateTypedValue($requirement, $typedInput);
    if ($typed === null) return ['ok' => false, 'error' => 'One of the details entered for this photo is not valid.'];

    db_update_inspection_photo_typed($pdo, $photoId, $typed ? json_encode($typed) : null);
    return ['ok' => true, 'error' => null, 'photo' => inspectionPublicPhoto(db_get_inspection_photo($pdo, $photoId))];
}
