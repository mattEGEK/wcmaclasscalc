<?php
// wcma-calculator/tests/MediaSourceTest.php
//
// Source-level guards for the media controllers (they need config.php/sessions, so they cannot run here).
use PHPUnit\Framework\TestCase;

final class MediaSourceTest extends TestCase
{
    private function src(string $file): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . $file));
    }

    public function testSessionCarriesTheMediaFlag(): void
    {
        $src = $this->src('session_bootstrap.php');
        $this->assertStringContainsString("'is_media' => (int)(\$_SESSION['user_is_media'] ?? 0)", $src);
        $this->assertStringContainsString("\$_SESSION['user_is_media'] = (int)(\$user['is_media'] ?? 0);", $src);
    }

    public function testAdminCanSetTheMediaFlagByCsrfCheckedPost(): void
    {
        $src = $this->src('admin.php');
        $this->assertMatchesRegularExpression("/case 'set-media':\s*adminRequirePost\('admin.php\?action=users'\);\s*handleSetMedia\(\\\$pdo, \\\$postId, \(\\\$_POST\['is_media'\] \?\? ''\) === '1'\);/", $src);
        $this->assertStringContainsString('function handleSetMedia(PDO $pdo, int $id, bool $on): void', $src);
        $this->assertStringContainsString('action="admin.php?action=set-media"', $src);
        $this->assertStringContainsString('name="is_media" value="1"', $src);
    }

    public function testMediaProfileControllerGuardsOwnershipAndCsrf(): void
    {
        $src = $this->src('media-profile.php');
        $this->assertStringContainsString("require_role('user')", $src);
        $this->assertMatchesRegularExpression("/REQUEST_METHOD'\] === 'POST'\) \{\s*if \(!validateCsrfToken\(/", $src);
        $this->assertStringContainsString('mediaOwnedDriver($pdo, $uid, $driverId)', $src);
        $this->assertStringContainsString('http_response_code(404)', $src);
        $this->assertStringContainsString('UPLOAD_ERR_NO_FILE', $src);
        $this->assertStringContainsString('js/photo-resize.js', $src);
    }
}
