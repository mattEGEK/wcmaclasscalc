# Pre-Tech Phase 2b: Photo Pre-Tech Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a competitor optionally pre-tech their car by submitting the photo set for one of their tech sheets, let an inspector accept it remotely or send individual photos back for a retake, and email everyone at each step.

**Architecture:** Phase 1 already stores photos (`inspection_photos`, `inspection.php`, browser resize/upload) and phase 2a already derives a car's annual status and lets an inspector accept in person. This phase adds the workflow on top: DB transitions for `tech_sheets.photo_status` (`draft` → `submitted` → `needs_changes` → back to `submitted` → `accepted`), a session-free workflow library (`pretech-lib.php`), pure email renderers plus a notifier with an injectable send function (`pretech-email.php`), a competitor "Get pre-teched" page, and a photo-review card on the existing admin review page. Photos attach to one existing tech sheet through a separate page (not inside the big sheet form), so photo uploads never depend on the form's signature-required submit.

**Tech Stack:** PHP 8.3, SQLite via PDO, PHPUnit (`phpunit.phar`), vanilla JS (classic scripts, dual-mode where tested with `node --test`), PHPMailer (existing), Playwright for the end-to-end check.

**Spec:** `docs/superpowers/specs/2026-09-23-digital-tech-inspection-design.md` (implements the *Competitor Flow* photo section, *Inspector Flow* pre-tech review and *Emails*; phase 3 gear records are out of scope).

## Global Constraints

- **Terminology (binding):** UI and email copy uses "reviewed", "accepted", "teched", "pre-teched"; never "approved", "passed" or "safe". The only place "safe" may appear is the verbatim disclaimer constant `TECH_ACCEPTANCE_DISCLAIMER` (*"Acceptance confirms that what you submitted matches what was reviewed. It is not a certification that the vehicle or equipment is safe."*), which the accepted email must include.
- **Photo statuses on `tech_sheets.photo_status`:** `NULL` (no photos) → `draft` (first photo activity) → `submitted` (competitor submitted a complete set) → `needs_changes` (inspector sent photos back) → `submitted` (resubmitted) → `accepted`. Derived car status already maps `needs_changes`/`submitted`/`draft` (phase 2a); `accepted` without `status = 'teched'` is treated as `pending_review` defensively.
- **Completeness:** a set can be submitted only if every `required` car photo is present plus every `conditional` photo the competitor marked as applying (a row with `applies = 1`); `recommended` never counts. A photo counts as present only when its row has a non-empty `file_path`.
- **Competitor write lock:** photo uploads, typed-value edits and "applies" toggles by the sheet owner are refused once the sheet is `teched` OR `photo_status` is `submitted` or `accepted`. Admins may always read and write.
- **One photo set per car per year:** it lives on one sheet. If the car is already accepted for the season the pre-tech page says so; if another sheet of the same car identity already holds photos, the page points to that sheet.
- **Send-back rule:** an inspector must flag at least one photo and give every flagged photo a note (max 500 characters); flagged photos get `review_status = 'retake'`; a resubmission is refused while any photo is still flagged `retake`. Replacing a photo resets its review status to `pending` (phase 1 behaviour).
- **Remote acceptance:** sets `status = 'teched'`, `accepted_via = 'photos'`, `photo_status = 'accepted'`, `reviewed_by_user_id`, `reviewed_at`; no signature is captured; the rendered sheet's tech-signature slot reads "Accepted remotely (photos reviewed)". Revoking a photo-accepted sheet returns it to `status = 'submitted'`, `photo_status = 'submitted'` (back in the review queue).
- **Emails (branded like the tech sheet emails, logo via `cid:wcma-logo`; never embed the photos):** *submitted* → club + competitor; *sent back* → competitor (lists each flagged photo with its note and links to the pre-tech page); *accepted* → competitor + club (must contain the disclaimer and tell the competitor to collect their decals). An email failure never blocks or rolls back the workflow step; it is reported in the flash message.
- **Mail dry-run:** if the PHP constant `WCMA_MAIL_LOG` is defined, `emailSmtpSend()` appends each message as a JSON line to that file and sends nothing over SMTP (used by the end-to-end run so it can never email real addresses).
- **Roster:** the admin roster gains a filter `pending_review` labelled "Photos awaiting review".
- **Repo conventions:** LF-authored PHP with a `// wcma-calculator/<file>` header comment; session-free libraries like `inspection-lib.php`; tests in `wcma-calculator/tests/*Test.php`; migrations idempotent; commit after each task with a subject line, a blank line, then the trailer; run PHP tests from `wcma-calculator/` with `php phpunit.phar` and JS tests with `node --test "tests/js/*.test.js"` (the glob must be quoted).

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `wcma-calculator/db.php` | modify | Photo-status transitions, remote acceptance, applies/typed/review helpers; revoke resets `accepted` photo status |
| `wcma-calculator/tech-status.php` | modify | `accepted` photo status counts as pending; `pending_review` roster filter |
| `wcma-calculator/pretech-lib.php` | create | Snapshot/completeness, submit, accept, send back, page mode |
| `wcma-calculator/inspection-lib.php` | modify | Competitor write lock on photo status; mark draft on upload; applies + typed actions |
| `wcma-calculator/inspection.php` | modify | New `applies` and `typed` actions |
| `wcma-calculator/js/photo-upload.js` | modify | Client `applies()` and `typed()` |
| `wcma-calculator/js/pretech-progress.js` | create | Pure missing-photo computation (dual-mode) |
| `wcma-calculator/js/pretech-form.js` | create | Competitor page behaviour |
| `wcma-calculator/pretech-email.php` | create | Email renderers and the notifier |
| `wcma-calculator/email-helpers.php` | modify | `emailSmtpSend()` with mail dry-run |
| `wcma-calculator/pretech-page.php` | create | Competitor pre-tech page markup |
| `wcma-calculator/tech-sheets.php` | modify | Routes/handlers for the pre-tech page and submit; link from the sheet view |
| `wcma-calculator/admin-tech-sheets.php` | modify | Photo review card, accept/send-back handlers, new filter |
| `wcma-calculator/admin.php` | modify | Requires and routes |
| `wcma-calculator/tech-sheet-render.php` | modify | Remote-acceptance signature slot |
| `wcma-calculator/css/calculator.css` | modify | Small pre-tech page styles |
| `wcma-calculator/tests/*` | create/modify | Tests per task |

---

### Task 1: Photo workflow database functions

**Files:**
- Modify: `wcma-calculator/db.php` (edit `db_revoke_tech_sheet_acceptance`; append new functions)
- Test: `wcma-calculator/tests/DbPretechTest.php`

**Interfaces:**
- Consumes: `make_temp_pdo()`, existing `db_insert_tech_sheet()`, `db_upsert_inspection_photo()`, `db_get_inspection_photos()`, `db_get_inspection_photo()`, `db_accept_tech_sheet_in_person()`.
- Produces:
  - `db_mark_tech_sheet_photos_draft(PDO $pdo, int $id): void` — `NULL` → `draft`; no-op otherwise or if the sheet is teched.
  - `db_transition_tech_sheet_photo_status(PDO $pdo, int $id, array $from, string $to): bool` — atomic; true only if the sheet is not teched and its current `photo_status` was in `$from`.
  - `db_accept_tech_sheet_by_photos(PDO $pdo, int $id, int $reviewerUserId): bool` — only from `status = 'submitted'` AND `photo_status = 'submitted'`.
  - `db_set_conditional_photo_applies(PDO $pdo, string $subjectType, int $subjectId, string $requirementKey, int $requirementVersion, bool $applies): ?string` — `true` inserts a placeholder row (`file_path = ''`, `applies = 1`) if none exists; `false` deletes the row; returns the previous `file_path` (for the caller to delete the file) or `null`.
  - `db_update_inspection_photo_typed(PDO $pdo, int $photoId, ?string $typedJson): void` — sets `typed_value` and resets review to `pending`, clearing the note.
  - `db_set_inspection_photo_review(PDO $pdo, int $photoId, string $status, ?string $note): void`
  - `db_set_all_photos_review_status(PDO $pdo, string $subjectType, int $subjectId, string $status): void` — only rows that have a file.
  - Behaviour change: `db_revoke_tech_sheet_acceptance` also turns `photo_status = 'accepted'` into `'submitted'`.

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/DbPretechTest.php`:

```php
<?php
// wcma-calculator/tests/DbPretechTest.php
use PHPUnit\Framework\TestCase;

final class DbPretechTest extends TestCase
{
    private function fixture(PDO $pdo): array {
        $userId = db_create_user($pdo, ['email' => 'racer@example.com', 'name' => 'Racer', 'password_hash' => 'x', 'google_id' => null]);
        $adminId = db_create_user($pdo, ['email' => 'tech@example.com', 'name' => 'Tech', 'password_hash' => 'x', 'google_id' => null]);
        $subId = db_insert_submission($pdo, [
            ':submitted_at' => date('Y-m-d H:i:s'), ':name' => 'Racer', ':email' => 'racer@example.com',
            ':year' => '2020', ':make' => 'Mazda', ':model' => 'MX-5', ':comments' => null,
            ':competition_weight' => 2200, ':declared_hp' => 150, ':dyno_hp' => null,
            ':chassis_display' => null, ':body_mods_display' => null, ':transmission_display' => null,
            ':drivetrain_display' => null, ':tires_display' => null, ':brake_suspension' => null,
            ':chassis_value' => 0, ':body_mods_value' => 0, ':transmission_value' => 0,
            ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
            ':weight_factor' => 0, ':modification_factor' => 0, ':base_ratio' => 14.67, ':modified_ratio' => 14.67,
            ':calculated_class' => 'IT1', ':user_id' => $userId,
        ]);
        $eventId = db_create_event($pdo, 'Spring Sprint', '2026-05-10', null);
        $sheetId = db_insert_tech_sheet($pdo, [
            'submission_id' => $subId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'standard',
            'entrant_name' => 'Racer', 'driver_name' => 'Racer', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => '42', 'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150',
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ]);
        return [$userId, $adminId, $sheetId];
    }

    private function photoStatus(PDO $pdo, int $id): ?string {
        return db_get_tech_sheet($pdo, $id)['photo_status'];
    }

    private function photo(PDO $pdo, int $sheetId, string $key = 'front_34', string $path = 'uploads/x.jpg'): int {
        db_upsert_inspection_photo($pdo, [
            'subject_type' => 'tech_sheet', 'subject_id' => $sheetId, 'requirement_key' => $key,
            'requirement_version' => 1, 'file_path' => $path, 'typed_value' => null,
        ]);
        return (int)db_get_inspection_photos($pdo, 'tech_sheet', $sheetId)[$key]['id'];
    }

    public function testMarkDraftOnlyFromNullAndNotWhenTeched(): void
    {
        $pdo = make_temp_pdo();
        [$u, $admin, $id] = $this->fixture($pdo);

        $this->assertNull($this->photoStatus($pdo, $id));
        db_mark_tech_sheet_photos_draft($pdo, $id);
        $this->assertSame('draft', $this->photoStatus($pdo, $id));

        db_transition_tech_sheet_photo_status($pdo, $id, ['draft'], 'submitted');
        db_mark_tech_sheet_photos_draft($pdo, $id);
        $this->assertSame('submitted', $this->photoStatus($pdo, $id));   // not reset

        $pdo2 = make_temp_pdo();
        [, $admin2, $sheet2] = $this->fixture($pdo2);
        db_accept_tech_sheet_in_person($pdo2, $sheet2, $admin2, 'sig.png');
        db_mark_tech_sheet_photos_draft($pdo2, $sheet2);
        $this->assertNull($this->photoStatus($pdo2, $sheet2));
    }

    public function testTransitionIsAtomicAndChecksSourceState(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);

        $this->assertFalse(db_transition_tech_sheet_photo_status($pdo, $id, ['draft'], 'submitted'));   // still NULL
        db_mark_tech_sheet_photos_draft($pdo, $id);
        $this->assertTrue(db_transition_tech_sheet_photo_status($pdo, $id, ['draft', 'needs_changes'], 'submitted'));
        $this->assertSame('submitted', $this->photoStatus($pdo, $id));
        $this->assertFalse(db_transition_tech_sheet_photo_status($pdo, $id, ['draft', 'needs_changes'], 'submitted'));
        $this->assertTrue(db_transition_tech_sheet_photo_status($pdo, $id, ['submitted'], 'needs_changes'));
        $this->assertSame('needs_changes', $this->photoStatus($pdo, $id));
    }

    public function testAcceptByPhotosOnlyFromSubmittedOnce(): void
    {
        $pdo = make_temp_pdo();
        [, $admin, $id] = $this->fixture($pdo);

        $this->assertFalse(db_accept_tech_sheet_by_photos($pdo, $id, $admin));   // no photo set yet
        db_mark_tech_sheet_photos_draft($pdo, $id);
        $this->assertFalse(db_accept_tech_sheet_by_photos($pdo, $id, $admin));   // draft is not reviewable
        db_transition_tech_sheet_photo_status($pdo, $id, ['draft'], 'submitted');

        $this->assertTrue(db_accept_tech_sheet_by_photos($pdo, $id, $admin));
        $row = db_get_tech_sheet($pdo, $id);
        $this->assertSame('teched', $row['status']);
        $this->assertSame('photos', $row['accepted_via']);
        $this->assertSame('accepted', $row['photo_status']);
        $this->assertSame($admin, (int)$row['reviewed_by_user_id']);
        $this->assertNotNull($row['reviewed_at']);
        $this->assertNull($row['tech_signature_path']);

        $this->assertFalse(db_accept_tech_sheet_by_photos($pdo, $id, $admin));
        $this->assertFalse(db_transition_tech_sheet_photo_status($pdo, $id, ['accepted'], 'submitted'));   // teched sheets never transition
    }

    public function testRevokeOfPhotoAcceptedSheetReturnsItToTheReviewQueue(): void
    {
        $pdo = make_temp_pdo();
        [, $admin, $id] = $this->fixture($pdo);
        db_mark_tech_sheet_photos_draft($pdo, $id);
        db_transition_tech_sheet_photo_status($pdo, $id, ['draft'], 'submitted');
        db_accept_tech_sheet_by_photos($pdo, $id, $admin);

        $this->assertTrue(db_revoke_tech_sheet_acceptance($pdo, $id));

        $row = db_get_tech_sheet($pdo, $id);
        $this->assertSame('submitted', $row['status']);
        $this->assertSame('submitted', $row['photo_status']);
        $this->assertNull($row['accepted_via']);
    }

    public function testRevokeLeavesOtherPhotoStatusesAlone(): void
    {
        $pdo = make_temp_pdo();
        [, $admin, $id] = $this->fixture($pdo);
        db_mark_tech_sheet_photos_draft($pdo, $id);
        db_accept_tech_sheet_in_person($pdo, $id, $admin, 'sig.png');

        $this->assertTrue(db_revoke_tech_sheet_acceptance($pdo, $id));
        $this->assertSame('draft', $this->photoStatus($pdo, $id));
    }

    public function testConditionalAppliesPlaceholderAndRemoval(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);

        $this->assertNull(db_set_conditional_photo_applies($pdo, 'tech_sheet', $id, 'ballast', 1, true));
        $rows = db_get_inspection_photos($pdo, 'tech_sheet', $id);
        $this->assertSame('', $rows['ballast']['file_path']);
        $this->assertSame(1, (int)$rows['ballast']['applies']);

        $this->assertNull(db_set_conditional_photo_applies($pdo, 'tech_sheet', $id, 'ballast', 1, true));   // idempotent
        $this->assertCount(1, db_get_inspection_photos($pdo, 'tech_sheet', $id));

        $this->assertNull(db_set_conditional_photo_applies($pdo, 'tech_sheet', $id, 'ballast', 1, false));  // nothing to return: placeholder had no file
        $this->assertSame([], db_get_inspection_photos($pdo, 'tech_sheet', $id));
    }

    public function testTurningOffAppliesReturnsThePreviousFilePath(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);
        $this->photo($pdo, $id, 'aero', 'uploads/inspection/tech_sheet/1/aero.jpg');

        $this->assertSame('uploads/inspection/tech_sheet/1/aero.jpg', db_set_conditional_photo_applies($pdo, 'tech_sheet', $id, 'aero', 1, false));
        $this->assertSame([], db_get_inspection_photos($pdo, 'tech_sheet', $id));

        $this->photo($pdo, $id, 'aero', 'uploads/inspection/tech_sheet/1/aero.jpg');
        $this->assertNull(db_set_conditional_photo_applies($pdo, 'tech_sheet', $id, 'aero', 1, true));      // turning on keeps an existing photo
        $this->assertSame('uploads/inspection/tech_sheet/1/aero.jpg', db_get_inspection_photos($pdo, 'tech_sheet', $id)['aero']['file_path']);
    }

    public function testTypedUpdateResetsReviewAndReviewHelpers(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);
        $photoId = $this->photo($pdo, $id, 'harness_date');

        db_set_inspection_photo_review($pdo, $photoId, 'retake', 'Label not readable');
        $row = db_get_inspection_photo($pdo, $photoId);
        $this->assertSame('retake', $row['review_status']);
        $this->assertSame('Label not readable', $row['reviewer_note']);

        db_update_inspection_photo_typed($pdo, $photoId, '{"date":"05/2025"}');
        $row = db_get_inspection_photo($pdo, $photoId);
        $this->assertSame('{"date":"05/2025"}', $row['typed_value']);
        $this->assertSame('pending', $row['review_status']);
        $this->assertNull($row['reviewer_note']);
    }

    public function testSetAllReviewStatusOnlyTouchesRowsWithFiles(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);
        $withFile = $this->photo($pdo, $id, 'front_34');
        db_set_conditional_photo_applies($pdo, 'tech_sheet', $id, 'ballast', 1, true);   // placeholder, no file

        db_set_all_photos_review_status($pdo, 'tech_sheet', $id, 'accepted');

        $rows = db_get_inspection_photos($pdo, 'tech_sheet', $id);
        $this->assertSame('accepted', $rows['front_34']['review_status']);
        $this->assertSame('pending', $rows['ballast']['review_status']);
        $this->assertSame($withFile, (int)$rows['front_34']['id']);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run (from `wcma-calculator/`): `php phpunit.phar --filter DbPretechTest`
Expected: errors such as `Call to undefined function db_mark_tech_sheet_photos_draft()`.

- [ ] **Step 3: Update the revoke function**

In `wcma-calculator/db.php`, in `db_revoke_tech_sheet_acceptance`, change:

```php
            status = 'submitted', accepted_via = NULL,
            reviewed_by_user_id = NULL, reviewed_at = NULL,
            tech_signature_path = NULL, tech_signed_at = NULL, updated_at = :now
        WHERE id = :id AND status = 'teched'
```
to:
```php
            status = 'submitted', accepted_via = NULL,
            photo_status = CASE WHEN photo_status = 'accepted' THEN 'submitted' ELSE photo_status END,
            reviewed_by_user_id = NULL, reviewed_at = NULL,
            tech_signature_path = NULL, tech_signed_at = NULL, updated_at = :now
        WHERE id = :id AND status = 'teched'
```

- [ ] **Step 4: Append the new functions**

Append to the end of `wcma-calculator/db.php`:

```php

/** First photo activity on a sheet: photo_status NULL -> 'draft'. No-op otherwise (or once the sheet is teched). */
function db_mark_tech_sheet_photos_draft(PDO $pdo, int $id): void {
    $pdo->prepare("
        UPDATE tech_sheets SET photo_status = 'draft', updated_at = :now
        WHERE id = :id AND photo_status IS NULL AND status = 'submitted'
    ")->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
}

/** Atomic photo_status transition: true only if the sheet is not teched and its status was one of $from. */
function db_transition_tech_sheet_photo_status(PDO $pdo, int $id, array $from, string $to): bool {
    if (empty($from)) return false;
    $marks = implode(',', array_fill(0, count($from), '?'));
    $stmt = $pdo->prepare("
        UPDATE tech_sheets SET photo_status = ?, updated_at = ?
        WHERE id = ? AND status = 'submitted' AND photo_status IN ($marks)
    ");
    $stmt->execute(array_merge([$to, date('Y-m-d H:i:s'), $id], array_values($from)));
    return $stmt->rowCount() === 1;
}

/** Remote acceptance after reviewing photos. Atomic; only from a submitted photo set on a not-yet-teched sheet. */
function db_accept_tech_sheet_by_photos(PDO $pdo, int $id, int $reviewerUserId): bool {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        UPDATE tech_sheets SET
            status = 'teched', accepted_via = 'photos', photo_status = 'accepted',
            reviewed_by_user_id = :reviewer, reviewed_at = :now, updated_at = :now
        WHERE id = :id AND status = 'submitted' AND photo_status = 'submitted'
    ");
    $stmt->execute([':reviewer' => $reviewerUserId, ':now' => $now, ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/**
 * Marks a conditional photo as applying (placeholder row with no file yet) or not applying
 * (row removed). Returns the removed row's file_path so the caller can delete the file.
 */
function db_set_conditional_photo_applies(PDO $pdo, string $subjectType, int $subjectId, string $requirementKey, int $requirementVersion, bool $applies): ?string {
    $find = $pdo->prepare("SELECT id, file_path FROM inspection_photos WHERE subject_type = :t AND subject_id = :s AND requirement_key = :k");
    $find->execute([':t' => $subjectType, ':s' => $subjectId, ':k' => $requirementKey]);
    $existing = $find->fetch();

    if ($applies) {
        if (!$existing) {
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("
                INSERT INTO inspection_photos
                    (subject_type, subject_id, requirement_key, requirement_version, file_path, applies, created_at, updated_at)
                VALUES (:t, :s, :k, :v, '', 1, :now, :now)
            ")->execute([':t' => $subjectType, ':s' => $subjectId, ':k' => $requirementKey, ':v' => $requirementVersion, ':now' => $now]);
        }
        return null;
    }

    if (!$existing) return null;
    $pdo->prepare("DELETE FROM inspection_photos WHERE id = :id")->execute([':id' => $existing['id']]);
    return $existing['file_path'] !== '' ? $existing['file_path'] : null;
}

/** Edit the typed details of an existing photo; any change puts the photo back to 'pending' review. */
function db_update_inspection_photo_typed(PDO $pdo, int $photoId, ?string $typedJson): void {
    $pdo->prepare("
        UPDATE inspection_photos SET typed_value = :tv, review_status = 'pending', reviewer_note = NULL, updated_at = :now
        WHERE id = :id
    ")->execute([':tv' => $typedJson, ':now' => date('Y-m-d H:i:s'), ':id' => $photoId]);
}

function db_set_inspection_photo_review(PDO $pdo, int $photoId, string $status, ?string $note): void {
    $pdo->prepare("
        UPDATE inspection_photos SET review_status = :st, reviewer_note = :note, updated_at = :now WHERE id = :id
    ")->execute([':st' => $status, ':note' => $note, ':now' => date('Y-m-d H:i:s'), ':id' => $photoId]);
}

/** Sets review_status on every photo that has a file (placeholder rows are left alone). */
function db_set_all_photos_review_status(PDO $pdo, string $subjectType, int $subjectId, string $status): void {
    $pdo->prepare("
        UPDATE inspection_photos SET review_status = :st, updated_at = :now
        WHERE subject_type = :t AND subject_id = :s AND file_path != ''
    ")->execute([':st' => $status, ':now' => date('Y-m-d H:i:s'), ':t' => $subjectType, ':s' => $subjectId]);
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php phpunit.phar`
Expected: `OK` for the whole suite, including `DbPretechTest` (8 tests) and the existing `DbTechStatusTest` revoke test.

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/db.php wcma-calculator/tests/DbPretechTest.php
git commit -m "feat(pre-tech): add photo review workflow database functions"
```

---

### Task 2: Workflow library, lock rule and status tweaks

**Files:**
- Create: `wcma-calculator/pretech-lib.php`
- Modify: `wcma-calculator/inspection-lib.php` (`inspectionCanAccess` lock rule; mark draft in `inspectionSavePhoto`)
- Modify: `wcma-calculator/tech-status.php` (`accepted` photo status counts as pending; `pending_review` roster filter)
- Test: `wcma-calculator/tests/PretechLibTest.php`; add cases to `tests/TechStatusTest.php`

**Interfaces:**
- Consumes: Task 1 db functions; `photoRequirements()`, `photoRequirementByKey()`, `photoSetMissingRequired()`; `techCarStatus()`.
- Produces:
  - `pretechSnapshot(PDO $pdo, int $sheetId): array{photos: array, present: string[], applicable: string[], missing: string[]}` — `photos` keyed by requirement key (all rows, including placeholders); `present` keys have a file; `applicable` are conditional keys with `applies = 1`; `missing` from `photoSetMissingRequired('car', ...)`.
  - `pretechSubmit(PDO $pdo, int $sheetId): array{ok: bool, error: ?string}`
  - `pretechAccept(PDO $pdo, int $sheetId, int $reviewerUserId): array{ok: bool, error: ?string}` — remote acceptance; on success marks every photo `accepted`.
  - `pretechSendBack(PDO $pdo, int $sheetId, array $notes): array{ok: bool, error: ?string, retakes: array<string,string>}` — `$notes` is `[requirementKey => note]`.
  - `pretechPageMode(array $sheet, array $identitySheets): array{mode: string, sheet_id: ?int}` — `mode` is `car_accepted` | `held_elsewhere` | `this_sheet`.
  - `inspectionCanAccess()` now also refuses owner writes while `photo_status` is `submitted` or `accepted`.
  - `techRosterFilter()` accepts `pending_review`.

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/PretechLibTest.php`:

```php
<?php
// wcma-calculator/tests/PretechLibTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../pretech-lib.php';

use PHPUnit\Framework\TestCase;

final class PretechLibTest extends TestCase
{
    private function fixture(PDO $pdo, string $number = '42', ?int $userId = null, string $eventDate = '2026-05-10'): array {
        $userId = $userId ?? db_create_user($pdo, ['email' => "u$number@example.com", 'name' => 'Racer', 'password_hash' => 'x', 'google_id' => null]);
        $adminId = db_create_user($pdo, ['email' => 'admin' . uniqid() . '@example.com', 'name' => 'Tech', 'password_hash' => 'x', 'google_id' => null]);
        $subId = db_insert_submission($pdo, [
            ':submitted_at' => date('Y-m-d H:i:s'), ':name' => 'Racer', ':email' => "u$number@example.com",
            ':year' => '2020', ':make' => 'Mazda', ':model' => 'MX-5', ':comments' => null,
            ':competition_weight' => 2200, ':declared_hp' => 150, ':dyno_hp' => null,
            ':chassis_display' => null, ':body_mods_display' => null, ':transmission_display' => null,
            ':drivetrain_display' => null, ':tires_display' => null, ':brake_suspension' => null,
            ':chassis_value' => 0, ':body_mods_value' => 0, ':transmission_value' => 0,
            ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
            ':weight_factor' => 0, ':modification_factor' => 0, ':base_ratio' => 14.67, ':modified_ratio' => 14.67,
            ':calculated_class' => 'IT1', ':user_id' => $userId,
        ]);
        $eventId = db_create_event($pdo, 'Event ' . $eventDate, $eventDate, null);
        $sheetId = db_insert_tech_sheet($pdo, [
            'submission_id' => $subId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'standard',
            'entrant_name' => 'Racer', 'driver_name' => 'Racer', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => $number, 'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150',
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ]);
        return [$userId, $adminId, $sheetId];
    }

    private function addPhoto(PDO $pdo, int $sheetId, string $key, string $path = 'uploads/x.jpg'): void {
        db_upsert_inspection_photo($pdo, [
            'subject_type' => 'tech_sheet', 'subject_id' => $sheetId, 'requirement_key' => $key,
            'requirement_version' => 1, 'file_path' => $path, 'typed_value' => null,
        ]);
        db_mark_tech_sheet_photos_draft($pdo, $sheetId);
    }

    private function addRequiredPhotos(PDO $pdo, int $sheetId): void {
        foreach (photoRequirements('car') as $key => $def) {
            if ($def['tier'] === 'required') $this->addPhoto($pdo, $sheetId, $key);
        }
    }

    public function testSnapshotReportsPresentApplicableAndMissing(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);

        $empty = pretechSnapshot($pdo, $id);
        $this->assertCount(15, $empty['missing']);
        $this->assertSame([], $empty['present']);

        $this->addPhoto($pdo, $id, 'front_34');
        db_set_conditional_photo_applies($pdo, 'tech_sheet', $id, 'ballast', 1, true);   // placeholder: applies, no file

        $snap = pretechSnapshot($pdo, $id);
        $this->assertSame(['front_34'], $snap['present']);
        $this->assertSame(['ballast'], $snap['applicable']);
        $this->assertContains('ballast', $snap['missing']);
        $this->assertNotContains('front_34', $snap['missing']);
        $this->assertCount(15, $snap['missing']);   // 14 required + ballast
        $this->assertArrayHasKey('ballast', $snap['photos']);
    }

    public function testSubmitRequiresACompleteSet(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);

        $r = pretechSubmit($pdo, $id);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Add your photos', $r['error']);

        $this->addPhoto($pdo, $id, 'front_34');
        $r = pretechSubmit($pdo, $id);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('14 required photos are still missing', $r['error']);

        $this->addRequiredPhotos($pdo, $id);
        db_set_conditional_photo_applies($pdo, 'tech_sheet', $id, 'ballast', 1, true);
        $r = pretechSubmit($pdo, $id);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('1 required photo is still missing', $r['error']);

        $this->addPhoto($pdo, $id, 'ballast');
        $this->assertTrue(pretechSubmit($pdo, $id)['ok']);
        $this->assertSame('submitted', db_get_tech_sheet($pdo, $id)['photo_status']);

        $again = pretechSubmit($pdo, $id);
        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('already been submitted', $again['error']);
    }

    public function testSubmitRefusesTechedSheetsAndUnknownSheets(): void
    {
        $pdo = make_temp_pdo();
        [, $admin, $id] = $this->fixture($pdo);
        $this->addRequiredPhotos($pdo, $id);
        db_accept_tech_sheet_in_person($pdo, $id, $admin, 'sig.png');

        $this->assertStringContainsString('already been teched', pretechSubmit($pdo, $id)['error']);
        $this->assertFalse(pretechSubmit($pdo, 99999)['ok']);
    }

    public function testSendBackRequiresNotesAndFlagsPhotos(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);
        $this->addRequiredPhotos($pdo, $id);
        pretechSubmit($pdo, $id);

        $r = pretechSendBack($pdo, $id, []);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('at least one photo', $r['error']);

        $r = pretechSendBack($pdo, $id, ['front_34' => '   ']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('note for every photo', $r['error']);

        $r = pretechSendBack($pdo, $id, ['not_a_photo' => 'x']);
        $this->assertFalse($r['ok']);

        $r = pretechSendBack($pdo, $id, ['harness_date' => 'Date stamp not readable', 'front_34' => 'Car number hidden']);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame(['harness_date' => 'Date stamp not readable', 'front_34' => 'Car number hidden'], $r['retakes']);
        $this->assertSame('needs_changes', db_get_tech_sheet($pdo, $id)['photo_status']);

        $photos = db_get_inspection_photos($pdo, 'tech_sheet', $id);
        $this->assertSame('retake', $photos['harness_date']['review_status']);
        $this->assertSame('Date stamp not readable', $photos['harness_date']['reviewer_note']);
        $this->assertSame('pending', $photos['rear_34']['review_status']);
    }

    public function testSendBackOnlyFromSubmitted(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);
        $this->addRequiredPhotos($pdo, $id);   // draft
        $r = pretechSendBack($pdo, $id, ['front_34' => 'Blurry']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('not awaiting review', $r['error']);
    }

    public function testResubmitBlockedWhileAPhotoIsStillFlaggedThenAllowedAfterRetake(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);
        $this->addRequiredPhotos($pdo, $id);
        pretechSubmit($pdo, $id);
        pretechSendBack($pdo, $id, ['front_34' => 'Blurry']);

        $blocked = pretechSubmit($pdo, $id);
        $this->assertFalse($blocked['ok']);
        $this->assertStringContainsString('retake the photos the inspector flagged', $blocked['error']);

        $this->addPhoto($pdo, $id, 'front_34', 'uploads/retaken.jpg');   // replacing resets review to pending
        $this->assertTrue(pretechSubmit($pdo, $id)['ok']);
        $this->assertSame('submitted', db_get_tech_sheet($pdo, $id)['photo_status']);
    }

    public function testAcceptMarksSheetAndPhotosAccepted(): void
    {
        $pdo = make_temp_pdo();
        [, $admin, $id] = $this->fixture($pdo);
        $this->addRequiredPhotos($pdo, $id);

        $early = pretechAccept($pdo, $id, $admin);
        $this->assertFalse($early['ok']);
        $this->assertStringContainsString('not awaiting review', $early['error']);

        pretechSubmit($pdo, $id);
        $r = pretechAccept($pdo, $id, $admin);
        $this->assertTrue($r['ok'], (string)$r['error']);

        $row = db_get_tech_sheet($pdo, $id);
        $this->assertSame('teched', $row['status']);
        $this->assertSame('photos', $row['accepted_via']);
        foreach (db_get_inspection_photos($pdo, 'tech_sheet', $id) as $photo) {
            $this->assertSame('accepted', $photo['review_status']);
        }
        $this->assertFalse(pretechAccept($pdo, $id, $admin)['ok']);
    }

    public function testPageModes(): void
    {
        $pdo = make_temp_pdo();
        [$u, $admin, $spring] = $this->fixture($pdo, '42', null, '2026-05-10');
        [, , $fall] = $this->fixture($pdo, '42', $u, '2026-10-04');

        $sheets = fn() => db_get_identity_sheets($pdo, $u, '42', 2026);
        $mode = fn(int $id) => pretechPageMode(db_get_tech_sheet($pdo, $id), $sheets());

        $this->assertSame(['mode' => 'this_sheet', 'sheet_id' => null], $mode($spring));

        $this->addPhoto($pdo, $spring, 'front_34');   // spring now holds a photo set
        $this->assertSame(['mode' => 'this_sheet', 'sheet_id' => null], $mode($spring));
        $this->assertSame(['mode' => 'held_elsewhere', 'sheet_id' => $spring], $mode($fall));

        db_accept_tech_sheet_in_person($pdo, $fall, $admin, 'sig.png');
        $this->assertSame('car_accepted', $mode($spring)['mode']);
        $this->assertSame('car_accepted', $mode($fall)['mode']);
        $this->assertSame($fall, $mode($spring)['sheet_id']);
    }

    public function testOwnerWritesLockOnSubmittedAndAcceptedPhotoStatus(): void
    {
        $owner = ['id' => 5, 'role' => 'user'];
        $admin = ['id' => 1, 'role' => 'admin'];
        $base = ['user_id' => 5, 'status' => 'submitted'];

        foreach ([null, 'draft', 'needs_changes'] as $open) {
            $this->assertTrue(inspectionCanAccess($owner, $base + ['photo_status' => $open], true), (string)$open);
        }
        foreach (['submitted', 'accepted'] as $locked) {
            $this->assertFalse(inspectionCanAccess($owner, $base + ['photo_status' => $locked], true), $locked);
            $this->assertTrue(inspectionCanAccess($owner, $base + ['photo_status' => $locked], false), $locked . ' (read)');
            $this->assertTrue(inspectionCanAccess($admin, $base + ['photo_status' => $locked], true), $locked . ' (admin)');
        }
    }

    public function testSavingAPhotoMarksTheSheetDraft(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);
        $dir = sys_get_temp_dir() . '/wcma_pt_' . uniqid();
        mkdir($dir, 0755, true);
        $tmp = $dir . '/in.bin';
        file_put_contents($tmp, hex2bin('ffd8ffc00011080001000103011100021100031100ffd9'));

        $this->assertNull(db_get_tech_sheet($pdo, $id)['photo_status']);
        $r = inspectionSavePhoto($pdo, $dir, 'tech_sheet', $id, 'front_34', $tmp, [], 'rename');

        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame('draft', db_get_tech_sheet($pdo, $id)['photo_status']);

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        rmdir($dir);
    }
}
```

Add to `wcma-calculator/tests/TechStatusTest.php`, inside the class:

```php
    public function testAcceptedPhotoStatusWithoutTechedSheetCountsAsPending(): void
    {
        $status = techCarStatus([$this->sheet(1, ['photo_status' => 'accepted'])]);
        $this->assertSame('pending_review', $status['state']);
    }

    public function testPendingReviewRosterFilter(): void
    {
        $rows = [
            ['sheet' => $this->sheet(1), 'status' => ['state' => 'pending_review', 'via' => null, 'sheet_id' => 1]],
            ['sheet' => $this->sheet(2), 'status' => ['state' => 'none', 'via' => null, 'sheet_id' => null]],
            ['sheet' => $this->sheet(3), 'status' => ['state' => 'needs_changes', 'via' => null, 'sheet_id' => 3]],
            ['sheet' => $this->sheet(4), 'status' => ['state' => 'accepted', 'via' => 'photos', 'sheet_id' => 4]],
        ];
        $this->assertSame([1], array_map(fn($r) => $r['sheet']['id'], techRosterFilter($rows, 'pending_review')));
        $this->assertSame([1, 2, 3], array_map(fn($r) => $r['sheet']['id'], techRosterFilter($rows, 'needs_tech')));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "PretechLibTest|TechStatusTest"`
Expected: fatal error, `pretech-lib.php` not found (and the two new TechStatusTest cases fail).

- [ ] **Step 3: Update `tech-status.php`**

In `wcma-calculator/tech-status.php`, change the photo loop in `techCarStatus`:

```php
    foreach (['needs_changes' => 'needs_changes', 'submitted' => 'pending_review', 'draft' => 'photos_draft'] as $photoStatus => $state) {
```
to:
```php
    foreach (['needs_changes' => 'needs_changes', 'submitted' => 'pending_review', 'accepted' => 'pending_review', 'draft' => 'photos_draft'] as $photoStatus => $state) {
```
(An `accepted` photo status on a sheet that is not teched is a half-applied state; showing it as pending keeps it visible in the review queue.)

Replace the whole `techRosterFilter` function with:

```php
/** $filter: 'all' | 'needs_tech' (car not accepted) | 'accepted' | 'pending_review'. Unknown values mean 'all'. */
function techRosterFilter(array $rows, string $filter): array {
    if (!in_array($filter, ['needs_tech', 'accepted', 'pending_review'], true)) return $rows;
    return array_values(array_filter($rows, function (array $r) use ($filter): bool {
        $state = $r['status']['state'];
        if ($filter === 'pending_review') return $state === 'pending_review';
        $accepted = $state === 'accepted';
        return $filter === 'accepted' ? $accepted : !$accepted;
    }));
}
```

- [ ] **Step 4: Update `inspection-lib.php`**

Replace `inspectionCanAccess` (and its docblock) with:

```php
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
```

In `inspectionSavePhoto`, change:

```php
    $stored = db_get_inspection_photos($pdo, $subjectType, $subjectId)[$requirementKey];
    return ['ok' => true, 'error' => null, 'photo' => $stored];
```
to:
```php
    if ($subjectType === 'tech_sheet') db_mark_tech_sheet_photos_draft($pdo, $subjectId);

    $stored = db_get_inspection_photos($pdo, $subjectType, $subjectId)[$requirementKey];
    return ['ok' => true, 'error' => null, 'photo' => $stored];
```

- [ ] **Step 5: Write the workflow library**

Create `wcma-calculator/pretech-lib.php`:

```php
<?php
// wcma-calculator/pretech-lib.php
//
// The photo pre-tech workflow for a tech sheet. Session-free (callers inject the reviewer id)
// so it is unit-testable. Callers must have loaded db.php, tech-status.php,
// photo-requirements.php and inspection-lib.php.

/** Photos of the sheet, which are present, which conditional ones apply, and what is still missing. */
function pretechSnapshot(PDO $pdo, int $sheetId): array {
    $photos = db_get_inspection_photos($pdo, 'tech_sheet', $sheetId);
    $present = [];
    $applicable = [];
    foreach ($photos as $key => $row) {
        if ($row['file_path'] !== '') $present[] = $key;
        $req = photoRequirementByKey($key);
        if ($req !== null && $req['tier'] === 'conditional' && (int)$row['applies'] === 1) $applicable[] = $key;
    }
    return [
        'photos' => $photos,
        'present' => $present,
        'applicable' => $applicable,
        'missing' => photoSetMissingRequired('car', $present, $applicable),
    ];
}

function pretechPlural(int $n, string $singular, string $plural): string {
    return $n === 1 ? $singular : $plural;
}

/** Competitor submits a complete photo set for review. @return array{ok: bool, error: ?string} */
function pretechSubmit(PDO $pdo, int $sheetId): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg];

    $sheet = db_get_tech_sheet($pdo, $sheetId);
    if ($sheet === null) return $fail('Tech sheet not found.');
    if ($sheet['status'] === 'teched') return $fail('This car has already been teched.');

    $photoStatus = $sheet['photo_status'] ?? null;
    if ($photoStatus === 'submitted') return $fail('These photos have already been submitted for review.');
    if (!in_array($photoStatus, ['draft', 'needs_changes'], true)) return $fail('Add your photos before submitting.');

    $snapshot = pretechSnapshot($pdo, $sheetId);
    $missing = count($snapshot['missing']);
    if ($missing > 0) {
        return $fail($missing . ' required ' . pretechPlural($missing, 'photo is', 'photos are') . ' still missing.');
    }
    foreach ($snapshot['photos'] as $row) {
        if ($row['file_path'] !== '' && $row['review_status'] === 'retake') {
            return $fail('Please retake the photos the inspector flagged before submitting again.');
        }
    }

    if (!db_transition_tech_sheet_photo_status($pdo, $sheetId, ['draft', 'needs_changes'], 'submitted')) {
        return $fail('These photos could not be submitted. Please reload and try again.');
    }
    return ['ok' => true, 'error' => null];
}

/** Inspector accepts a submitted photo set remotely. @return array{ok: bool, error: ?string} */
function pretechAccept(PDO $pdo, int $sheetId, int $reviewerUserId): array {
    if (!db_accept_tech_sheet_by_photos($pdo, $sheetId, $reviewerUserId)) {
        return ['ok' => false, 'error' => 'These photos are not awaiting review.'];
    }
    db_set_all_photos_review_status($pdo, 'tech_sheet', $sheetId, 'accepted');
    return ['ok' => true, 'error' => null];
}

/**
 * Inspector sends individual photos back for a retake. Every flagged photo needs a note.
 *
 * @param array<string,string> $notes requirement key => note
 * @return array{ok: bool, error: ?string, retakes: array<string,string>}
 */
function pretechSendBack(PDO $pdo, int $sheetId, array $notes): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'retakes' => []];

    $sheet = db_get_tech_sheet($pdo, $sheetId);
    if ($sheet === null) return $fail('Tech sheet not found.');
    if ($sheet['status'] === 'teched' || ($sheet['photo_status'] ?? null) !== 'submitted') {
        return $fail('These photos are not awaiting review.');
    }

    $photos = db_get_inspection_photos($pdo, 'tech_sheet', $sheetId);
    $retakes = [];
    foreach ($notes as $key => $note) {
        if (!isset($photos[$key]) || $photos[$key]['file_path'] === '') continue;
        $note = substr(trim((string)$note), 0, 500);
        if ($note === '') return $fail('Add a note for every photo you send back.');
        $retakes[$key] = $note;
    }
    if (!$retakes) return $fail('Choose at least one photo to retake and say what is wrong.');

    if (!db_transition_tech_sheet_photo_status($pdo, $sheetId, ['submitted'], 'needs_changes')) {
        return $fail('These photos are not awaiting review.');
    }
    foreach ($retakes as $key => $note) {
        db_set_inspection_photo_review($pdo, (int)$photos[$key]['id'], 'retake', $note);
    }
    return ['ok' => true, 'error' => null, 'retakes' => $retakes];
}

/**
 * What the competitor's pre-tech page should do for $sheet, given ALL of the owner's sheets for
 * this car identity: the car is already accepted, another sheet already holds the photo set, or
 * this sheet is where the photos live.
 *
 * @return array{mode: string, sheet_id: ?int}
 */
function pretechPageMode(array $sheet, array $identitySheets): array {
    $status = techCarStatus($identitySheets);
    if ($status['state'] === 'accepted') return ['mode' => 'car_accepted', 'sheet_id' => $status['sheet_id']];

    foreach ($identitySheets as $other) {
        if ((int)$other['id'] !== (int)$sheet['id'] && ($other['photo_status'] ?? null) !== null) {
            return ['mode' => 'held_elsewhere', 'sheet_id' => (int)$other['id']];
        }
    }
    return ['mode' => 'this_sheet', 'sheet_id' => null];
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php phpunit.phar`
Expected: `OK` for the whole suite, including `PretechLibTest` (10 tests), the two new `TechStatusTest` cases, and the existing `InspectionLibTest` / `InspectionEndpointTest` (the lock change must not break them).

- [ ] **Step 7: Commit**

```bash
git add wcma-calculator/pretech-lib.php wcma-calculator/inspection-lib.php wcma-calculator/tech-status.php wcma-calculator/tests/PretechLibTest.php wcma-calculator/tests/TechStatusTest.php
git commit -m "feat(pre-tech): add photo pre-tech workflow library and review-state lock"
```

---

### Task 3: Applies/typed actions and browser client

**Files:**
- Modify: `wcma-calculator/inspection-lib.php` (add `inspectionSetApplies`, `inspectionUpdateTyped`)
- Modify: `wcma-calculator/inspection.php` (two new actions)
- Modify: `wcma-calculator/js/photo-upload.js` (`applies`, `typed`)
- Create: `wcma-calculator/js/pretech-progress.js`
- Test: `wcma-calculator/tests/InspectionLibTest.php` (add cases), `wcma-calculator/tests/InspectionEndpointTest.php` (add cases), `wcma-calculator/tests/js/photo-upload.test.js` (add cases), `wcma-calculator/tests/js/pretech-progress.test.js` (create)

**Interfaces:**
- Consumes: Task 1 (`db_set_conditional_photo_applies`, `db_update_inspection_photo_typed`, `db_mark_tech_sheet_photos_draft`), `photoRequirementByKey`, `photoValidateTypedValue`.
- Produces:
  - `inspectionSetApplies(PDO $pdo, string $baseDir, string $subjectType, int $subjectId, string $requirementKey, bool $applies): array{ok: bool, error: ?string}` — only for `conditional` requirements; turning off deletes the file too; turning on marks a tech sheet's photos `draft`.
  - `inspectionUpdateTyped(PDO $pdo, int $photoId, array $typedInput): array{ok: bool, error: ?string, photo?: array}`
  - HTTP: `POST inspection.php?action=applies` (`csrf_token`, `subject_type`, `subject_id`, `requirement_key`, `applies` = `1`/`0`) → `{ok, applies}`; `POST inspection.php?action=typed` (`csrf_token`, `id`, `typed[name]`) → `{ok, photo}`. Same auth/CSRF/lock rules as `upload`.
  - JS: `client.applies({subjectType, subjectId, requirementKey, applies: boolean}): Promise<void>` and `client.typed({id, typed}): Promise<photo>`.
  - `WcmaPretechProgress.computeMissing(requirements, presentKeys, applicableKeys): string[]` (dual-mode; mirrors `photoSetMissingRequired`).

- [ ] **Step 1: Write the failing tests**

Add to `wcma-calculator/tests/InspectionLibTest.php`, inside the class:

```php
    public function testSetAppliesOnlyForConditionalPhotosAndRemovesTheFileWhenTurnedOff(): void
    {
        $pdo = make_temp_pdo();

        $this->assertTrue(inspectionSetApplies($pdo, $this->dir, 'tech_sheet', 7, 'aero', true)['ok']);
        $this->assertSame('', db_get_inspection_photos($pdo, 'tech_sheet', 7)['aero']['file_path']);

        $saved = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'aero', $this->tmpFile($this->jpeg()), [], 'rename');
        $this->assertTrue($saved['ok'], (string)$saved['error']);
        $file = $this->dir . '/' . $saved['photo']['file_path'];
        $this->assertFileExists($file);

        $this->assertTrue(inspectionSetApplies($pdo, $this->dir, 'tech_sheet', 7, 'aero', false)['ok']);
        $this->assertFileDoesNotExist($file);
        $this->assertSame([], db_get_inspection_photos($pdo, 'tech_sheet', 7));

        $required = inspectionSetApplies($pdo, $this->dir, 'tech_sheet', 7, 'front_34', true);
        $this->assertFalse($required['ok']);
        $this->assertStringContainsString('not optional', $required['error']);
        $this->assertFalse(inspectionSetApplies($pdo, $this->dir, 'tech_sheet', 7, 'nope', true)['ok']);
        $this->assertFalse(inspectionSetApplies($pdo, $this->dir, 'gear_record', 7, 'aero', true)['ok']);
        $this->assertFalse(inspectionSetApplies($pdo, $this->dir, 'tech_sheet', 7, 'helmet_label', true)['ok']);   // gear scope on a car subject
    }

    public function testUpdateTypedValidatesAndResetsReview(): void
    {
        $pdo = make_temp_pdo();
        $saved = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'harness_date', $this->tmpFile($this->jpeg()), [], 'rename');
        $photoId = (int)$saved['photo']['id'];
        db_set_inspection_photo_review($pdo, $photoId, 'retake', 'Not readable');

        $r = inspectionUpdateTyped($pdo, $photoId, ['date' => '05/2025']);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame(['date' => '05/2025'], $r['photo']['typed']);
        $this->assertSame('pending', $r['photo']['review_status']);

        $bad = inspectionUpdateTyped($pdo, $photoId, ['date' => '2025-05']);
        $this->assertFalse($bad['ok']);
        $this->assertFalse(inspectionUpdateTyped($pdo, 99999, ['date' => '05/2025'])['ok']);

        db_set_conditional_photo_applies($pdo, 'tech_sheet', 7, 'ballast', 1, true);
        $placeholderId = (int)db_get_inspection_photos($pdo, 'tech_sheet', 7)['ballast']['id'];
        $this->assertStringContainsString('photo first', inspectionUpdateTyped($pdo, $placeholderId, [])['error']);
    }
```

Add to `wcma-calculator/tests/InspectionEndpointTest.php`, inside the class (uses the helpers already defined there):

```php
    private function post(string $action, array $post, ?int $userId, string $role = 'user', string $csrf = self::CSRF): array
    {
        return $this->request('POST', ['action' => $action], $post + ['csrf_token' => $csrf], $this->session($userId, $role));
    }

    public function testAppliesToggleRoundTrip(): void
    {
        $fields = ['subject_type' => 'tech_sheet', 'subject_id' => $this->ownerSheet, 'requirement_key' => 'ballast', 'applies' => '1'];

        $on = $this->post('applies', $fields, $this->ownerId);
        $this->assertSame(200, $on['status']);
        $this->assertTrue(json_decode($on['body'], true)['ok']);
        $this->assertArrayHasKey('ballast', db_get_inspection_photos($this->pdo, 'tech_sheet', $this->ownerSheet));

        $off = $this->post('applies', ['applies' => '0'] + $fields, $this->ownerId);
        $this->assertSame(200, $off['status']);
        $this->assertArrayNotHasKey('ballast', db_get_inspection_photos($this->pdo, 'tech_sheet', $this->ownerSheet));
    }

    public function testAppliesAndTypedRefuseWrongCsrfOtherUsersAndLockedSheets(): void
    {
        $fields = ['subject_type' => 'tech_sheet', 'subject_id' => $this->ownerSheet, 'requirement_key' => 'ballast', 'applies' => '1'];

        $this->assertSame(401, $this->post('applies', $fields, null)['status']);
        $this->assertSame(403, $this->post('applies', $fields, $this->ownerId, 'user', 'wrong')['status']);
        $this->assertSame(404, $this->post('applies', $fields, $this->otherId)['status']);
        $this->assertSame(405, $this->request('GET', ['action' => 'applies'], [], $this->session($this->ownerId))['status']);

        $typed = ['id' => (string)$this->ownerPhotoId, 'typed' => ['date' => '05/2025']];
        $this->assertSame(404, $this->post('typed', $typed, $this->otherId)['status']);

        $this->pdo->prepare("UPDATE tech_sheets SET photo_status = 'submitted' WHERE id = :id")->execute([':id' => $this->ownerSheet]);
        $this->assertSame(404, $this->post('applies', $fields, $this->ownerId)['status']);       // locked while under review
        $this->assertSame(404, $this->post('typed', $typed, $this->ownerId)['status']);
        $this->assertSame(200, $this->post('applies', $fields, $this->adminId, 'admin')['status']);   // admins may still write
    }

    public function testTypedUpdateOnAnExistingPhoto(): void
    {
        // ownerPhotoId is a front_34 photo, which has no typed fields; any value is rejected, an empty set is accepted.
        $bad = $this->post('typed', ['id' => (string)$this->ownerPhotoId, 'typed' => ['date' => '05/2025']], $this->ownerId);
        $this->assertSame(400, $bad['status']);

        $ok = $this->post('typed', ['id' => (string)$this->ownerPhotoId], $this->ownerId);
        $this->assertSame(200, $ok['status']);
        $this->assertSame([], json_decode($ok['body'], true)['photo']['typed']);
    }
```

Add to `wcma-calculator/tests/js/photo-upload.test.js`:

```js
test('applies posts the toggle fields', async () => {
    const { calls, client } = makeClient(okResponse({ ok: true, applies: true }));
    await client.applies({ subjectType: 'tech_sheet', subjectId: 7, requirementKey: 'ballast', applies: true });
    assert.strictEqual(calls[0].url, 'inspection.php?action=applies');
    const body = calls[0].init.body;
    assert.strictEqual(body.get('csrf_token'), 'tok123');
    assert.strictEqual(body.get('subject_type'), 'tech_sheet');
    assert.strictEqual(body.get('subject_id'), '7');
    assert.strictEqual(body.get('requirement_key'), 'ballast');
    assert.strictEqual(body.get('applies'), '1');

    await client.applies({ subjectType: 'tech_sheet', subjectId: 7, requirementKey: 'ballast', applies: false });
    assert.strictEqual(calls[1].init.body.get('applies'), '0');
});

test('typed posts the photo id and typed fields and resolves the photo', async () => {
    const photo = { id: 9, typed: { date: '05/2025' } };
    const { calls, client } = makeClient(okResponse({ ok: true, photo }));
    const result = await client.typed({ id: 9, typed: { date: '05/2025', standard: null } });
    assert.deepStrictEqual(result, photo);
    assert.strictEqual(calls[0].url, 'inspection.php?action=typed');
    const body = calls[0].init.body;
    assert.strictEqual(body.get('id'), '9');
    assert.strictEqual(body.get('typed[date]'), '05/2025');
    assert.strictEqual(body.get('typed[standard]'), null);   // nullish values are skipped
});
```

Create `wcma-calculator/tests/js/pretech-progress.test.js`:

```js
const test = require('node:test');
const assert = require('node:assert');
const { computeMissing } = require('../../js/pretech-progress.js');

const requirements = [
    { key: 'a', tier: 'required' },
    { key: 'b', tier: 'required' },
    { key: 'c', tier: 'conditional' },
    { key: 'd', tier: 'recommended' },
];

test('everything required is missing at first; recommended and unselected conditionals never count', () => {
    assert.deepStrictEqual(computeMissing(requirements, [], []), ['a', 'b']);
});

test('present photos are not missing', () => {
    assert.deepStrictEqual(computeMissing(requirements, ['a'], []), ['b']);
    assert.deepStrictEqual(computeMissing(requirements, ['a', 'b'], []), []);
});

test('an applicable conditional photo is required until it is present', () => {
    assert.deepStrictEqual(computeMissing(requirements, ['a', 'b'], ['c']), ['c']);
    assert.deepStrictEqual(computeMissing(requirements, ['a', 'b', 'c'], ['c']), []);
});

test('a conditional photo that was added but is switched off no longer counts', () => {
    assert.deepStrictEqual(computeMissing(requirements, ['a', 'b', 'c'], []), []);
});

test('unknown applicable keys are ignored', () => {
    assert.deepStrictEqual(computeMissing(requirements, ['a', 'b'], ['zzz']), []);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "InspectionLibTest|InspectionEndpointTest"` and `node --test "tests/js/*.test.js"`
Expected: PHP errors `Call to undefined function inspectionSetApplies()`; JS failures (`client.applies is not a function`, missing `pretech-progress.js`).

- [ ] **Step 3: Add the library functions**

Append to `wcma-calculator/inspection-lib.php`:

```php

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
```

- [ ] **Step 4: Add the endpoint actions**

In `wcma-calculator/inspection.php`, insert immediately before the `if ($action === 'photo') {` block:

```php
if ($action === 'applies') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') inspectionJson(405, ['ok' => false, 'error' => 'POST required.']);
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) inspectionJson(403, ['ok' => false, 'error' => 'Invalid CSRF token.']);

    $type = (string)($_POST['subject_type'] ?? '');
    $subjectId = (int)($_POST['subject_id'] ?? 0);
    if (inspectionLoadSubject($pdo, $user, $type, $subjectId, true) === null) {
        inspectionJson(404, ['ok' => false, 'error' => 'Not found.']);
    }

    $applies = ($_POST['applies'] ?? '') === '1';
    $result = inspectionSetApplies($pdo, __DIR__, $type, $subjectId, (string)($_POST['requirement_key'] ?? ''), $applies);
    if (!$result['ok']) inspectionJson(400, ['ok' => false, 'error' => $result['error']]);
    inspectionJson(200, ['ok' => true, 'applies' => $applies]);
}

if ($action === 'typed') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') inspectionJson(405, ['ok' => false, 'error' => 'POST required.']);
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) inspectionJson(403, ['ok' => false, 'error' => 'Invalid CSRF token.']);

    $photo = db_get_inspection_photo($pdo, (int)($_POST['id'] ?? 0));
    if ($photo === null || inspectionLoadSubject($pdo, $user, $photo['subject_type'], (int)$photo['subject_id'], true) === null) {
        inspectionJson(404, ['ok' => false, 'error' => 'Not found.']);
    }

    $typed = isset($_POST['typed']) && is_array($_POST['typed']) ? $_POST['typed'] : [];
    $result = inspectionUpdateTyped($pdo, (int)$photo['id'], $typed);
    if (!$result['ok']) inspectionJson(400, ['ok' => false, 'error' => $result['error']]);
    inspectionJson(200, ['ok' => true, 'photo' => $result['photo']]);
}

```

- [ ] **Step 5: Extend the upload client**

In `wcma-calculator/js/photo-upload.js`, replace the line `        return { upload: upload, remove: remove };` with:

```js
        async function applies(opts) {
            const form = new FormData();
            form.append('csrf_token', csrfToken);
            form.append('subject_type', opts.subjectType);
            form.append('subject_id', String(opts.subjectId));
            form.append('requirement_key', opts.requirementKey);
            form.append('applies', opts.applies ? '1' : '0');
            await post('applies', form);
        }

        async function typed(opts) {
            const form = new FormData();
            form.append('csrf_token', csrfToken);
            form.append('id', String(opts.id));
            const values = opts.typed || {};
            Object.keys(values).forEach(function (name) {
                if (values[name] === undefined || values[name] === null) return;
                form.append('typed[' + name + ']', values[name]);
            });
            return (await post('typed', form)).photo;
        }

        return { upload: upload, remove: remove, applies: applies, typed: typed };
```

- [ ] **Step 6: Create the progress helper**

Create `wcma-calculator/js/pretech-progress.js`:

```js
// wcma-calculator/js/pretech-progress.js
// Which photos are still missing from a pre-tech set. Mirrors photoSetMissingRequired() in
// photo-requirements.php: every 'required' photo, plus each 'conditional' photo the competitor
// marked as applying; 'recommended' photos never count. Loadable as a classic script
// (window.WcmaPretechProgress) or via require() for tests.
(function (root) {
    'use strict';

    function computeMissing(requirements, presentKeys, applicableKeys) {
        const present = new Set(presentKeys);
        const applicable = new Set(applicableKeys);
        return requirements
            .filter(function (req) {
                const needed = req.tier === 'required' || (req.tier === 'conditional' && applicable.has(req.key));
                return needed && !present.has(req.key);
            })
            .map(function (req) { return req.key; });
    }

    const api = { computeMissing: computeMissing };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.WcmaPretechProgress = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);
```

- [ ] **Step 7: Run all tests**

Run: `php phpunit.phar` and `node --test "tests/js/*.test.js"`
Expected: PHPUnit `OK` (including the new lib and endpoint cases); Node `# fail 0` with the new client and progress tests.

- [ ] **Step 8: Commit**

```bash
git add wcma-calculator/inspection-lib.php wcma-calculator/inspection.php wcma-calculator/js/photo-upload.js wcma-calculator/js/pretech-progress.js wcma-calculator/tests/InspectionLibTest.php wcma-calculator/tests/InspectionEndpointTest.php wcma-calculator/tests/js/photo-upload.test.js wcma-calculator/tests/js/pretech-progress.test.js
git commit -m "feat(pre-tech): add applies and typed-detail actions with client support"
```

---

### Task 4: Emails and the notifier

**Files:**
- Create: `wcma-calculator/pretech-email.php`
- Modify: `wcma-calculator/email-helpers.php` (add `emailSmtpSend`)
- Test: `wcma-calculator/tests/PretechEmailTest.php`

**Interfaces:**
- Consumes: `h()` (`view_helpers.php`), `TECH_ACCEPTANCE_DISCLAIMER` (`tech-sheet-data.php`), `db_find_user_by_id()`, `db_get_inspection_photos()`, `techSeasonFromDate()`.
- Produces:
  - `pretechEmailSubmitted(array $sheet, array $event, string $adminUrl, string $pageUrl, int $photoCount, bool $forClub): array{subject, html, text}`
  - `pretechEmailSentBack(array $sheet, array $event, array $retakes, string $pageUrl): array{subject, html, text}` — `$retakes` is a list of `['label' => string, 'note' => string]`.
  - `pretechEmailAccepted(array $sheet, array $event, string $viewUrl): array{subject, html, text}`
  - `pretechNotify(PDO $pdo, string $kind, array $sheet, array $event, string $baseUrl, array $club, callable $sendFn, array $retakes = []): bool` — `$kind` is `submitted` | `sent_back` | `accepted`; `$club` is `['email' => ..., 'name' => ...]`; `$retakes` is `[requirementKey => note]`; `$sendFn(array $to, array $message): bool` where `$to` is a list of `[email, name]` pairs. Returns true only if every message was sent; exceptions are caught and logged.
  - `emailSmtpSend(array $to, array $message): bool` — production `$sendFn` (SMTP through PHPMailer with the logo embedded); with `WCMA_MAIL_LOG` defined it appends a JSON line to that file and sends nothing.

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/PretechEmailTest.php`:

```php
<?php
// wcma-calculator/tests/PretechEmailTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../pretech-email.php';
require_once __DIR__ . '/../email-helpers.php';

use PHPUnit\Framework\TestCase;

final class PretechEmailTest extends TestCase
{
    private function sheet(array $o = []): array {
        return array_merge([
            'id' => 12, 'user_id' => 1, 'car_number' => '42', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'entrant_name' => 'Jane <Racer>', 'season' => 2026,
        ], $o);
    }

    private function event(): array {
        return ['name' => 'Spring Sprint', 'event_date' => '2026-05-10'];
    }

    public function testSubmittedEmailsForClubAndCompetitor(): void
    {
        $club = pretechEmailSubmitted($this->sheet(), $this->event(), 'https://x.test/admin.php?action=tech-sheet&id=12', 'https://x.test/tech-sheets.php?action=pretech&id=12', 15, true);
        $this->assertStringContainsString('Pre-Tech Submitted', $club['subject']);
        $this->assertStringContainsString('Car #42', $club['subject']);
        $this->assertStringContainsString('https://x.test/admin.php?action=tech-sheet&amp;id=12', $club['html']);
        $this->assertStringContainsString('15 photos', $club['text']);
        $this->assertStringContainsString('cid:wcma-logo', $club['html']);
        $this->assertStringContainsString('Jane &lt;Racer&gt;', $club['html']);   // escaped
        $this->assertStringNotContainsString('<Racer>', $club['html']);

        $competitor = pretechEmailSubmitted($this->sheet(), $this->event(), 'https://x.test/admin', 'https://x.test/page', 15, false);
        $this->assertStringContainsString('received your photos', $competitor['text']);
        $this->assertStringContainsString('teched in person', $competitor['text']);
        $this->assertStringNotContainsString('https://x.test/admin', $competitor['text']);   // competitors never get the admin link
    }

    public function testSentBackEmailListsEachRetakeWithItsNote(): void
    {
        $mail = pretechEmailSentBack($this->sheet(), $this->event(), [
            ['label' => 'Harness date stamp', 'note' => 'Date not readable'],
            ['label' => 'Front three-quarter view', 'note' => 'Car number hidden <by a cone>'],
        ], 'https://x.test/tech-sheets.php?action=pretech&id=12');

        $this->assertStringContainsString('changes needed', $mail['subject']);
        foreach (['Harness date stamp', 'Date not readable', 'Front three-quarter view', 'Car number hidden'] as $needle) {
            $this->assertStringContainsString($needle, $mail['html']);
            $this->assertStringContainsString($needle, $mail['text']);
        }
        $this->assertStringContainsString('&lt;by a cone&gt;', $mail['html']);
        $this->assertStringContainsString('https://x.test/tech-sheets.php?action=pretech&amp;id=12', $mail['html']);
    }

    public function testAcceptedEmailHasDisclaimerAndDecalsInstruction(): void
    {
        $mail = pretechEmailAccepted($this->sheet(), $this->event(), 'https://x.test/tech-sheets.php?action=view&id=12');
        $this->assertStringContainsString('Pre-Tech Accepted', $mail['subject']);
        $this->assertStringContainsString(TECH_ACCEPTANCE_DISCLAIMER, $mail['html']);
        $this->assertStringContainsString(TECH_ACCEPTANCE_DISCLAIMER, $mail['text']);
        $this->assertStringContainsString('decals', $mail['text']);
        $this->assertStringContainsString('2026', $mail['text']);
    }

    public function testNoApprovalWordingOutsideTheDisclaimer(): void
    {
        $mails = [
            pretechEmailSubmitted($this->sheet(), $this->event(), 'a', 'b', 3, true),
            pretechEmailSubmitted($this->sheet(), $this->event(), 'a', 'b', 3, false),
            pretechEmailSentBack($this->sheet(), $this->event(), [['label' => 'X', 'note' => 'Y']], 'b'),
            pretechEmailAccepted($this->sheet(), $this->event(), 'v'),
        ];
        foreach ($mails as $mail) {
            $text = str_replace(TECH_ACCEPTANCE_DISCLAIMER, '', $mail['subject'] . "\n" . $mail['text']);
            $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $text);
        }
    }

    private function notifyFixture(): array {
        $pdo = make_temp_pdo();
        $userId = db_create_user($pdo, ['email' => 'jane@example.com', 'name' => 'Jane', 'password_hash' => 'x', 'google_id' => null]);
        $sheet = $this->sheet(['user_id' => $userId]);
        return [$pdo, $sheet];
    }

    private function recorder(array &$log, bool $result = true): callable {
        return function (array $to, array $message) use (&$log, $result): bool {
            $log[] = ['to' => $to, 'subject' => $message['subject']];
            return $result;
        };
    }

    public function testNotifySubmittedGoesToClubAndCompetitor(): void
    {
        [$pdo, $sheet] = $this->notifyFixture();
        $log = [];
        $ok = pretechNotify($pdo, 'submitted', $sheet, $this->event(), 'https://x.test/', ['email' => 'club@example.com', 'name' => 'Club'], $this->recorder($log));

        $this->assertTrue($ok);
        $this->assertCount(2, $log);
        $recipients = array_map(fn($e) => $e['to'][0][0], $log);
        $this->assertEqualsCanonicalizing(['club@example.com', 'jane@example.com'], $recipients);
    }

    public function testNotifySentBackGoesToTheCompetitorOnlyWithLabelsFromTheRequirementList(): void
    {
        [$pdo, $sheet] = $this->notifyFixture();
        $captured = [];
        $sendFn = function (array $to, array $message) use (&$captured): bool { $captured[] = [$to, $message]; return true; };

        $ok = pretechNotify($pdo, 'sent_back', $sheet, $this->event(), 'https://x.test', ['email' => 'club@example.com', 'name' => 'Club'], $sendFn,
            ['harness_date' => 'Date not readable']);

        $this->assertTrue($ok);
        $this->assertCount(1, $captured);
        $this->assertSame('jane@example.com', $captured[0][0][0][0]);
        $this->assertStringContainsString('Harness date stamp', $captured[0][1]['text']);
        $this->assertStringContainsString('Date not readable', $captured[0][1]['text']);
        $this->assertStringContainsString('https://x.test/tech-sheets.php?action=pretech&id=12', $captured[0][1]['text']);
    }

    public function testNotifyAcceptedGoesToBoth(): void
    {
        [$pdo, $sheet] = $this->notifyFixture();
        $log = [];
        $this->assertTrue(pretechNotify($pdo, 'accepted', $sheet, $this->event(), 'https://x.test', ['email' => 'club@example.com', 'name' => 'Club'], $this->recorder($log)));
        $this->assertCount(2, $log);
        $this->assertStringContainsString('Accepted', $log[0]['subject']);
    }

    public function testNotifyReportsFailureAndSurvivesExceptions(): void
    {
        [$pdo, $sheet] = $this->notifyFixture();
        $club = ['email' => 'club@example.com', 'name' => 'Club'];

        $log = [];
        $this->assertFalse(pretechNotify($pdo, 'accepted', $sheet, $this->event(), 'https://x.test', $club, $this->recorder($log, false)));
        $this->assertCount(2, $log);   // a failed send does not stop the others

        $boom = function (array $to, array $message): bool { throw new RuntimeException('smtp down'); };
        $this->assertFalse(pretechNotify($pdo, 'accepted', $sheet, $this->event(), 'https://x.test', $club, $boom));

        $this->assertFalse(pretechNotify($pdo, 'bogus', $sheet, $this->event(), 'https://x.test', $club, $this->recorder($log)));
    }

    public function testMailDryRunLogsInsteadOfSending(): void
    {
        $file = sys_get_temp_dir() . '/wcma_mail_' . uniqid() . '.log';
        define('WCMA_MAIL_LOG', $file);

        $ok = emailSmtpSend([['jane@example.com', 'Jane']], ['subject' => 'Hello', 'html' => '<p>x</p>', 'text' => 'plain body']);

        $this->assertTrue($ok);
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $this->assertCount(1, $lines);
        $entry = json_decode($lines[0], true);
        $this->assertSame([['jane@example.com', 'Jane']], $entry['to']);
        $this->assertSame('Hello', $entry['subject']);
        $this->assertSame('plain body', $entry['text']);
        unlink($file);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter PretechEmailTest`
Expected: fatal error, `pretech-email.php` not found.

- [ ] **Step 3: Write the emails module**

Create `wcma-calculator/pretech-email.php`:

```php
<?php
// wcma-calculator/pretech-email.php
//
// Emails for the photo pre-tech workflow: pure renderers (branded like the tech sheet emails,
// logo via cid:wcma-logo, photos are never embedded) and a notifier with an injectable send
// function. Callers must have loaded db.php first.
require_once __DIR__ . '/view_helpers.php';        // h()
require_once __DIR__ . '/photo-requirements.php';  // photoRequirementByKey()
require_once __DIR__ . '/tech-sheet-data.php';     // TECH_ACCEPTANCE_DISCLAIMER

function pretechEmailCarLine(array $sheet, array $event): string {
    $when = !empty($event['event_date']) ? ', ' . date('F j, Y', strtotime($event['event_date'])) : '';
    return 'Car #' . $sheet['car_number'] . ' — ' . trim($sheet['car_make'] . ' ' . $sheet['car_model'])
        . ' (' . $sheet['entrant_name'] . ') — ' . ($event['name'] ?? '') . $when;
}

/** Branded HTML shell: logo, title, then already-escaped body HTML. */
function pretechEmailWrap(string $title, string $bodyHtml): string {
    return '<html><body><div style="font-family:Arial,sans-serif;color:#222;max-width:800px">'
        . '<div style="text-align:center;margin-bottom:0.5rem"><img src="cid:wcma-logo" alt="WCMA Logo" style="max-height:70px"></div>'
        . '<h1 style="text-align:center;margin-bottom:0.2rem">' . h($title) . '</h1>'
        . $bodyHtml
        . '</div></body></html>';
}

function pretechEmailPara(string $text): string {
    return '<p>' . h($text) . '</p>';
}

function pretechEmailLink(string $url, string $label): string {
    return '<p><a href="' . h($url) . '">' . h($label) . '</a></p>';
}

/** @return array{subject: string, html: string, text: string} */
function pretechEmailSubmitted(array $sheet, array $event, string $adminUrl, string $pageUrl, int $photoCount, bool $forClub): array {
    $car = pretechEmailCarLine($sheet, $event);
    $count = $photoCount . ' ' . ($photoCount === 1 ? 'photo' : 'photos');

    if ($forClub) {
        $subject = 'WCMA Pre-Tech Submitted — Car #' . $sheet['car_number'] . ' — ' . ($event['name'] ?? '');
        $lines = ['A pre-tech photo set is waiting for review.', $car, 'Photos submitted: ' . $count . '.', 'Review it here:', $adminUrl];
        $html = pretechEmailPara($lines[0]) . pretechEmailPara($car) . pretechEmailPara($lines[2]) . pretechEmailLink($adminUrl, 'Open the review page');
        $title = 'PRE-TECH SUBMITTED';
    } else {
        $subject = 'Your WCMA pre-tech submission — ' . ($event['name'] ?? '');
        $lines = [
            'We received your photos (' . $count . ') for ' . $car . '.',
            'An inspector will review them. You will get an email when they are accepted or when a photo needs to be retaken.',
            'You can still be teched in person at the track if you prefer.',
            'You can check your submission here:', $pageUrl,
        ];
        $html = pretechEmailPara($lines[0]) . pretechEmailPara($lines[1]) . pretechEmailPara($lines[2]) . pretechEmailLink($pageUrl, 'View your pre-tech photos');
        $title = 'PRE-TECH RECEIVED';
    }
    return ['subject' => $subject, 'html' => pretechEmailWrap($title, $html), 'text' => implode("\n\n", $lines) . "\n"];
}

/**
 * @param array<int, array{label: string, note: string}> $retakes
 * @return array{subject: string, html: string, text: string}
 */
function pretechEmailSentBack(array $sheet, array $event, array $retakes, string $pageUrl): array {
    $car = pretechEmailCarLine($sheet, $event);
    $intro = 'An inspector reviewed your pre-tech photos for ' . $car . ' and needs the following photos retaken:';

    $html = pretechEmailPara($intro) . '<ul>';
    $text = $intro . "\n\n";
    foreach ($retakes as $r) {
        $html .= '<li><strong>' . h($r['label']) . '</strong>: ' . h($r['note']) . '</li>';
        $text .= '- ' . $r['label'] . ': ' . $r['note'] . "\n";
    }
    $html .= '</ul>' . pretechEmailPara('Retake them and submit again. The photos that were not flagged do not need to be redone.')
        . pretechEmailLink($pageUrl, 'Open your pre-tech page');
    $text .= "\nRetake them and submit again. The photos that were not flagged do not need to be redone.\n" . $pageUrl . "\n";

    return [
        'subject' => 'WCMA Pre-Tech — changes needed — Car #' . $sheet['car_number'],
        'html' => pretechEmailWrap('PRE-TECH: CHANGES NEEDED', $html),
        'text' => $text,
    ];
}

/** @return array{subject: string, html: string, text: string} */
function pretechEmailAccepted(array $sheet, array $event, string $viewUrl): array {
    $car = pretechEmailCarLine($sheet, $event);
    $season = (int)($sheet['season'] ?? date('Y'));
    $lines = [
        'The pre-tech photos for ' . $car . ' were reviewed and accepted. This car is pre-teched for ' . $season . '.',
        'You do not need to be inspected at the track: just collect your decals at the event.',
        TECH_ACCEPTANCE_DISCLAIMER,
        'Your tech sheet:', $viewUrl,
    ];
    $html = pretechEmailPara($lines[0]) . pretechEmailPara($lines[1])
        . '<p style="font-size:0.85rem;color:#555">' . h(TECH_ACCEPTANCE_DISCLAIMER) . '</p>'
        . pretechEmailLink($viewUrl, 'View your tech sheet');

    return [
        'subject' => 'WCMA Pre-Tech Accepted — Car #' . $sheet['car_number'] . ' — ' . ($event['name'] ?? ''),
        'html' => pretechEmailWrap('PRE-TECH ACCEPTED', $html),
        'text' => implode("\n\n", $lines) . "\n",
    ];
}

/**
 * Sends the emails for one workflow step. Failures never propagate: the workflow step has
 * already happened, so this only reports whether every message was sent.
 *
 * @param string $kind 'submitted' | 'sent_back' | 'accepted'
 * @param array{email: string, name: string} $club recipient for club copies
 * @param callable $sendFn function(array $to, array $message): bool; $to is a list of [email, name]
 * @param array<string,string> $retakes requirement key => note (sent_back only)
 */
function pretechNotify(PDO $pdo, string $kind, array $sheet, array $event, string $baseUrl, array $club, callable $sendFn, array $retakes = []): bool {
    try {
        $base = rtrim($baseUrl, '/');
        $id = (int)$sheet['id'];
        $pageUrl = $base . '/tech-sheets.php?action=pretech&id=' . $id;
        $viewUrl = $base . '/tech-sheets.php?action=view&id=' . $id;
        $adminUrl = $base . '/admin.php?action=tech-sheet&id=' . $id;

        $owner = db_find_user_by_id($pdo, (int)$sheet['user_id']);
        $competitor = $owner ? [[$owner['email'], $owner['name']]] : [];
        $clubTo = [[$club['email'], $club['name']]];

        $messages = [];   // list of [to, message]
        switch ($kind) {
            case 'submitted':
                $count = count(array_filter(db_get_inspection_photos($pdo, 'tech_sheet', $id), fn(array $p): bool => $p['file_path'] !== ''));
                $messages[] = [$clubTo, pretechEmailSubmitted($sheet, $event, $adminUrl, $pageUrl, $count, true)];
                if ($competitor) $messages[] = [$competitor, pretechEmailSubmitted($sheet, $event, $adminUrl, $pageUrl, $count, false)];
                break;
            case 'sent_back':
                $list = [];
                foreach ($retakes as $key => $note) {
                    $req = photoRequirementByKey((string)$key);
                    $list[] = ['label' => $req['label'] ?? (string)$key, 'note' => (string)$note];
                }
                if ($competitor) $messages[] = [$competitor, pretechEmailSentBack($sheet, $event, $list, $pageUrl)];
                break;
            case 'accepted':
                $mail = pretechEmailAccepted($sheet, $event, $viewUrl);
                if ($competitor) $messages[] = [$competitor, $mail];
                $messages[] = [$clubTo, $mail];
                break;
            default:
                return false;
        }

        $allSent = true;
        foreach ($messages as [$to, $message]) {
            if (!$sendFn($to, $message)) $allSent = false;
        }
        return $allSent;
    } catch (Throwable $e) {
        error_log('Pre-tech notification error: ' . $e->getMessage());
        return false;
    }
}
```

- [ ] **Step 4: Add the SMTP sender**

Append to `wcma-calculator/email-helpers.php`:

```php

/**
 * Production send function for pretechNotify(): one SMTP message to $to (a list of [email, name]),
 * with the WCMA logo embedded. If the constant WCMA_MAIL_LOG is defined, the message is appended to
 * that file as a JSON line and NOTHING is sent (development / end-to-end runs).
 *
 * @param array{subject: string, html: string, text: string} $message
 */
function emailSmtpSend(array $to, array $message): bool {
    if (defined('WCMA_MAIL_LOG')) {
        file_put_contents(WCMA_MAIL_LOG, json_encode(['to' => $to, 'subject' => $message['subject'], 'text' => $message['text']]) . "\n", FILE_APPEND);
        return true;
    }
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = (SMTP_PORT === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        foreach ($to as [$address, $name]) {
            $mail->addAddress($address, $name);
        }
        $mail->Subject = $message['subject'];
        $mail->isHTML(true);
        $mail->Body    = $message['html'];
        $mail->AltBody = $message['text'];
        emailLogoSrc($mail);
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Pre-tech email error: ' . $e->getMessage());
        return false;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php phpunit.phar`
Expected: `OK` for the whole suite, including `PretechEmailTest` (9 tests). Note: `testMailDryRunLogsInsteadOfSending` defines the global constant `WCMA_MAIL_LOG` for the rest of the test process, which is harmless because nothing else in the suite sends mail.

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/pretech-email.php wcma-calculator/email-helpers.php wcma-calculator/tests/PretechEmailTest.php
git commit -m "feat(pre-tech): add pre-tech emails, notifier and mail dry-run sender"
```

---

### Task 5: Competitor pre-tech page

**Files:**
- Create: `wcma-calculator/pretech-page.php`
- Create: `wcma-calculator/js/pretech-form.js`
- Modify: `wcma-calculator/tech-sheets.php` (requires, two routes, two handlers, a link on the sheet view)
- Modify: `wcma-calculator/css/calculator.css` (append styles)
- Test: `wcma-calculator/tests/PretechPageTest.php`

**Interfaces:**
- Consumes: Tasks 1-4; `renderSiteHeader()`, `renderCommonNav()`, `h()`; existing constants `TECH_SHEET_EMAIL` / `TECH_SHEET_EMAIL_NAME` in `tech-sheets.php`; `feedbackBaseUrl()` from `feedback-lib.php`; `WcmaPhotoUpload.browserClient()`, `WcmaPretechProgress.computeMissing()`.
- Produces:
  - `renderPretechPage(array $sheet, array $event, array $mode, array $snapshot, string $csrf, ?array $flash): void` — full HTML page.
  - Routes `GET tech-sheets.php?action=pretech&id=N` and `POST tech-sheets.php?action=pretech-submit` (`csrf_token`, `id`).
  - Markup contract used by the JS and the end-to-end script: each requirement is `<div class="pretech-card" data-key="…" data-tier="required|conditional|recommended">` containing `input[type=file][data-photo-input]`, `[data-status]` (text), `img[data-thumb]`, `[data-error]`; conditional cards also have `input[data-applies-toggle]`; typed fields are `[data-typed="<name>"]`; the page has `#pretech-progress` (text `X of Y required photos`), `#pretech-fill` (progress bar), and the submit button `#pretech-submit-btn` inside `#pretech-submit-form`.
  - `window.PRETECH_STATE = {sheetId, csrf, locked, requirements: [{key, tier, typed}], photos: {key: publicPhoto}, applicable: [keys]}`.

The page has three modes (from `pretechPageMode`): `car_accepted` (a banner only), `held_elsewhere` (a banner linking to the sheet that holds the photos), `this_sheet` (the form; read-only when the sheet's photo status is `submitted` or `accepted`).

- [ ] **Step 1: Write the failing page test**

Create `wcma-calculator/tests/PretechPageTest.php`:

```php
<?php
// wcma-calculator/tests/PretechPageTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../pretech-lib.php';
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../pretech-page.php';

use PHPUnit\Framework\TestCase;

// The page header calls current_user()/is_admin() (session_bootstrap.php, which starts a session and
// is not loaded in unit tests). A signed-out stub is enough to render the layout.
if (!function_exists('current_user')) {
    function current_user(): ?array { return null; }
}
if (!function_exists('is_admin')) {
    function is_admin(): bool { return false; }
}

final class PretechPageTest extends TestCase
{
    private function sheet(array $o = []): array {
        return array_merge([
            'id' => 12, 'user_id' => 1, 'event_id' => 3, 'car_number' => '42', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'entrant_name' => 'Jane', 'status' => 'submitted', 'photo_status' => null, 'season' => 2026,
        ], $o);
    }

    private function snapshot(array $photos = [], array $applicable = []): array {
        $present = array_keys(array_filter($photos, fn($p) => $p['file_path'] !== ''));
        return ['photos' => $photos, 'present' => $present, 'applicable' => $applicable, 'missing' => photoSetMissingRequired('car', $present, $applicable)];
    }

    private function photoRow(string $key, array $o = []): array {
        return array_merge([
            'id' => 5, 'requirement_key' => $key, 'file_path' => 'uploads/x.jpg', 'typed_value' => null,
            'review_status' => 'pending', 'reviewer_note' => null, 'applies' => 1,
        ], $o);
    }

    private function render(array $sheet, array $mode, array $snapshot, ?array $flash = null): string {
        ob_start();
        renderPretechPage($sheet, ['name' => 'Spring Sprint', 'event_date' => '2026-05-10'], $mode, $snapshot, 'csrf-token-1', $flash);
        return (string)ob_get_clean();
    }

    public function testFormModeShowsACardForEveryRequirementAndAnEmptyProgress(): void
    {
        $html = $this->render($this->sheet(), ['mode' => 'this_sheet', 'sheet_id' => null], $this->snapshot());

        $this->assertSame(count(photoRequirements('car')), substr_count($html, 'class="pretech-card"'));
        foreach (['front_34', 'harness_date', 'ballast'] as $key) {
            $this->assertStringContainsString('data-key="' . $key . '"', $html);
        }
        $this->assertStringContainsString('data-tier="conditional"', $html);
        $this->assertStringContainsString('0 of 15 required photos', $html);
        $this->assertStringContainsString('id="pretech-submit-btn"', $html);
        $this->assertMatchesRegularExpression('/id="pretech-submit-btn"[^>]*disabled/', $html);
        $this->assertStringContainsString('name="csrf_token" value="csrf-token-1"', $html);
        $this->assertStringContainsString('data-photo-input', $html);
        $this->assertStringContainsString('window.PRETECH_STATE', $html);
        $this->assertStringContainsString('js/pretech-form.js', $html);
    }

    public function testCompleteSetEnablesSubmitAndShowsPhotosAndRetakeNotes(): void
    {
        $photos = [];
        foreach (photoRequirements('car') as $key => $def) {
            if ($def['tier'] === 'required') $photos[$key] = $this->photoRow($key, ['id' => count($photos) + 1]);
        }
        $photos['front_34']['review_status'] = 'retake';
        $photos['front_34']['reviewer_note'] = 'Car number is hidden <behind a cone>';
        $photos['harness_date']['typed_value'] = '{"date":"05/2025"}';

        $html = $this->render($this->sheet(['photo_status' => 'needs_changes']), ['mode' => 'this_sheet', 'sheet_id' => null], $this->snapshot($photos));

        $this->assertStringContainsString('15 of 15 required photos', $html);
        $this->assertDoesNotMatchRegularExpression('/id="pretech-submit-btn"[^>]*disabled/', $html);
        $this->assertStringContainsString('Retake requested', $html);
        $this->assertStringContainsString('Car number is hidden &lt;behind a cone&gt;', $html);
        $this->assertStringNotContainsString('<behind a cone>', $html);
        $this->assertStringContainsString('value="05/2025"', $html);
        $this->assertStringContainsString('inspection.php?action=photo&amp;id=1', $html);
    }

    public function testLockedSheetIsReadOnly(): void
    {
        $html = $this->render($this->sheet(['photo_status' => 'submitted']), ['mode' => 'this_sheet', 'sheet_id' => null], $this->snapshot());
        $this->assertStringContainsString('submitted for review', $html);
        $this->assertStringNotContainsString('data-photo-input', $html);
        $this->assertStringNotContainsString('id="pretech-submit-btn"', $html);
        $this->assertStringContainsString('"locked":true', $html);
    }

    public function testCarAcceptedAndHeldElsewhereModesShowOnlyABanner(): void
    {
        $accepted = $this->render($this->sheet(), ['mode' => 'car_accepted', 'sheet_id' => 9], $this->snapshot());
        $this->assertStringContainsString('already teched', $accepted);
        $this->assertStringNotContainsString('pretech-card', $accepted);

        $elsewhere = $this->render($this->sheet(), ['mode' => 'held_elsewhere', 'sheet_id' => 9], $this->snapshot());
        $this->assertStringContainsString('tech-sheets.php?action=pretech&amp;id=9', $elsewhere);
        $this->assertStringNotContainsString('pretech-card', $elsewhere);
    }

    public function testApplicableConditionalIsCheckedAndCounted(): void
    {
        $html = $this->render($this->sheet(['photo_status' => 'draft']), ['mode' => 'this_sheet', 'sheet_id' => null],
            $this->snapshot(['ballast' => $this->photoRow('ballast', ['file_path' => ''])], ['ballast']));
        $this->assertStringContainsString('0 of 16 required photos', $html);
        $this->assertMatchesRegularExpression('/data-applies-toggle[^>]*checked/', $html);
    }

    public function testCopyAvoidsBannedWording(): void
    {
        $html = $this->render($this->sheet(), ['mode' => 'this_sheet', 'sheet_id' => null], $this->snapshot());
        $text = strip_tags(preg_replace('/<script.*?<\/script>/s', '', $html));
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $text);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php phpunit.phar --filter PretechPageTest`
Expected: fatal error, `pretech-page.php` not found.

- [ ] **Step 3: Write the page renderer**

Create `wcma-calculator/pretech-page.php`:

```php
<?php
// wcma-calculator/pretech-page.php
//
// Markup for the competitor's "Get pre-teched" page (tech-sheets.php?action=pretech). Pure output;
// the decisions (mode, completeness) are made by pretech-lib.php. Callers must have loaded
// photo-requirements.php, inspection-lib.php (inspectionPublicPhoto) and view_helpers.php.

function pretechPhotoStatusText(?array $photo, string $tier): string {
    if ($photo === null || $photo['file_path'] === '') return $tier === 'required' ? 'Photo needed' : 'No photo yet';
    if ($photo['review_status'] === 'retake') return 'Retake requested';
    if ($photo['review_status'] === 'accepted') return 'Accepted';
    return 'Added';
}

function pretechRenderTypedField(array $field, array $typed, bool $locked): string {
    $id = 'typed-' . $field['name'] . '-' . bin2hex(random_bytes(3));
    $value = (string)($typed[$field['name']] ?? '');
    $out = '<label for="' . h($id) . '">' . h($field['label']) . '</label>';
    if ($field['type'] === 'select') {
        $out .= '<select id="' . h($id) . '" data-typed="' . h($field['name']) . '"' . ($locked ? ' disabled' : '') . '><option value="">—</option>';
        foreach ($field['options'] as $option) {
            $out .= '<option value="' . h($option) . '"' . ($option === $value ? ' selected' : '') . '>' . h($option) . '</option>';
        }
        return $out . '</select>';
    }
    $placeholder = $field['type'] === 'month_year' ? ' placeholder="MM/YYYY" inputmode="numeric" maxlength="7"' : '';
    return $out . '<input type="text" id="' . h($id) . '" data-typed="' . h($field['name']) . '" value="' . h($value) . '"' . $placeholder . ($locked ? ' disabled' : '') . '>';
}

function pretechRenderCard(string $key, array $req, ?array $photoRow, bool $applies, bool $locked): string {
    $hasPhoto = $photoRow !== null && $photoRow['file_path'] !== '';
    $public = $hasPhoto ? inspectionPublicPhoto($photoRow) : null;
    $typed = $public['typed'] ?? [];
    $isRetake = $hasPhoto && $photoRow['review_status'] === 'retake';

    $out = '<div class="pretech-card" data-key="' . h($key) . '" data-tier="' . h($req['tier']) . '">';
    $out .= '<h3>' . h($req['label']) . ' <span class="pretech-status ' . ($isRetake ? 'badge-fail' : ($hasPhoto ? 'badge-ok' : 'badge-pending')) . '" data-status>'
        . h(pretechPhotoStatusText($photoRow, $req['tier'])) . '</span></h3>';
    $out .= '<p class="form-hint">' . h($req['guidance']) . '</p>';
    if ($req['tier'] === 'recommended') {
        $out .= '<p class="form-hint">Recommended, not required.</p>';
    }
    if ($isRetake && !empty($photoRow['reviewer_note'])) {
        $out .= '<p class="pretech-note badge-fail">Inspector note: ' . h((string)$photoRow['reviewer_note']) . '</p>';
    }
    if ($req['tier'] === 'conditional') {
        $out .= '<label class="pretech-toggle"><input type="checkbox" data-applies-toggle' . ($applies ? ' checked' : '') . ($locked ? ' disabled' : '')
            . '> This applies to my car</label>';
    }
    $out .= '<img class="pretech-thumb" data-thumb alt="' . h($req['label']) . '"' . ($hasPhoto ? ' src="' . h($public['url']) . '"' : ' hidden') . '>';
    foreach ($req['typed'] as $field) {
        $out .= '<div class="pretech-typed">' . pretechRenderTypedField($field, $typed, $locked) . '</div>';
    }
    if (!$locked) {
        $out .= '<label class="btn btn-secondary pretech-upload">' . ($hasPhoto ? 'Retake photo' : 'Take or choose photo')
            . '<input type="file" accept="image/*" capture="environment" data-photo-input hidden></label>';
    }
    $out .= '<p class="badge-fail" data-error hidden></p>';
    return $out . '</div>';
}

function renderPretechPage(array $sheet, array $event, array $mode, array $snapshot, string $csrf, ?array $flash): void {
    $id = (int)$sheet['id'];
    $requirements = photoRequirements('car');
    $photoStatus = $sheet['photo_status'] ?? null;
    $locked = in_array($photoStatus, ['submitted', 'accepted'], true) || ($sheet['status'] ?? '') === 'teched';
    $formMode = $mode['mode'] === 'this_sheet';
    $missing = count($snapshot['missing']);
    $requiredTotal = count($requirements) - count(array_filter($requirements, fn(array $r): bool => $r['tier'] === 'recommended'))
        - count(array_filter($requirements, fn(array $r): bool => $r['tier'] === 'conditional'))
        + count($snapshot['applicable']);
    $done = $requiredTotal - $missing;

    $clientPhotos = [];
    foreach ($snapshot['photos'] as $key => $row) {
        if ($row['file_path'] !== '') $clientPhotos[$key] = inspectionPublicPhoto($row);
    }
    $clientRequirements = [];
    foreach ($requirements as $key => $req) {
        $clientRequirements[] = ['key' => $key, 'tier' => $req['tier'], 'typed' => array_map(fn(array $f): array => ['name' => $f['name'], 'type' => $f['type']], $req['typed'])];
    }
    $state = [
        'sheetId' => $id, 'csrf' => $csrf, 'locked' => $locked,
        'requirements' => $clientRequirements, 'photos' => (object)$clientPhotos, 'applicable' => $snapshot['applicable'],
    ];
    $carLine = 'Car #' . $sheet['car_number'] . ' — ' . trim($sheet['car_make'] . ' ' . $sheet['car_model']) . ' — ' . ($event['name'] ?? '');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Get pre-teched — WCMA Calculator</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Get pre-teched', '<a href="tech-sheets.php?action=view&amp;id=' . $id . '">← Back to tech sheet</a>' . renderCommonNav('account')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <div class="detail-card">
    <h2><?= h($carLine) ?></h2>
    <?php if ($mode['mode'] === 'car_accepted'): ?>
      <p>This car is already teched for <?= (int)($sheet['season'] ?? date('Y')) ?>. You do not need to submit photos.</p>
    <?php elseif ($mode['mode'] === 'held_elsewhere'): ?>
      <p>Your pre-tech photos for this car are on another of your tech sheets.
        <a href="tech-sheets.php?action=pretech&amp;id=<?= (int)$mode['sheet_id'] ?>">Open that page</a>.</p>
    <?php else: ?>
      <p>Optional: submit photos of your car so an inspector can review them before the event. If they are accepted, you skip inspection at the track and just collect your decals. You can still be teched in person instead.</p>
      <?php if ($photoStatus === 'submitted'): ?>
        <p class="badge-pending">Your photos were submitted for review. You will get an email when an inspector has looked at them.</p>
      <?php elseif ($photoStatus === 'needs_changes'): ?>
        <p class="badge-fail">An inspector asked for some photos to be retaken. Retake the flagged photos below, then submit again.</p>
      <?php elseif ($photoStatus === 'accepted'): ?>
        <p class="badge-ok">Your photos were reviewed and accepted.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

<?php if ($formMode): ?>
  <div class="checklist-progress-wrap">
    <div class="checklist-progress-label" id="pretech-progress"><?= (int)$done ?> of <?= (int)$requiredTotal ?> required photos</div>
    <div class="checklist-progress-bar"><div class="checklist-progress-fill" id="pretech-fill" style="width:<?= $requiredTotal > 0 ? (int)round($done / $requiredTotal * 100) : 0 ?>%"></div></div>
  </div>

  <?php foreach ($requirements as $key => $req): ?>
    <?= pretechRenderCard($key, $req, $snapshot['photos'][$key] ?? null, in_array($key, $snapshot['applicable'], true), $locked) ?>
  <?php endforeach; ?>

  <?php if (!$locked): ?>
  <form method="post" action="tech-sheets.php?action=pretech-submit" id="pretech-submit-form" class="detail-card">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <button type="submit" class="btn btn-primary" id="pretech-submit-btn"<?= $missing > 0 ? ' disabled' : '' ?>>Submit for pre-tech review</button>
    <p class="form-hint" id="pretech-submit-hint"><?= $missing > 0 ? 'Add every required photo to enable submitting.' : 'Everything required is in. Submit when you are ready.' ?></p>
  </form>
  <?php endif; ?>

  <script>window.PRETECH_STATE = <?= json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
  <script src="js/photo-resize.js"></script>
  <script src="js/photo-upload.js"></script>
  <script src="js/pretech-progress.js"></script>
  <script src="js/pretech-form.js"></script>
<?php endif; ?>
</div>
<script src="js/form-feedback.js"></script>
</body>
</html><?php
}
```

- [ ] **Step 4: Add the page behaviour script**

Create `wcma-calculator/js/pretech-form.js`:

```js
// wcma-calculator/js/pretech-form.js
// Behaviour of the competitor pre-tech page: upload each photo as soon as it is chosen (one request
// per photo), edit typed details, toggle conditional photos, and keep the progress bar and the
// submit button in step. State arrives in window.PRETECH_STATE (see pretech-page.php).
(function () {
    'use strict';

    const state = window.PRETECH_STATE;
    if (!state || state.locked) return;

    const client = WcmaPhotoUpload.browserClient(state.csrf);
    const present = new Set(Object.keys(state.photos));
    const applicable = new Set(state.applicable);

    const progressLabel = document.getElementById('pretech-progress');
    const progressFill = document.getElementById('pretech-fill');
    const submitBtn = document.getElementById('pretech-submit-btn');
    const submitHint = document.getElementById('pretech-submit-hint');

    function cards() { return Array.prototype.slice.call(document.querySelectorAll('.pretech-card')); }
    function cardFor(key) { return document.querySelector('.pretech-card[data-key="' + key + '"]'); }

    function requiredTotal() {
        return state.requirements.filter(function (r) {
            return r.tier === 'required' || (r.tier === 'conditional' && applicable.has(r.key));
        }).length;
    }

    function refresh() {
        const missing = WcmaPretechProgress.computeMissing(state.requirements, Array.from(present), Array.from(applicable));
        const total = requiredTotal();
        const done = total - missing.length;
        progressLabel.textContent = done + ' of ' + total + ' required photos';
        progressFill.style.width = (total > 0 ? Math.round(done / total * 100) : 0) + '%';
        if (submitBtn) {
            submitBtn.disabled = missing.length > 0;
            submitHint.textContent = missing.length > 0
                ? 'Add every required photo to enable submitting.'
                : 'Everything required is in. Submit when you are ready.';
        }
    }

    function setError(card, message) {
        const el = card.querySelector('[data-error]');
        el.textContent = message || '';
        el.hidden = !message;
    }

    function setStatus(card, text, cls) {
        const el = card.querySelector('[data-status]');
        el.textContent = text;
        el.className = 'pretech-status ' + cls;
    }

    function typedValues(card) {
        const values = {};
        card.querySelectorAll('[data-typed]').forEach(function (input) {
            values[input.getAttribute('data-typed')] = input.value;
        });
        return values;
    }

    function showPhoto(card, photo) {
        const img = card.querySelector('[data-thumb]');
        img.src = photo.url + '&v=' + Date.now();   // same URL after a retake: bust the cache
        img.hidden = false;
        const note = card.querySelector('.pretech-note');
        if (note) note.remove();
        setStatus(card, 'Added', 'badge-ok');
        const label = card.querySelector('.pretech-upload');
        if (label && label.firstChild) label.firstChild.textContent = 'Retake photo';
    }

    cards().forEach(function (card) {
        const key = card.getAttribute('data-key');

        const fileInput = card.querySelector('[data-photo-input]');
        if (fileInput) {
            fileInput.addEventListener('change', async function () {
                const file = fileInput.files && fileInput.files[0];
                if (!file) return;
                setError(card, '');
                setStatus(card, 'Uploading…', 'badge-pending');
                try {
                    const photo = await client.upload({
                        file: file, subjectType: 'tech_sheet', subjectId: state.sheetId,
                        requirementKey: key, typed: typedValues(card),
                    });
                    state.photos[key] = photo;
                    present.add(key);
                    showPhoto(card, photo);
                } catch (e) {
                    setError(card, e.message);
                    setStatus(card, present.has(key) ? 'Added' : 'Photo needed', present.has(key) ? 'badge-ok' : 'badge-pending');
                } finally {
                    fileInput.value = '';
                    refresh();
                }
            });
        }

        card.querySelectorAll('[data-typed]').forEach(function (input) {
            input.addEventListener('change', async function () {
                const photo = state.photos[key];
                if (!photo) return;   // details are sent with the photo when it is added
                setError(card, '');
                try {
                    state.photos[key] = await client.typed({ id: photo.id, typed: typedValues(card) });
                    setStatus(card, 'Added', 'badge-ok');
                } catch (e) {
                    setError(card, e.message);
                }
            });
        });

        const toggle = card.querySelector('[data-applies-toggle]');
        if (toggle) {
            toggle.addEventListener('change', async function () {
                setError(card, '');
                try {
                    await client.applies({ subjectType: 'tech_sheet', subjectId: state.sheetId, requirementKey: key, applies: toggle.checked });
                    if (toggle.checked) {
                        applicable.add(key);
                    } else {
                        applicable.delete(key);
                        present.delete(key);            // turning it off removes any photo already added
                        delete state.photos[key];
                        card.querySelector('[data-thumb]').hidden = true;
                        setStatus(card, 'No photo yet', 'badge-pending');
                    }
                } catch (e) {
                    toggle.checked = !toggle.checked;   // the server refused: put the switch back
                    setError(card, e.message);
                } finally {
                    refresh();
                }
            });
        }
    });

    refresh();
})();
```

- [ ] **Step 5: Append the page styles**

Append to the end of `wcma-calculator/css/calculator.css`:

```css

/* ── Pre-tech photo page ─────────────────────────────────────────────────── */
.pretech-card { border: 1px solid var(--border-color); border-radius: var(--border-radius); background: #fff; padding: 1rem; margin-bottom: 1rem; }
.pretech-card h3 { margin: 0 0 0.25rem; font-size: 1.05rem; }
.pretech-status { font-size: 0.85rem; margin-left: 0.4rem; }
.pretech-note { margin: 0.4rem 0; }
.pretech-thumb { display: block; max-width: 100%; max-height: 220px; margin: 0.5rem 0; border-radius: var(--border-radius); }
.pretech-typed { margin: 0.4rem 0; }
.pretech-typed label { display: block; font-size: 0.85rem; margin-bottom: 0.15rem; }
.pretech-upload { display: inline-block; cursor: pointer; margin-top: 0.4rem; }
.pretech-toggle { display: block; margin: 0.4rem 0; }
```

- [ ] **Step 6: Wire the routes, handlers and link in `tech-sheets.php`**

In `wcma-calculator/tech-sheets.php`:

1. After the existing `require __DIR__ . '/tech-sheet-files.php';` line add:
```php
require __DIR__ . '/feedback-lib.php';
require __DIR__ . '/photo-requirements.php';
require __DIR__ . '/inspection-lib.php';
require __DIR__ . '/pretech-lib.php';
require __DIR__ . '/pretech-email.php';
require __DIR__ . '/pretech-page.php';
```
2. In the router `switch`, add these cases immediately before `case 'sig':`:
```php
    case 'pretech':
        $user = requireTechSheetLogin();
        handlePretech($pdo, $user, (int)($_GET['id'] ?? 0));
        break;

    case 'pretech-submit':
        $user = requireTechSheetLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: account.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handlePretechSubmit($pdo, $user, (int)($_POST['id'] ?? 0));
        break;

```
3. Add these functions after `handleView()` (before `function handleEdit(`):
```php
function handlePretech(PDO $pdo, array $user, int $id): void {
    $sheet = db_get_user_tech_sheet($pdo, $user['id'], $id);
    if (!$sheet) {
        setFlash('Tech sheet not found.', 'error');
        header('Location: account.php');
        exit;
    }
    $event = db_get_event($pdo, (int)$sheet['event_id']) ?? [];
    $identity = db_get_identity_sheets($pdo, (int)$user['id'], (string)$sheet['car_number_norm'], (int)$sheet['season']);
    renderPretechPage($sheet, $event, pretechPageMode($sheet, $identity), pretechSnapshot($pdo, $id), generateCsrfToken(), getFlash());
}

function handlePretechSubmit(PDO $pdo, array $user, int $id): void {
    $sheet = db_get_user_tech_sheet($pdo, $user['id'], $id);
    if (!$sheet) {
        setFlash('Tech sheet not found.', 'error');
        header('Location: account.php');
        exit;
    }

    $result = pretechSubmit($pdo, $id);
    if (!$result['ok']) {
        setFlash($result['error'], 'error');
    } else {
        $event = db_get_event($pdo, (int)$sheet['event_id']) ?? [];
        $sent = pretechNotify(
            $pdo, 'submitted', db_get_tech_sheet($pdo, $id), $event,
            feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', '')),
            ['email' => TECH_SHEET_EMAIL, 'name' => TECH_SHEET_EMAIL_NAME], 'emailSmtpSend'
        );
        setFlash('Photos submitted for review.' . ($sent ? ' We emailed you a confirmation.' : ' The confirmation email could not be sent.'), $sent ? 'success' : 'error');
    }
    header('Location: tech-sheets.php?action=pretech&id=' . $id);
    exit;
}

```
4. In `handleView`'s action block, immediately after this existing block:
```php
    <?php if ($sheet['status'] === 'submitted'): ?>
    <a href="tech-sheets.php?action=edit&id=<?= (int)$sheet['id'] ?>" class="btn btn-secondary">Edit</a>
    <?php endif; ?>
```
add:
```php
    <?php if ($sheet['status'] === 'submitted' && $carStatus['state'] !== 'accepted'): ?>
    <a href="tech-sheets.php?action=pretech&id=<?= (int)$sheet['id'] ?>" class="btn btn-secondary">Get pre-teched (optional)</a>
    <?php endif; ?>
```

- [ ] **Step 7: Run the tests and lints**

Run: `php -l pretech-page.php && php -l tech-sheets.php && node --check js/pretech-form.js && php phpunit.phar`
Expected: `No syntax errors detected` twice, no output from `node --check`, then `OK` for the whole suite including `PretechPageTest` (6 tests). (The routes and handlers need `config.php` and are exercised in Task 7.)

- [ ] **Step 8: Commit**

```bash
git add wcma-calculator/pretech-page.php wcma-calculator/js/pretech-form.js wcma-calculator/tech-sheets.php wcma-calculator/css/calculator.css wcma-calculator/tests/PretechPageTest.php
git commit -m "feat(pre-tech): add competitor pre-tech photo page and submit"
```

---

### Task 6: Inspector photo review

**Files:**
- Modify: `wcma-calculator/admin-tech-sheets.php` (filter constant, photo review card, two handlers, wiring in `handleTechSheetView`/`renderTechSheetViewPage`)
- Modify: `wcma-calculator/admin.php` (requires; two routes)
- Modify: `wcma-calculator/tech-sheet-render.php` (remote-acceptance signature slot)
- Test: `wcma-calculator/tests/TechSheetRenderTest.php` (add cases), `wcma-calculator/tests/AdminTechCopyTest.php` (extend)

**Interfaces:**
- Consumes: `pretechAccept()`, `pretechSendBack()`, `pretechSnapshot()`, `pretechNotify()`, `emailSmtpSend()`, `photoRequirementByKey()`, `inspectionPublicPhoto()`; admin.php constants `TECH_EMAIL` / `TECH_NAME` and `feedbackBaseUrl()`.
- Produces:
  - Routes `POST admin.php?action=tech-sheet-photos-accept` (`csrf_token`, `id`) and `POST admin.php?action=tech-sheet-photos-send-back` (`csrf_token`, `id`, `retake[<key>]=1`, `note[<key>]=text`), both admin-only with CSRF.
  - `handleTechSheetPhotosAccept(PDO $pdo, int $id)`, `handleTechSheetPhotosSendBack(PDO $pdo, int $id)`, `renderPretechReviewCard(array $sheet, array $snapshot, string $csrf): void`.
  - `TECH_SHEET_FILTERS` gains `'pending_review' => 'Photos awaiting review'`.
  - Markup contract for the end-to-end script: the card is `#pretech-review`; accept button `#pretech-accept-btn`; per-photo controls `input[name="retake[<key>]"]` and `input[name="note[<key>]"]`; send-back button `#pretech-sendback-btn`.
  - The rendered sheet's tech-signature slot reads `Accepted remotely (photos reviewed)` for `accepted_via = 'photos'`.

- [ ] **Step 1: Write the failing tests**

Add to `wcma-calculator/tests/TechSheetRenderTest.php`, inside the class:

```php
    public function testRemotelyAcceptedSheetShowsRemoteAcceptanceInTheTechSignatureSlot(): void
    {
        require_once __DIR__ . '/../tech-sheet-render.php';
        $sheet = $this->sampleSheet();
        $sheet['status'] = 'teched';
        $sheet['accepted_via'] = 'photos';
        $sheet['reviewed_at'] = '2026-05-10 09:30:00';
        $html = renderTechSheetHtml($sheet, [], ['name' => 'Spring Sprint', 'event_date' => '2026-05-10']);
        $this->assertStringContainsString('Accepted remotely (photos reviewed)', $html);

        $inPerson = $sheet;
        $inPerson['accepted_via'] = 'in_person';
        $this->assertStringNotContainsString('Accepted remotely', renderTechSheetHtml($inPerson, [], ['name' => 'Spring Sprint', 'event_date' => '2026-05-10']));
    }
```

Append a test to `wcma-calculator/tests/AdminTechCopyTest.php` (inside the class), keeping the existing tests:

```php
    public function testPhotoReviewCardHasNoBannedWording(): void
    {
        $source = file_get_contents(__DIR__ . '/../admin-tech-sheets.php');
        $start = strpos($source, 'function renderPretechReviewCard');
        $this->assertNotFalse($start, 'renderPretechReviewCard must exist');
        $card = substr($source, $start);
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed)\b/i', $card);
        $this->assertDoesNotMatchRegularExpression('/\bsafe\b/i', $card);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "TechSheetRenderTest|AdminTechCopyTest"`
Expected: the render test fails (no remote wording yet) and the copy test fails (`renderPretechReviewCard must exist`).

- [ ] **Step 3: Update the rendered sheet**

In `wcma-calculator/tech-sheet-render.php`, replace this line:

```php
    $out .= '<td style="width:33%"><div>' . techSheetSignatureImg($sheet['tech_signature_path'] ?? null, 'tech', $resolveSignatureSrc) . '</div><p style="font-size:0.8rem">Tech Representative\'s Signature</p></td>';
```
with:
```php
    $techSignatureCell = (($sheet['status'] ?? '') === 'teched' && ($sheet['accepted_via'] ?? null) === 'photos')
        ? '<span style="color:#555">Accepted remotely (photos reviewed)</span>'
        : techSheetSignatureImg($sheet['tech_signature_path'] ?? null, 'tech', $resolveSignatureSrc);
    $out .= '<td style="width:33%"><div>' . $techSignatureCell . '</div><p style="font-size:0.8rem">Tech Representative\'s Signature</p></td>';
```

- [ ] **Step 4: Update the admin module**

In `wcma-calculator/admin-tech-sheets.php`:

1. Replace the `TECH_SHEET_FILTERS` constant with:
```php
const TECH_SHEET_FILTERS = [
    'all' => 'All sheets',
    'needs_tech' => 'Needs tech at the track',
    'pending_review' => 'Photos awaiting review',
    'accepted' => 'Accepted',
];
```
2. In `handleTechSheetView`, change
```php
    renderTechSheetViewPage($sheet, $drivers, $event, $carStatus, $reviewer, generateCsrfToken(), getFlash());
```
to
```php
    renderTechSheetViewPage($sheet, $drivers, $event, $carStatus, $reviewer, generateCsrfToken(), getFlash(), pretechSnapshot($pdo, $id));
```
3. Change the `renderTechSheetViewPage` signature from
```php
function renderTechSheetViewPage(array $sheet, array $drivers, array $event, array $carStatus, ?array $reviewer, string $csrf, ?array $flash): void {
```
to
```php
function renderTechSheetViewPage(array $sheet, array $drivers, array $event, array $carStatus, ?array $reviewer, string $csrf, ?array $flash, array $snapshot): void {
```
4. In `renderTechSheetViewPage`, immediately before the line `  <?= renderTechSheetHtml($sheet, $drivers, $event, adminTechSheetSigResolver($id), 'assets/wcma-logo.png') ?>` add:
```php
  <?php renderPretechReviewCard($sheet, $snapshot, $csrf); ?>

```
5. Append to the end of the file:

```php

function handleTechSheetPhotosAccept(PDO $pdo, int $id): void {
    $user = current_user();
    $result = pretechAccept($pdo, $id, (int)$user['id']);
    if (!$result['ok']) {
        setFlash($result['error'], 'error');
    } else {
        $sheet = db_get_tech_sheet($pdo, $id);
        $sent = pretechNotify(
            $pdo, 'accepted', $sheet, db_get_event($pdo, (int)$sheet['event_id']) ?? [],
            feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', '')),
            ['email' => TECH_EMAIL, 'name' => TECH_NAME], 'emailSmtpSend'
        );
        setFlash('Photos accepted: the car is pre-teched.' . ($sent ? ' The competitor and the club were emailed.' : ' The notification email could not be sent.'), $sent ? 'success' : 'error');
    }
    header('Location: admin.php?action=tech-sheet&id=' . $id);
    exit;
}

function handleTechSheetPhotosSendBack(PDO $pdo, int $id): void {
    $flagged = isset($_POST['retake']) && is_array($_POST['retake']) ? array_keys($_POST['retake']) : [];
    $noteInput = isset($_POST['note']) && is_array($_POST['note']) ? $_POST['note'] : [];
    $notes = [];
    foreach ($flagged as $key) {
        $notes[(string)$key] = (string)($noteInput[$key] ?? '');
    }

    $result = pretechSendBack($pdo, $id, $notes);
    if (!$result['ok']) {
        setFlash($result['error'], 'error');
    } else {
        $sheet = db_get_tech_sheet($pdo, $id);
        $sent = pretechNotify(
            $pdo, 'sent_back', $sheet, db_get_event($pdo, (int)$sheet['event_id']) ?? [],
            feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', '')),
            ['email' => TECH_EMAIL, 'name' => TECH_NAME], 'emailSmtpSend', $result['retakes']
        );
        setFlash(count($result['retakes']) . ' ' . (count($result['retakes']) === 1 ? 'photo' : 'photos') . ' sent back for a retake.'
            . ($sent ? ' The competitor was emailed.' : ' The notification email could not be sent.'), $sent ? 'success' : 'error');
    }
    header('Location: admin.php?action=tech-sheet&id=' . $id);
    exit;
}

/** The pre-tech photos of a sheet, with accept / send-back controls while they are awaiting review. */
function renderPretechReviewCard(array $sheet, array $snapshot, string $csrf): void {
    $photos = array_filter($snapshot['photos'], fn(array $p): bool => $p['file_path'] !== '');
    $photoStatus = $sheet['photo_status'] ?? null;
    if ($photoStatus === null && !$photos) return;

    $id = (int)$sheet['id'];
    $awaiting = $photoStatus === 'submitted' && $sheet['status'] === 'submitted';
    $statusLabels = [
        'draft' => 'The competitor has started adding photos (not submitted yet).',
        'submitted' => 'Submitted: awaiting review.',
        'needs_changes' => 'Sent back: waiting for the competitor to retake photos.',
        'accepted' => 'Photos reviewed and accepted.',
    ];
    ?>
  <div class="detail-card" id="pretech-review">
    <h2>Pre-tech photos</h2>
    <p><?= h($statusLabels[$photoStatus] ?? 'No photo set yet.') ?> <?= count($photos) ?> <?= count($photos) === 1 ? 'photo' : 'photos' ?> on file.</p>
    <?php if ($awaiting): ?>
    <p class="form-hint">Accepting these photos makes the car pre-teched for the season. To send photos back, tick each one, say what is wrong, and use "Send back for retakes".</p>
    <?php endif; ?>

    <form method="post" action="admin.php?action=tech-sheet-photos-send-back" id="pretech-review-form">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <?php foreach ($photos as $key => $row):
          $req = photoRequirementByKey($key);
          $public = inspectionPublicPhoto($row);
      ?>
      <div class="pretech-card" data-key="<?= h($key) ?>">
        <h3><?= h($req['label'] ?? $key) ?>
          <span class="pretech-status <?= $row['review_status'] === 'retake' ? 'badge-fail' : ($row['review_status'] === 'accepted' ? 'badge-ok' : 'badge-pending') ?>">
            <?= h($row['review_status'] === 'retake' ? 'Retake requested' : ($row['review_status'] === 'accepted' ? 'Accepted' : 'Pending')) ?></span></h3>
        <a href="<?= h($public['url']) ?>" target="_blank" rel="noopener"><img class="pretech-thumb" src="<?= h($public['url']) ?>" alt="<?= h($req['label'] ?? $key) ?>"></a>
        <?php foreach ($public['typed'] as $name => $value): ?>
          <p class="form-hint"><?= h(ucfirst((string)$name)) ?>: <strong><?= h((string)$value) ?></strong></p>
        <?php endforeach; ?>
        <?php if ($row['review_status'] === 'retake' && !empty($row['reviewer_note'])): ?>
          <p class="badge-fail">Note sent: <?= h((string)$row['reviewer_note']) ?></p>
        <?php endif; ?>
        <?php if ($awaiting): ?>
          <label><input type="checkbox" name="retake[<?= h($key) ?>]" value="1"> Needs a retake</label>
          <input type="text" name="note[<?= h($key) ?>]" maxlength="500" placeholder="What is wrong with this photo?">
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <?php if ($awaiting): ?>
      <button type="submit" class="btn btn-secondary" id="pretech-sendback-btn">Send back for retakes</button>
      <?php endif; ?>
    </form>

    <?php if ($awaiting): ?>
    <form method="post" action="admin.php?action=tech-sheet-photos-accept" style="margin-top:.75rem">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="btn btn-primary" id="pretech-accept-btn">Accept photos (pre-teched)</button>
    </form>
    <?php endif; ?>
  </div>
<?php
}
```

- [ ] **Step 5: Add requires and routes in `admin.php`**

After the existing `require __DIR__ . '/tech-review-lib.php';` line add:

```php
require __DIR__ . '/photo-requirements.php';
require __DIR__ . '/inspection-lib.php';
require __DIR__ . '/pretech-lib.php';
require __DIR__ . '/pretech-email.php';
```
and add these router cases right after the existing `case 'tech-sheet-sig':` block:

```php
    case 'tech-sheet-photos-accept':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=tech-sheets'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleTechSheetPhotosAccept($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'tech-sheet-photos-send-back':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=tech-sheets'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleTechSheetPhotosSendBack($pdo, (int)($_POST['id'] ?? 0));
        break;

```

- [ ] **Step 6: Run the tests and lints**

Run: `php -l admin-tech-sheets.php && php -l admin.php && php phpunit.phar`
Expected: two `No syntax errors detected` lines, then `OK` for the whole suite including the new render and copy tests. (The admin page and handlers need `config.php` and are exercised in Task 7.)

- [ ] **Step 7: Commit**

```bash
git add wcma-calculator/admin-tech-sheets.php wcma-calculator/admin.php wcma-calculator/tech-sheet-render.php wcma-calculator/tests/TechSheetRenderTest.php wcma-calculator/tests/AdminTechCopyTest.php
git commit -m "feat(pre-tech): add inspector photo review with accept, send back and roster filter"
```

---

### Task 7: End-to-end verification

No product code changes; no commits. This proves the whole pre-tech loop in a real browser: competitor adds 15 photos (with the real resize-and-upload), toggles a conditional photo, submits; inspector sees it in the queue, sends two photos back with notes; competitor retakes and resubmits; inspector accepts; statuses, locks and emails (captured in a dry-run log) are checked.

**Database and email safety (critical, learned in earlier phases):** the harness must use ONLY the scratch DB `scratch/tech2b-e2e.db`, never `wcma-calculator/data/submissions.db` (real local data: currently users=0, submissions=1, tech_sheets=0, events=0). Every entry point loads `scratch/tech2b-prepend.php` first; start the server with the ABSOLUTE prepend path; the harness `require_once`s the prepend itself (php -S skips `auto_prepend_file` for router-served requests). The prepend also defines `WCMA_MAIL_LOG`, so NO email is ever sent over SMTP (they are appended to `scratch/tech2b-mail.log`). Verify the default DB counts before and after.

**Files:**
- Create (scratch, untracked): `scratch/tech2b-prepend.php`, `scratch/tech2b-router.php`, `scratch/tech2b-harness.php`, `scratch/tech2b-e2e.js`

- [ ] **Step 1: Record the default database state**

Run (repo root): `php -r '$p=new PDO("sqlite:wcma-calculator/data/submissions.db"); foreach(["users","submissions","tech_sheets","events"] as $t) echo $t,"=",$p->query("SELECT COUNT(*) FROM $t")->fetchColumn(),"\n";'`
Note the four counts for Step 6.

- [ ] **Step 2: Create the harness files**

Create `scratch/tech2b-prepend.php`:

```php
<?php
if (!defined('DB_PATH')) {
    define('DB_PATH', 'C:/dev/wcmaclasscalc/scratch/tech2b-e2e.db');
}
if (!defined('WCMA_MAIL_LOG')) {
    define('WCMA_MAIL_LOG', 'C:/dev/wcmaclasscalc/scratch/tech2b-mail.log');
}
```

Create `scratch/tech2b-router.php`:

```php
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/harness') {
    require __DIR__ . '/tech2b-harness.php';
    return true;
}
return false;
```

Create `scratch/tech2b-harness.php`:

```php
<?php
// Seeds the scratch DB and signs the browser in. ?as=admin | owner | other
require_once __DIR__ . '/tech2b-prepend.php';
require 'C:/dev/wcmaclasscalc/wcma-calculator/session_bootstrap.php';
require 'C:/dev/wcmaclasscalc/wcma-calculator/db.php';
require 'C:/dev/wcmaclasscalc/wcma-calculator/view_helpers.php';

$pdo = db_connect();
db_init($pdo);

function harnessUser(PDO $pdo, string $who, string $role): int {
    $u = db_find_user_by_email($pdo, "$who@example.com");
    if ($u) return (int)$u['id'];
    $id = db_create_user($pdo, ['email' => "$who@example.com", 'name' => ucfirst($who), 'password_hash' => 'x', 'google_id' => null]);
    $pdo->prepare('UPDATE users SET role = :r WHERE id = :id')->execute([':r' => $role, ':id' => $id]);
    return $id;
}

$adminId = harnessUser($pdo, 'admin', 'admin');
$ownerId = harnessUser($pdo, 'owner', 'user');
$otherId = harnessUser($pdo, 'other', 'user');

if (!$pdo->query('SELECT COUNT(*) FROM tech_sheets')->fetchColumn()) {
    $sub = function (int $userId, string $email) use ($pdo): int {
        return db_insert_submission($pdo, [
            ':submitted_at' => date('Y-m-d H:i:s'), ':name' => 'Racer', ':email' => $email, ':year' => '2020', ':make' => 'Mazda',
            ':model' => 'MX-5', ':comments' => null, ':competition_weight' => 2200, ':declared_hp' => 150, ':dyno_hp' => null,
            ':chassis_display' => null, ':body_mods_display' => null, ':transmission_display' => null, ':drivetrain_display' => null,
            ':tires_display' => null, ':brake_suspension' => null, ':chassis_value' => 0, ':body_mods_value' => 0,
            ':transmission_value' => 0, ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
            ':weight_factor' => 0, ':modification_factor' => 0, ':base_ratio' => 14.67, ':modified_ratio' => 14.67,
            ':calculated_class' => 'IT1', ':user_id' => $userId,
        ]);
    };
    $sheet = function (int $userId, int $subId, int $eventId, string $number, string $entrant) use ($pdo): int {
        return db_insert_tech_sheet($pdo, [
            'submission_id' => $subId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'standard',
            'entrant_name' => $entrant, 'driver_name' => $entrant, 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => $number, 'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150',
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ]);
    };
    $spring = db_create_event($pdo, 'E2E Spring Sprint', '2026-05-10', null);
    $fall   = db_create_event($pdo, 'E2E Fall Finale', '2026-10-04', null);
    $ownerSub = $sub($ownerId, 'owner@example.com');
    $otherSub = $sub($otherId, 'other@example.com');
    $sheet($ownerId, $ownerSub, $spring, '042', 'Owner');   // sheet 1: owner car 42, spring
    $sheet($ownerId, $ownerSub, $fall, '42', 'Owner');      // sheet 2: same car, fall
    $sheet($otherId, $otherSub, $fall, '7', 'Other');       // sheet 3: someone else's car
}

$who = $_GET['as'] ?? 'admin';
$_SESSION['user_id'] = ['admin' => $adminId, 'owner' => $ownerId, 'other' => $otherId][$who] ?? $adminId;
$_SESSION['user_name'] = ucfirst($who);
$_SESSION['user_role'] = $who === 'admin' ? 'admin' : 'user';
generateCsrfToken();
?>
<!doctype html><meta charset="utf-8"><title>tech2b harness</title><p>harness ready: <?= h($who) ?></p>
```

Create `scratch/tech2b-e2e.js`:

```js
const { chromium } = require('C:/dev/wcmaclasscalc/scratch/tech-sheet-mockups/node_modules/playwright');
const assert = require('node:assert');
const fs = require('node:fs');

const BASE = 'http://localhost:8125';
const MAIL_LOG = 'C:/dev/wcmaclasscalc/scratch/tech2b-mail.log';
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');

function mailLog() {
    if (!fs.existsSync(MAIL_LOG)) return [];
    return fs.readFileSync(MAIL_LOG, 'utf8').split('\n').filter(Boolean).map(l => JSON.parse(l));
}

async function signIn(browser, who) {
    const ctx = await browser.newContext({ viewport: { width: 420, height: 900 } });
    const page = await ctx.newPage();
    await page.goto(BASE + '/harness?as=' + who);
    await page.waitForSelector('text=harness ready');
    return page;
}

async function uploadPhoto(page, key) {
    const card = page.locator('.pretech-card[data-key="' + key + '"]');
    await card.locator('input[data-photo-input]').setInputFiles({ name: key + '.png', mimeType: 'image/png', buffer: PNG });
    await page.waitForFunction(k => {
        const s = document.querySelector('.pretech-card[data-key="' + k + '"] [data-status]');
        return s && /^(Added|Accepted)$/.test(s.textContent.trim());
    }, key, { timeout: 15000 });
}

(async () => {
    if (fs.existsSync(MAIL_LOG)) fs.unlinkSync(MAIL_LOG);
    const browser = await chromium.launch();
    const owner = await signIn(browser, 'owner');
    const admin = await signIn(browser, 'admin');
    const other = await signIn(browser, 'other');

    // ── Owner opens the pre-tech page for sheet 1 ─────────────────────────────
    await owner.goto(BASE + '/tech-sheets.php?action=view&id=1');
    assert.match(await owner.textContent('body'), /Get pre-teched/);
    await owner.goto(BASE + '/tech-sheets.php?action=pretech&id=1');
    const requiredKeys = await owner.$$eval('.pretech-card[data-tier="required"]', els => els.map(e => e.getAttribute('data-key')));
    assert.strictEqual(requiredKeys.length, 15, 'fifteen required photos');
    assert.match(await owner.textContent('#pretech-progress'), /0 of 15 required photos/);
    assert.ok(await owner.locator('#pretech-submit-btn').isDisabled(), 'submit disabled at first');
    console.log('pre-tech page: 15 required cards, submit disabled');

    // ── Upload the required photos (real browser resize + upload) ─────────────
    for (const key of requiredKeys) await uploadPhoto(owner, key);
    assert.match(await owner.textContent('#pretech-progress'), /15 of 15 required photos/);
    assert.ok(await owner.locator('#pretech-submit-btn').isEnabled(), 'submit enabled when complete');
    console.log('uploaded 15 required photos: progress 15 of 15, submit enabled');

    // ── Typed detail on a photo that has one ─────────────────────────────────
    await owner.locator('.pretech-card[data-key="harness_date"] [data-typed="date"]').fill('05/2025');
    await owner.locator('.pretech-card[data-key="harness_date"] [data-typed="date"]').blur();
    await owner.waitForTimeout(600);

    // ── Conditional photo: on makes it required, off makes it optional again ──
    await owner.check('.pretech-card[data-key="ballast"] [data-applies-toggle]');
    await owner.waitForFunction(() => /0? ?of 16|15 of 16/.test(document.getElementById('pretech-progress').textContent));
    assert.match(await owner.textContent('#pretech-progress'), /15 of 16 required photos/);
    assert.ok(await owner.locator('#pretech-submit-btn').isDisabled(), 'submit disabled while an applicable photo is missing');
    await owner.uncheck('.pretech-card[data-key="ballast"] [data-applies-toggle]');
    await owner.waitForFunction(() => /15 of 15/.test(document.getElementById('pretech-progress').textContent));
    assert.ok(await owner.locator('#pretech-submit-btn').isEnabled());
    console.log('conditional toggle: on -> 15 of 16 (disabled), off -> 15 of 15 (enabled)');

    // ── Reload: state persists ────────────────────────────────────────────────
    await owner.reload();
    assert.match(await owner.textContent('#pretech-progress'), /15 of 15 required photos/);
    assert.strictEqual(await owner.locator('.pretech-card[data-key="harness_date"] [data-typed="date"]').inputValue(), '05/2025');
    console.log('reload: photos and typed detail persisted');

    // ── Submit ────────────────────────────────────────────────────────────────
    await Promise.all([owner.waitForNavigation(), owner.click('#pretech-submit-btn')]);
    assert.match(await owner.textContent('body'), /submitted for review/i);
    assert.strictEqual(await owner.locator('input[data-photo-input]').count(), 0, 'read-only once submitted');
    let mails = mailLog();
    assert.strictEqual(mails.length, 2, 'submitted: club + competitor emails');
    assert.ok(mails.every(m => /Pre-Tech|pre-tech/i.test(m.subject)));
    assert.ok(mails.some(m => m.to[0][0] === 'owner@example.com') && mails.some(m => m.to[0][0] === 'classing@wcma.ca'));
    console.log('submitted: page read-only; 2 emails (club + competitor) logged');

    // ── Owner cannot change photos while under review ─────────────────────────
    const locked = await owner.evaluate(async () => {
        const csrf = window.PRETECH_STATE.csrf;
        const f = new FormData();
        f.append('csrf_token', csrf); f.append('subject_type', 'tech_sheet'); f.append('subject_id', '1');
        f.append('requirement_key', 'ballast'); f.append('applies', '1');
        return (await fetch('inspection.php?action=applies', { method: 'POST', body: f })).status;
    });
    assert.strictEqual(locked, 404, 'writes refused while under review');

    // ── Inspector: roster filter, review page ─────────────────────────────────
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=0&filter=pending_review');
    // A car's status is shared by all of its sheets this season, so both sheets of car 42 show as awaiting review.
    assert.strictEqual(await admin.locator('#tech-sheets-table tbody tr').count(), 2, 'car 42 awaiting review (both of its sheets)');
    await admin.goto(BASE + '/admin.php?action=tech-sheet&id=1');
    assert.strictEqual(await admin.locator('#pretech-review .pretech-card').count(), 15, 'inspector sees all 15 photos');
    assert.match(await admin.textContent('#pretech-review'), /05\/2025/, 'typed detail visible to the inspector');

    // Send back with no selection: refused with a message
    await Promise.all([admin.waitForNavigation(), admin.click('#pretech-sendback-btn')]);
    assert.match(await admin.textContent('body'), /at least one photo/i);

    // Send back two photos, one without a note first (refused), then with notes
    await admin.check('input[name="retake[front_34]"]');
    await Promise.all([admin.waitForNavigation(), admin.click('#pretech-sendback-btn')]);
    assert.match(await admin.textContent('body'), /note for every photo/i);
    await admin.check('input[name="retake[front_34]"]');
    await admin.fill('input[name="note[front_34]"]', 'Car number is hidden');
    await admin.check('input[name="retake[harness_date]"]');
    await admin.fill('input[name="note[harness_date]"]', 'Date stamp not readable');
    await Promise.all([admin.waitForNavigation(), admin.click('#pretech-sendback-btn')]);
    assert.match(await admin.textContent('body'), /2 photos sent back/i);
    mails = mailLog();
    assert.strictEqual(mails.length, 3, 'send back: one more email');
    assert.strictEqual(mails[2].to[0][0], 'owner@example.com');
    assert.match(mails[2].text, /Car number is hidden/);
    assert.match(mails[2].text, /Date stamp not readable/);
    console.log('inspector sent 2 photos back with notes; competitor emailed');

    // ── Owner sees the notes, retakes, resubmits ──────────────────────────────
    await owner.goto(BASE + '/tech-sheets.php?action=pretech&id=1');
    assert.match(await owner.textContent('body'), /Car number is hidden/);
    assert.strictEqual(await owner.locator('.pretech-status:has-text("Retake requested")').count(), 2);
    await Promise.all([owner.waitForNavigation(), owner.click('#pretech-submit-btn')]).catch(() => {});
    assert.match(await owner.textContent('body'), /retake the photos the inspector flagged|flagged/i, 'resubmit blocked while flagged');
    await owner.goto(BASE + '/tech-sheets.php?action=pretech&id=1');
    await uploadPhoto(owner, 'front_34');
    await uploadPhoto(owner, 'harness_date');
    await Promise.all([owner.waitForNavigation(), owner.click('#pretech-submit-btn')]);
    assert.match(await owner.textContent('body'), /submitted for review/i);
    assert.strictEqual(mailLog().length, 5, 'resubmitted: two more emails');
    console.log('retook the two photos, resubmitted');

    // ── Inspector accepts the photos ──────────────────────────────────────────
    await admin.goto(BASE + '/admin.php?action=tech-sheet&id=1');
    await Promise.all([admin.waitForNavigation(), admin.click('#pretech-accept-btn')]);
    const acceptedBody = await admin.textContent('body');
    assert.match(acceptedBody, /Photos accepted/);
    assert.match(acceptedBody, /Pre-teched 2026/);
    assert.match(acceptedBody, /Reviewed remotely/);
    assert.match(acceptedBody, /Accepted remotely \(photos reviewed\)/);
    mails = mailLog();
    assert.strictEqual(mails.length, 7, 'accepted: competitor + club');
    const acceptedMails = mails.slice(5);
    assert.ok(acceptedMails.every(m => /Accepted/.test(m.subject) && /not a certification/.test(m.text) && /decals/.test(m.text)));
    console.log('accepted remotely; 2 emails with disclaimer and decals instruction');

    // ── Statuses everywhere ───────────────────────────────────────────────────
    await owner.goto(BASE + '/account.php');
    assert.match(await owner.textContent('body'), /Pre-teched 2026/);
    await owner.goto(BASE + '/tech-sheets.php?action=pretech&id=2');
    assert.match(await owner.textContent('body'), /already teched/i, 'same car, other sheet: nothing more to do');
    await other.goto(BASE + '/account.php');
    assert.ok(!/Pre-teched 2026/.test(await other.textContent('body')), 'other owner unaffected');
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=0&filter=accepted');
    assert.strictEqual(await admin.locator('#tech-sheets-table tbody tr').count(), 2, 'both sheets of car 42 accepted');
    console.log('statuses: owner Pre-teched 2026, sibling sheet says already teched, other owner unaffected');

    // ── Revoke returns it to the review queue ────────────────────────────────
    await admin.goto(BASE + '/admin.php?action=tech-sheet&id=1');
    await admin.click('text=Revoke acceptance');
    await Promise.all([admin.waitForNavigation(), admin.click('[data-role="confirm"]')]);
    assert.match(await admin.textContent('body'), /Acceptance revoked/);
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=0&filter=pending_review');
    assert.strictEqual(await admin.locator('#tech-sheets-table tbody tr').count(), 2, 'revoked photo acceptance is back in the queue (both sheets of car 42)');
    console.log('revoke: photo set back in the review queue');

    await browser.close();
    console.log('E2E OK');
})().catch(e => { console.error(e); process.exit(1); });
```

- [ ] **Step 3: Start the server (port 8125, scratch DB and mail log only)**

Run (repo root, in the background): `php -d auto_prepend_file=C:/dev/wcmaclasscalc/scratch/tech2b-prepend.php -S localhost:8125 -t wcma-calculator scratch/tech2b-router.php`
Verify: `curl -s -o /dev/null -w "%{http_code}" "http://localhost:8125/harness?as=admin"` returns `200`; `scratch/tech2b-e2e.db` now exists; the default DB counts still match Step 1.

- [ ] **Step 4: Run the end-to-end script**

Run: `node scratch/tech2b-e2e.js`
Expected: output ending with `E2E OK`. If a step fails, decide whether the scratch script or the product is wrong: fix scratch-script mistakes (selectors, timing) in the scratch script only, and report genuine product defects with evidence (file, line, expected behaviour) instead of editing product code in this task.

- [ ] **Step 5: Stop the server and clean up**

Stop the PHP server (find the listener on 8125 with `netstat -ano | grep 8125 | grep LISTENING`, kill that process id, confirm the port is free). Remove `scratch/tech2b-e2e.db*` and `scratch/tech2b-mail.log`. Photos uploaded by the run live under `wcma-calculator/uploads/inspection/tech_sheet/<id>/`, and signature files under `uploads/tech-sheets/`: list them, and delete only the folders created by this run (ids 1-3 in the scratch DB; the folders did not exist before, check timestamps). `git status --short` must show only the pre-existing `?? .htaccess.server` and `?? scratch/`.

- [ ] **Step 6: Verify the default database and run the full regression**

Re-run the Step 1 command: the four counts must match. Then from `wcma-calculator/` run `php phpunit.phar` (all tests OK) and `node --test "tests/js/*.test.js"` (`# fail 0`). Nothing to commit for this task.

---

## Self-Review Notes

- **Spec coverage (phase 2b):** photo section as an optional pre-tech page with progress, draft saving, typed details and conditional toggles (Tasks 3, 5); inspector review with per-photo retake notes, accept, and the queue filter (Tasks 2, 6); remote acceptance rules including "accepted remotely" on the rendered sheet and revoke returning to the queue (Tasks 1, 2, 6); the three emails with the disclaimer and the decals instruction (Task 4); competitor write lock during review (Task 2). Deliberately deferred: gear records and gear photos (phase 3), expiry reminders.
- **Spec deviation to note:** the spec describes a photo section inside the sheet form; this plan puts it on a separate page linked from the sheet, because photos attach to an existing sheet id and must be savable as a draft independent of the form's signature-required submit. The data model and workflow are as specified.
- **Type consistency:** `pretechSnapshot()` returns `{photos, present, applicable, missing}` consumed by `pretechSubmit`, the page renderer and the admin card; `pretechSendBack()` returns `retakes` as `[key => note]` consumed by `pretechNotify()`; `pretechNotify()`'s `$sendFn(array $to, array $message)` matches `emailSmtpSend`; the JS client methods match the two new endpoint actions.
- **Known limits:** the competitor and admin pages and handlers load `config.php`, so they are verified end to end (Task 7) rather than by PHPUnit; the page renderer and all decisions are unit-tested. Backfill and index behaviour of phase 2a are unchanged.
