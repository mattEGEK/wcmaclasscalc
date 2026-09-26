<?php
// wcma-calculator/tests/ProfileTest.php
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    private function lib(): void {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../profile.php'));
        foreach (['profileValidateName', 'profileValidatePassword', 'profileCanChangePassword'] as $fn) {
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

    public function testNameValidationCountsMultibyteCharactersNotBytes(): void
    {
        $this->lib();
        // 100 two-byte characters: 200 bytes but 100 chars, so this must be valid.
        $name = str_repeat('é', 100);
        $this->assertTrue(profileValidateName($name)['ok']);
        $this->assertSame(100, mb_strlen(profileValidateName($name)['name'], 'UTF-8'));
        // 101 multibyte characters must fail.
        $this->assertFalse(profileValidateName(str_repeat('é', 101))['ok']);
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

    public function testCanChangePasswordWithEmailAccount(): void
    {
        $this->lib();
        // Email account with password_hash: can change
        $this->assertTrue(profileCanChangePassword(['password_hash' => 'somehash', 'google_id' => null]));
        // Email account without password_hash: can change (set password for first time)
        $this->assertTrue(profileCanChangePassword(['password_hash' => null, 'google_id' => null]));
        $this->assertTrue(profileCanChangePassword(['password_hash' => '', 'google_id' => null]));
    }

    public function testCannotChangePasswordWithGoogleOnlyAccount(): void
    {
        $this->lib();
        // Google-only account (google_id set, no password_hash): cannot change
        $this->assertFalse(profileCanChangePassword(['password_hash' => null, 'google_id' => 'g-12345']));
        $this->assertFalse(profileCanChangePassword(['password_hash' => '', 'google_id' => 'g-12345']));
    }

    public function testCanChangePasswordWithGoogleAndPassword(): void
    {
        $this->lib();
        // Account with both google_id and password_hash: can change (linked account)
        $this->assertTrue(profileCanChangePassword(['password_hash' => 'somehash', 'google_id' => 'g-12345']));
    }

    private function source(): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../profile.php'));
    }

    public function testUnknownPostActionRedirectsInsteadOfRendering(): void
    {
        $src = $this->source();
        $postBlockStart = strpos($src, "REQUEST_METHOD'] === 'POST'");
        $this->assertNotFalse($postBlockStart);
        $renderStart = strpos($src, 'renderPageStart(');
        $this->assertNotFalse($renderStart);
        $postBlock = substr($src, $postBlockStart, $renderStart - $postBlockStart);
        // There must be five redirects in the POST handling: name, the Google-only guard, the
        // password outcome, the reminders outcome, and a fallback for any other/unknown action —
        // so an unrecognised action never falls through to the render below.
        $this->assertSame(5, substr_count($postBlock, "header('Location: profile.php');"));
        $this->assertSame(5, substr_count($postBlock, 'exit;'));
    }

    public function testSessionIsRegeneratedAfterAPasswordChange(): void
    {
        $src = $this->source();
        $passwordActionStart = strpos($src, "if (\$action === 'password')");
        $nextActionOrEnd = strpos($src, "\n}\n", $passwordActionStart);
        $passwordBlock = substr($src, $passwordActionStart, $nextActionOrEnd - $passwordActionStart);
        $this->assertStringContainsString('session_regenerate_id(true)', $passwordBlock);
        // Regeneration must happen after the password is actually stored, and before redirect.
        $this->assertGreaterThan(strpos($passwordBlock, 'db_set_user_password('), strpos($passwordBlock, 'session_regenerate_id(true)'));
    }
}
