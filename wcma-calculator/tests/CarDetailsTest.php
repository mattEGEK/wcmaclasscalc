<?php
// wcma-calculator/tests/CarDetailsTest.php
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../view_helpers.php';

use PHPUnit\Framework\TestCase;

final class CarDetailsTest extends TestCase
{
    private function form(array $o = []): array {
        return array_merge(['car_number' => ' 42 ', 'year' => '2004', 'make' => 'Honda', 'model' => ' S2000 ', 'colour' => 'Silver', 'engine_cc' => ''], $o);
    }

    public function testValidDetailsAreTrimmedAndBlankOptionalsBecomeNull(): void
    {
        $r = carsValidateDetails($this->form());
        $this->assertTrue($r['ok']);
        $this->assertSame(['car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'colour' => 'Silver', 'engine_cc' => null], $r['data']);
        $this->assertNull(carsValidateDetails($this->form(['year' => '']))['data']['year']);
    }

    public function testRequiredFieldsAreNamedInTheError(): void
    {
        $this->assertSame("Enter the car's number.", carsValidateDetails($this->form(['car_number' => '  ']))['error']);
        $this->assertSame("Enter the car's make.", carsValidateDetails($this->form(['make' => '']))['error']);
        $this->assertSame("Enter the car's model.", carsValidateDetails($this->form(['model' => '']))['error']);
        $this->assertSame("Enter the car's colour.", carsValidateDetails($this->form(['colour' => '']))['error']);
    }

    public function testLengthAndYearRules(): void
    {
        $this->assertSame('That number is too long (10 characters at most).', carsValidateDetails($this->form(['car_number' => '12345678901']))['error']);
        $this->assertSame('Enter the year as four digits, like 2004.', carsValidateDetails($this->form(['year' => '04']))['error']);
        $this->assertFalse(carsValidateDetails($this->form(['year' => 'abcd']))['ok']);
        $this->assertTrue(carsValidateDetails($this->form(['engine_cc' => '1997']))['ok']);
    }

    public function testAFailedValidationKeepsWhatWasTyped(): void
    {
        $r = carsValidateDetails($this->form(['colour' => '']));
        $this->assertSame('Honda', $r['data']['make']);
    }

    public function testSeasonLinkMatchingIsCaseInsensitiveAndNullWhenAbsent(): void
    {
        $links = [['label' => '2026 Race Licences', 'url' => 'a'], ['label' => 'Car Classing & Number Reservation', 'url' => 'b']];
        $this->assertSame('b', seasonLinkMatching($links, 'classing')['url']);
        $this->assertSame('a', seasonLinkMatching($links, 'Licen')['url']);
        $this->assertNull(seasonLinkMatching($links, 'Waiver'));
    }
}
