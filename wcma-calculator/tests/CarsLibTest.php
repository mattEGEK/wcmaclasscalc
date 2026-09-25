<?php
// wcma-calculator/tests/CarsLibTest.php
require_once __DIR__ . '/../cars-lib.php';

use PHPUnit\Framework\TestCase;

final class CarsLibTest extends TestCase
{
    private function user(PDO $pdo, string $email = 'r@example.com'): int {
        return db_create_user($pdo, ['email' => $email, 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testNewCarIsCreatedFromTheForm(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $r = carsResolveForDeclaration($pdo, $u, ['car_id' => 'new', 'car_number' => ' 42 ', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000']);
        $this->assertTrue($r['ok']);
        $car = db_get_car($pdo, $r['car_id']);
        $this->assertSame('42', $car['car_number']);
        $this->assertSame('Honda', $car['make']);
        $this->assertSame($u, (int)$car['owner_user_id']);
    }

    public function testNewCarNeedsNumberMakeAndModel(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $this->assertSame('Enter the car number for your new car.', carsResolveForDeclaration($pdo, $u, ['car_id' => 'new', 'make' => 'H', 'model' => 'S'])['error']);
        $this->assertFalse(carsResolveForDeclaration($pdo, $u, ['car_id' => 'new', 'car_number' => '12345678901', 'make' => 'H', 'model' => 'S'])['ok']);
        $this->assertFalse(carsResolveForDeclaration($pdo, $u, ['car_id' => 'new', 'car_number' => '4', 'make' => '', 'model' => 'S'])['ok']);
        $this->assertSame('Choose which car this class declaration is for.', carsResolveForDeclaration($pdo, $u, ['car_id' => ''])['error']);
    }

    public function testExistingCarMustBeOwnedAndActiveAndIsUpdated(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $other = $this->user($pdo, 'o@example.com');
        $mine = db_create_car($pdo, $u, ['car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000']);
        $theirs = db_create_car($pdo, $other, ['car_number' => '7', 'make' => 'Mazda', 'model' => 'MX-5']);

        $r = carsResolveForDeclaration($pdo, $u, ['car_id' => (string)$mine, 'year' => '2005', 'make' => 'Honda', 'model' => 'S2000 CR']);
        $this->assertSame($mine, $r['car_id']);
        $this->assertSame('S2000 CR', db_get_car($pdo, $mine)['model']);
        $this->assertSame('2005', db_get_car($pdo, $mine)['year']);

        $this->assertFalse(carsResolveForDeclaration($pdo, $u, ['car_id' => (string)$theirs, 'make' => 'x', 'model' => 'y'])['ok']);
        db_archive_car($pdo, $u, $mine);
        $this->assertFalse(carsResolveForDeclaration($pdo, $u, ['car_id' => (string)$mine, 'make' => 'x', 'model' => 'y'])['ok']);
    }

    public function testLabelsAndPublicShape(): void
    {
        $car = ['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'colour' => 'Silver', 'engine_cc' => null];
        $this->assertSame('#42 2004 Honda S2000', carDisplayName($car));
        $this->assertSame('#42 Honda S2000', carDisplayName(['year' => null] + $car));

        $this->assertSame('With an inspector', declarationReviewLabel('submitted'));
        $this->assertSame('Accepted', declarationReviewLabel('accepted'));
        $this->assertSame('Needs changes', declarationReviewLabel('needs_changes'));
        $this->assertSame('badge-ok', declarationReviewBadgeClass('accepted'));
        $this->assertSame('badge-fail', declarationReviewBadgeClass('needs_changes'));
        $this->assertSame('badge-pending', declarationReviewBadgeClass('submitted'));

        $shape = carsPublicShape($car, ['calculated_class' => 'GT3', 'review_status' => 'submitted']);
        $this->assertSame(['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000',
            'colour' => 'Silver', 'label' => '#42 2004 Honda S2000', 'current_class' => 'GT3', 'review_status' => 'submitted'], $shape);
        $this->assertNull(carsPublicShape($car, null)['current_class']);
    }

    public function testApplySheetDetailsWritesBackToTheCar(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $id = db_create_car($pdo, $u, ['car_number' => '42', 'make' => 'Honda', 'model' => 'S2000']);
        carsApplySheetDetails($pdo, $id, '042', 'Blue', '1998');
        $car = db_get_car($pdo, $id);
        $this->assertSame(['042', '42', 'Blue', '1998'], [$car['car_number'], $car['car_number_norm'], $car['colour'], $car['engine_cc']]);
    }

    public function testNoBannedWording(): void
    {
        $src = file_get_contents(__DIR__ . '/../cars-lib.php') . file_get_contents(__DIR__ . '/../email-copy.php');
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $src);
    }
}
