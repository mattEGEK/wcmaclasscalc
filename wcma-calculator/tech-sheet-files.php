<?php
// wcma-calculator/tech-sheet-files.php
//
// Reading and writing tech sheet signature PNGs (drawn on a canvas, sent as data URLs).
// Shared by the competitor form (tech-sheets.php) and the inspector review (admin).

const TECH_SHEET_SIGNATURE_FIELDS = ['entrant', 'driver', 'tech'];

/** The PNG bytes inside a `data:image/png;base64,...` URL, or null if it is not a valid PNG data URL. */
function techSheetDecodeSignature(string $dataUrl): ?string {
    $prefix = 'data:image/png;base64,';
    if (strpos($dataUrl, $prefix) !== 0) return null;
    $binary = base64_decode(substr($dataUrl, strlen($prefix)));
    if ($binary === false || substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
    return $binary;
}

function techSheetSignatureRelativePath(int $techSheetId, string $field): string {
    return 'uploads/tech-sheets/' . $techSheetId . '/' . $field . '.png';
}

/** Writes PNG bytes for a sheet's signature; returns the relative path, or null on a bad field or write failure. */
function techSheetWriteSignature(string $baseDir, int $techSheetId, string $field, string $binary): ?string {
    if (!in_array($field, TECH_SHEET_SIGNATURE_FIELDS, true)) return null;
    $relative = techSheetSignatureRelativePath($techSheetId, $field);
    $dir = dirname($baseDir . '/' . $relative);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return null;
    if (file_put_contents($baseDir . '/' . $relative, $binary) === false) return null;
    return $relative;
}

/** Decode a data URL and write it. Null if the data URL is not a valid PNG or the field is unknown. */
function techSheetSaveSignature(string $baseDir, int $techSheetId, string $field, string $dataUrl): ?string {
    if (!in_array($field, TECH_SHEET_SIGNATURE_FIELDS, true)) return null;
    $binary = techSheetDecodeSignature($dataUrl);
    if ($binary === null) return null;
    return techSheetWriteSignature($baseDir, $techSheetId, $field, $binary);
}

function techSheetDeleteSignature(string $baseDir, ?string $relativePath): void {
    if ($relativePath === null || $relativePath === '') return;
    $abs = $baseDir . '/' . $relativePath;
    if (is_file($abs)) unlink($abs);
}
