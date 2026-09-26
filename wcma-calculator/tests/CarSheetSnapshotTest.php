<?php
// wcma-calculator/tests/CarSheetSnapshotTest.php
require_once __DIR__ . '/../cars-lib.php';

use PHPUnit\Framework\TestCase;

final class CarSheetSnapshotTest extends TestCase
{
    private function car(array $o = []): array {
        return array_merge(['id' => 3, 'car_number' => '042', 'make' => 'Honda', 'model' => 'S2000', 'colour' => 'Silver', 'engine_cc' => '1997'], $o);
    }

    public function testTheSheetCopiesTheCarAndIgnoresPostedCarFields(): void
    {
        $r = carsSheetSnapshot($this->car(), ['car_number' => '99', 'car_colour' => 'Pink', 'engine_cc' => '1']);
        $this->assertSame(['ok' => true, 'error' => null, 'car_number' => '042', 'car_colour' => 'Silver', 'engine_cc' => '1997', 'colour_for_car' => null], $r);
        $this->assertNull(carsSheetSnapshot($this->car(['engine_cc' => '']), [])['engine_cc']);
    }

    public function testACarWithoutAColourTakesItFromTheFormAndSavesItBack(): void
    {
        $r = carsSheetSnapshot($this->car(['colour' => null]), ['car_colour' => '  Rally   Blue ']);
        $this->assertTrue($r['ok']);
        $this->assertSame('Rally Blue', $r['car_colour']);
        $this->assertSame('Rally Blue', $r['colour_for_car']);
    }

    public function testAMissingColourIsAnError(): void
    {
        $this->assertSame("Enter the car's colour.", carsSheetSnapshot($this->car(['colour' => '']), ['car_colour' => ' '])['error']);
        $this->assertFalse(carsSheetSnapshot($this->car(['colour' => '']), ['car_colour' => str_repeat('x', 31)])['ok']);
    }
}
