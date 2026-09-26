<?php
// wcma-calculator/tests/TechSheetCarPickerTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../garage-page.php';

use PHPUnit\Framework\TestCase;

final class TechSheetCarPickerTest extends TestCase
{
    private function src(): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../tech-sheets.php'));
    }

    public function testPickerListsTheGivenCarsAndCarriesTheEvent(): void
    {
        $cars = [['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S<2000'],
                 ['id' => 4, 'car_number' => '17', 'year' => null, 'make' => 'Mazda', 'model' => 'Miata']];
        $html = renderTechSheetCarPickerHtml($cars, 10);
        $this->assertStringContainsString('Which car is this tech sheet for?', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new&amp;car_id=3&amp;event_id=10"', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new&amp;car_id=4&amp;event_id=10"', $html);
        $this->assertStringContainsString('Honda S&lt;2000', $html);
        $this->assertStringNotContainsString('event_id=0', renderTechSheetCarPickerHtml($cars, 0));
    }

    public function testNewSheetOffersOnlyActiveCarsWhenNoValidCarIsGiven(): void
    {
        $src = $this->src();
        $start = strpos($src, 'function handleNew(');
        $body = substr($src, $start, strpos($src, "\nfunction ", $start + 1) - $start);
        $this->assertStringContainsString("if (!\$car || \$car['archived_at'] !== null) {", $body);
        $this->assertStringContainsString("\$cars = db_get_user_cars(\$pdo, (int)\$user['id']);", $body);   // active cars only
        $this->assertStringContainsString('renderTechSheetCarPickerHtml($cars, $eventId)', $body);
    }

    public function testFormShowsTheCarReadOnlyWithAnEditLinkAndAColourFallback(): void
    {
        $src = $this->src();
        $this->assertStringNotContainsString('id="car_number" name="car_number"', $src);
        $this->assertStringNotContainsString('id="engine_cc" name="engine_cc"', $src);
        $this->assertStringContainsString('>Edit car details</a>', $src);
        $this->assertStringContainsString('<input type="text" id="car_colour" name="car_colour" maxlength="30" required>', $src);
    }
}
