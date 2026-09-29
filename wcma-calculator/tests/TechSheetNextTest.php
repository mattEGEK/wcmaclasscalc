<?php
// wcma-calculator/tests/TechSheetNextTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../ice-rules.php';
require_once __DIR__ . '/../tech-sheet-next.php';

use PHPUnit\Framework\TestCase;

final class TechSheetNextTest extends TestCase
{
    private function sheet(array $o = []): array {
        return array_merge(['id' => 2, 'status' => 'submitted', 'discipline' => 'ice', 'club' => 'NASCC',
                            'car_number' => '42', 'car_make' => 'Honda', 'car_model' => 'Civic'], $o);
    }

    private function event(): array {
        return ['id' => 12, 'name' => 'NASCC Ice Race #1', 'event_date' => '2026-11-12'];
    }

    public function testTitleNamesTheSheetCarAndEvent(): void
    {
        $this->assertSame('Ice tech sheet — #42 Honda Civic — NASCC Ice Race #1', techSheetViewTitle($this->sheet(), $this->event()));
        $this->assertSame('Tech sheet — #42 Honda Civic', techSheetViewTitle($this->sheet(['discipline' => 'summer']), null));
    }

    public function testNextStepsOfferPreTechGearAndRegistration(): void
    {
        $html = renderTechSheetNextStepsHtml($this->sheet(), $this->event(), ['state' => 'none'], '<ul class="gear-chips"></ul>',
            ['name' => 'Northern Alberta Sports Car Club', 'url' => '']);
        $this->assertStringContainsString('<h2>What\'s next</h2>', $html);
        $this->assertStringContainsString('<a class="hub-btn" href="tech-sheets.php?action=pretech&amp;id=2">Pre-tech with photos</a>', $html);
        $this->assertStringContainsString('<ul class="gear-chips"></ul>', $html);
        $this->assertStringContainsString('Register for NASCC Ice Race #1 with the Northern Alberta Sports Car Club.', $html);
    }

    public function testNoPreTechStepOnceTheCarIsAcceptedOrPhotosAreWithAnInspector(): void
    {
        $this->assertStringNotContainsString('action=pretech', renderTechSheetNextStepsHtml($this->sheet(), $this->event(), ['state' => 'accepted'], ''));
        $pending = renderTechSheetNextStepsHtml($this->sheet(), $this->event(), ['state' => 'pending_review'], '');
        $this->assertStringContainsString('Your photos are with an inspector.', $pending);
        $this->assertStringNotContainsString('Pre-tech with photos</a>', $pending);
        $this->assertStringContainsString('>Retake photos</a>', renderTechSheetNextStepsHtml($this->sheet(), $this->event(), ['state' => 'needs_changes'], ''));
    }

    public function testNoGearStepWithoutGearChipsAndSummerSaysHostClub(): void
    {
        $html = renderTechSheetNextStepsHtml($this->sheet(['discipline' => 'summer', 'club' => null]), $this->event(), ['state' => 'none'], '');
        $this->assertStringNotContainsString('Driver gear', $html);
        $this->assertStringContainsString('Register for NASCC Ice Race #1 with the host club.', $html);
    }

    public function testTitleIncludesTheCarYearWhenKnown(): void
    {
        $this->assertSame('Ice tech sheet — #42 2008 Honda Civic — NASCC Ice Race #1', techSheetViewTitle($this->sheet(), $this->event(), ['year' => '2008']));
        $this->assertSame('Ice tech sheet — #42 Honda Civic — NASCC Ice Race #1', techSheetViewTitle($this->sheet(), $this->event(), ['year' => null]));
    }

    public function testRegisterStepNamesTheClubAndLinksToMotorsportReg(): void
    {
        $html = renderTechSheetNextStepsHtml($this->sheet(['discipline' => 'summer', 'club' => null]), ['id' => 10, 'name' => 'Fall Sprint'],
            ['state' => 'none'], '', ['name' => 'Edmonton Sports Car Club', 'url' => 'https://msr.example/escc?a=1&b=2']);
        $this->assertStringContainsString('Register for Fall Sprint with the Edmonton Sports Car Club.', $html);
        $this->assertStringContainsString('<a class="hub-btn hub-btn--secondary" href="https://msr.example/escc?a=1&amp;b=2" target="_blank" rel="noopener">Register on MotorsportReg &#8599;</a>', $html);
        $noLink = renderTechSheetNextStepsHtml($this->sheet(), $this->event(), ['state' => 'none'], '', ['name' => 'Northern Alberta Sports Car Club', 'url' => '']);
        $this->assertStringContainsString('with the Northern Alberta Sports Car Club.', $noLink);
        $this->assertStringNotContainsString('Register on MotorsportReg', $noLink);
    }
}
