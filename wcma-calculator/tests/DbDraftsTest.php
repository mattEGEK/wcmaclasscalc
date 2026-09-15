<?php
use PHPUnit\Framework\TestCase;

final class DbDraftsTest extends TestCase
{
    private function makeUser(PDO $pdo, string $email): int {
        return db_create_user($pdo, ['email' => $email, 'name' => 'Test', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testUpsertDraftCreatesNew(): void
    {
        $pdo = make_temp_pdo();
        $userId = $this->makeUser($pdo, 'a@example.com');

        $id = db_upsert_draft($pdo, $userId, '2020 Mazda MX-5', '{"year":"2020"}');

        $draft = db_get_user_draft($pdo, $userId, $id);
        $this->assertNotNull($draft);
        $this->assertSame('2020 Mazda MX-5', $draft['label']);
        $this->assertSame('{"year":"2020"}', $draft['form_data']);
    }

    public function testUpsertDraftUpdatesExistingSameLabel(): void
    {
        $pdo = make_temp_pdo();
        $userId = $this->makeUser($pdo, 'b@example.com');

        $id1 = db_upsert_draft($pdo, $userId, 'My Miata', '{"hp":"100"}');
        $id2 = db_upsert_draft($pdo, $userId, 'My Miata', '{"hp":"150"}');

        $this->assertSame($id1, $id2);
        $draft = db_get_user_draft($pdo, $userId, $id1);
        $this->assertSame('{"hp":"150"}', $draft['form_data']);
        $this->assertSame(1, db_count_user_drafts($pdo, $userId));
    }

    public function testUpsertDraftDifferentUsersSameLabelStaySeparate(): void
    {
        $pdo = make_temp_pdo();
        $userA = $this->makeUser($pdo, 'c@example.com');
        $userB = $this->makeUser($pdo, 'd@example.com');

        $idA = db_upsert_draft($pdo, $userA, 'My Car', '{"owner":"A"}');
        $idB = db_upsert_draft($pdo, $userB, 'My Car', '{"owner":"B"}');

        $this->assertNotSame($idA, $idB);
        $this->assertSame('{"owner":"A"}', db_get_user_draft($pdo, $userA, $idA)['form_data']);
        $this->assertSame('{"owner":"B"}', db_get_user_draft($pdo, $userB, $idB)['form_data']);
    }

    public function testGetUserDraftsOrderedByUpdatedAtDesc(): void
    {
        $pdo = make_temp_pdo();
        $userId = $this->makeUser($pdo, 'e@example.com');

        db_upsert_draft($pdo, $userId, 'First', '{}');
        db_upsert_draft($pdo, $userId, 'Second', '{}');

        $drafts = db_get_user_drafts($pdo, $userId);
        $this->assertCount(2, $drafts);
        $this->assertSame('Second', $drafts[0]['label']);
    }

    public function testGetUserDraftOwnershipScoped(): void
    {
        $pdo = make_temp_pdo();
        $owner = $this->makeUser($pdo, 'f@example.com');
        $other = $this->makeUser($pdo, 'g@example.com');

        $id = db_upsert_draft($pdo, $owner, 'Owned', '{}');

        $this->assertNotNull(db_get_user_draft($pdo, $owner, $id));
        $this->assertNull(db_get_user_draft($pdo, $other, $id));
    }

    public function testDeleteDraftOwnershipScoped(): void
    {
        $pdo = make_temp_pdo();
        $owner = $this->makeUser($pdo, 'h@example.com');
        $other = $this->makeUser($pdo, 'i@example.com');

        $id = db_upsert_draft($pdo, $owner, 'Delete Me', '{}');

        db_delete_draft($pdo, $id, $other);
        $this->assertNotNull(db_get_user_draft($pdo, $owner, $id), 'Should not delete another user\'s draft');

        db_delete_draft($pdo, $id, $owner);
        $this->assertNull(db_get_user_draft($pdo, $owner, $id));
    }
}
