<?php
// wcma-calculator/tests/RemindersChoiceTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../reminders-lib.php';

use PHPUnit\Framework\TestCase;

final class RemindersChoiceTest extends TestCase
{
    private function setUpUser(): array {
        $pdo = make_temp_pdo();
        return [$pdo, db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null])];
    }

    private function row(PDO $pdo, int $u): array {
        return db_find_user_by_id($pdo, $u);
    }

    public function testNothingHappensWhenTheFormDidNotOfferTheBox(): void
    {
        [$pdo, $u] = $this->setUpUser();
        $this->assertSame('', remindersRecordTagChoice($pdo, $u, ['reminders' => '1']));
        $this->assertTrue(remindersShouldOffer($this->row($pdo, $u)));
    }

    public function testTickingTheBoxTurnsRemindersOn(): void
    {
        [$pdo, $u] = $this->setUpUser();
        $this->assertSame(' We\'ll email you reminders before your events.', remindersRecordTagChoice($pdo, $u, ['offer_reminders' => '1', 'reminders' => '1']));
        $this->assertSame(1, (int)$this->row($pdo, $u)['reminder_emails']);
        $this->assertFalse(remindersShouldOffer($this->row($pdo, $u)));
    }

    public function testLeavingItUntickedIsAChoiceToo(): void
    {
        [$pdo, $u] = $this->setUpUser();
        $this->assertSame('', remindersRecordTagChoice($pdo, $u, ['offer_reminders' => '1']));
        $this->assertSame(0, (int)$this->row($pdo, $u)['reminder_emails']);
        $this->assertFalse(remindersShouldOffer($this->row($pdo, $u)));
    }

    public function testAStaleFormCannotUndoAChoiceMadeElsewhere(): void
    {
        [$pdo, $u] = $this->setUpUser();
        db_set_user_reminders($pdo, $u, true);   // turned on in Profile
        $this->assertSame('', remindersRecordTagChoice($pdo, $u, ['offer_reminders' => '1']));
        $this->assertSame(1, (int)$this->row($pdo, $u)['reminder_emails']);
    }

    public function testTheOptInFields(): void
    {
        $html = reminderOptInFieldsHtml();
        $this->assertStringContainsString('<input type="hidden" name="offer_reminders" value="1">', $html);
        $this->assertStringContainsString('<input type="checkbox" name="reminders" value="1">', $html);
        $this->assertStringContainsString('Email me reminders for events I&#039;m going to.', $html);
        $this->assertStringNotContainsString('checked', $html);
    }
}
