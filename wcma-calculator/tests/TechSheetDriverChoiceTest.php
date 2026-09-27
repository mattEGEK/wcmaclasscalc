<?php
// wcma-calculator/tests/TechSheetDriverChoiceTest.php
require_once __DIR__ . '/../tech-sheet-data.php';

use PHPUnit\Framework\TestCase;

final class TechSheetDriverChoiceTest extends TestCase
{
    private function owned(): array {
        return [
            5 => ['id' => 5, 'name' => 'Jordan Lee', 'name_norm' => 'jordan lee'],
            6 => ['id' => 6, 'name' => 'Sam Patel', 'name_norm' => 'sam patel'],
        ];
    }

    public function testAChoiceIsAnOwnedProfileOrANewName(): void
    {
        $this->assertSame('Sam Patel', techSheetDriverName($this->owned(), '6', ''));
        $this->assertSame('Alex Kim', techSheetDriverName($this->owned(), 'new', '  Alex   Kim '));
        $this->assertNull(techSheetDriverName($this->owned(), '99', ''));          // someone else's driver
        $this->assertNull(techSheetDriverName($this->owned(), 'new', '  '));
        $this->assertNull(techSheetDriverName($this->owned(), 'new', str_repeat('a', 101)));
        $this->assertNull(techSheetDriverName($this->owned(), '', ''));
    }

    public function testChoicesBecomeNamesForAnEnduranceSheet(): void
    {
        $post = ['sheet_type' => 'endurance', 'driver1_choice' => '5', 'driver1_new_name' => '',
                 'drivers_json' => json_encode([['driver_number' => 2, 'driver_choice' => 'new', 'new_name' => 'Alex Kim', 'equipment' => ['helmet' => 'ok']],
                                                ['driver_number' => 3, 'driver_choice' => '6', 'new_name' => 'ignored', 'equipment' => []]])];
        $r = techSheetApplyDriverChoices($post, $this->owned());
        $this->assertTrue($r['ok']);
        $this->assertSame('Jordan Lee', $r['post']['driver_name']);
        $this->assertSame([
            ['driver_number' => 2, 'driver_name' => 'Alex Kim', 'equipment' => ['helmet' => 'ok']],
            ['driver_number' => 3, 'driver_name' => 'Sam Patel', 'equipment' => []],
        ], json_decode($r['post']['drivers_json'], true));
    }

    public function testAStandardSheetDropsAdditionalDrivers(): void
    {
        $post = ['sheet_type' => 'standard', 'driver1_choice' => 'new', 'driver1_new_name' => 'Alex Kim', 'drivers_json' => '[{"driver_choice":"99"}]'];
        $r = techSheetApplyDriverChoices($post, $this->owned());
        $this->assertTrue($r['ok']);
        $this->assertSame('Alex Kim', $r['post']['driver_name']);
        $this->assertSame('[]', $r['post']['drivers_json']);
    }

    public function testBadChoicesAndDuplicatesAreRefusedWithAMessage(): void
    {
        $this->assertSame('Choose Driver 1 from your drivers, or add a co-driver with their name.',
            techSheetApplyDriverChoices(['driver1_choice' => '99'], $this->owned())['error']);
        $dup = ['sheet_type' => 'endurance', 'driver1_choice' => '6', 'drivers_json' => json_encode([['driver_number' => 2, 'driver_choice' => 'new', 'new_name' => 'sam  PATEL', 'equipment' => []]])];
        $this->assertSame('sam PATEL is on this sheet twice.', techSheetApplyDriverChoices($dup, $this->owned())['error']);
        $bad = ['sheet_type' => 'endurance', 'driver1_choice' => '5', 'drivers_json' => json_encode([['driver_number' => 2, 'driver_choice' => '99']])];
        $this->assertSame('Choose a driver for every added driver.', techSheetApplyDriverChoices($bad, $this->owned())['error']);
        $this->assertFalse(techSheetApplyDriverChoices(['sheet_type' => 'endurance', 'driver1_choice' => '5', 'drivers_json' => 'nope'], $this->owned())['ok']);
    }

    public function testEditingASheetWithAnUnknownNameKeepsItAsANewDriver(): void
    {
        $this->assertSame('6', techSheetDriverChoiceFor($this->owned(), ' Sam  PATEL '));
        $this->assertSame('new', techSheetDriverChoiceFor($this->owned(), 'Pat Old-Name'));
    }

    public function testDriver1FormStatePicksYouForANewSheetAndTheSheetsDriverForAnEdit(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'd@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $self = (int)db_get_self_driver($pdo, $u)['id'];
        $sam = db_create_driver($pdo, $u, 'Sam Patel');
        $drivers = db_get_user_drivers($pdo, $u);

        $new = techSheetDriver1FormState($drivers, null);
        $this->assertSame($self, $new['selfId']);
        $this->assertSame((string)$self, $new['choice']);
        $this->assertSame('', $new['newName']);
        $this->assertCount(count($drivers), $new['driversForJs']);

        $this->assertSame((string)$sam, techSheetDriver1FormState($drivers, ['driver_name' => 'Sam Patel'])['choice']);
        $gone = techSheetDriver1FormState($drivers, ['driver_name' => 'Alex Rivera']);
        $this->assertSame('new', $gone['choice']);
        $this->assertSame('Alex Rivera', $gone['newName']);
    }
}
