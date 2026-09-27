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
}
