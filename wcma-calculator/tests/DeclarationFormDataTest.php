<?php
// wcma-calculator/tests/DeclarationFormDataTest.php
use PHPUnit\Framework\TestCase;

final class DeclarationFormDataTest extends TestCase
{
    public function testFormDataIsStoredAndReturnedOnlyToTheOwner(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $other = db_create_user($pdo, ['email' => 'o@example.com', 'name' => 'Other Person', 'password_hash' => 'x', 'google_id' => null]);
        db_insert_submission($pdo, test_declaration_data($pdo, $u, '42', [':form_data' => json_encode(['competitionWeight' => '2860', 'chassis' => 'chassis3'])]));
        $car = test_make_car($pdo, $u, '42');

        $this->assertSame(['competitionWeight' => '2860', 'chassis' => 'chassis3'], db_get_car_declaration_form($pdo, $u, $car));
        $this->assertNull(db_get_car_declaration_form($pdo, $other, $car));
        $this->assertNull(db_get_car_declaration_form($pdo, $u, test_make_car($pdo, $u, '7')));
    }

    public function testDeclarationWithoutFormDataStillInserts(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $id = db_insert_submission($pdo, test_declaration_data($pdo, $u));
        $this->assertNull(db_get_submission($pdo, $id)['form_data']);
    }
}
