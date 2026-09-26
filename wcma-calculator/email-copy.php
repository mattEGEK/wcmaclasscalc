<?php
// wcma-calculator/email-copy.php
//
// Binding competitor-facing copy from the hub spec (docs/superpowers/specs/2026-09-24-wcma-hub-design.md,
// "Competitor emails"). Change wording here only, never inline.

const COPY_DECLARATION_RECEIVED = 'Class declaration received. An inspector will review and respond.';
const COPY_TECH_SHEET_RECEIVED = 'Tech sheet received. An inspector will review and respond.';
const COPY_DECLARATION_ACCEPTED = 'The scrutineer has reviewed & accepted your class declaration.';
const COPY_TECH_SHEET_ACCEPTED = 'The scrutineer has reviewed & accepted your tech sheet.';
const COPY_GEAR_ACCEPTED = 'The scrutineer has reviewed & accepted your gear.';
const COPY_REMINDER_OPT_IN = 'Email me reminders for events I\'m going to.';

/** "Reviewed by: First Last" for review emails, or '' when the reviewer is unknown. */
function reviewedByLine(?array $reviewer): string {
    $name = trim((string)preg_replace('/\s+/', ' ', (string)($reviewer['name'] ?? '')));
    return $name === '' ? '' : 'Reviewed by: ' . $name;
}

/** The tech sheet confirmation email body: the "received" headline, then the rendered sheet. */
function techSheetReceivedEmailHtml(string $sheetHtml): string {
    return '<html><body>'
        . '<p style="font-family:Arial,sans-serif;font-size:1.1rem;font-weight:bold">' . htmlspecialchars(COPY_TECH_SHEET_RECEIVED, ENT_QUOTES, 'UTF-8') . '</p>'
        . $sheetHtml . '</body></html>';
}
