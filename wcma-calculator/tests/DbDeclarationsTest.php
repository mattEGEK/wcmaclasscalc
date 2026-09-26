<?php
// wcma-calculator/tests/DbDeclarationsTest.php
use PHPUnit\Framework\TestCase;

final class DbDeclarationsTest extends TestCase
{
    private function user(PDO $pdo): int {
        return db_create_user($pdo, ['email' => 'r@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testDeclarationNeedsUserAndCar(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $data = test_declaration_data($pdo, $u);
        unset($data[':car_id']);
        $this->expectException(InvalidArgumentException::class);
        db_insert_submission($pdo, $data);
    }

    public function testNewDeclarationIsSubmittedAndSupersedesTheCarsPreviousOnes(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $first = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42', [':calculated_class' => 'GT2']));
        $other = db_insert_submission($pdo, test_declaration_data($pdo, $u, '7'));
        $second = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42', [':calculated_class' => 'GT3']));

        $this->assertSame('superseded', db_get_submission($pdo, $first)['review_status']);
        $this->assertSame('submitted', db_get_submission($pdo, $second)['review_status']);
        $this->assertSame('submitted', db_get_submission($pdo, $other)['review_status']);   // another car is untouched

        $car42 = test_make_car($pdo, $u, '42');
        $this->assertSame($second, (int)db_get_car_current_declaration($pdo, $car42)['id']);
        $this->assertSame([$second, $first], array_map(fn($r) => (int)$r['id'], db_get_car_declarations($pdo, $car42)));

        $current = db_get_user_current_declarations($pdo, $u);
        $this->assertSame($second, (int)$current[$car42]['id']);
        $this->assertSame($other, (int)$current[test_make_car($pdo, $u, '7')]['id']);
    }

    public function testCarWithNoDeclarationHasNoCurrent(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $this->assertNull(db_get_car_current_declaration($pdo, test_make_car($pdo, $u, '5')));
    }

    public function testDeletingTheCurrentDeclarationRestoresTheNewestRemainingOneAsAccepted(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $car = test_make_car($pdo, $u, '42');
        $first = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42', [':calculated_class' => 'GT2']));
        $pdo->prepare("UPDATE submissions SET accepted_at = :now WHERE id = :id")->execute([':now' => date('Y-m-d H:i:s'), ':id' => $first]);
        $second = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42', [':calculated_class' => 'GT3']));

        db_delete_submission($pdo, $second);
        db_restore_current_declaration($pdo, $car);

        $current = db_get_car_current_declaration($pdo, $car);
        $this->assertNotNull($current);
        $this->assertSame($first, (int)$current['id']);
        $this->assertSame('accepted', $current['review_status']);
    }

    public function testDeletingTheCurrentDeclarationWithNoAcceptedAtRestoresAsSubmitted(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $car = test_make_car($pdo, $u, '42');
        $first = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        $second = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));

        db_delete_submission($pdo, $second);
        db_restore_current_declaration($pdo, $car);

        $current = db_get_car_current_declaration($pdo, $car);
        $this->assertSame($first, (int)$current['id']);
        $this->assertSame('submitted', $current['review_status']);
    }

    public function testDeletingAnOlderDeclarationLeavesTheCurrentOneUnchanged(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $car = test_make_car($pdo, $u, '42');
        $first = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        $second = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));

        db_delete_submission($pdo, $first);
        db_restore_current_declaration($pdo, $car);

        $current = db_get_car_current_declaration($pdo, $car);
        $this->assertSame($second, (int)$current['id']);
        $this->assertSame('submitted', $current['review_status']);
    }

    public function testDeletingTheOnlyDeclarationIsANoOp(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $car = test_make_car($pdo, $u, '42');
        $only = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));

        db_delete_submission($pdo, $only);
        db_restore_current_declaration($pdo, $car);

        $this->assertNull(db_get_car_current_declaration($pdo, $car));
    }
}
