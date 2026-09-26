<?php
// wcma-calculator/declaration-review-lib.php
//
// An inspector's review of a class declaration (spec §5): Accept, or Send back with a required note.
// Session-free (the caller passes the reviewer id), so it is unit-testable. Callers must have loaded
// db.php.

const DECLARATION_NOTE_MAX = 1000;
const DECLARATION_REVIEW_CHANGED = 'This declaration changed while you were reviewing it. Reload the page and try again.';

/** Whether $action ('accept' | 'send_back') may be taken on a declaration whose review_status is $status. */
function declarationReviewAllowed(string $status, string $action): bool {
    $from = [
        'accept' => ['submitted', 'needs_changes'],
        'send_back' => ['submitted', 'accepted'],
    ];
    return in_array($status, $from[$action] ?? [], true);
}

/** Why $action is refused for $status. Only meaningful when declarationReviewAllowed() is false. */
function declarationReviewRefusal(string $status, string $action): string {
    if ($status === 'superseded') return 'A newer declaration has replaced this one. Review the newer one instead.';
    return $action === 'accept' ? 'This declaration is already accepted.' : 'This declaration has already been sent back.';
}

/** The refusal message for $action on declaration $id, or null when the action may go ahead. */
function declarationReviewCheck(PDO $pdo, int $id, string $action): ?string {
    $sub = db_get_submission($pdo, $id);
    if ($sub === null) return 'Class declaration not found.';
    $status = (string)$sub['review_status'];
    return declarationReviewAllowed($status, $action) ? null : declarationReviewRefusal($status, $action);
}

/** @return array{ok: bool, error: ?string} */
function declarationReviewAccept(PDO $pdo, int $id, int $reviewerUserId): array {
    $refused = declarationReviewCheck($pdo, $id, 'accept');
    if ($refused !== null) return ['ok' => false, 'error' => $refused];
    if (!db_accept_declaration($pdo, $id, $reviewerUserId)) return ['ok' => false, 'error' => DECLARATION_REVIEW_CHANGED];
    return ['ok' => true, 'error' => null];
}

/** @return array{ok: bool, error: ?string} */
function declarationReviewSendBack(PDO $pdo, int $id, int $reviewerUserId, string $note): array {
    $refused = declarationReviewCheck($pdo, $id, 'send_back');
    if ($refused !== null) return ['ok' => false, 'error' => $refused];

    $note = trim(str_replace("\r\n", "\n", $note));
    if ($note === '') {
        return ['ok' => false, 'error' => 'Write a note saying what needs to change. The competitor sees it in the email and in their Garage.'];
    }
    if (mb_strlen($note, 'UTF-8') > DECLARATION_NOTE_MAX) {
        return ['ok' => false, 'error' => 'Keep the note to 1,000 characters or fewer.'];
    }
    if (!db_send_back_declaration($pdo, $id, $reviewerUserId, $note)) return ['ok' => false, 'error' => DECLARATION_REVIEW_CHANGED];
    return ['ok' => true, 'error' => null];
}
