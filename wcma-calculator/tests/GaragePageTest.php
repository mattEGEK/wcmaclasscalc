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
require_once __DIR__ . '/../reminders-lib.php';
require_once __DIR__ . '/../ice-sheet-lib.php';

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
                            'usesSummer' => true, 'ice' => null,
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
        $this->assertStringContainsString('Start by adding your car.', $html);
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

    private function carVm(array $o = []): array {
        return array_merge([
            'car' => $this->car(),
            'class' => ['current' => $this->decl(['reviewer_note' => 'Show the <dyno> sheet']), 'earlierAccepted' => null],
            'declarations' => [$this->decl(['reviewer_note' => 'Show the <dyno> sheet']), $this->decl(['id' => 5, 'review_status' => 'superseded', 'calculated_class' => 'GT2', 'submitted_at' => '2026-02-01 10:00:00'])],
            'season' => 2026, 'techState' => 'none', 'techLabel' => 'Needs tech at the track',
            'techAction' => ['label' => 'Add photos', 'url' => 'tech-sheets.php?action=pretech&id=9'],
            'events' => [
                'tagged' => [
                    ['event' => ['id' => 10, 'name' => 'Fall Sprint', 'event_date' => '2026-10-11'], 'sheet' => null, 'gearLinks' => []],
                    ['event' => ['id' => 11, 'name' => 'Season Finale', 'event_date' => '2026-10-25'], 'sheet' => ['id' => 9, 'season' => 2026], 'gearLinks' => []],
                ],
                'untagged' => [['id' => 12, 'name' => 'Test <Day>', 'event_date' => '2026-11-01']],
                'earlierSheets' => [['sheet' => ['id' => 2], 'event_name' => 'Spring Opener']],
            ],
            'seasons' => ['summer' => true, 'ice' => false],
            'csrf' => 'tok', 'detailsForm' => null,
            'usesSummer' => true, 'ice' => null,
        ], $o);
    }

    public function testCarPageShowsDetailsFormClassAndHistory(): void
    {
        $html = renderGarageCarHtml($this->carVm());
        $this->assertStringContainsString('<h1>2004 Honda S2000</h1>', $html);
        $this->assertStringContainsString('name="action" value="update-car"', $html);
        $this->assertStringContainsString('id="car-colour" name="colour"', $html);
        $this->assertStringContainsString('value="Silver"', $html);
        $this->assertStringContainsString('Show the &lt;dyno&gt; sheet', $html);
        $this->assertStringContainsString('href="calculator.php?car=3">Re-declare class</a>', $html);
        $this->assertStringContainsString('href="garage.php?declaration=8">View</a>', $html);
        $this->assertStringContainsString('<h3>History</h3>', $html);
        $this->assertStringContainsString('href="garage.php?declaration=5"', $html);
        $this->assertStringContainsString('Replaced by a newer declaration', $html);
    }

    public function testCarPageShowsCarTechWithThePhotoAction(): void
    {
        $html = renderGarageCarHtml($this->carVm());
        $this->assertStringContainsString('<h2>Car tech 2026</h2>', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=pretech&amp;id=9">Add photos</a>', $html);
        $noSheet = renderGarageCarHtml($this->carVm(['techAction' => null]));
        $this->assertStringContainsString('after you submit a tech sheet', $noSheet);
    }

    public function testCarPageEventsHaveSheetStatusUntagAndBringToAnotherEvent(): void
    {
        $html = renderGarageCarHtml($this->carVm());
        $this->assertStringContainsString('href="tech-sheets.php?action=new&amp;car_id=3&amp;event_id=10">Submit tech sheet</a>', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=view&amp;id=9">View</a>', $html);
        $this->assertStringContainsString('name="action" value="untag"', $html);
        $this->assertStringContainsString('name="event_id" value="10"', $html);
        $this->assertStringContainsString('Add this car to another event', $html);
        $this->assertStringContainsString('<option value="12">Test &lt;Day&gt;', $html);
        $this->assertStringContainsString(EVENTS_NOT_REGISTERING, $html);
        $this->assertStringContainsString('Spring Opener', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=view&amp;id=2"', $html);
    }

    public function testUndeclaredCarPointsToTheCalculatorInsteadOfTheSheetForm(): void
    {
        $html = renderGarageCarHtml($this->carVm(['class' => ['current' => null, 'earlierAccepted' => null], 'declarations' => []]));
        $this->assertStringContainsString('href="calculator.php?car=3">Declare class</a>', $html);
        $this->assertStringContainsString('Declare a class first', $html);
        $this->assertStringNotContainsString('action=new&amp;car_id=3', $html);
    }

    public function testArchiveFormAsksForConfirmation(): void
    {
        $html = renderGarageCarHtml($this->carVm());
        $this->assertStringContainsString('name="action" value="archive"', $html);
        $this->assertStringContainsString('data-confirm="Archive #42 2004 Honda S2000? It will be hidden, and its history is kept."', $html);
    }

    public function testArchivedCarPageOffersRestoreAndNoEventActions(): void
    {
        $html = renderGarageCarHtml($this->carVm(['car' => $this->car(['archived_at' => '2026-05-01 10:00:00'])]));
        $this->assertStringContainsString('This car is archived', $html);
        $this->assertStringContainsString('name="action" value="restore"', $html);
        $this->assertStringNotContainsString('value="tag"', $html);
        $this->assertStringNotContainsString('value="untag"', $html);
        $this->assertStringNotContainsString('value="archive"', $html);
        $this->assertStringNotContainsString('Re-declare class', $html);
        // carVm()'s tagged event 10 has no sheet: an archived car offers neither
        // "Submit tech sheet" nor "Declare a class first" for it, just the restore hint.
        $this->assertStringNotContainsString('action=new&amp;car_id=3', $html);
        $this->assertStringContainsString('Restore the car to submit a tech sheet', $html);
    }

    public function testFailedDetailsEditReopensTheFormWithTheErrorAndTypedValues(): void
    {
        $html = renderGarageCarHtml($this->carVm(['detailsForm' => ['values' => ['make' => 'Typed'], 'error' => "Enter the car's colour."]]));
        $this->assertStringContainsString('<details class="garage-edit" open>', $html);
        $this->assertStringContainsString('value="Typed"', $html);
        $this->assertStringContainsString('Enter the car&#039;s colour.', $html);
    }

    public function testBringThisCarFormOffersRemindersOnlyWhenAsked(): void
    {
        $this->assertStringContainsString('name="offer_reminders" value="1"', renderGarageCarHtml($this->carVm(['offerReminders' => true])));
        $this->assertStringNotContainsString('offer_reminders', renderGarageCarHtml($this->carVm()));
    }

    public function testIceOnlyCardShowsTheIceChipAndNoDeclare(): void
    {
        $html = garageRenderCard($this->card(['class' => ['current' => null, 'earlierAccepted' => null], 'usesSummer' => false,
            'ice' => ['state' => 'accepted', 'label' => 'Teched Ice 2027 · NASCC · LS'], 'next' => null]));
        $this->assertStringContainsString('Teched Ice 2027 · NASCC · LS', $html);
        $this->assertStringNotContainsString('Declare class', $html);
        $this->assertStringNotContainsString('No class declared yet', $html);
    }

    public function testCardShowsBothChipsForACarRacedInBothSeasons(): void
    {
        $html = garageRenderCard($this->card(['usesSummer' => true, 'ice' => ['state' => 'none', 'label' => 'Needs ice tech']]));
        $this->assertStringContainsString('Needs ice tech', $html);
        $this->assertStringContainsString('Needs tech at the track', $html);   // the summer chip from card()
    }

    public function testCardNextIceEventOffersTheIceForm(): void
    {
        $html = garageRenderCard($this->card(['usesSummer' => false, 'class' => ['current' => null, 'earlierAccepted' => null],
            'ice' => ['state' => 'none', 'label' => 'Needs ice tech'],
            'next' => ['event' => ['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2027-01-10', 'discipline' => 'ice', 'host_club' => 'NASCC'], 'sheet' => null]]));
        $this->assertStringContainsString('href="tech-sheets.php?action=new-ice&amp;car_id=3&amp;event_id=20">Submit ice tech sheet</a>', $html);
    }

    public function testTaggedIceEventRowLinksToTheIceForm(): void
    {
        $html = renderGarageCarHtml($this->carVm(['usesSummer' => false, 'class' => ['current' => null, 'earlierAccepted' => null],
            'events' => ['tagged' => [
                ['event' => ['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2027-01-10', 'discipline' => 'ice', 'host_club' => 'NASCC'], 'sheet' => null, 'gearLinks' => []],
                ['event' => ['id' => 21, 'name' => 'WSCC Ice', 'event_date' => '2027-01-18', 'discipline' => 'ice', 'host_club' => 'WSCC'],
                 'sheet' => ['id' => 9, 'season' => 2027, 'discipline' => 'ice', 'club' => 'WSCC', 'class' => 'FOI-STD'], 'gearLinks' => []],
            ], 'untagged' => [['id' => 22, 'name' => 'Spring <Ice>', 'event_date' => '2027-02-01', 'discipline' => 'ice', 'host_club' => 'NASCC']], 'earlierSheets' => []]]));
        $this->assertStringContainsString('href="tech-sheets.php?action=new-ice&amp;car_id=3&amp;event_id=20">Submit ice tech sheet</a>', $html);
        $this->assertStringNotContainsString('Declare a class first', $html);
        $this->assertStringContainsString('FOI-STD — Fire on Ice – Studded (WSCC)', $html);
        $this->assertStringContainsString('Spring &lt;Ice&gt; — ', $html);
        $this->assertStringContainsString(' · Ice NASCC</option>', $html);
        $this->assertStringNotContainsString('<h2>Ice racing</h2>', $html);
        $this->assertStringNotContainsString('<h2>Class</h2>', $html);   // ice-only car: no summer class card
    }

    public function testDeclarationPageShowsTheReviewAndFilesAndGuardsDelete(): void
    {
        $s = $this->decl(['id' => 8, 'car_id' => 3, 'year' => '2004', 'make' => 'Honda', 'model' => 'S2<000', 'review_status' => 'needs_changes',
                          'reviewer_note' => 'Add the dyno table', 'car_image_path' => 'uploads/8/car.jpg', 'dyno_chart_path' => null, 'dyno_table_path' => 'uploads/8/t.pdf']);
        $html = renderDeclarationHtml($s, 'tok');
        $this->assertStringContainsString('2004 Honda S2&lt;000', $html);
        $this->assertStringContainsString('Needs changes', $html);
        $this->assertStringContainsString('Add the dyno table', $html);
        $this->assertStringContainsString('src="garage.php?action=file&amp;id=8&amp;field=car_image"', $html);
        $this->assertStringContainsString('href="garage.php?action=file&amp;id=8&amp;field=dyno_table"', $html);
        $this->assertStringNotContainsString('field=dyno_chart', $html);
        $this->assertStringContainsString('name="action" value="resend-declaration"', $html);
        $this->assertStringContainsString('data-confirm="Permanently delete this class declaration and its files?"', $html);
        $this->assertStringContainsString('href="garage.php?car=3"', $html);
    }

    private function addVm(array $o = []): array {
        return array_merge(['csrf' => 'tok', 'values' => [], 'error' => null, 'msrLink' => null, 'event' => null], $o);
    }

    public function testAddCarAsksWhereTheCarRacesFirst(): void
    {
        $html = renderAddCarHtml($this->addVm());
        $this->assertStringContainsString('<legend>Where will this car race? (required)</legend>', $html);
        foreach (['ice' => 'Ice', 'summer' => 'Summer', 'both' => 'Both'] as $v => $label) {
            $this->assertStringContainsString('<input type="radio" name="disciplines" value="' . $v . '" required>', $html);
            $this->assertStringContainsString('<span>' . $label . '</span>', $html);
        }
        $this->assertStringNotContainsString('declare its class with the Class Calculator', $html);
        $this->assertLessThan(strpos($html, 'id="car-car_number"'), strpos($html, 'Where will this car race?'));
    }

    public function testAddCarLabelsYearOptionalAndUsesANumberKeypadForTheCarNumber(): void
    {
        $html = renderAddCarHtml($this->addVm());
        $this->assertStringContainsString('<label for="car-year">Year (optional)</label>', $html);
        $this->assertMatchesRegularExpression('/id="car-car_number"[^>]*inputmode="numeric"/', $html);
    }

    public function testAddCarForAnEventPreselectsItsSeasonAndCarriesTheEvent(): void
    {
        $html = renderAddCarHtml($this->addVm(['values' => ['disciplines' => 'ice'],
            'event' => ['id' => 12, 'name' => 'NASCC Ice Race #1', 'event_date' => '2026-11-12', 'discipline' => 'ice']]));
        $this->assertStringContainsString('<input type="radio" name="disciplines" value="ice" required checked>', $html);
        $this->assertStringContainsString('<input type="hidden" name="event_id" value="12">', $html);
        $this->assertStringContainsString('for NASCC Ice Race #1', $html);
    }

    public function testEditDetailsShowsTheSeasonButDoesNotRequireIt(): void
    {
        $html = renderGarageCarHtml($this->carVm(['car' => $this->car(['disciplines' => 'both'])]));
        $this->assertStringContainsString('<legend>Where will this car race?</legend>', $html);
        $this->assertStringContainsString('<input type="radio" name="disciplines" value="both" checked>', $html);
    }

    private function iceEvent(int $id = 12): array {
        return ['id' => $id, 'name' => 'NASCC Ice Race #1', 'event_date' => '2026-11-12', 'discipline' => 'ice', 'host_club' => 'NASCC'];
    }

    public function testIceCarWithNoEventShowsIceEventButtonsAndNoClassCard(): void
    {
        $html = renderGarageCarHtml($this->carVm([
            'seasons' => ['summer' => false, 'ice' => true], 'usesSummer' => false,
            'class' => ['current' => null, 'earlierAccepted' => null],
            'ice' => ['state' => 'none', 'label' => 'Needs ice tech'],
            'events' => ['tagged' => [], 'untagged' => [$this->iceEvent()], 'earlierSheets' => []],
        ]));
        $this->assertStringContainsString('<h2>Which ice event is this car going to?</h2>', $html);
        $this->assertStringContainsString('<input type="hidden" name="then" value="sheet">', $html);
        $this->assertStringContainsString('NASCC Ice Race #1 · Thu, Nov 12 · NASCC', $html);
        $this->assertStringNotContainsString('No class declared yet', $html);
        $this->assertStringNotContainsString('<h2>Class</h2>', $html);
        $this->assertLessThan(strpos($html, '<h2>Details</h2>'), strpos($html, 'Which ice event is this car going to?'));
    }

    public function testIceCarTaggedWithoutASheetLeadsWithSubmitIceTechSheet(): void
    {
        $html = renderGarageCarHtml($this->carVm([
            'seasons' => ['summer' => false, 'ice' => true], 'usesSummer' => false,
            'events' => ['tagged' => [['event' => $this->iceEvent(), 'sheet' => null, 'gearLinks' => []]], 'untagged' => [], 'earlierSheets' => []],
        ]));
        $this->assertStringContainsString('<h2>Next: your ice tech sheet</h2>', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new-ice&amp;car_id=3&amp;event_id=12">Submit ice tech sheet</a>', $html);
    }

    public function testArchivedIceCarHasNoNextStep(): void
    {
        $html = renderGarageCarHtml($this->carVm([
            'car' => $this->car(['archived_at' => '2026-09-01 10:00:00']),
            'seasons' => ['summer' => false, 'ice' => true], 'usesSummer' => false,
            'events' => ['tagged' => [], 'untagged' => [$this->iceEvent()], 'earlierSheets' => []],
        ]));
        $this->assertStringNotContainsString('Which ice event is this car going to?', $html);
    }

    public function testTagFormSaysAddThisCarToAnEventUntilItHasOne(): void
    {
        $vm = $this->carVm(['events' => ['tagged' => [], 'untagged' => [['id' => 10, 'name' => 'Fall Sprint', 'event_date' => '2026-10-15']], 'earlierSheets' => []]]);
        $this->assertStringContainsString('>Add this car to an event</label>', renderGarageCarHtml($vm));
        $vm['events']['tagged'] = [['event' => ['id' => 9, 'name' => 'Spring', 'event_date' => '2026-10-01'], 'sheet' => null, 'gearLinks' => []]];
        $this->assertStringContainsString('>Add this car to another event</label>', renderGarageCarHtml($vm));
    }

    private function iceSheetRow(array $o = []): array {
        return array_merge(['id' => 30, 'car_id' => 3, 'event_id' => 12, 'season' => 2027, 'discipline' => 'ice', 'club' => 'NASCC', 'class' => 'SS', 'status' => 'submitted'], $o);
    }

    public function testNextStepDoesNotAskForASecondSheetForTheSameClubAndSeason(): void
    {
        $race2 = ['id' => 13, 'name' => 'NASCC Ice Race #2', 'event_date' => '2027-01-20', 'discipline' => 'ice', 'host_club' => 'NASCC'];
        $html = renderGarageCarHtml($this->carVm([
            'seasons' => ['summer' => false, 'ice' => true], 'usesSummer' => false,
            'iceSheets' => [$this->iceSheetRow()],
            'ice' => ['state' => 'none', 'label' => 'Ice tech sheet in · NASCC · SS'],
            'events' => ['tagged' => [['event' => $race2, 'sheet' => null, 'gearLinks' => []]], 'untagged' => [], 'earlierSheets' => []],
        ]));
        $this->assertStringNotContainsString('Next: your ice tech sheet', $html);
        $this->assertStringContainsString('<h2>Ice tech sheet sent</h2>', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=view&amp;id=30">See what\'s next</a>', $html);
    }

    public function testNextStepMovesOnToAnotherClubsEventWithoutASheet(): void
    {
        $wscc = ['id' => 14, 'name' => 'WSCC Fire on Ice #1', 'event_date' => '2026-11-19', 'discipline' => 'ice', 'host_club' => 'WSCC'];
        $html = renderGarageCarHtml($this->carVm([
            'seasons' => ['summer' => false, 'ice' => true], 'usesSummer' => false,
            'iceSheets' => [$this->iceSheetRow()],
            'events' => ['tagged' => [['event' => $this->iceEvent(), 'sheet' => $this->iceSheetRow(), 'gearLinks' => []],
                                      ['event' => $wscc, 'sheet' => null, 'gearLinks' => []]], 'untagged' => [], 'earlierSheets' => []],
        ]));
        $this->assertStringContainsString('<h2>Next: your ice tech sheet</h2>', $html);
        $this->assertStringContainsString('event_id=14">Submit ice tech sheet</a>', $html);
    }
}
