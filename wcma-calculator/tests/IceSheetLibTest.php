<?php
// wcma-calculator/tests/IceSheetLibTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../ice-sheet-lib.php';

final class IceSheetLibTest extends TestCase
{
    /** A checklist with every item in $sections marked OK. */
    private function allOk(array $sections): array {
        $out = [];
        foreach ($sections as $s) foreach ($s['items'] as $k => $label) $out[$k] = ['status' => 'ok'];
        return $out;
    }

    private function allEquipment(array $items): array {
        $out = [];
        foreach ($items as $k => $def) $out[$k] = ['competitor_confirmed' => true, 'value' => $def['has_rating'] ? 'SA2020' : null];
        return $out;
    }

    private function parsed(string $club, string $class, array $o = []): array {
        $def = iceClass($club, $class);
        return array_merge([
            'class' => $class, 'car_weight' => '2300',
            'checklist' => $def ? $this->allOk(iceChecklistSections($club, $def['group'])) : [],
            'equipment' => $this->allEquipment(iceEquipmentItems($def)),
            'entrant_name' => 'Sam', 'driver_name' => 'Sam', 'car_number' => '7', 'car_colour' => 'Blue',
            'engine_cc' => null, 'engine_hp' => null, 'log_book' => '1',
        ], $o);
    }

    public function testSheetSectionsAndItemsFollowDiscipline(): void
    {
        $this->assertSame(TECH_CHECKLIST_SECTIONS, techSheetChecklistSections(['discipline' => 'summer', 'class' => 'IT1']));
        $this->assertSame(TECH_CHECKLIST_SECTIONS, techSheetChecklistSections(['class' => 'IT1']));
        $this->assertSame(iceChecklistSections('WSCC', 'street_safe'),
            techSheetChecklistSections(['discipline' => 'ice', 'club' => 'WSCC', 'class' => 'FOI-SS']));
        $this->assertSame([], techSheetChecklistSections(['discipline' => 'ice', 'club' => 'WSCC', 'class' => 'LS']));
        $this->assertSame(TECH_DRIVER_EQUIPMENT_ITEMS, techSheetEquipmentItems(['discipline' => 'summer']));
        $this->assertFalse(techSheetEquipmentItems(['discipline' => 'ice', 'club' => 'NASCC', 'class' => 'LS'])['head_neck_restraints']['optional']);
    }

    public function testClassLine(): void
    {
        $this->assertSame('IT1', techSheetClassLine(['class' => 'IT1']));
        $this->assertSame('LS — Limited Stud (NASCC)', techSheetClassLine(['discipline' => 'ice', 'club' => 'NASCC', 'class' => 'LS']));
        $this->assertSame('XX (NASCC)', techSheetClassLine(['discipline' => 'ice', 'club' => 'NASCC', 'class' => 'XX']));
    }

    public function testParsePostTrimsAndDecodes(): void
    {
        $p = iceSheetParsePost(['class' => ' LS ', 'car_weight' => ' 2300 ', 'checklist_json' => '{"a":{"status":"ok"}}',
            'driver1_equipment_json' => '{}', 'entrant_name' => ' Sam ', 'driver_name' => 'Sam', 'car_number' => '7',
            'car_colour' => 'Blue', 'engine_cc' => '', 'engine_hp' => '110', 'log_book_turned_in' => '1']);
        $this->assertSame('LS', $p['class']);
        $this->assertSame('2300', $p['car_weight']);
        $this->assertSame(['a' => ['status' => 'ok']], $p['checklist']);
        $this->assertSame('Sam', $p['entrant_name']);
        $this->assertNull($p['engine_cc']);
        $this->assertSame('110', $p['engine_hp']);
        $this->assertSame([], iceSheetParsePost([])['checklist']);
        $this->assertSame('', iceSheetParsePost(['class' => ['LS']])['class']);
    }

    public function testParsePostDegradesToEmptyArrayForNonArrayJson(): void
    {
        $p = iceSheetParsePost(['checklist_json' => '"hello"', 'driver1_equipment_json' => '5']);
        $this->assertSame([], $p['checklist']);
        $this->assertSame([], $p['equipment']);
        $p2 = iceSheetParsePost(['checklist_json' => 'true']);
        $this->assertSame([], $p2['checklist']);
    }

    public function testValidSheetPasses(): void
    {
        $this->assertNull(iceSheetValidate($this->parsed('NASCC', 'LS'), 'NASCC'));
        $this->assertNull(iceSheetValidate($this->parsed('WSCC', 'DRIFT'), 'WSCC'));
    }

    public function testClassFromAnotherClubIsRejected(): void
    {
        $this->assertSame('Choose a class from the NASCC list.', iceSheetValidate($this->parsed('WSCC', 'FOI-STD'), 'NASCC'));
        $this->assertSame('Choose a class from the NASCC list.', iceSheetValidate($this->parsed('NASCC', 'LS', ['class' => '']), 'NASCC'));
    }

    public function testChecklistIsCheckedAgainstThePostedClassGroup(): void
    {
        $cagedChecklist = $this->allOk(iceChecklistSections('NASCC', 'caged'));
        $p = $this->parsed('NASCC', 'SS', ['checklist' => $cagedChecklist]);
        $this->assertSame('Please mark every checklist item OK or N/A.', iceSheetValidate($p, 'NASCC'));
    }

    public function testFhrClassNeedsHeadAndNeckConfirmed(): void
    {
        $p = $this->parsed('NASCC', 'LS');
        $p['equipment']['head_neck_restraints']['competitor_confirmed'] = false;
        $this->assertSame("Please confirm Driver 1's safety equipment, including the helmet and suit ratings.", iceSheetValidate($p, 'NASCC'));
        $ns = $this->parsed('NASCC', 'NS');
        $ns['equipment']['head_neck_restraints']['competitor_confirmed'] = false;
        $this->assertNull(iceSheetValidate($ns, 'NASCC'));
    }

    public function testWeightMustBeAWholePositiveNumber(): void
    {
        foreach (['', '0', 'abc', '23.5', '99999'] as $w) {
            $this->assertSame("Enter the car's race weight in pounds.", iceSheetValidate($this->parsed('NASCC', 'LS', ['car_weight' => $w]), 'NASCC'), $w);
        }
    }

    public function testRequiredTextAndLogBook(): void
    {
        $this->assertSame('Please complete every required field.', iceSheetValidate($this->parsed('NASCC', 'LS', ['entrant_name' => '']), 'NASCC'));
        $this->assertSame('Please complete every required field.', iceSheetValidate($this->parsed('NASCC', 'LS', ['log_book' => null]), 'NASCC'));
    }

    public function testRowBuildsTheSharedDbColumnsFromAParsedFormAndTheCar(): void
    {
        $p = $this->parsed('NASCC', 'LS', ['engine_hp' => '90']);
        $car = ['make' => 'Chevrolet', 'model' => 'Chevette'];
        $row = iceSheetRow($p, $car);
        $this->assertSame([
            'sheet_type' => 'ice',
            'entrant_name' => 'Sam', 'driver_name' => 'Sam',
            'car_make' => 'Chevrolet', 'car_model' => 'Chevette', 'car_colour' => 'Blue',
            'car_number' => '7', 'class' => 'LS',
            'engine_cc' => null, 'engine_hp' => '90', 'car_weight' => 2300,
            'checklist_json' => json_encode($p['checklist']), 'driver1_equipment_json' => json_encode($p['equipment']),
            'log_book_turned_in' => 1,
        ], $row);
        $this->assertIsInt($row['car_weight']);
        $this->assertIsInt($row['log_book_turned_in']);
    }

    public function testRecipientFallsBackToTheAccountEmail(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'acct@example.com', 'name' => 'Sam', 'password_hash' => 'x', 'google_id' => null]);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '7', [':email' => 'decl@example.com']));
        $this->assertSame('decl@example.com', techSheetRecipientEmail($pdo, ['submission_id' => $sub, 'user_id' => $u]));
        $this->assertSame('acct@example.com', techSheetRecipientEmail($pdo, ['submission_id' => null, 'user_id' => $u]));
        $this->assertNull(techSheetRecipientEmail($pdo, ['submission_id' => null, 'user_id' => 999]));
    }
}
