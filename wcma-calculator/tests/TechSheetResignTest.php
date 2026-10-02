<?php
// wcma-calculator/tests/TechSheetResignTest.php — an edited tech sheet must not keep a signature
// made by someone who is no longer the entrant or Driver 1 (bug list 2026-10-02, item 1).
require_once __DIR__ . '/../tech-sheet-data.php';

use PHPUnit\Framework\TestCase;

final class TechSheetResignTest extends TestCase
{
    private function sheet(array $o = []): array {
        return array_merge(['entrant_name' => 'Jordan Lee', 'driver_name' => 'Jordan Lee',
            'entrant_signature_path' => 'uploads/sig/e.png', 'driver_signature_path' => 'uploads/sig/d.png'], $o);
    }

    public function testNoChangeNeedsNoNewSignature(): void
    {
        $this->assertNull(techSheetResignError($this->sheet(), 'Jordan Lee', 'Jordan Lee', []));
        // Spacing and capitals are not a different person.
        $this->assertNull(techSheetResignError($this->sheet(), '  jordan   LEE ', 'JORDAN lee', []));
    }

    public function testANewDriverMustSign(): void
    {
        $error = techSheetResignError($this->sheet(), 'Jordan Lee', 'Sam Patel', []);
        $this->assertSame('Driver 1 has changed, so Sam Patel needs to sign the sheet.', $error);
        $this->assertNull(techSheetResignError($this->sheet(), 'Jordan Lee', 'Sam Patel', ['driver_signature' => 'data:image/png;base64,AAA']));
    }

    public function testANewEntrantMustSign(): void
    {
        $error = techSheetResignError($this->sheet(), 'Lee Racing', 'Jordan Lee', []);
        $this->assertSame('The entrant has changed, so Lee Racing needs to sign the sheet.', $error);
        $this->assertNull(techSheetResignError($this->sheet(), 'Lee Racing', 'Jordan Lee', ['entrant_signature' => 'data:image/png;base64,AAA']));
    }

    public function testBothChangedAsksForBoth(): void
    {
        $error = techSheetResignError($this->sheet(), 'Lee Racing', 'Sam Patel', ['entrant_signature' => 'data:image/png;base64,AAA']);
        $this->assertSame('Driver 1 has changed, so Sam Patel needs to sign the sheet.', $error);
    }

    public function testASheetWithNoSignatureOnFileIsLeftToTheFormRules(): void
    {
        // Nothing stale can be kept, so this rule has nothing to say.
        $sheet = $this->sheet(['driver_signature_path' => null, 'entrant_signature_path' => '']);
        $this->assertNull(techSheetResignError($sheet, 'Lee Racing', 'Sam Patel', []));
    }

    public function testEveryUpdateHandlerChecksBeforeSaving(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../tech-sheets.php'));
        foreach (['handleUpdate', 'handleUpdateIce', 'handleUpdateTaDrift'] as $fn) {
            $start = strpos($src, "function $fn(");
            $end = strpos($src, "\n}\n", $start);
            $body = substr($src, $start, $end - $start);
            $check = strpos($body, 'techSheetResignError(');
            $this->assertNotFalse($check, "$fn checks for a stale signature");
            $this->assertLessThan(strpos($body, 'db_update_tech_sheet($pdo'), $check, "$fn checks before it saves");
        }
    }
}
