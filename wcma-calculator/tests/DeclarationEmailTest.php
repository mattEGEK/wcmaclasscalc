<?php
// wcma-calculator/tests/DeclarationEmailTest.php
require_once __DIR__ . '/../declaration-email.php';

use PHPUnit\Framework\TestCase;

final class DeclarationEmailTest extends TestCase
{
    private function sub(array $o = []): array {
        return array_merge([
            'id' => 7, 'car_id' => 3, 'user_id' => 1, 'name' => 'Jordan Lee', 'email' => 'jordan@example.com',
            'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'calculated_class' => 'GT3',
            'submitted_at' => '2026-03-03 10:00:00', 'reviewer_note' => null, 'reviewed_by_user_id' => null,
        ], $o);
    }

    private function car(): array {
        return ['id' => 3, 'car_number' => '42'];
    }

    public function testAcceptedLeadsWithTheBindingHeadlineAndNamesTheReviewer(): void
    {
        $this->assertSame('The scrutineer has reviewed & accepted your class declaration.', COPY_DECLARATION_ACCEPTED);
        $m = declarationEmailAccepted($this->sub(), $this->car(), 'https://x.test/garage.php?car=3', ['name' => 'Ivy  Inspector']);
        $this->assertSame('WCMA Class Declaration Accepted — Car #42 — GT3', $m['subject']);
        $this->assertStringStartsWith(COPY_DECLARATION_ACCEPTED, $m['text']);
        $this->assertStringContainsString('Reviewed by: Ivy Inspector', $m['text']);
        $this->assertStringContainsString('Reviewed by: Ivy Inspector', $m['html']);
        $this->assertStringContainsString('Car #42 — 2004 Honda S2000 (GT3), submitted March 3, 2026, is accepted.', $m['text']);
        $this->assertStringContainsString('https://x.test/garage.php?car=3', $m['text']);
        $this->assertStringContainsString('cid:wcma-logo', $m['html']);
    }

    public function testSentBackCarriesTheEscapedNoteAndTheRedeclareLink(): void
    {
        $m = declarationEmailSentBack($this->sub(), $this->car(), "Dyno sheet <missing>.\nAttach it.", 'https://x.test/calculator.php?car=3', ['name' => 'Ivy Inspector']);
        $this->assertSame('WCMA Class Declaration — changes needed — Car #42', $m['subject']);
        $this->assertStringContainsString("Inspector's note: Dyno sheet <missing>.\nAttach it.", $m['text']);
        $this->assertStringContainsString('Dyno sheet &lt;missing&gt;.<br />', $m['html']);
        $this->assertStringContainsString('Reviewed by: Ivy Inspector', $m['text']);
        $this->assertStringContainsString('https://x.test/calculator.php?car=3', $m['text']);
        $this->assertStringContainsString('href="https://x.test/calculator.php?car=3"', $m['html']);
    }

    public function testWithoutAKnownReviewerThereIsNoByLine(): void
    {
        $m = declarationEmailAccepted($this->sub(), $this->car(), 'https://x.test/garage.php?car=3', null);
        $this->assertStringNotContainsString('Reviewed by', $m['text'] . $m['html']);
    }

    public function testNoBannedWording(): void
    {
        $a = declarationEmailAccepted($this->sub(), $this->car(), 'u', ['name' => 'Ivy Inspector']);
        $b = declarationEmailSentBack($this->sub(), $this->car(), 'n', 'u', ['name' => 'Ivy Inspector']);
        foreach ([$a, $b] as $m) {
            $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $m['subject'] . $m['html'] . $m['text']);
        }
    }

    public function testNotifySendsToTheAccountHolderWithTheStoredNote(): void
    {
        $pdo = make_temp_pdo();
        $uid = db_create_user($pdo, ['email' => 'jordan@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $insp = db_create_user($pdo, ['email' => 'ivy@example.com', 'name' => 'Ivy Inspector', 'password_hash' => 'x', 'google_id' => null]);
        $id = db_insert_submission($pdo, test_declaration_data($pdo, $uid));
        db_send_back_declaration($pdo, $id, $insp, 'Attach the dyno sheet.');
        $sub = db_get_submission($pdo, $id);

        $sent = [];
        $ok = declarationNotify($pdo, 'sent_back', $sub, 'https://x.test/', function (array $to, array $m) use (&$sent): bool {
            $sent[] = [$to, $m];
            return true;
        });
        $this->assertTrue($ok);
        $this->assertCount(1, $sent);
        $this->assertSame([['jordan@example.com', 'Jordan Lee']], $sent[0][0]);
        $this->assertStringContainsString("Inspector's note: Attach the dyno sheet.", $sent[0][1]['text']);
        $this->assertStringContainsString('Reviewed by: Ivy Inspector', $sent[0][1]['text']);
        $this->assertStringContainsString('https://x.test/calculator.php?car=' . (int)$sub['car_id'], $sent[0][1]['text']);
    }

    public function testNotifyReportsFailureWithoutThrowing(): void
    {
        $pdo = make_temp_pdo();
        $uid = db_create_user($pdo, ['email' => 'jordan@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $id = db_insert_submission($pdo, test_declaration_data($pdo, $uid));
        db_accept_declaration($pdo, $id, $uid);
        $sub = db_get_submission($pdo, $id);

        $this->assertFalse(declarationNotify($pdo, 'accepted', $sub, 'https://x.test', fn(array $to, array $m): bool => throw new RuntimeException('smtp down')));
        $this->assertFalse(declarationNotify($pdo, 'accepted', $sub, 'https://x.test', fn(array $to, array $m): bool => false));
        $this->assertFalse(declarationNotify($pdo, 'bogus', $sub, 'https://x.test', fn(array $to, array $m): bool => true));
    }
}
