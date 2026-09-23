<?php
require_once __DIR__ . '/../submission-email-render.php';

use PHPUnit\Framework\TestCase;

final class SubmissionEmailRenderTest extends TestCase
{
    private function sample(array $overrides = []): array {
        return array_merge([
            'submitted_at' => '2026-09-23 14:05:00', 'name' => 'Jane Racer', 'email' => 'jane@example.com',
            'year' => '2019', 'make' => 'Mazda', 'model' => 'MX-5', 'comments' => null,
            'competition_weight' => 2860, 'declared_hp' => 200, 'dyno_hp' => null,
            'chassis_display' => null, 'body_mods_display' => null, 'transmission_display' => null,
            'drivetrain_display' => null, 'tires_display' => null,
            'brake_suspension' => json_encode(['brake3', 'brake7']),
            'weight_factor' => 0.1, 'base_ratio' => 14.3, 'modification_factor' => -1.2,
            'modified_ratio' => 13.2, 'calculated_class' => 'GT4',
        ], $overrides);
    }

    public function testIncludesLogoWhenGiven(): void
    {
        $html = renderSubmissionEmailHtml($this->sample(), 'cid:wcma-logo');
        $this->assertStringContainsString('<img src="cid:wcma-logo"', $html);
        $this->assertStringNotContainsString('<img', renderSubmissionEmailHtml($this->sample()));
    }

    public function testShowsCoreFieldsAndClass(): void
    {
        $html = renderSubmissionEmailHtml($this->sample());
        foreach (['Jane Racer', 'jane@example.com', '2019 Mazda MX-5', '2860 lbs', 'GT4', '14.30', '13.20', '-1.20', 'September 23, 2026'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function testOmitsEmptyOptionalRows(): void
    {
        $html = renderSubmissionEmailHtml($this->sample());
        foreach (['Dyno HP', 'Chassis', 'Body Mods', 'Transmission', 'Drivetrain', 'Tires', 'Comments'] as $label) {
            $this->assertStringNotContainsString($label, $html);
        }
        $html = renderSubmissionEmailHtml($this->sample(['dyno_hp' => 180, 'tires_display' => 'Slicks']));
        $this->assertStringContainsString('Dyno HP', $html);
        $this->assertStringContainsString('Slicks', $html);
    }

    public function testBrakeSuspensionShowsDescriptionsNotIds(): void
    {
        $html = renderSubmissionEmailHtml($this->sample());
        $this->assertStringContainsString('Replace, modify, or remove control arms', $html);
        $this->assertStringContainsString('Increase in track width', $html);
        $this->assertStringNotContainsString('brake3', $html);

        $text = renderSubmissionEmailText($this->sample(['brake_suspension' => ['brake3']]));
        $this->assertStringContainsString('Replace, modify, or remove control arms', $text);
        $this->assertStringNotContainsString('brake3', $text);
    }

    public function testNoBrakeRowWhenNoneSelected(): void
    {
        foreach ([null, '', '[]', []] as $none) {
            $this->assertStringNotContainsString('Brake', renderSubmissionEmailHtml($this->sample(['brake_suspension' => $none])));
        }
    }

    public function testEscapesUserInput(): void
    {
        $html = renderSubmissionEmailHtml($this->sample(['name' => '<script>x</script>', 'comments' => "a & b\nline2"]));
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('a &amp; b<br />', $html);
    }

    public function testResendLabelsOriginalSubmissionDate(): void
    {
        $this->assertStringContainsString('Originally submitted: September 23, 2026', renderSubmissionEmailHtml($this->sample(), null, true));
        $this->assertStringContainsString('Submitted: September 23, 2026', renderSubmissionEmailHtml($this->sample()));
        $this->assertStringContainsString('Originally submitted', renderSubmissionEmailText($this->sample(), true));
    }

    public function testTextVersionHasAllSections(): void
    {
        $text = renderSubmissionEmailText($this->sample());
        foreach (['CONTACT INFORMATION', 'VEHICLE FACTORS', 'CALCULATION RESULTS', 'Calculated Class: GT4', 'Base Ratio: 14.30'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
    }

    public function testBrakeLabelsMatchJavascriptTable(): void
    {
        $js = file_get_contents(__DIR__ . '/../js/modifiers.js');
        preg_match('/export const brakeModifierTable = \[(.*?)\n\];/s', $js, $m);
        $this->assertNotEmpty($m, 'brakeModifierTable not found in modifiers.js');
        preg_match_all('/\["(brake\d+)",\s*"((?:[^"\\\\]|\\\\.)*)"/', $m[1], $rows, PREG_SET_ORDER);
        $fromJs = [];
        foreach ($rows as $r) $fromJs[$r[1]] = stripcslashes($r[2]);
        $this->assertSame($fromJs, SUBMISSION_BRAKE_LABELS);
    }
}
