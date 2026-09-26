<?php
// wcma-calculator/tests/DeclarationReviewTest.php
require_once __DIR__ . '/../declaration-review-lib.php';

use PHPUnit\Framework\TestCase;

final class DeclarationReviewTest extends TestCase
{
    private PDO $pdo;
    private int $uid;
    private int $inspector;

    protected function setUp(): void
    {
        $this->pdo = make_temp_pdo();
        $this->uid = db_create_user($this->pdo, ['email' => 'jordan@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $this->inspector = db_create_user($this->pdo, ['email' => 'ivy@example.com', 'name' => 'Ivy Inspector', 'password_hash' => 'x', 'google_id' => null]);
    }

    private function declare(string $number = '42'): int {
        return db_insert_submission($this->pdo, test_declaration_data($this->pdo, $this->uid, $number));
    }

    private function submissionStatus(int $id): string {
        return (string)db_get_submission($this->pdo, $id)['review_status'];
    }

    public function testWhichActionsEachStatusAllows(): void
    {
        $this->assertTrue(declarationReviewAllowed('submitted', 'accept'));
        $this->assertTrue(declarationReviewAllowed('needs_changes', 'accept'));
        $this->assertFalse(declarationReviewAllowed('accepted', 'accept'));
        $this->assertTrue(declarationReviewAllowed('submitted', 'send_back'));
        $this->assertTrue(declarationReviewAllowed('accepted', 'send_back'));
        $this->assertFalse(declarationReviewAllowed('needs_changes', 'send_back'));
        $this->assertFalse(declarationReviewAllowed('superseded', 'accept'));
        $this->assertFalse(declarationReviewAllowed('superseded', 'send_back'));
        $this->assertFalse(declarationReviewAllowed('submitted', 'delete'));
    }

    public function testAcceptRecordsTheReviewerAndWhen(): void
    {
        $id = $this->declare();
        $this->assertSame(['ok' => true, 'error' => null], declarationReviewAccept($this->pdo, $id, $this->inspector));
        $s = db_get_submission($this->pdo, $id);
        $this->assertSame('accepted', $s['review_status']);
        $this->assertSame($this->inspector, (int)$s['reviewed_by_user_id']);
        $this->assertNotNull($s['reviewed_at']);
        $this->assertNotNull($s['accepted_at']);
        $this->assertNull($s['reviewer_note']);
    }

    public function testAcceptingTwiceIsRefused(): void
    {
        $id = $this->declare();
        declarationReviewAccept($this->pdo, $id, $this->inspector);
        $this->assertSame(['ok' => false, 'error' => 'This declaration is already accepted.'], declarationReviewAccept($this->pdo, $id, $this->inspector));
    }

    public function testSendBackNeedsANote(): void
    {
        $id = $this->declare();
        $r = declarationReviewSendBack($this->pdo, $id, $this->inspector, "  \r\n  ");
        $this->assertFalse($r['ok']);
        $this->assertSame('Write a note saying what needs to change. The competitor sees it in the email and in their Garage.', $r['error']);
        $this->assertSame('submitted', $this->submissionStatus($id));
    }

    public function testSendBackStoresTheTrimmedNoteAndReviewer(): void
    {
        $id = $this->declare();
        $this->assertTrue(declarationReviewSendBack($this->pdo, $id, $this->inspector, "  Dyno sheet missing.\r\nPlease attach it. ")['ok']);
        $s = db_get_submission($this->pdo, $id);
        $this->assertSame('needs_changes', $s['review_status']);
        $this->assertSame("Dyno sheet missing.\nPlease attach it.", $s['reviewer_note']);
        $this->assertSame($this->inspector, (int)$s['reviewed_by_user_id']);
        $this->assertSame(['ok' => false, 'error' => 'This declaration has already been sent back.'], declarationReviewSendBack($this->pdo, $id, $this->inspector, 'Again'));
    }

    public function testNotesOverTheLimitAreRefused(): void
    {
        $id = $this->declare();
        $r = declarationReviewSendBack($this->pdo, $id, $this->inspector, str_repeat('é', DECLARATION_NOTE_MAX + 1));
        $this->assertSame('Keep the note to 1,000 characters or fewer.', $r['error']);
        $this->assertTrue(declarationReviewSendBack($this->pdo, $id, $this->inspector, str_repeat('é', DECLARATION_NOTE_MAX))['ok']);
    }

    public function testSendingBackAnAcceptedDeclarationWithdrawsTheAcceptance(): void
    {
        $id = $this->declare();
        declarationReviewAccept($this->pdo, $id, $this->inspector);
        $this->assertTrue(declarationReviewSendBack($this->pdo, $id, $this->inspector, 'Wrong car, sorry.')['ok']);
        $s = db_get_submission($this->pdo, $id);
        $this->assertSame('needs_changes', $s['review_status']);
        $this->assertNull($s['accepted_at']);
    }

    public function testAcceptingASentBackDeclarationClearsTheNote(): void
    {
        $id = $this->declare();
        declarationReviewSendBack($this->pdo, $id, $this->inspector, 'Bring the dyno sheet.');
        $this->assertTrue(declarationReviewAccept($this->pdo, $id, $this->inspector)['ok']);
        $s = db_get_submission($this->pdo, $id);
        $this->assertSame('accepted', $s['review_status']);
        $this->assertNull($s['reviewer_note']);
        $this->assertNotNull($s['accepted_at']);
    }

    public function testSupersededDeclarationCannotBeReviewed(): void
    {
        $old = $this->declare();
        $this->declare();   // the competitor re-declares the same car, superseding $old
        foreach ([declarationReviewAccept($this->pdo, $old, $this->inspector), declarationReviewSendBack($this->pdo, $old, $this->inspector, 'Fix it')] as $r) {
            $this->assertFalse($r['ok']);
            $this->assertSame('A newer declaration has replaced this one. Review the newer one instead.', $r['error']);
        }
        $this->assertSame('superseded', $this->submissionStatus($old));
    }

    public function testUnknownDeclaration(): void
    {
        $this->assertSame(['ok' => false, 'error' => 'Class declaration not found.'], declarationReviewAccept($this->pdo, 999, $this->inspector));
        $this->assertSame(['ok' => false, 'error' => 'Class declaration not found.'], declarationReviewSendBack($this->pdo, 999, $this->inspector, 'x'));
    }

    public function testDbUpdatesAreAtomic(): void
    {
        $id = $this->declare();
        $this->pdo->exec("UPDATE submissions SET review_status = 'superseded' WHERE id = $id");
        $this->assertFalse(db_accept_declaration($this->pdo, $id, $this->inspector));
        $this->assertFalse(db_send_back_declaration($this->pdo, $id, $this->inspector, 'x'));
        $this->assertSame('superseded', $this->submissionStatus($id));
    }
}
