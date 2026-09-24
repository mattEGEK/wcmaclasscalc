<?php
// wcma-calculator/tests/TechSheetFilesTest.php
require_once __DIR__ . '/../tech-sheet-files.php';

use PHPUnit\Framework\TestCase;

final class TechSheetFilesTest extends TestCase
{
    private string $dir;
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wcma_tsf_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        rmdir($this->dir);
    }

    private function dataUrl(): string { return 'data:image/png;base64,' . self::PNG_B64; }

    public function testDecodeAcceptsPngDataUrlOnly(): void
    {
        $bytes = techSheetDecodeSignature($this->dataUrl());
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($bytes, 0, 8));

        $this->assertNull(techSheetDecodeSignature('data:image/jpeg;base64,' . self::PNG_B64));
        $this->assertNull(techSheetDecodeSignature('not a data url'));
        $this->assertNull(techSheetDecodeSignature('data:image/png;base64,' . base64_encode('plain text, not a png')));
        $this->assertNull(techSheetDecodeSignature(''));
    }

    public function testRelativePath(): void
    {
        $this->assertSame('uploads/tech-sheets/12/tech.png', techSheetSignatureRelativePath(12, 'tech'));
    }

    public function testSaveWritesFileAndReturnsRelativePath(): void
    {
        $rel = techSheetSaveSignature($this->dir, 7, 'entrant', $this->dataUrl());
        $this->assertSame('uploads/tech-sheets/7/entrant.png', $rel);
        $this->assertFileExists($this->dir . '/' . $rel);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr(file_get_contents($this->dir . '/' . $rel), 0, 8));
    }

    public function testSaveRejectsBadInputAndUnknownFields(): void
    {
        $this->assertNull(techSheetSaveSignature($this->dir, 7, 'entrant', 'garbage'));
        $this->assertNull(techSheetSaveSignature($this->dir, 7, '../evil', $this->dataUrl()));
        $this->assertNull(techSheetSaveSignature($this->dir, 7, 'passenger', $this->dataUrl()));
        $this->assertDirectoryDoesNotExist($this->dir . '/uploads/tech-sheets/7');
    }

    public function testWriteThenDelete(): void
    {
        $rel = techSheetWriteSignature($this->dir, 9, 'tech', techSheetDecodeSignature($this->dataUrl()));
        $this->assertFileExists($this->dir . '/' . $rel);

        techSheetDeleteSignature($this->dir, $rel);
        $this->assertFileDoesNotExist($this->dir . '/' . $rel);

        techSheetDeleteSignature($this->dir, $rel);   // already gone: no error
        techSheetDeleteSignature($this->dir, null);
        techSheetDeleteSignature($this->dir, '');
        $this->assertTrue(true);
    }
}
