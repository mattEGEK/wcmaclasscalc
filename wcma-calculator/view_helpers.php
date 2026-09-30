<?php
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/layout.php';

function generateCsrfToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function setFlash(string $message, string $type): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * The hub's three on-screen date forms (UX review 2026-09-30 §M10). Emails and the printed tech
 * sheet keep the long "October 16, 2026".
 *   hubEventDate(): an event's day, with the weekday and year: "Fri, Oct 16, 2026".
 *   hubDate():      the day something was recorded: "Oct 16, 2026".
 *   hubDateTime():  the same with the time: "Oct 16, 2026, 11:04 PM".
 * Each returns '' for a value that can't be read.
 */
function hubEventDate(?string $value): string {
    $ts = $value === null || trim($value) === '' ? false : strtotime(substr($value, 0, 10));
    return $ts === false ? '' : date('D, M j, Y', $ts);
}

function hubDate(?string $value): string {
    $ts = $value === null || trim($value) === '' ? false : strtotime($value);
    return $ts === false ? '' : date('M j, Y', $ts);
}

function hubDateTime(?string $value): string {
    $ts = $value === null || trim($value) === '' ? false : strtotime($value);
    return $ts === false ? '' : date('M j, Y, g:i A', $ts);
}

/** The first season link whose label contains $needle (case-insensitive), or null. Links are admin data, not code. */
function seasonLinkMatching(array $links, string $needle): ?array {
    foreach ($links as $link) {
        if (stripos((string)$link['label'], $needle) !== false) return $link;
    }
    return null;
}

/** Status word class for a hub-status chip (Home, Garage, Drivers, inspect, tech sheet pages). */
function homeStatusClass(string $state): string {
    switch ($state) {
        case 'accepted':       return 'hub-status--ok';
        case 'needs_changes':  return 'hub-status--todo';
        case 'pending_review':
        case 'submitted':      return 'hub-status--info';
        default:                return 'hub-status--warn';
    }
}
