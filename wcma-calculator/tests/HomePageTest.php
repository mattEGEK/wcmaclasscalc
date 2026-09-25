<?php
// wcma-calculator/tests/HomePageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../home-page.php';

use PHPUnit\Framework\TestCase;

final class HomePageTest extends TestCase
{
    private function item(string $kind, int $id, string $state, string $label, ?array $action = null, ?array $atTrack = null): array {
        return ['kind' => $kind, 'subject_type' => $kind === 'gear' ? 'driver' : 'car', 'subject_id' => $id, 'state' => $state,
                'label' => $label, 'detail' => '', 'action' => $action, 'at_track' => $atTrack];
    }

    private function vm(array $o = []): array {
        return array_merge([
            'name' => 'Jordan Lee',
            'readiness' => ['events' => [[
                'event' => ['id' => 10, 'name' => 'Fall Sprint', 'event_date' => '2099-10-11'],
                'items' => [
                    $this->item('tech_sheet', 3, 'todo', 'Submit a tech sheet for #42', ['label' => 'Submit tech sheet', 'url' => 'tech-sheets.php?action=new&car_id=3&event_id=10']),
                    $this->item('car_tech', 3, 'todo', 'Car tech for #42', null, ['subject_type' => 'car', 'subject_id' => 3, 'season' => 2099]),
                    $this->item('declaration', 3, 'info', 'Class declaration for #42 is with an inspector'),
                    $this->item('gear', 5, 'done', 'Gear for Jordan Lee: pre-teched 2099'),
                ],
            ]], 'untagged' => [['id' => 11, 'name' => 'Season Finale', 'event_date' => '2099-10-25']]],
            'cars' => [3 => ['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000']],
            'garage' => [],
            'drivers' => [],
            'seasonLinks' => [['label' => 'Waiver <2026>', 'url' => 'https://x.test/a?b=1&c=2']],
            'csrf' => 'tok',
        ], $o);
    }

    public function testHeadlineVariants(): void
    {
        $r = $this->vm()['readiness'];
        $this->assertSame('2 things to do before Fall Sprint', homeHeadline($r));
        $r['events'][0]['items'] = array_values(array_filter($r['events'][0]['items'], fn($i) => $i['state'] !== 'todo'));
        $this->assertSame('You\'re all set for Fall Sprint ✓', homeHeadline($r));
        $this->assertSame('Which events are you going to?', homeHeadline(['events' => [], 'untagged' => []]));
        $one = $this->vm()['readiness'];
        array_pop($one['events'][0]['items']); array_pop($one['events'][0]['items']); array_pop($one['events'][0]['items']);
        $this->assertSame('1 thing to do before Fall Sprint', homeHeadline($one));
    }

    public function testHomeRendersNumberedTodosSecondaryActionAndSections(): void
    {
        $html = renderHomeHtml($this->vm());
        $this->assertStringContainsString('class="hub-todo"', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new&amp;car_id=3&amp;event_id=10"', $html);
        $this->assertStringContainsString("I'll do it at the track", $html);
        $this->assertStringContainsString('name="action" value="at-track"', $html);
        $this->assertStringContainsString('name="csrf_token" value="tok"', $html);
        $this->assertStringContainsString('With an inspector', $html);
        $this->assertStringContainsString('Already done for Fall Sprint (1)', $html);
        $this->assertStringContainsString('Season Finale', $html);
        $this->assertStringContainsString("I'm going", $html);
        $this->assertStringContainsString(EVENTS_NOT_REGISTERING, $html);
    }

    public function testSeasonLinksAreEscapedAndOmittedWhenEmpty(): void
    {
        $html = renderHomeHtml($this->vm());
        $this->assertStringContainsString('Waiver &lt;2026&gt;', $html);
        $this->assertStringContainsString('href="https://x.test/a?b=1&amp;c=2"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
        $this->assertStringNotContainsString('MotorsportReg', renderHomeHtml($this->vm(['seasonLinks' => []])));
    }

    public function testEmptyStates(): void
    {
        $noCars = renderHomeHtml($this->vm(['cars' => [], 'readiness' => ['events' => [], 'untagged' => []]]));
        $this->assertStringContainsString('Start by declaring your class', $noCars);
        $this->assertStringContainsString('href="calculator.php"', $noCars);
        $this->assertStringNotContainsString('<div class="hub-card"><h3>Garage</h3><div class="hub-card">', $noCars);
        $noEvents = renderHomeHtml($this->vm(['readiness' => ['events' => [], 'untagged' => []]]));
        $this->assertStringContainsString('No upcoming events yet.', $noEvents);
    }

    public function testLandingOffersCalculatorAndSignIn(): void
    {
        $html = renderLandingHtml([['label' => 'Waiver', 'url' => 'https://x.test']]);
        foreach (['href="calculator.php"', 'action=login', 'action=register', 'Waiver'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function testNoBannedWording(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', file_get_contents(__DIR__ . '/../home-page.php'));
    }
}
