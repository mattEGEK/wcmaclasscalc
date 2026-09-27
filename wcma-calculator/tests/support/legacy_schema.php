<?php
// wcma-calculator/tests/support/legacy_schema.php
//
// The four tables exactly as they were before ice racing (2026-09-27), so migration tests can
// start from a real pre-ice database. Do not edit to match the current schema.

function test_make_legacy_pdo(): PDO {
    $path = sys_get_temp_dir() . '/wcma_legacy_' . uniqid() . '.db';
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("CREATE TABLE events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, event_date DATE NOT NULL,
        location TEXT, active INTEGER NOT NULL DEFAULT 1, created_at DATETIME NOT NULL)");
    $pdo->exec("CREATE TABLE tech_sheets (
        id INTEGER PRIMARY KEY AUTOINCREMENT, submission_id INTEGER NOT NULL, car_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL, event_id INTEGER NOT NULL, sheet_type TEXT NOT NULL,
        entrant_name TEXT NOT NULL, driver_name TEXT NOT NULL, driver_id INTEGER, car_make TEXT NOT NULL,
        car_model TEXT NOT NULL, car_colour TEXT NOT NULL, car_number TEXT NOT NULL, class TEXT NOT NULL,
        engine_cc TEXT, engine_hp TEXT, car_weight INTEGER NOT NULL,
        checklist_json TEXT NOT NULL, driver1_equipment_json TEXT NOT NULL, log_book_turned_in INTEGER,
        entrant_signature_path TEXT, entrant_signed_at DATETIME, driver_signature_path TEXT, driver_signed_at DATETIME,
        tech_signature_path TEXT, tech_signed_at DATETIME,
        status TEXT NOT NULL DEFAULT 'submitted', reviewed_by_user_id INTEGER, reviewed_at DATETIME,
        email_sent INTEGER DEFAULT 0, email_send_count INTEGER NOT NULL DEFAULT 0, last_emailed_at DATETIME,
        accepted_via TEXT, photo_status TEXT, car_number_norm TEXT, season INTEGER,
        created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)");
    $pdo->exec("CREATE TABLE gear_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT, driver_id INTEGER NOT NULL, season INTEGER NOT NULL,
        photo_status TEXT, status TEXT NOT NULL DEFAULT 'open', accepted_via TEXT,
        reviewed_by_user_id INTEGER, reviewed_at DATETIME, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        UNIQUE (driver_id, season))");
    $pdo->exec("CREATE TABLE at_track_choices (
        id INTEGER PRIMARY KEY AUTOINCREMENT, subject_type TEXT NOT NULL, subject_id INTEGER NOT NULL,
        season INTEGER NOT NULL, created_at DATETIME NOT NULL, UNIQUE (subject_type, subject_id, season))");
    return $pdo;
}
