<?php
// wcma-calculator/tests/GarageLibTest.php
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../garage-lib.php';

use PHPUnit\Framework\TestCase;

final class GarageLibTest extends TestCase
{
    private function decl(int $id, string $status, string $class, ?string $acceptedAt = null): array {
        return ['id' => $id, 'review_status' => $status, 'calculated_class' => $class, 'accepted_at' => $acceptedAt,
                'submitted_at' => sprintf('2026-%02d-01 10:00:00', $id)];
    }

    private function sheet(int $id, int $eventId, int $season = 2026, array $o = []): array {
        return array_merge(['id' => $id, 'car_id' => 3, 'event_id' => $eventId, 'season' => $season, 'status' => 'submitted',
                            'photo_status' => null, 'accepted_via' => null], $o);
    }

    private function event(int $id, string $name, string $date): array {
        return ['id' => $id, 'name' => $name, 'event_date' => $date, 'active' => 1];
    }

    public function testClassLineIsTheNewestNonSupersededDeclaration(): void
    {
        $line = garageClassLine([$this->decl(3, 'accepted', 'GT3', '2026-03-02 10:00:00'), $this->decl(2, 'superseded', 'GT2', '2026-02-02 10:00:00')]);
        $this->assertSame(3, $line['current']['id']);
        $this->assertNull($line['earlierAccepted']);
    }

    public function testClassLineShowsTheNewestEarlierAcceptedWhenTheCurrentIsNotAccepted(): void
    {
        $line = garageClassLine([
            $this->decl(4, 'submitted', 'GT3'),
            $this->decl(3, 'superseded', 'GT4'),
            $this->decl(2, 'superseded', 'GT2', '2026-02-02 10:00:00'),
            $this->decl(1, 'superseded', 'GT1', '2026-01-02 10:00:00'),
        ]);
        $this->assertSame(4, $line['current']['id']);
        $this->assertSame(2, $line['earlierAccepted']['id']);
    }

    public function testClassLineWithNoDeclarations(): void
    {
        $this->assertSame(['current' => null, 'earlierAccepted' => null], garageClassLine([]));
    }

    public function testCarEventsSplitTaggedUntaggedAndEarlierSheets(): void
    {
        $events = [$this->event(11, 'Season Finale', '2026-10-25'), $this->event(10, 'Fall Sprint', '2026-10-11'), $this->event(12, 'Test Day', '2026-09-01')];
        $sheets = [$this->sheet(7, 10), $this->sheet(4, 99)];
        $ev = garageCarEvents($sheets, [10, 12], $events, [99 => 'Spring Opener', 10 => 'Fall Sprint'], '2026-09-25');

        $this->assertCount(1, $ev['tagged']);                          // 12 is tagged but already past
        $this->assertSame(10, $ev['tagged'][0]['event']['id']);
        $this->assertSame(7, $ev['tagged'][0]['sheet']['id']);
        $this->assertSame([11], array_map(fn($e) => (int)$e['id'], $ev['untagged']));
        $this->assertSame(4, $ev['earlierSheets'][0]['sheet']['id']);
        $this->assertSame('Spring Opener', $ev['earlierSheets'][0]['event_name']);
    }

    public function testTaggedEventsAreSoonestFirst(): void
    {
        $events = [$this->event(11, 'Season Finale', '2026-10-25'), $this->event(10, 'Fall Sprint', '2026-10-11')];
        $ev = garageCarEvents([], [11, 10], $events, [], '2026-09-25');
        $this->assertSame([10, 11], array_map(fn($r) => (int)$r['event']['id'], $ev['tagged']));
        $this->assertNull($ev['tagged'][0]['sheet']);
        $this->assertSame([], $ev['untagged']);
    }

    public function testCardHasThisSeasonsTechStatusAndTheNearestTaggedEvent(): void
    {
        $events = [$this->event(11, 'Season Finale', '2026-10-25'), $this->event(10, 'Fall Sprint', '2026-10-11')];
        $sheets = [$this->sheet(7, 11, 2026, ['status' => 'teched', 'accepted_via' => 'photos']), $this->sheet(5, 99, 2025, ['status' => 'teched'])];
        $card = garageCard(['id' => 3, 'car_number' => '42'], [$this->decl(2, 'submitted', 'GT3')], $sheets, [10, 11], $events, 2026, '2026-09-25');

        $this->assertSame('accepted', $card['techState']);
        $this->assertSame('Pre-teched 2026', $card['techLabel']);
        $this->assertSame(10, $card['next']['event']['id']);
        $this->assertNull($card['next']['sheet']);
        $this->assertSame(2, $card['class']['current']['id']);
    }

    public function testCardWithoutTaggedEventsOrSheetsNeedsTechAtTheTrack(): void
    {
        $card = garageCard(['id' => 3, 'car_number' => '42'], [], [], [], [], 2026, '2026-09-25');
        $this->assertNull($card['next']);
        $this->assertSame('none', $card['techState']);
        $this->assertSame('Needs tech at the track', $card['techLabel']);
    }

    public function testTechPhotosAction(): void
    {
        $none = ['state' => 'none', 'via' => null, 'sheet_id' => null];
        $this->assertNull(garageTechPhotosAction([], $none));
        $this->assertSame(['label' => 'Add photos', 'url' => 'tech-sheets.php?action=pretech&id=9'],
            garageTechPhotosAction([$this->sheet(9, 10), $this->sheet(4, 11)], $none));
        $this->assertSame('Continue photos', garageTechPhotosAction([$this->sheet(9, 10)], ['state' => 'photos_draft', 'via' => null, 'sheet_id' => 9])['label']);
        $this->assertSame(['label' => 'Retake photos', 'url' => 'tech-sheets.php?action=pretech&id=4'],
            garageTechPhotosAction([$this->sheet(9, 10)], ['state' => 'needs_changes', 'via' => null, 'sheet_id' => 4]));
        $this->assertSame('View photos', garageTechPhotosAction([$this->sheet(9, 10)], ['state' => 'pending_review', 'via' => null, 'sheet_id' => 9])['label']);
        $this->assertNull(garageTechPhotosAction([$this->sheet(9, 10)], ['state' => 'accepted', 'via' => 'in_person', 'sheet_id' => 9]));
    }
}
