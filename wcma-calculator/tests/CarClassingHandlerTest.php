<?php
// wcma-calculator/tests/CarClassingHandlerTest.php
//
// Source-level guard: car-classing.php needs config.php and PHPMailer so it cannot run under
// PHPUnit. This checks that identity fields come from the account record, not the POSTed form.
use PHPUnit\Framework\TestCase;

final class CarClassingHandlerTest extends TestCase
{
    private function source(): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../car-classing.php'));
    }

    public function testNameAndEmailComeFromTheAccountRecordNotThePost(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('db_find_user_by_id(', $src);
        $nameLine = strstr(strstr($src, '$name ='), "\n", true);
        $emailLine = strstr(strstr($src, '$email ='), "\n", true);
        $this->assertStringNotContainsString("_POST['name']", $nameLine);
        $this->assertStringNotContainsString("_POST['email']", $emailLine);
    }

    public function testFormDataIsStoredOnlyWhenAValidJsonObjectWithinSizeLimit(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('JSON_ERROR_NONE', $src);
        $this->assertStringContainsString('instanceof stdClass', $src);
        $this->assertStringContainsString('16384', $src);
    }
}
