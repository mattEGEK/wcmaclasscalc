<?php
// wcma-calculator/season-links-lib.php
//
// Admin-maintained links shown to competitors (e.g. this season's MotorsportReg waiver and
// licences). MSR items get new URLs every season, so these are data, not code.

/** @return array{ok: bool, error: ?string, label: string, url: string, sort_order: int} */
function seasonLinkValidate(string $label, string $url, string $sortOrder): array {
    $label = trim((string)preg_replace('/\s+/', ' ', $label));
    $url = trim($url);
    $sort = ctype_digit(ltrim(trim($sortOrder), '-')) ? (int)$sortOrder : 0;
    $out = ['ok' => false, 'error' => null, 'label' => $label, 'url' => $url, 'sort_order' => $sort];

    if ($label === '' || mb_strlen($label, 'UTF-8') > 120) {
        return ['error' => 'Enter a label of 120 characters or fewer.'] + $out;
    }
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
        return ['error' => 'Enter a full web address starting with https://.'] + $out;
    }
    return ['ok' => true] + $out;
}
