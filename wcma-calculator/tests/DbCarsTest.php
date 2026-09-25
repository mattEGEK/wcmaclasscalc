<?php
// wcma-calculator/tests/DbCarsTest.php
use PHPUnit\Framework\TestCase;

final class DbCarsTest extends TestCase
{
    private function user(PDO $pdo, string $email = 'racer@example.com'): int {
        return db_create_user($pdo, ['email' => $email, 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testCreateAndGetCar(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $id = db_create_car($pdo, $u, ['car_number' => ' 042 ', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'colour' => 'Silver']);

        $car = db_get_car($pdo, $id);
        $this->assertSame(' 042 ', $car['car_number']);
        $this->assertSame('42', $car['car_number_norm']);
        $this->assertSame('Honda', $car['make']);
        $this->assertSame('Silver', $car['colour']);
        $this->assertNull($car['engine_cc']);
        $this->assertNull($car['archived_at']);
        $this->assertSame($id, (int)db_get_user_car($pdo, $u, $id)['id']);
        $this->assertNull(db_get_user_car($pdo, $u + 1, $id));
        $this->assertNull(db_get_car($pdo, 99999));
    }

    public function testUserCarsOrderedByNumberAndArchivedHidden(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $c10 = db_create_car($pdo, $u, ['car_number' => '10', 'make' => 'A', 'model' => 'A']);
        $c9 = db_create_car($pdo, $u, ['car_number' => '9', 'make' => 'B', 'model' => 'B']);
        $c7a = db_create_car($pdo, $u, ['car_number' => '7A', 'make' => 'C', 'model' => 'C']);
        $ids = fn(array $rows) => array_map(fn($r) => (int)$r['id'], $rows);

        $this->assertSame([$c7a, $c9, $c10], $ids(db_get_user_cars($pdo, $u)));

        $this->assertTrue(db_archive_car($pdo, $u, $c9));
        $this->assertFalse(db_archive_car($pdo, $u, $c9));        // already archived
        $this->assertFalse(db_archive_car($pdo, $u + 1, $c10));   // not the owner
        $this->assertSame([$c7a, $c10], $ids(db_get_user_cars($pdo, $u)));
        $this->assertSame([$c7a, $c9, $c10], $ids(db_get_user_cars($pdo, $u, true)));
    }

    public function testUpdateCarAppliesOnlyKnownFieldsAndRenormalises(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $id = db_create_car($pdo, $u, ['car_number' => '42', 'make' => 'Honda', 'model' => 'S2000']);

        db_update_car($pdo, $id, ['car_number' => '007', 'colour' => 'Red', 'owner_user_id' => 999]);

        $car = db_get_car($pdo, $id);
        $this->assertSame('007', $car['car_number']);
        $this->assertSame('7', $car['car_number_norm']);
        $this->assertSame('Red', $car['colour']);
        $this->assertSame($u, (int)$car['owner_user_id']);
    }
}
