<?php
// wcma-calculator/tests/HomeFormatsSourceTest.php — the Home and Garage handlers post the picker to plan 1's functions.
use PHPUnit\Framework\TestCase;

final class HomeFormatsSourceTest extends TestCase
{
    public function testHandlersPassTheFormatsThrough(): void
    {
        // Task 7 widens this to garage.php
        foreach (['index.php'] as $file) {
            $src = (string)file_get_contents(__DIR__ . '/../' . $file);
            $this->assertStringContainsString("case 'formats':", $src, $file);
            $this->assertStringContainsString('eventsSetFormats(', $src, $file);
            $this->assertStringContainsString('entryFormatsFromPost($_POST)', $src, $file);
            $this->assertStringContainsString("!empty(\$_POST['supps_ack'])", $src, $file);
        }
    }
}
