<?php
// wcma-calculator/tests/ProfileTest.php
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    private function lib(): void {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../profile.php'));
        foreach (['profileValidateName', 'profileValidatePassword'] as $fn) {
            if (function_exists($fn)) continue;
            $start = strpos($src, "function $fn");
            $this->assertNotFalse($start, $fn);
            $end = strpos($src, "\n}\n", $start);
            eval(substr($src, $start, $end - $start + 2));
        }
    }

    public function testNameValidation(): void
    {
        $this->lib();
        $this->assertSame(['ok' => true, 'error' => null, 'name' => 'Jordan Lee'], profileValidateName('  Jordan   Lee '));
        $this->assertFalse(profileValidateName('   ')['ok']);
        $this->assertFalse(profileValidateName(str_repeat('x', 101))['ok']);
    }

    public function testPasswordValidation(): void
    {
        $this->lib();
        $this->assertNull(profileValidatePassword('longenough', 'longenough'));
        $this->assertNotNull(profileValidatePassword('short', 'short'));
        $this->assertNotNull(profileValidatePassword('longenough', 'different1'));
    }

    public function testPasswordUpdateStoresTheHash(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Jordan Lee', 'password_hash' => password_hash('oldpassword', PASSWORD_BCRYPT), 'google_id' => null]);
        db_set_user_password($pdo, $u, password_hash('newpassword', PASSWORD_BCRYPT));
        $this->assertTrue(password_verify('newpassword', db_find_user_by_id($pdo, $u)['password_hash']));
    }
}
