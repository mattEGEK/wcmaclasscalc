<?php
// wcma-calculator/tests/GaragePageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../gear-chips.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../garage-lib.php';
require_once __DIR__ . '/../garage-page.php';

use PHPUnit\Framework\TestCase;

final class GaragePageTest extends TestCase
{
    private function car(array $o = []): array {
        return array_merge(['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000',
                            'colour' => 'Silver', 'engine_cc' => '1997', 'archived_at' => null], $o);
    }

    private function decl(array $o = []): array {
        return array_merge(['id' => 8, 'review_status' => 'submitted', 'calculated_class' => 'GT3', 'accepted_at' => null,
                            'submitted_at' => '2026-04-02 10:00:00', 'competition_weight' => 2800, 'declared_hp' => 240, 'reviewer_note' => null], $o);
    }

    private function card(array $o = []): array {
        return array_merge(['car' => $this->car(), 'class' => ['current' => $this->decl(), 'earlierAccepted' => null],
                            'techState' => 'none', 'techLabel' => 'Needs tech at the track',
                            'next' => ['event' => ['id' => 10, 'name' => 'Fall Sprint', 'event_date' => '2026-10-11'], 'sheet' => null]], $o);
    }

    public function testListShowsACardPerCarWithPlateClassTechAndNextEvent(): void
    {
        $html = renderGarageListHtml(['cards' => [$this->card()], 'archived' => [], 'csrf' => 'tok']);
        $this->assertStringContainsString('<h1>Garage</h1>', $html);
        $this->assertStringContainsString('<span class="hub-plate hub-plate--lg">42</span>', $html);
        $this->assertStringContainsString('href="garage.php?car=3">2004 Honda S2000</a>', $html);
        $this->assertStringContainsString('Silver · 1997 cc', $html);
        $this->assertStringContainsString('<span class="hub-class">GT3</span>', $html);
        $this->assertStringContainsString('With an inspector', $html);
        $this->assertStringContainsString('Needs tech at the track', $html);
        $this->assertStringContainsString('<dt>Fall Sprint</dt>', $html);
        $this->assertStringContainsString('No tech sheet', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new&amp;car_id=3&amp;event_id=10"', $html);
        $this->assertStringContainsString('href="garage.php?action=add">+ Add a car</a>', $html);
    }

    public function testCardShowsAnEarlierAcceptedClassAndAnUndeclaredCarOffersDeclare(): void
    {
        $html = renderGarageListHtml(['cards' => [$this->card(['class' => ['current' => $this->decl(), 'earlierAccepted' => $this->decl(['id' => 5, 'calculated_class' => 'GT2'])]])], 'archived' => [], 'csrf' => 't']);
        $this->assertStringContainsString('Accepted: GT2', $html);

        $html = renderGarageListHtml(['cards' => [$this->card(['class' => ['current' => null, 'earlierAccepted' => null], 'next' => null])], 'archived' => [], 'csrf' => 't']);
        $this->assertStringContainsString('No class declared yet', $html);
        $this->assertStringContainsString('href="calculator.php?car=3">Declare class</a>', $html);
        $this->assertStringContainsString('Not going to any events yet', $html);
    }

    public function testArchivedCarsAreListedSeparatelyWithRestore(): void
    {
        $archived = $this->car(['id' => 4, 'car_number' => '17', 'make' => 'Mazda', 'model' => 'Miata', 'archived_at' => '2026-05-01 10:00:00']);
        $html = renderGarageListHtml(['cards' => [], 'archived' => [$archived], 'csrf' => 'tok']);
        $this->assertStringContainsString('<summary>Archived cars (1)</summary>', $html);
        $this->assertStringNotContainsString('hub-plate--lg">17', $html);
        $this->assertStringContainsString('name="action" value="restore"', $html);
        $this->assertStringContainsString('name="car_id" value="4"', $html);
        $this->assertStringContainsString('Start by adding your car and declaring its class.', $html);
    }

    public function testListEscapesCarFields(): void
    {
        $html = renderGarageListHtml(['cards' => [$this->card(['car' => $this->car(['make' => '<b>Hon"da'])])], 'archived' => [], 'csrf' => 'tok']);
        $this->assertStringContainsString('&lt;b&gt;Hon&quot;da', $html);
        $this->assertStringNotContainsString('<b>Hon', $html);
    }

    public function testAddCarFormHasTheFieldsTheErrorAndTheMsrLink(): void
    {
        $html = renderAddCarHtml(['csrf' => 'tok', 'values' => ['make' => 'Hon<da'], 'error' => "Enter the car's colour.",
                                  'msrLink' => ['label' => 'Car Classing & Number Reservation', 'url' => 'https://msr.test/x?a=1&b=2']]);
        foreach (['car_number', 'year', 'make', 'model', 'colour', 'engine_cc'] as $f) {
            $this->assertStringContainsString('name="' . $f . '"', $html, $f);
        }
        $this->assertStringContainsString('value="Hon&lt;da"', $html);
        $this->assertStringContainsString('Enter the car&#039;s colour.', $html);
        $this->assertStringContainsString('href="https://msr.test/x?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('name="action" value="add"', $html);
        $this->assertStringNotContainsString('target="_blank"', renderAddCarHtml(['csrf' => 't', 'values' => [], 'error' => null, 'msrLink' => null]));
    }
}
