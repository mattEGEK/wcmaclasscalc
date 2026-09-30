<?php
// wcma-calculator/tests/TaDriftSheetLibTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../ice-sheet-lib.php';

final class TaDriftSheetLibTest extends TestCase
{
    private function okChecklist(bool $caged, string $club = 'WSCC'): array {
        return array_map(fn($v): array => ['status' => 'ok'], emptyChecklist(taDriftChecklistSections($caged, $club)));
    }

    private function okEquipment(bool $caged): array {
        $e = emptyDriverEquipment(taDriftEquipmentItems($caged));
        foreach ($e as $k => $v) $e[$k]['competitor_confirmed'] = true;
        $e['helmet']['value'] = 'Snell SA2020';
        return $e;
    }

    private function parsed(array $o = []): array {
        return array_merge([
            'caged' => false, 'checklist' => $this->okChecklist(false), 'equipment' => $this->okEquipment(false),
            'drivers_input' => [], 'entrant_name' => 'Pat Winters', 'driver_name' => 'Pat Winters',
            'car_number' => '86', 'car_colour' => 'White', 'engine_cc' => null,
        ], $o);
    }

    public function testOpenEventsAreSummerEventsWithAHostClub(): void
    {
        $events = [
            ['id' => 1, 'discipline' => 'summer', 'host_club' => 'WSCC'],
            ['id' => 2, 'discipline' => 'summer', 'host_club' => null],
            ['id' => 3, 'discipline' => 'summer', 'host_club' => '  '],
            ['id' => 4, 'discipline' => 'ice', 'host_club' => 'NASCC'],
            ['id' => 5, 'host_club' => 'NASCC'],   // rows from before disciplines are summer
        ];
        $this->assertSame([1, 5], array_column(taDriftOpenEvents($events), 'id'));
    }

    public function testParsePost(): void
    {
        $p = taDriftSheetParsePost([
            'caged' => '1', 'checklist_json' => '{"brakes":{"status":"ok"}}', 'driver1_equipment_json' => 'not json',
            'drivers_json' => '[{"driver_number":2,"driver_name":"Sam","equipment":{}}]',
            'entrant_name' => ' Pat ', 'driver_name' => 'Pat', 'car_number' => '86', 'car_colour' => 'White', 'engine_cc' => '',
        ]);
        $this->assertTrue($p['caged']);
        $this->assertSame(['brakes' => ['status' => 'ok']], $p['checklist']);
        $this->assertSame([], $p['equipment']);
        $this->assertSame('Sam', $p['drivers_input'][0]['driver_name']);
        $this->assertSame('Pat', $p['entrant_name']);
        $this->assertNull($p['engine_cc']);
        $this->assertFalse(taDriftSheetParsePost([])['caged']);
        $this->assertFalse(taDriftSheetParsePost(['caged' => ['1']])['caged']);
    }

    public function testValidateAcceptsACompleteSheet(): void
    {
        $this->assertSame(['error' => null, 'drivers' => []], taDriftSheetValidate($this->parsed(), 'WSCC'));
    }

    public function testValidateRefusesGaps(): void
    {
        $this->assertSame('Please complete every required field.', taDriftSheetValidate($this->parsed(['entrant_name' => '']), 'WSCC')['error']);
        $checklist = $this->okChecklist(false);
        unset($checklist['tow_points']);
        $this->assertSame('Please mark every checklist item OK or N/A.', taDriftSheetValidate($this->parsed(['checklist' => $checklist]), 'WSCC')['error']);
        $equipment = $this->okEquipment(false);
        $equipment['helmet']['value'] = '';
        $this->assertStringContainsString("Driver 1's safety equipment", (string)taDriftSheetValidate($this->parsed(['equipment' => $equipment]), 'WSCC')['error']);
    }

    public function testRegulationsItemCannotBeNotApplicable(): void
    {
        $checklist = $this->okChecklist(false);
        $checklist['supps_read'] = ['status' => 'na'];
        $this->assertSame('Confirm you have read the WSCC supplementary regulations.',
            taDriftSheetValidate($this->parsed(['checklist' => $checklist]), 'WSCC')['error']);
    }

    public function testCagedSheetNeedsTheCageChecksAndARestraint(): void
    {
        // An uncaged checklist is incomplete once the car is caged.
        $this->assertNotNull(taDriftSheetValidate($this->parsed(['caged' => true]), 'WSCC')['error']);
        $ok = $this->parsed(['caged' => true, 'checklist' => $this->okChecklist(true), 'equipment' => $this->okEquipment(true)]);
        $this->assertNull(taDriftSheetValidate($ok, 'WSCC')['error']);
        $ok['equipment']['head_neck_restraints']['competitor_confirmed'] = false;
        $this->assertNotNull(taDriftSheetValidate($ok, 'WSCC')['error']);
    }

    public function testAddedDriversAreCheckedAgainstTheTaDriftGear(): void
    {
        $row = ['driver_number' => 2, 'driver_name' => 'Sam Patel', 'equipment' => $this->okEquipment(false)];
        $r = taDriftSheetValidate($this->parsed(['drivers_input' => [$row]]), 'WSCC');
        $this->assertNull($r['error']);
        $this->assertSame([['driver_number' => 2, 'driver_name' => 'Sam Patel', 'equipment_json' => json_encode($this->okEquipment(false))]], $r['drivers']);

        $row['equipment']['clothing']['competitor_confirmed'] = false;
        $this->assertSame('Please confirm the safety equipment of every added driver.',
            taDriftSheetValidate($this->parsed(['drivers_input' => [$row]]), 'WSCC')['error']);
    }

    public function testRowHasNoClassWeightHpOrLogBook(): void
    {
        $row = taDriftSheetRow($this->parsed(['caged' => true]), ['make' => 'Subaru', 'model' => 'BRZ']);
        $this->assertSame('ta_drift', $row['sheet_type']);
        $this->assertTrue($row['caged']);
        $this->assertSame('', $row['class']);
        $this->assertSame(0, $row['car_weight']);
        $this->assertNull($row['engine_hp']);
        $this->assertNull($row['log_book_turned_in']);
        $this->assertSame('Subaru', $row['car_make']);
    }

    public function testDispatchersFollowTheSheet(): void
    {
        $sheet = ['discipline' => 'summer', 'sheet_type' => 'ta_drift', 'club' => 'NASCC', 'caged' => 1];
        $this->assertSame(taDriftChecklistSections(true, 'NASCC'), techSheetChecklistSections($sheet));
        $this->assertSame(taDriftEquipmentItems(true), techSheetEquipmentItems($sheet));
        $this->assertSame('TA/Drift (NASCC)', techSheetClassLine($sheet));
        $this->assertSame(TECH_CHECKLIST_SECTIONS, techSheetChecklistSections(['discipline' => 'summer', 'sheet_type' => 'standard']));
        $this->assertSame('IT1', techSheetClassLine(['discipline' => 'summer', 'sheet_type' => 'standard', 'class' => 'IT1']));
    }

    public function testCarStatusCountsRaceTechAndOnlyThisClub(): void
    {
        $tad = ['id' => 7, 'car_id' => 3, 'season' => 2026, 'discipline' => 'summer', 'sheet_type' => 'ta_drift', 'club' => 'WSCC',
                'status' => 'submitted', 'photo_status' => null, 'accepted_via' => null];
        $race = ['id' => 2, 'car_id' => 3, 'season' => 2026, 'discipline' => 'summer', 'sheet_type' => 'standard', 'club' => null,
                 'status' => 'teched', 'photo_status' => null, 'accepted_via' => 'in_person'];
        $nascc = ['id' => 5, 'club' => 'NASCC', 'status' => 'teched', 'accepted_via' => 'photos'] + $tad;

        $this->assertSame(['state' => 'none', 'via' => null, 'sheet_id' => null, 'tier' => 'ta_drift'], taDriftSheetCarStatus($tad, [$tad, $nascc]));
        $this->assertSame(['state' => 'accepted', 'via' => 'in_person', 'sheet_id' => 2, 'tier' => 'race'], taDriftSheetCarStatus($tad, [$tad, $race]));
        $this->assertSame('accepted', taDriftSheetCarStatus($nascc, [$tad, $nascc])['state']);
    }

    public function testStatusLabel(): void
    {
        $this->assertSame('Teched 2026 (race)', taDriftCarTechStatusLabel(['state' => 'accepted', 'via' => 'in_person', 'tier' => 'race'], 2026, 'WSCC'));
        $this->assertSame('Pre-teched TA/Drift WSCC 2026', taDriftCarTechStatusLabel(['state' => 'accepted', 'via' => 'photos', 'tier' => 'ta_drift'], 2026, 'WSCC'));
        $this->assertSame('Photos with an inspector', taDriftCarTechStatusLabel(['state' => 'pending_review', 'via' => null, 'tier' => 'ta_drift'], 2026, 'WSCC'));
    }
}
