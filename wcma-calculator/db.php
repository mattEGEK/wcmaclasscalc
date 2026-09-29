<?php
/**
 * Database layer — SQLite via PDO.
 * Define DB_PATH before requiring this file to override (e.g. in tests).
 */
require_once __DIR__ . '/tech-status.php';

if (!defined('DB_PATH')) {
    define('DB_PATH', __DIR__ . '/data/submissions.db');
}

if (!defined('BOOTSTRAP_ADMIN_EMAIL')) {
    define('BOOTSTRAP_ADMIN_EMAIL', 'matt.sinfield@gmail.com');
}

function db_connect(): PDO {
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    return $pdo;
}

/** tech_sheets schema. {table} is filled in by db_init() and db_rebuild_table(). */
const DB_TECH_SHEETS_SQL = "
    CREATE TABLE IF NOT EXISTS {table} (
        id                      INTEGER PRIMARY KEY AUTOINCREMENT,
        submission_id           INTEGER,
        car_id                  INTEGER NOT NULL,
        user_id                 INTEGER NOT NULL,
        event_id                INTEGER NOT NULL,
        sheet_type              TEXT NOT NULL,

        entrant_name            TEXT NOT NULL,
        driver_name             TEXT NOT NULL,
        driver_id               INTEGER,
        car_make                TEXT NOT NULL,
        car_model               TEXT NOT NULL,
        car_colour              TEXT NOT NULL,
        car_number              TEXT NOT NULL,
        class                   TEXT NOT NULL,
        engine_cc               TEXT,
        engine_hp               TEXT,
        car_weight              INTEGER NOT NULL,

        checklist_json          TEXT NOT NULL,
        driver1_equipment_json  TEXT NOT NULL,
        log_book_turned_in      INTEGER,

        entrant_signature_path  TEXT,
        entrant_signed_at       DATETIME,
        driver_signature_path   TEXT,
        driver_signed_at        DATETIME,
        tech_signature_path     TEXT,
        tech_signed_at          DATETIME,

        status                  TEXT NOT NULL DEFAULT 'submitted',
        reviewed_by_user_id     INTEGER,
        reviewed_at             DATETIME,

        email_sent              INTEGER DEFAULT 0,
        email_send_count        INTEGER NOT NULL DEFAULT 0,
        last_emailed_at         DATETIME,

        accepted_via            TEXT,
        photo_status            TEXT,
        car_number_norm         TEXT,
        season                  INTEGER,
        discipline              TEXT NOT NULL DEFAULT 'summer',
        club                    TEXT,

        created_at              DATETIME NOT NULL,
        updated_at              DATETIME NOT NULL
    )";

/** gear_records schema. {table} is filled in by db_init() and db_rebuild_table(). */
const DB_GEAR_RECORDS_SQL = "
    CREATE TABLE IF NOT EXISTS {table} (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        driver_id           INTEGER NOT NULL,
        season              INTEGER NOT NULL,
        discipline          TEXT NOT NULL DEFAULT 'summer',
        level               TEXT,
        photo_status        TEXT,
        status              TEXT NOT NULL DEFAULT 'open',
        accepted_via        TEXT,
        reviewed_by_user_id INTEGER,
        reviewed_at         DATETIME,
        created_at          DATETIME NOT NULL,
        updated_at          DATETIME NOT NULL,
        UNIQUE (driver_id, discipline, season)
    )";

/** at_track_choices schema. club is '' (not NULL) so UNIQUE still de-duplicates summer and gear rows. */
const DB_AT_TRACK_SQL = "
    CREATE TABLE IF NOT EXISTS {table} (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        subject_type TEXT NOT NULL,
        subject_id   INTEGER NOT NULL,
        season       INTEGER NOT NULL,
        discipline   TEXT NOT NULL DEFAULT 'summer',
        club         TEXT NOT NULL DEFAULT '',
        created_at   DATETIME NOT NULL,
        UNIQUE (subject_type, subject_id, discipline, club, season)
    )";

function db_init(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS submissions (
            id                      INTEGER PRIMARY KEY AUTOINCREMENT,
            submitted_at            DATETIME NOT NULL,
            name                    TEXT NOT NULL,
            email                   TEXT NOT NULL,
            year                    TEXT NOT NULL,
            make                    TEXT NOT NULL,
            model                   TEXT NOT NULL,
            comments                TEXT,
            competition_weight      INTEGER NOT NULL,
            declared_hp             INTEGER NOT NULL,
            dyno_hp                 INTEGER,
            chassis_display         TEXT,
            body_mods_display       TEXT,
            transmission_display    TEXT,
            drivetrain_display      TEXT,
            tires_display           TEXT,
            brake_suspension        TEXT,
            chassis_value           REAL DEFAULT 0,
            body_mods_value         REAL DEFAULT 0,
            transmission_value      REAL DEFAULT 0,
            drivetrain_value        REAL DEFAULT 0,
            tires_value             REAL DEFAULT 0,
            brake_suspension_value  REAL DEFAULT 0,
            weight_factor           REAL DEFAULT 0,
            modification_factor     REAL DEFAULT 0,
            base_ratio              REAL DEFAULT 0,
            modified_ratio          REAL DEFAULT 0,
            calculated_class        TEXT,
            dyno_chart_path         TEXT,
            dyno_table_path         TEXT,
            car_image_path          TEXT,
            email_sent              INTEGER DEFAULT 0,
            last_emailed_at         DATETIME,
            email_send_count        INTEGER NOT NULL DEFAULT 0,
            user_id                 INTEGER NOT NULL,
            car_id                  INTEGER NOT NULL,
            review_status           TEXT NOT NULL DEFAULT 'submitted',
            reviewer_note           TEXT,
            reviewed_by_user_id     INTEGER,
            reviewed_at             DATETIME,
            accepted_at             DATETIME,
            form_data               TEXT
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_submissions_car ON submissions (car_id)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            ip              TEXT PRIMARY KEY,
            attempts        INTEGER NOT NULL DEFAULT 0,
            last_attempt_at DATETIME NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            email         TEXT NOT NULL UNIQUE,
            password_hash TEXT,
            google_id     TEXT UNIQUE,
            name          TEXT NOT NULL,
            role          TEXT NOT NULL DEFAULT 'user', -- 'user' | 'inspector' | 'admin'
            created_at    DATETIME NOT NULL,
            active        INTEGER NOT NULL DEFAULT 1,
            reminder_emails INTEGER NOT NULL DEFAULT 0,
            reminder_prompted_at DATETIME
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            token_hash TEXT PRIMARY KEY,
            user_id    INTEGER NOT NULL,
            expires_at DATETIME NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS drafts (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL,
            label       TEXT,
            form_data   TEXT NOT NULL,
            updated_at  DATETIME NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS events (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT NOT NULL,
            event_date  DATE NOT NULL,
            location    TEXT,
            active      INTEGER NOT NULL DEFAULT 1,
            created_at  DATETIME NOT NULL
        )
    ");

    $pdo->exec(str_replace('{table}', 'tech_sheets', DB_TECH_SHEETS_SQL));
    // Pre-ice databases: submission_id was NOT NULL and there was no discipline/club (2026-09-27 spec).
    db_rebuild_table($pdo, 'tech_sheets', 'discipline', DB_TECH_SHEETS_SQL);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            setting_key   TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feedback (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            type                TEXT NOT NULL,
            message             TEXT NOT NULL,
            name                TEXT,
            email               TEXT,
            user_id             INTEGER,
            page_url            TEXT,
            user_agent          TEXT,
            viewport            TEXT,
            calc_inputs         TEXT,
            ip_hash             TEXT,
            status              TEXT NOT NULL DEFAULT 'new',
            github_issue_number INTEGER,
            github_issue_url    TEXT,
            github_error        TEXT,
            created_at          DATETIME NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tech_sheet_drivers (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            tech_sheet_id    INTEGER NOT NULL,
            driver_number    INTEGER NOT NULL,
            driver_name      TEXT NOT NULL,
            driver_id        INTEGER,
            equipment_json   TEXT NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inspection_photos (
            id                   INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_type         TEXT NOT NULL,
            subject_id           INTEGER NOT NULL,
            requirement_key      TEXT NOT NULL,
            requirement_version  INTEGER NOT NULL,
            file_path            TEXT NOT NULL DEFAULT '',
            typed_value          TEXT,
            review_status        TEXT NOT NULL DEFAULT 'pending',
            reviewer_note        TEXT,
            applies              INTEGER NOT NULL DEFAULT 1,
            created_at           DATETIME NOT NULL,
            updated_at           DATETIME NOT NULL,
            UNIQUE (subject_type, subject_id, requirement_key)
        )
    ");

    $pdo->exec(str_replace('{table}', 'gear_records', DB_GEAR_RECORDS_SQL));
    // Pre-ice databases: UNIQUE (driver_id, season), no discipline/level (2026-09-27 spec).
    db_rebuild_table($pdo, 'gear_records', 'discipline', DB_GEAR_RECORDS_SQL);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cars (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_user_id   INTEGER NOT NULL,
            car_number      TEXT NOT NULL,
            car_number_norm TEXT NOT NULL,
            year            TEXT,
            make            TEXT NOT NULL,
            model           TEXT NOT NULL,
            colour          TEXT,
            engine_cc       TEXT,
            disciplines     TEXT,
            archived_at     DATETIME,
            created_at      DATETIME NOT NULL,
            updated_at      DATETIME NOT NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_owner ON cars (owner_user_id)");
    // Mobile UX spec 2026-09-28 §A1: the season a car races (ice/summer/both); null for older cars.
    db_add_column_if_missing($pdo, 'cars', 'disciplines', 'TEXT');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS drivers (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_user_id INTEGER NOT NULL,
            user_id       INTEGER UNIQUE,
            name          TEXT NOT NULL,
            name_norm     TEXT NOT NULL,
            licence_no    TEXT,
            created_at    DATETIME NOT NULL,
            updated_at    DATETIME NOT NULL,
            UNIQUE (owner_user_id, name_norm)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS event_plans (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            event_id   INTEGER NOT NULL,
            car_id     INTEGER NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE (event_id, car_id)
        )
    ");

    $pdo->exec(str_replace('{table}', 'at_track_choices', DB_AT_TRACK_SQL));
    db_rebuild_table($pdo, 'at_track_choices', 'discipline', DB_AT_TRACK_SQL);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS season_links (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            label      TEXT NOT NULL,
            url        TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            active     INTEGER NOT NULL DEFAULT 1
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reminder_log (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id   INTEGER NOT NULL,
            event_id  INTEGER NOT NULL,
            days_out  INTEGER NOT NULL,
            sent_at   DATETIME NOT NULL,
            UNIQUE (user_id, event_id, days_out)
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tech_sheets_car ON tech_sheets (car_id, season)");

    // ── Ice racing (2026-09-27 spec). Added in place: no reset. ──
    db_add_column_if_missing($pdo, 'events', 'discipline', "TEXT NOT NULL DEFAULT 'summer'");
    db_add_column_if_missing($pdo, 'events', 'host_club', 'TEXT');
    // The event's own MotorsportReg page (event MSR links, 2026-09-29); '' = none, use the club's page.
    db_add_column_if_missing($pdo, 'events', 'msr_url', "TEXT NOT NULL DEFAULT ''");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS clubs (
            code       TEXT PRIMARY KEY,
            name       TEXT NOT NULL,
            msr_url    TEXT NOT NULL DEFAULT '',
            active     INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL
        )
    ");
    // Clubs spec 2026-09-29 §1: the ice clubs exist from the start; admin edits are never overwritten.
    $seed = $pdo->prepare("INSERT OR IGNORE INTO clubs (code, name, msr_url, active, created_at) VALUES (:c, :n, '', 1, :t)");
    foreach (DB_ICE_CLUB_SEED as $code => $name) $seed->execute([':c' => $code, ':n' => $name, ':t' => date('Y-m-d H:i:s')]);

    // ── MotorsportReg calendar import (2026-09-29 spec). Added in place: no reset. ──
    db_add_column_if_missing($pdo, 'clubs', 'msr_org_id', "TEXT NOT NULL DEFAULT ''");
    if (db_get_setting($pdo, 'msr_org_ids_seeded') === null) {
        // Once only, so an admin who disconnects a club isn't reconnected on the next page load.
        $seedOrg = $pdo->prepare("UPDATE clubs SET msr_org_id = :id WHERE code = :c AND msr_org_id = ''");
        foreach (DB_MSR_ORG_SEED as $code => $orgId) $seedOrg->execute([':id' => $orgId, ':c' => $code]);
        db_set_setting($pdo, 'msr_org_ids_seeded', '1');
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS msr_events (
            msr_id         TEXT PRIMARY KEY,
            club_code      TEXT NOT NULL,
            name           TEXT NOT NULL,
            start_date     DATE NOT NULL,
            end_date       DATE NOT NULL,
            type           TEXT NOT NULL,
            venue          TEXT NOT NULL DEFAULT '',
            detail_url     TEXT NOT NULL DEFAULT '',
            cancelled      INTEGER NOT NULL DEFAULT 0,
            status         TEXT NOT NULL DEFAULT 'new',
            hub_event_id   INTEGER,
            is_primary     INTEGER NOT NULL DEFAULT 0,
            snap_name      TEXT,
            snap_start     DATE,
            snap_venue     TEXT,
            snap_cancelled INTEGER,
            first_seen_at  DATETIME NOT NULL,
            last_seen_at   DATETIME NOT NULL
        )
    ");

    // ── Driver media profiles (2026-09-27 spec). Added in place: no reset. ──
    db_add_column_if_missing($pdo, 'users', 'is_media', 'INTEGER NOT NULL DEFAULT 0');
    db_add_column_if_missing($pdo, 'users', 'media_prompt_dismissed', 'INTEGER NOT NULL DEFAULT 0');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS driver_media_profiles (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            driver_id          INTEGER NOT NULL UNIQUE,
            blurb              TEXT NOT NULL DEFAULT '',
            pronunciation      TEXT,
            hometown           TEXT,
            racing_since       INTEGER,
            social_handle      TEXT,
            photo_path         TEXT,
            public_status      TEXT NOT NULL DEFAULT 'none',
            public_reviewed_by INTEGER,
            public_reviewed_at DATETIME,
            public_note        TEXT,
            hidden_at          DATETIME,
            hidden_by          INTEGER,
            hidden_reason      TEXT,
            created_at         DATETIME NOT NULL,
            updated_at         DATETIME NOT NULL
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS driver_sponsors (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            driver_id  INTEGER NOT NULL,
            name       TEXT NOT NULL,
            url        TEXT,
            sort_order INTEGER NOT NULL DEFAULT 0
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_driver_sponsors_driver ON driver_sponsors (driver_id, sort_order)");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS media_consents (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            driver_id        INTEGER NOT NULL,
            consent_media    INTEGER NOT NULL,
            consent_public   INTEGER NOT NULL,
            is_minor         INTEGER NOT NULL DEFAULT 0,
            guardian_name    TEXT,
            given_by_user_id INTEGER NOT NULL,
            on_behalf        INTEGER NOT NULL DEFAULT 0,
            wording_version  INTEGER NOT NULL,
            created_at       DATETIME NOT NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_media_consents_driver ON media_consents (driver_id, id)");

    // ── TA/Drift (2026-09-29 spec §2). Added in place: no reset. Existing entries read as race. ──
    db_add_column_if_missing($pdo, 'event_plans', 'formats', "TEXT NOT NULL DEFAULT 'race'");
    db_add_column_if_missing($pdo, 'event_plans', 'supps_ack_at', 'DATETIME');
    db_add_column_if_missing($pdo, 'tech_sheets', 'caged', 'INTEGER NOT NULL DEFAULT 0');
    db_add_column_if_missing($pdo, 'tech_sheets', 'revoke_note', 'TEXT');
    db_add_column_if_missing($pdo, 'gear_records', 'revoke_note', 'TEXT');
    // TA/Drift gear photos (phase 2): which photo list a summer gear record is on (NULL = race, 'ta_drift'), and
    // whether its driver's car is caged (adds the head and neck restraint photo).
    db_add_column_if_missing($pdo, 'gear_records', 'photo_tier', 'TEXT');
    db_add_column_if_missing($pdo, 'gear_records', 'caged', 'INTEGER NOT NULL DEFAULT 0');
}

function db_insert_submission(PDO $pdo, array $data): int {
    if (empty($data[':user_id']) || empty($data[':car_id'])) {
        throw new InvalidArgumentException('A class declaration needs :user_id and :car_id.');
    }
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $data[':form_data'] ??= null;
        $pdo->prepare("
            INSERT INTO submissions (
                submitted_at, name, email, year, make, model, comments,
                competition_weight, declared_hp, dyno_hp,
                chassis_display, body_mods_display, transmission_display,
                drivetrain_display, tires_display, brake_suspension,
                chassis_value, body_mods_value, transmission_value,
                drivetrain_value, tires_value, brake_suspension_value,
                weight_factor, modification_factor, base_ratio, modified_ratio,
                calculated_class, email_sent, user_id, car_id, review_status, form_data
            ) VALUES (
                :submitted_at, :name, :email, :year, :make, :model, :comments,
                :competition_weight, :declared_hp, :dyno_hp,
                :chassis_display, :body_mods_display, :transmission_display,
                :drivetrain_display, :tires_display, :brake_suspension,
                :chassis_value, :body_mods_value, :transmission_value,
                :drivetrain_value, :tires_value, :brake_suspension_value,
                :weight_factor, :modification_factor, :base_ratio, :modified_ratio,
                :calculated_class, 0, :user_id, :car_id, 'submitted', :form_data
            )
        ")->execute($data);
        $id = (int)$pdo->lastInsertId();
        // Only one current declaration per car: the new one replaces the rest.
        $pdo->prepare("UPDATE submissions SET review_status = 'superseded' WHERE car_id = :c AND id != :id AND review_status != 'superseded'")
            ->execute([':c' => $data[':car_id'], ':id' => $id]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $id;
}

function db_update_submission_files(PDO $pdo, int $id, ?string $dyno_chart, ?string $dyno_table, ?string $car_image): void {
    $pdo->prepare("
        UPDATE submissions
        SET dyno_chart_path = :dyno_chart, dyno_table_path = :dyno_table, car_image_path = :car_image
        WHERE id = :id
    ")->execute([':dyno_chart' => $dyno_chart, ':dyno_table' => $dyno_table, ':car_image' => $car_image, ':id' => $id]);
}

function db_update_email_sent(PDO $pdo, int $id, int $sent): void {
    if ($sent === 1) {
        $pdo->prepare("
            UPDATE submissions
            SET email_sent = 1, last_emailed_at = :now, email_send_count = email_send_count + 1
            WHERE id = :id
        ")->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    } else {
        $pdo->prepare("UPDATE submissions SET email_sent = 0 WHERE id = :id")
            ->execute([':id' => $id]);
    }
}

function db_get_submissions(PDO $pdo, string $sort = 'submitted_at', string $dir = 'desc', ?int $limit = null, int $offset = 0): array {
    $allowed_sorts = ['submitted_at', 'name', 'year', 'make', 'model',
                      'competition_weight', 'declared_hp', 'calculated_class', 'email_sent'];
    $allowed_dirs  = ['asc', 'desc'];
    $sort = in_array($sort, $allowed_sorts, true) ? $sort : 'submitted_at';
    $dir  = in_array($dir,  $allowed_dirs,  true) ? $dir  : 'desc';
    $sql = "SELECT * FROM submissions ORDER BY {$sort} {$dir}";
    if ($limit !== null) {
        $sql .= " LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    return $pdo->query($sql)->fetchAll();
}

function db_count_submissions(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
}

function db_get_submission(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function db_get_user_submissions(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE user_id = :user_id ORDER BY submitted_at DESC");
    $stmt->execute([':user_id' => $user_id]);
    return $stmt->fetchAll();
}

function db_count_user_submissions(PDO $pdo, int $user_id): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $user_id]);
    return (int)$stmt->fetchColumn();
}

function db_get_user_submission(PDO $pdo, int $user_id, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE id = :id AND user_id = :user_id");
    $stmt->execute([':id' => $id, ':user_id' => $user_id]);
    return $stmt->fetch() ?: null;
}

function db_get_car_current_declaration(PDO $pdo, int $carId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE car_id = :c AND review_status != 'superseded' ORDER BY submitted_at DESC, id DESC LIMIT 1");
    $stmt->execute([':c' => $carId]);
    return $stmt->fetch() ?: null;
}

/** The calculator inputs of a car's current declaration, for pre-filling a re-declaration. */
function db_get_car_declaration_form(PDO $pdo, int $userId, int $carId): ?array {
    if (db_get_user_car($pdo, $userId, $carId) === null) return null;
    $decl = db_get_car_current_declaration($pdo, $carId);
    if ($decl === null || $decl['form_data'] === null) return null;
    $data = json_decode((string)$decl['form_data'], true);
    return is_array($data) ? $data : null;
}

function db_get_car_declarations(PDO $pdo, int $carId): array {
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE car_id = :c ORDER BY submitted_at DESC, id DESC");
    $stmt->execute([':c' => $carId]);
    return $stmt->fetchAll();
}

/** car_id => that car's current (newest non-superseded) declaration, for all of one user's cars. */
function db_get_user_current_declarations(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE user_id = :u AND review_status != 'superseded' ORDER BY submitted_at DESC, id DESC");
    $stmt->execute([':u' => $userId]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['car_id']] ??= $row;
    }
    return $map;
}

function db_count_tech_sheets_for_submission(PDO $pdo, int $submissionId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tech_sheets WHERE submission_id = :s");
    $stmt->execute([':s' => $submissionId]);
    return (int)$stmt->fetchColumn();
}

function db_delete_submission(PDO $pdo, int $id): void {
    $pdo->prepare("DELETE FROM submissions WHERE id = :id")->execute([':id' => $id]);
}

/**
 * After deleting a car's current declaration, restores the newest remaining declaration as current
 * (un-supersedes it) so the car isn't left with no class while earlier declarations exist. Its
 * review_status becomes 'accepted' if accepted_at is set, else 'submitted'. No-op if a
 * non-superseded declaration exists for the car, or none remain.
 */
function db_restore_current_declaration(PDO $pdo, int $carId): void {
    if (db_get_car_current_declaration($pdo, $carId) !== null) return;
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE car_id = :c ORDER BY submitted_at DESC, id DESC LIMIT 1");
    $stmt->execute([':c' => $carId]);
    $newest = $stmt->fetch();
    if (!$newest) return;
    $status = $newest['accepted_at'] !== null ? 'accepted' : 'submitted';
    $pdo->prepare("UPDATE submissions SET review_status = :s WHERE id = :id")
        ->execute([':s' => $status, ':id' => $newest['id']]);
}

/**
 * An inspector accepts a declaration (spec §5). Atomic: only from 'submitted' or 'needs_changes', so
 * a declaration a re-declaration superseded in the meantime is refused. accepted_at is the lasting
 * record of the acceptance: it survives superseding (see db_restore_current_declaration()).
 */
function db_accept_declaration(PDO $pdo, int $id, int $reviewerUserId): bool {
    $stmt = $pdo->prepare("
        UPDATE submissions SET review_status = 'accepted', accepted_at = :now, reviewer_note = NULL,
            reviewed_by_user_id = :reviewer, reviewed_at = :now
        WHERE id = :id AND review_status IN ('submitted', 'needs_changes')
    ");
    $stmt->execute([':now' => date('Y-m-d H:i:s'), ':reviewer' => $reviewerUserId, ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/**
 * An inspector sends a declaration back with a note (-> 'needs_changes'). Atomic: only from 'submitted'
 * or 'accepted'. Sending back an accepted declaration withdraws the acceptance, so accepted_at is cleared.
 */
function db_send_back_declaration(PDO $pdo, int $id, int $reviewerUserId, string $note): bool {
    $stmt = $pdo->prepare("
        UPDATE submissions SET review_status = 'needs_changes', reviewer_note = :note, accepted_at = NULL,
            reviewed_by_user_id = :reviewer, reviewed_at = :now
        WHERE id = :id AND review_status IN ('submitted', 'accepted')
    ");
    $stmt->execute([':note' => $note, ':now' => date('Y-m-d H:i:s'), ':reviewer' => $reviewerUserId, ':id' => $id]);
    return $stmt->rowCount() === 1;
}

function db_delete_submissions(PDO $pdo, array $ids): int {
    if (empty($ids)) return 0;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM submissions WHERE id IN ({$placeholders})");
    $stmt->execute(array_map('intval', $ids));
    return $stmt->rowCount();
}

function db_count_submissions_by_user(PDO $pdo): array {
    $rows = $pdo->query("SELECT user_id, COUNT(*) AS cnt FROM submissions WHERE user_id IS NOT NULL GROUP BY user_id")->fetchAll();
    $counts = [];
    foreach ($rows as $row) {
        $counts[(int)$row['user_id']] = (int)$row['cnt'];
    }
    return $counts;
}

function db_update_submission_contact(PDO $pdo, int $id, array $data): void {
    $pdo->prepare("
        UPDATE submissions
        SET name = :name, email = :email, year = :year, make = :make, model = :model, comments = :comments
        WHERE id = :id
    ")->execute([
        ':name' => $data['name'], ':email' => $data['email'],
        ':year' => $data['year'], ':make' => $data['make'], ':model' => $data['model'],
        ':comments' => $data['comments'], ':id' => $id,
    ]);
}

// ── Drafts ────────────────────────────────────────────────────────────────────

function db_upsert_draft(PDO $pdo, int $user_id, string $label, string $form_data_json): int {
    $stmt = $pdo->prepare("SELECT id FROM drafts WHERE user_id = :user_id AND label = :label");
    $stmt->execute([':user_id' => $user_id, ':label' => $label]);
    $existing = $stmt->fetch();
    $now = date('Y-m-d H:i:s');

    if ($existing) {
        $pdo->prepare("UPDATE drafts SET form_data = :form_data, updated_at = :updated_at WHERE id = :id")
            ->execute([':form_data' => $form_data_json, ':updated_at' => $now, ':id' => $existing['id']]);
        return (int)$existing['id'];
    }

    $pdo->prepare("INSERT INTO drafts (user_id, label, form_data, updated_at) VALUES (:user_id, :label, :form_data, :updated_at)")
        ->execute([':user_id' => $user_id, ':label' => $label, ':form_data' => $form_data_json, ':updated_at' => $now]);
    return (int)$pdo->lastInsertId();
}

function db_get_user_drafts(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare("SELECT * FROM drafts WHERE user_id = :user_id ORDER BY updated_at DESC, id DESC");
    $stmt->execute([':user_id' => $user_id]);
    return $stmt->fetchAll();
}

function db_get_user_draft(PDO $pdo, int $user_id, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM drafts WHERE id = :id AND user_id = :user_id");
    $stmt->execute([':id' => $id, ':user_id' => $user_id]);
    return $stmt->fetch() ?: null;
}

function db_count_user_drafts(PDO $pdo, int $user_id): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM drafts WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $user_id]);
    return (int)$stmt->fetchColumn();
}

function db_delete_draft(PDO $pdo, int $id, int $user_id): void {
    $pdo->prepare("DELETE FROM drafts WHERE id = :id AND user_id = :user_id")
        ->execute([':id' => $id, ':user_id' => $user_id]);
}

// ── Events ────────────────────────────────────────────────────────────────────

function db_create_event(PDO $pdo, string $name, string $event_date, ?string $location,
                         string $discipline = 'summer', ?string $hostClub = null): int {
    $pdo->prepare("
        INSERT INTO events (name, event_date, location, active, created_at, discipline, host_club)
        VALUES (:name, :event_date, :location, 1, :created_at, :discipline, :host_club)
    ")->execute([
        ':name' => $name, ':event_date' => $event_date, ':location' => $location,
        ':created_at' => date('Y-m-d H:i:s'), ':discipline' => $discipline, ':host_club' => $hostClub,
    ]);
    return (int)$pdo->lastInsertId();
}

function db_get_active_events(PDO $pdo, ?string $discipline = null): array {
    if ($discipline === null) {
        return $pdo->query("SELECT * FROM events WHERE active = 1 ORDER BY event_date ASC")->fetchAll();
    }
    $stmt = $pdo->prepare("SELECT * FROM events WHERE active = 1 AND discipline = :d ORDER BY event_date ASC");
    $stmt->execute([':d' => $discipline]);
    return $stmt->fetchAll();
}

function db_get_all_events(PDO $pdo): array {
    return $pdo->query("SELECT * FROM events ORDER BY event_date DESC")->fetchAll();
}

function db_get_event(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function db_update_event(PDO $pdo, int $id, string $name, string $event_date, ?string $location,
                         string $discipline, ?string $hostClub): void {
    $pdo->prepare("
        UPDATE events SET name = :name, event_date = :event_date, location = :location,
            discipline = :discipline, host_club = :host_club
        WHERE id = :id
    ")->execute([':name' => $name, ':event_date' => $event_date, ':location' => $location,
                 ':discipline' => $discipline, ':host_club' => $hostClub, ':id' => $id]);
}

/** The event's own MotorsportReg link ('' clears it). The caller validates it (eventMsrUrlError()). */
function db_set_event_msr_url(PDO $pdo, int $id, string $url): void {
    $pdo->prepare("UPDATE events SET msr_url = :u WHERE id = :id")->execute([':u' => $url, ':id' => $id]);
}

function db_set_event_active(PDO $pdo, int $id, bool $active): void {
    $pdo->prepare("UPDATE events SET active = :active WHERE id = :id")
        ->execute([':active' => $active ? 1 : 0, ':id' => $id]);
}

// ── Event plans and at-track choices ─────────────────────────────────────────

/** Tags the car for the event. True if it was not tagged yet (a new entry starts as race). */
function db_tag_event(PDO $pdo, int $userId, int $eventId, int $carId): bool {
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO event_plans (user_id, event_id, car_id, created_at) VALUES (:u, :e, :c, :now)");
    $stmt->execute([':u' => $userId, ':e' => $eventId, ':c' => $carId, ':now' => date('Y-m-d H:i:s')]);
    return $stmt->rowCount() === 1;
}

function db_untag_event(PDO $pdo, int $userId, int $eventId, int $carId): void {
    $pdo->prepare("DELETE FROM event_plans WHERE user_id = :u AND event_id = :e AND car_id = :c")
        ->execute([':u' => $userId, ':e' => $eventId, ':c' => $carId]);
}

function db_get_user_event_plans(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT event_id, car_id, formats, supps_ack_at FROM event_plans WHERE user_id = :u ORDER BY event_id ASC, car_id ASC");
    $stmt->execute([':u' => $userId]);
    return $stmt->fetchAll();
}

/** One entry (event_plans row) of the user's, or null. */
function db_get_entry(PDO $pdo, int $userId, int $eventId, int $carId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM event_plans WHERE user_id = :u AND event_id = :e AND car_id = :c");
    $stmt->execute([':u' => $userId, ':e' => $eventId, ':c' => $carId]);
    return $stmt->fetch() ?: null;
}

/** An entry's formats (as entryFormatsStore() writes them) and when the regulations box was ticked (null = not ticked). */
function db_set_entry_formats(PDO $pdo, int $userId, int $eventId, int $carId, string $formats, ?string $suppsAckAt): void {
    $pdo->prepare("UPDATE event_plans SET formats = :f, supps_ack_at = :a WHERE user_id = :u AND event_id = :e AND car_id = :c")
        ->execute([':f' => $formats, ':a' => $suppsAckAt, ':u' => $userId, ':e' => $eventId, ':c' => $carId]);
}

/** The formats of the car's most recently made summer entry, other than $exceptEventId. Null if none. */
function db_get_car_last_summer_formats(PDO $pdo, int $carId, int $exceptEventId): ?string {
    $stmt = $pdo->prepare("
        SELECT p.formats FROM event_plans p JOIN events e ON e.id = p.event_id
        WHERE p.car_id = :c AND p.event_id != :x AND e.discipline = 'summer'
        ORDER BY p.created_at DESC, p.id DESC LIMIT 1
    ");
    $stmt->execute([':c' => $carId, ':x' => $exceptEventId]);
    $f = $stmt->fetchColumn();
    return $f === false ? null : (string)$f;
}

function db_set_at_track(PDO $pdo, string $subjectType, int $subjectId, int $season,
                         string $discipline = DISCIPLINE_SUMMER, string $club = ''): void {
    $pdo->prepare("
        INSERT OR IGNORE INTO at_track_choices (subject_type, subject_id, season, discipline, club, created_at)
        VALUES (:t, :s, :y, :d, :c, :now)
    ")->execute([':t' => $subjectType, ':s' => $subjectId, ':y' => $season, ':d' => $discipline, ':c' => $club,
                 ':now' => date('Y-m-d H:i:s')]);
}

/** atTrackKey() keys for the given subjects that chose "I'll do it at the track" in this season and discipline. */
function db_get_at_track_keys(PDO $pdo, array $carIds, array $driverIds, int $season, string $discipline = DISCIPLINE_SUMMER): array {
    $keys = [];
    $stmt = $pdo->prepare("SELECT subject_type, subject_id, club FROM at_track_choices WHERE season = :y AND discipline = :d");
    $stmt->execute([':y' => $season, ':d' => $discipline]);
    $cars = array_flip(array_map('intval', $carIds));
    $drivers = array_flip(array_map('intval', $driverIds));
    foreach ($stmt->fetchAll() as $r) {
        $id = (int)$r['subject_id'];
        if (($r['subject_type'] === 'car' && isset($cars[$id])) || ($r['subject_type'] === 'driver' && isset($drivers[$id]))) {
            // A summer row with a club is TA/Drift car tech at that club (TA/Drift spec §2).
            $keyDiscipline = $discipline === DISCIPLINE_SUMMER && (string)$r['club'] !== '' ? TECH_TIER_TA_DRIFT : $discipline;
            $keys[] = atTrackKey($r['subject_type'], $id, $season, $keyDiscipline, (string)$r['club']);
        }
    }
    return $keys;
}

function db_get_gear_record_for_driver(PDO $pdo, int $driverId, int $season, string $discipline = DISCIPLINE_SUMMER): ?array {
    $stmt = $pdo->prepare(DB_GEAR_SELECT . " WHERE g.driver_id = :d AND g.season = :s AND g.discipline = :disc");
    $stmt->execute([':d' => $driverId, ':s' => $season, ':disc' => $discipline]);
    return $stmt->fetch() ?: null;
}

// ── Tech Sheets ───────────────────────────────────────────────────────────────

/** The host club a TA/Drift sheet is keyed to: its event's host_club. Throws if the event has none. */
function db_ta_drift_club(PDO $pdo, int $eventId): string {
    $club = trim((string)(db_get_event($pdo, $eventId)['host_club'] ?? ''));
    if ($club === '') throw new InvalidArgumentException('A TA/Drift tech sheet needs an event with a host club.');
    return $club;
}

function db_insert_tech_sheet(PDO $pdo, array $data): int {
    $now = date('Y-m-d H:i:s');
    $identity = db_tech_sheet_identity($pdo, (string)$data['car_number'], (int)$data['event_id']);
    if (($data['sheet_type'] ?? '') === SHEET_TYPE_TA_DRIFT) {
        if ($identity['discipline'] !== DISCIPLINE_SUMMER) {
            throw new InvalidArgumentException('A TA/Drift tech sheet is for summer events.');
        }
        if (!empty($data['submission_id'])) {
            throw new InvalidArgumentException('A TA/Drift tech sheet does not take a class declaration.');
        }
        $car = db_get_user_car($pdo, (int)$data['user_id'], (int)($data['car_id'] ?? 0));
        if ($car === null) {
            throw new InvalidArgumentException('A TA/Drift tech sheet needs one of your cars.');
        }
        $identity['club'] = db_ta_drift_club($pdo, (int)$data['event_id']);
        $submissionId = null;
        $carId = (int)$car['id'];
    } elseif ($identity['discipline'] === DISCIPLINE_ICE) {
        if (!empty($data['submission_id'])) {
            throw new InvalidArgumentException('An ice tech sheet does not take a class declaration.');
        }
        $car = db_get_user_car($pdo, (int)$data['user_id'], (int)($data['car_id'] ?? 0));
        if ($car === null) {
            throw new InvalidArgumentException('An ice tech sheet needs one of your cars.');
        }
        $submissionId = null;
        $carId = (int)$car['id'];
    } else {
        $submission = db_get_submission($pdo, (int)($data['submission_id'] ?? 0));
        if ($submission === null) {
            throw new InvalidArgumentException('A tech sheet needs an existing class declaration.');
        }
        $submissionId = (int)$submission['id'];
        $carId = (int)$submission['car_id'];
    }
    $driverId = db_find_or_create_driver($pdo, (int)$data['user_id'], (string)$data['driver_name']);
    $stmt = $pdo->prepare("
        INSERT INTO tech_sheets (
            submission_id, car_id, user_id, event_id, sheet_type,
            entrant_name, driver_name, driver_id, car_make, car_model, car_colour, car_number,
            class, engine_cc, engine_hp, car_weight,
            checklist_json, driver1_equipment_json, log_book_turned_in,
            car_number_norm, season, discipline, club, caged,
            status, created_at, updated_at
        ) VALUES (
            :submission_id, :car_id, :user_id, :event_id, :sheet_type,
            :entrant_name, :driver_name, :driver_id, :car_make, :car_model, :car_colour, :car_number,
            :class, :engine_cc, :engine_hp, :car_weight,
            :checklist_json, :driver1_equipment_json, :log_book_turned_in,
            :car_number_norm, :season, :discipline, :club, :caged,
            'submitted', :created_at, :updated_at
        )
    ");
    $stmt->execute([
        ':submission_id' => $submissionId, ':car_id' => $carId, ':user_id' => $data['user_id'], ':event_id' => $data['event_id'],
        ':sheet_type' => $data['sheet_type'], ':entrant_name' => $data['entrant_name'], ':driver_name' => $data['driver_name'],
        ':driver_id' => $driverId,
        ':car_make' => $data['car_make'], ':car_model' => $data['car_model'], ':car_colour' => $data['car_colour'],
        ':car_number' => $data['car_number'], ':class' => $data['class'], ':engine_cc' => $data['engine_cc'] ?? null,
        ':engine_hp' => $data['engine_hp'] ?? null, ':car_weight' => $data['car_weight'],
        ':checklist_json' => $data['checklist_json'], ':driver1_equipment_json' => $data['driver1_equipment_json'],
        ':log_book_turned_in' => $data['log_book_turned_in'] ?? null,
        ':car_number_norm' => $identity['car_number_norm'], ':season' => $identity['season'],
        ':discipline' => $identity['discipline'], ':club' => $identity['club'], ':caged' => empty($data['caged']) ? 0 : 1,
        ':created_at' => $now, ':updated_at' => $now,
    ]);
    return (int)$pdo->lastInsertId();
}

function db_get_tech_sheet(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM tech_sheets WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

/** The id of a car's newest tech sheet (by created_at, then id), or null if it has none. */
function db_get_car_latest_tech_sheet_id(PDO $pdo, int $carId): ?int {
    $stmt = $pdo->prepare("SELECT id FROM tech_sheets WHERE car_id = :c ORDER BY created_at DESC, id DESC LIMIT 1");
    $stmt->execute([':c' => $carId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

function db_get_user_tech_sheet(PDO $pdo, int $user_id, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM tech_sheets WHERE id = :id AND user_id = :user_id");
    $stmt->execute([':id' => $id, ':user_id' => $user_id]);
    return $stmt->fetch() ?: null;
}

function db_get_user_tech_sheets(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare("SELECT * FROM tech_sheets WHERE user_id = :user_id ORDER BY created_at DESC, id DESC");
    $stmt->execute([':user_id' => $user_id]);
    return $stmt->fetchAll();
}

function db_update_tech_sheet(PDO $pdo, int $id, array $data): void {
    $current = db_get_tech_sheet($pdo, $id);
    $identity = db_tech_sheet_identity($pdo, (string)$data['car_number'], (int)$data['event_id']);
    if ($current !== null && ($current['discipline'] ?? DISCIPLINE_SUMMER) !== $identity['discipline']) {
        throw new InvalidArgumentException('A tech sheet cannot move between summer and ice events.');
    }
    $isTaDrift = ($data['sheet_type'] ?? '') === SHEET_TYPE_TA_DRIFT;
    if ($current !== null && (($current['sheet_type'] ?? '') === SHEET_TYPE_TA_DRIFT) !== $isTaDrift) {
        throw new InvalidArgumentException('A tech sheet cannot change between TA/Drift and race.');
    }
    if ($isTaDrift) $identity['club'] = db_ta_drift_club($pdo, (int)$data['event_id']);
    $owner = (int)($current['user_id'] ?? 0);
    $driverId = db_find_or_create_driver($pdo, $owner, (string)$data['driver_name']);
    $pdo->prepare("
        UPDATE tech_sheets SET
            event_id = :event_id, sheet_type = :sheet_type,
            entrant_name = :entrant_name, driver_name = :driver_name, driver_id = :driver_id,
            car_make = :car_make, car_model = :car_model, car_colour = :car_colour, car_number = :car_number,
            class = :class, engine_cc = :engine_cc, engine_hp = :engine_hp, car_weight = :car_weight,
            checklist_json = :checklist_json, driver1_equipment_json = :driver1_equipment_json,
            log_book_turned_in = :log_book_turned_in,
            car_number_norm = :car_number_norm, season = :season, club = :club, caged = :caged, updated_at = :updated_at
        WHERE id = :id
    ")->execute([
        ':event_id' => $data['event_id'], ':sheet_type' => $data['sheet_type'],
        ':entrant_name' => $data['entrant_name'], ':driver_name' => $data['driver_name'], ':driver_id' => $driverId,
        ':car_make' => $data['car_make'], ':car_model' => $data['car_model'], ':car_colour' => $data['car_colour'],
        ':car_number' => $data['car_number'], ':class' => $data['class'], ':engine_cc' => $data['engine_cc'] ?? null,
        ':engine_hp' => $data['engine_hp'] ?? null, ':car_weight' => $data['car_weight'],
        ':checklist_json' => $data['checklist_json'], ':driver1_equipment_json' => $data['driver1_equipment_json'],
        ':log_book_turned_in' => $data['log_book_turned_in'] ?? null,
        ':car_number_norm' => $identity['car_number_norm'], ':season' => $identity['season'],
        ':club' => $identity['club'], ':caged' => empty($data['caged']) ? 0 : 1,
        ':updated_at' => date('Y-m-d H:i:s'), ':id' => $id,
    ]);
}

function db_update_tech_sheet_signatures(PDO $pdo, int $id, array $paths): void {
    $now = date('Y-m-d H:i:s');
    $sets = [];
    $params = [':id' => $id];
    foreach (['entrant_signature_path', 'driver_signature_path', 'tech_signature_path'] as $col) {
        if (array_key_exists($col, $paths)) {
            $sets[] = "{$col} = :{$col}";
            $params[":{$col}"] = $paths[$col];
            $signedAtCol = str_replace('_signature_path', '_signed_at', $col);
            $sets[] = "{$signedAtCol} = :{$signedAtCol}";
            $params[":{$signedAtCol}"] = $now;
        }
    }
    if (empty($sets)) return;
    $pdo->prepare("UPDATE tech_sheets SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
}

function db_update_email_sent_tech_sheet(PDO $pdo, int $id, int $sent): void {
    if ($sent === 1) {
        $pdo->prepare("
            UPDATE tech_sheets
            SET email_sent = 1, last_emailed_at = :now, email_send_count = email_send_count + 1
            WHERE id = :id
        ")->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    } else {
        $pdo->prepare("UPDATE tech_sheets SET email_sent = 0 WHERE id = :id")->execute([':id' => $id]);
    }
}

function db_add_tech_sheet_driver(PDO $pdo, int $tech_sheet_id, int $driver_number, string $driver_name, string $equipment_json): int {
    $owner = (int)(db_get_tech_sheet($pdo, $tech_sheet_id)['user_id'] ?? 0);
    $pdo->prepare("
        INSERT INTO tech_sheet_drivers (tech_sheet_id, driver_number, driver_name, driver_id, equipment_json)
        VALUES (:tech_sheet_id, :driver_number, :driver_name, :driver_id, :equipment_json)
    ")->execute([
        ':tech_sheet_id' => $tech_sheet_id, ':driver_number' => $driver_number, ':driver_name' => $driver_name,
        ':driver_id' => $owner > 0 ? db_find_or_create_driver($pdo, $owner, $driver_name) : null,
        ':equipment_json' => $equipment_json,
    ]);
    return (int)$pdo->lastInsertId();
}

function db_get_tech_sheet_drivers(PDO $pdo, int $tech_sheet_id): array {
    $stmt = $pdo->prepare("SELECT * FROM tech_sheet_drivers WHERE tech_sheet_id = :tsid ORDER BY driver_number ASC");
    $stmt->execute([':tsid' => $tech_sheet_id]);
    return $stmt->fetchAll();
}

/** Replaces all additional-driver rows for a sheet — used on submit/edit since the whole set is resent each save. */
function db_replace_tech_sheet_drivers(PDO $pdo, int $tech_sheet_id, array $drivers): void {
    $pdo->prepare("DELETE FROM tech_sheet_drivers WHERE tech_sheet_id = :tsid")->execute([':tsid' => $tech_sheet_id]);
    foreach ($drivers as $d) {
        db_add_tech_sheet_driver($pdo, $tech_sheet_id, (int)$d['driver_number'], $d['driver_name'], $d['equipment_json']);
    }
}

// ── Users ─────────────────────────────────────────────────────────────────────

function db_create_user(PDO $pdo, array $data): int {
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $role = (strtolower($data['email']) === strtolower(BOOTSTRAP_ADMIN_EMAIL)) ? 'admin' : 'user';
        $stmt = $pdo->prepare("
            INSERT INTO users (email, password_hash, google_id, name, role, created_at)
            VALUES (:email, :password_hash, :google_id, :name, :role, :created_at)
        ");
        $stmt->execute([
            ':email'         => $data['email'],
            ':password_hash' => $data['password_hash'] ?? null,
            ':google_id'     => $data['google_id'] ?? null,
            ':name'          => $data['name'],
            ':role'          => $role,
            ':created_at'    => date('Y-m-d H:i:s'),
        ]);
        $id = (int)$pdo->lastInsertId();
        db_create_driver($pdo, $id, (string)$data['name'], null, $id);   // the account holder's own driver profile
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $id;
}

function db_find_user_by_email(PDO $pdo, string $email): ?array {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email COLLATE NOCASE");
    $stmt->execute([':email' => $email]);
    return $stmt->fetch() ?: null;
}

function db_find_user_by_google_id(PDO $pdo, string $google_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = :google_id");
    $stmt->execute([':google_id' => $google_id]);
    return $stmt->fetch() ?: null;
}

function db_find_user_by_id(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function db_get_all_users(PDO $pdo): array {
    return $pdo->query("SELECT * FROM users ORDER BY created_at ASC")->fetchAll();
}

function db_set_user_role(PDO $pdo, int $id, string $role): void {
    $pdo->prepare("UPDATE users SET role = :role WHERE id = :id")
        ->execute([':role' => $role, ':id' => $id]);
}

/** Renames the account and its self driver profile (the profile is left alone if the new name collides). */
function db_set_user_name(PDO $pdo, int $id, string $name): void {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE users SET name = :n WHERE id = :id")->execute([':n' => $name, ':id' => $id]);
    $pdo->prepare("UPDATE OR IGNORE drivers SET name = :n, name_norm = :norm, updated_at = :now WHERE user_id = :id")
        ->execute([':n' => $name, ':norm' => db_driver_name_norm($name), ':now' => $now, ':id' => $id]);
}

function db_set_user_password(PDO $pdo, int $id, string $hash): void {
    $pdo->prepare("UPDATE users SET password_hash = :h WHERE id = :id")->execute([':h' => $hash, ':id' => $id]);
}

function db_count_admins(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
}

function db_set_user_active(PDO $pdo, int $id, bool $active): void {
    $pdo->prepare("UPDATE users SET active = :active WHERE id = :id")
        ->execute([':active' => $active ? 1 : 0, ':id' => $id]);
}

function db_count_active_admins(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
}

function db_link_google_id(PDO $pdo, int $user_id, string $google_id): void {
    $pdo->prepare("UPDATE users SET google_id = :google_id WHERE id = :id")
        ->execute([':google_id' => $google_id, ':id' => $user_id]);
}

// ── Cars ──────────────────────────────────────────────────────────────────────

const DB_CAR_FIELDS = ['car_number', 'year', 'make', 'model', 'colour', 'engine_cc', 'disciplines'];

function db_create_car(PDO $pdo, int $ownerId, array $d): int {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO cars (owner_user_id, car_number, car_number_norm, year, make, model, colour, engine_cc, disciplines, created_at, updated_at)
        VALUES (:o, :n, :norm, :y, :make, :model, :colour, :cc, :disc, :now, :now)
    ")->execute([
        ':o' => $ownerId, ':n' => (string)$d['car_number'], ':norm' => techCarNumberNorm((string)$d['car_number']),
        ':y' => $d['year'] ?? null, ':make' => (string)$d['make'], ':model' => (string)$d['model'],
        ':colour' => $d['colour'] ?? null, ':cc' => $d['engine_cc'] ?? null, ':disc' => $d['disciplines'] ?? null, ':now' => $now,
    ]);
    return (int)$pdo->lastInsertId();
}

function db_get_car(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM cars WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function db_get_user_car(PDO $pdo, int $ownerId, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM cars WHERE id = :id AND owner_user_id = :o");
    $stmt->execute([':id' => $id, ':o' => $ownerId]);
    return $stmt->fetch() ?: null;
}

/** One owner's cars, numeric numbers first in numeric order, then the rest alphabetically. */
function db_get_user_cars(PDO $pdo, int $ownerId, bool $includeArchived = false): array {
    $sql = "SELECT * FROM cars WHERE owner_user_id = :o" . ($includeArchived ? "" : " AND archived_at IS NULL")
         . " ORDER BY (car_number_norm GLOB '[0-9]*') DESC, CAST(car_number_norm AS INTEGER) ASC, car_number_norm ASC, id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':o' => $ownerId]);
    return $stmt->fetchAll();
}

/** Applies only the keys in DB_CAR_FIELDS; a new car_number recomputes car_number_norm. */
function db_update_car(PDO $pdo, int $id, array $d): void {
    $sets = [];
    $params = [':id' => $id, ':now' => date('Y-m-d H:i:s')];
    foreach (DB_CAR_FIELDS as $field) {
        if (!array_key_exists($field, $d)) continue;
        $sets[] = "{$field} = :{$field}";
        $params[':' . $field] = $d[$field];
    }
    if (array_key_exists('car_number', $d)) {
        $sets[] = 'car_number_norm = :norm';
        $params[':norm'] = techCarNumberNorm((string)$d['car_number']);
    }
    if (!$sets) return;
    $pdo->prepare("UPDATE cars SET " . implode(', ', $sets) . ", updated_at = :now WHERE id = :id")->execute($params);
}

/** Archives one of the owner's active cars. False if it is not theirs or is already archived. */
function db_archive_car(PDO $pdo, int $ownerId, int $id): bool {
    $stmt = $pdo->prepare("UPDATE cars SET archived_at = :now, updated_at = :now WHERE id = :id AND owner_user_id = :o AND archived_at IS NULL");
    $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id, ':o' => $ownerId]);
    return $stmt->rowCount() === 1;
}

/** Restores one of the owner's archived cars. False if it is not theirs or is not archived. */
function db_restore_car(PDO $pdo, int $ownerId, int $id): bool {
    $stmt = $pdo->prepare("UPDATE cars SET archived_at = NULL, updated_at = :now WHERE id = :id AND owner_user_id = :o AND archived_at IS NOT NULL");
    $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id, ':o' => $ownerId]);
    return $stmt->rowCount() === 1;
}

// ── Drivers ───────────────────────────────────────────────────────────────────

/** Collapse whitespace, trim, lowercase: a driver's identity within the account that manages them. */
function db_driver_name_norm(string $name): string {
    return strtolower(trim((string)preg_replace('/\s+/', ' ', $name)));
}

function db_create_driver(PDO $pdo, int $ownerId, string $name, ?string $licenceNo = null, ?int $userId = null): int {
    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO drivers (owner_user_id, user_id, name, name_norm, licence_no, created_at, updated_at)
        VALUES (:o, :u, :n, :norm, :l, :now, :now)
    ")->execute([':o' => $ownerId, ':u' => $userId, ':n' => $name, ':norm' => db_driver_name_norm($name), ':l' => $licenceNo, ':now' => $now]);
    return (int)$pdo->lastInsertId();
}

function db_get_driver(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM drivers WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function db_find_driver(PDO $pdo, int $ownerId, string $name): ?array {
    $stmt = $pdo->prepare("SELECT * FROM drivers WHERE owner_user_id = :o AND name_norm = :n");
    $stmt->execute([':o' => $ownerId, ':n' => db_driver_name_norm($name)]);
    return $stmt->fetch() ?: null;
}

/** The owner's driver profile with this (normalised) name, created if missing. Null for a blank name. */
function db_find_or_create_driver(PDO $pdo, int $ownerId, string $name): ?int {
    if (db_driver_name_norm($name) === '') return null;
    $existing = db_find_driver($pdo, $ownerId, $name);
    return $existing !== null ? (int)$existing['id'] : db_create_driver($pdo, $ownerId, $name);
}

function db_get_self_driver(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM drivers WHERE user_id = :u");
    $stmt->execute([':u' => $userId]);
    return $stmt->fetch() ?: null;
}

/** The owner's own profile first, then the drivers they manage by name. */
function db_get_user_drivers(PDO $pdo, int $ownerId): array {
    $stmt = $pdo->prepare("
        SELECT * FROM drivers WHERE owner_user_id = :o
        ORDER BY (user_id IS NOT NULL AND user_id = owner_user_id) DESC, name_norm ASC, id ASC
    ");
    $stmt->execute([':o' => $ownerId]);
    return $stmt->fetchAll();
}

function db_update_driver_licence(PDO $pdo, int $id, ?string $licenceNo): void {
    $pdo->prepare("UPDATE drivers SET licence_no = :l, updated_at = :now WHERE id = :id")
        ->execute([':l' => $licenceNo, ':now' => date('Y-m-d H:i:s'), ':id' => $id]);
}

// ── Password resets ──────────────────────────────────────────────────────────

function db_create_password_reset(PDO $pdo, int $user_id, string $token_hash, string $expires_at): void {
    $pdo->prepare("INSERT INTO password_resets (token_hash, user_id, expires_at) VALUES (:token_hash, :user_id, :expires_at)")
        ->execute([':token_hash' => $token_hash, ':user_id' => $user_id, ':expires_at' => $expires_at]);
}

function db_get_password_reset(PDO $pdo, string $token_hash): ?array {
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = :token_hash");
    $stmt->execute([':token_hash' => $token_hash]);
    return $stmt->fetch() ?: null;
}

function db_delete_password_reset(PDO $pdo, string $token_hash): void {
    $pdo->prepare("DELETE FROM password_resets WHERE token_hash = :token_hash")->execute([':token_hash' => $token_hash]);
}

function db_delete_password_resets_for_user(PDO $pdo, int $user_id): void {
    $pdo->prepare("DELETE FROM password_resets WHERE user_id = :user_id")->execute([':user_id' => $user_id]);
}

// ── Rate limiting ─────────────────────────────────────────────────────────────

define('RL_MAX_ATTEMPTS', 5);
define('RL_WINDOW_SECONDS', 15 * 60); // 15 minutes

function db_is_locked_out(PDO $pdo, string $ip): array {
    $stmt = $pdo->prepare("SELECT * FROM login_attempts WHERE ip = :ip");
    $stmt->execute([':ip' => $ip]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['locked' => false, 'remaining' => 0];
    }

    $elapsed = time() - strtotime($row['last_attempt_at']);
    if ($row['attempts'] >= RL_MAX_ATTEMPTS && $elapsed < RL_WINDOW_SECONDS) {
        return ['locked' => true, 'remaining' => (int)ceil((RL_WINDOW_SECONDS - $elapsed) / 60)];
    }

    return ['locked' => false, 'remaining' => 0];
}

function db_record_failed_attempt(PDO $pdo, string $ip): void {
    $stmt = $pdo->prepare("SELECT * FROM login_attempts WHERE ip = :ip");
    $stmt->execute([':ip' => $ip]);
    $row = $stmt->fetch();
    $now = date('Y-m-d H:i:s');

    if (!$row) {
        $pdo->prepare("INSERT INTO login_attempts (ip, attempts, last_attempt_at) VALUES (:ip, 1, :now)")
            ->execute([':ip' => $ip, ':now' => $now]);
    } else {
        $elapsed      = time() - strtotime($row['last_attempt_at']);
        $new_attempts = ($elapsed >= RL_WINDOW_SECONDS) ? 1 : $row['attempts'] + 1;
        $pdo->prepare("UPDATE login_attempts SET attempts = :attempts, last_attempt_at = :now WHERE ip = :ip")
            ->execute([':attempts' => $new_attempts, ':now' => $now, ':ip' => $ip]);
    }
}

function db_clear_login_attempts(PDO $pdo, string $ip): void {
    $pdo->prepare("DELETE FROM login_attempts WHERE ip = :ip")->execute([':ip' => $ip]);
}

// ── Settings ──────────────────────────────────────────────────────────────────

function db_get_setting(PDO $pdo, string $key, ?string $default = null): ?string {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = :key");
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

/**
 * Reads a config.php constant if defined, otherwise falls back to a literal.
 * Guards against a deployed config.php that predates a constant being added —
 * referencing an undefined constant directly is a fatal error in PHP 8.
 */
function config_default(string $constant, string $fallback): string {
    return defined($constant) ? constant($constant) : $fallback;
}

function db_set_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
        ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value
    ")->execute([':key' => $key, ':value' => $value]);
}

// ── Feedback ──────────────────────────────────────────────────────────────────

function db_insert_feedback(PDO $pdo, array $data): int {
    $pdo->prepare("
        INSERT INTO feedback (type, message, name, email, user_id, page_url, user_agent, viewport, calc_inputs, ip_hash, status, created_at)
        VALUES (:type, :message, :name, :email, :user_id, :page_url, :user_agent, :viewport, :calc_inputs, :ip_hash, 'new', :created_at)
    ")->execute([
        ':type'        => $data['type'],
        ':message'     => $data['message'],
        ':name'        => $data['name'] ?? null,
        ':email'       => $data['email'] ?? null,
        ':user_id'     => $data['user_id'] ?? null,
        ':page_url'    => $data['page_url'] ?? null,
        ':user_agent'  => $data['user_agent'] ?? null,
        ':viewport'    => $data['viewport'] ?? null,
        ':calc_inputs' => $data['calc_inputs'] ?? null,
        ':ip_hash'     => $data['ip_hash'] ?? null,
        ':created_at'  => $data['created_at'],
    ]);
    return (int)$pdo->lastInsertId();
}

function db_get_feedback(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM feedback WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function db_get_all_feedback(PDO $pdo): array {
    return $pdo->query("SELECT * FROM feedback ORDER BY created_at DESC, id DESC")->fetchAll();
}

function db_update_feedback_status(PDO $pdo, int $id, string $status): void {
    $pdo->prepare("UPDATE feedback SET status = :status WHERE id = :id")
        ->execute([':status' => $status, ':id' => $id]);
}

function db_set_feedback_github(PDO $pdo, int $id, int $number, string $url): void {
    $pdo->prepare("
        UPDATE feedback SET github_issue_number = :n, github_issue_url = :url, github_error = NULL WHERE id = :id
    ")->execute([':n' => $number, ':url' => $url, ':id' => $id]);
}

function db_set_feedback_github_error(PDO $pdo, int $id, string $error): void {
    $pdo->prepare("UPDATE feedback SET github_error = :err WHERE id = :id")
        ->execute([':err' => $error, ':id' => $id]);
}

function db_count_recent_feedback_by_ip_hash(PDO $pdo, string $ipHash, string $since): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM feedback WHERE ip_hash = :h AND created_at >= :since");
    $stmt->execute([':h' => $ipHash, ':since' => $since]);
    return (int)$stmt->fetchColumn();
}

/**
 * Inserts the photo for (subject_type, subject_id, requirement_key), or
 * replaces it if one exists (a retake): the review state resets to pending.
 * Returns the previous file_path when replacing, so the caller can delete the
 * stale file, or null for a first upload.
 */
function db_upsert_inspection_photo(PDO $pdo, array $d): ?string {
    // The read-then-write must be atomic: two concurrent uploads for one key would
    // otherwise both see "no row" and the second INSERT would hit the UNIQUE constraint.
    // BEGIN IMMEDIATE takes the write lock up front. Don't nest inside a caller's transaction.
    if ($pdo->inTransaction()) {
        return db_upsert_inspection_photo_unlocked($pdo, $d);
    }
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $previous = db_upsert_inspection_photo_unlocked($pdo, $d);
        $pdo->exec('COMMIT');
        return $previous;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function db_upsert_inspection_photo_unlocked(PDO $pdo, array $d): ?string {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("SELECT id, file_path FROM inspection_photos WHERE subject_type = :t AND subject_id = :s AND requirement_key = :k");
    $stmt->execute([':t' => $d['subject_type'], ':s' => $d['subject_id'], ':k' => $d['requirement_key']]);
    $existing = $stmt->fetch();

    if ($existing) {
        $pdo->prepare("
            UPDATE inspection_photos SET
                requirement_version = :v, file_path = :p, typed_value = :tv,
                review_status = 'pending', reviewer_note = NULL, applies = 1, updated_at = :now
            WHERE id = :id
        ")->execute([
            ':v' => $d['requirement_version'], ':p' => $d['file_path'], ':tv' => $d['typed_value'],
            ':now' => $now, ':id' => $existing['id'],
        ]);
        return $existing['file_path'];
    }

    $pdo->prepare("
        INSERT INTO inspection_photos
            (subject_type, subject_id, requirement_key, requirement_version, file_path, typed_value, created_at, updated_at)
        VALUES (:t, :s, :k, :v, :p, :tv, :now, :now)
    ")->execute([
        ':t' => $d['subject_type'], ':s' => $d['subject_id'], ':k' => $d['requirement_key'],
        ':v' => $d['requirement_version'], ':p' => $d['file_path'], ':tv' => $d['typed_value'], ':now' => $now,
    ]);
    return null;
}

function db_get_inspection_photo(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM inspection_photos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

/** All photos for a subject, keyed by requirement_key. */
function db_get_inspection_photos(PDO $pdo, string $subjectType, int $subjectId): array {
    $stmt = $pdo->prepare("SELECT * FROM inspection_photos WHERE subject_type = :t AND subject_id = :s ORDER BY id ASC");
    $stmt->execute([':t' => $subjectType, ':s' => $subjectId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['requirement_key']] = $row;
    }
    return $out;
}

function db_delete_inspection_photo(PDO $pdo, int $id): void {
    $pdo->prepare("DELETE FROM inspection_photos WHERE id = :id")->execute([':id' => $id]);
}

/** Normalised car number and season key (season, discipline, club) of the sheet's event. */
function db_tech_sheet_identity(PDO $pdo, string $carNumber, int $eventId): array {
    $key = seasonForEvent(db_get_event($pdo, $eventId));
    return [
        'car_number_norm' => techCarNumberNorm($carNumber),
        'season' => $key['season'], 'discipline' => $key['discipline'], 'club' => $key['club'],
    ];
}

/**
 * Marks a submitted sheet as accepted in person. The WHERE clause makes this atomic:
 * returns false if the sheet does not exist or was already accepted.
 */
function db_accept_tech_sheet_in_person(PDO $pdo, int $id, int $reviewerUserId, string $signaturePath): bool {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        UPDATE tech_sheets SET
            status = 'teched', accepted_via = 'in_person',
            reviewed_by_user_id = :reviewer, reviewed_at = :now,
            tech_signature_path = :sig, tech_signed_at = :now, revoke_note = NULL, updated_at = :now
        WHERE id = :id AND status = 'submitted'
    ");
    $stmt->execute([':reviewer' => $reviewerUserId, ':now' => $now, ':sig' => $signaturePath, ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/**
 * Returns an accepted sheet to 'submitted' and clears its review fields. $note says why (for example
 * the car changed substantially) and is shown to the owner until the sheet is accepted again.
 * False if it was not accepted.
 */
function db_revoke_tech_sheet_acceptance(PDO $pdo, int $id, ?string $note = null): bool {
    $stmt = $pdo->prepare("
        UPDATE tech_sheets SET
            status = 'submitted', accepted_via = NULL,
            photo_status = CASE WHEN photo_status = 'accepted' THEN 'submitted' ELSE photo_status END,
            reviewed_by_user_id = NULL, reviewed_at = NULL,
            tech_signature_path = NULL, tech_signed_at = NULL, revoke_note = :note, updated_at = :now
        WHERE id = :id AND status = 'teched'
    ");
    $stmt->execute([':note' => $note, ':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/** All sheets for one car in one season (and discipline, and club for ice). */
function db_get_identity_sheets(PDO $pdo, int $carId, int $season, string $discipline = DISCIPLINE_SUMMER, ?string $club = null): array {
    $stmt = $pdo->prepare("
        SELECT * FROM tech_sheets
        WHERE car_id = :c AND season = :s AND discipline = :d AND club IS :club
        ORDER BY id ASC
    ");
    $stmt->execute([':c' => $carId, ':s' => $season, ':d' => $discipline, ':club' => $club]);
    return $stmt->fetchAll();
}

/** Every sheet that shares $sheet's car and season key: the sheets that decide its car's status. */
function db_get_sheet_identity_sheets(PDO $pdo, array $sheet): array {
    return db_get_identity_sheets($pdo, (int)$sheet['car_id'], (int)$sheet['season'],
        (string)($sheet['discipline'] ?? DISCIPLINE_SUMMER), $sheet['club'] ?? null);
}

/** Every tech sheet in a season of one discipline (used to derive each car's status on a roster). */
function db_get_season_sheets(PDO $pdo, int $season, string $discipline = DISCIPLINE_SUMMER): array {
    $stmt = $pdo->prepare("SELECT * FROM tech_sheets WHERE season = :s AND discipline = :d ORDER BY id ASC");
    $stmt->execute([':s' => $season, ':d' => $discipline]);
    return $stmt->fetchAll();
}

/** Sheets for one event (0 = every event), with event name/date, newest event first then by car number. */
function db_get_event_tech_sheets(PDO $pdo, int $eventId): array {
    $sql = "SELECT ts.*, e.name AS event_name, e.event_date AS event_date
            FROM tech_sheets ts LEFT JOIN events e ON e.id = ts.event_id";
    $params = [];
    if ($eventId > 0) {
        $sql .= " WHERE ts.event_id = :e";
        $params[':e'] = $eventId;
    }
    $sql .= " ORDER BY e.event_date DESC, CAST(ts.car_number_norm AS INTEGER) ASC, ts.car_number_norm ASC, ts.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** First photo activity on a sheet: photo_status NULL -> 'draft'. No-op otherwise (or once the sheet is teched). */
function db_mark_tech_sheet_photos_draft(PDO $pdo, int $id): void {
    $pdo->prepare("
        UPDATE tech_sheets SET photo_status = 'draft', updated_at = :now
        WHERE id = :id AND photo_status IS NULL AND status = 'submitted'
    ")->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
}

/** Atomic photo_status transition: true only if the sheet is not teched and its status was one of $from. */
function db_transition_tech_sheet_photo_status(PDO $pdo, int $id, array $from, string $to): bool {
    if (empty($from)) return false;
    $marks = implode(',', array_fill(0, count($from), '?'));
    $stmt = $pdo->prepare("
        UPDATE tech_sheets SET photo_status = ?, updated_at = ?
        WHERE id = ? AND status = 'submitted' AND photo_status IN ($marks)
    ");
    $stmt->execute(array_merge([$to, date('Y-m-d H:i:s'), $id], array_values($from)));
    return $stmt->rowCount() === 1;
}

/** Remote acceptance after reviewing photos. Atomic; only from a submitted photo set on a not-yet-teched sheet. */
function db_accept_tech_sheet_by_photos(PDO $pdo, int $id, int $reviewerUserId): bool {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        UPDATE tech_sheets SET
            status = 'teched', accepted_via = 'photos', photo_status = 'accepted', revoke_note = NULL,
            reviewed_by_user_id = :reviewer, reviewed_at = :now, updated_at = :now
        WHERE id = :id AND status = 'submitted' AND photo_status = 'submitted'
    ");
    $stmt->execute([':reviewer' => $reviewerUserId, ':now' => $now, ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/**
 * Marks a conditional photo as applying (placeholder row with no file yet) or not applying
 * (row removed). Returns the removed row's file_path so the caller can delete the file.
 */
function db_set_conditional_photo_applies(PDO $pdo, string $subjectType, int $subjectId, string $requirementKey, int $requirementVersion, bool $applies): ?string {
    $find = $pdo->prepare("SELECT id, file_path FROM inspection_photos WHERE subject_type = :t AND subject_id = :s AND requirement_key = :k");
    $find->execute([':t' => $subjectType, ':s' => $subjectId, ':k' => $requirementKey]);
    $existing = $find->fetch();

    if ($applies) {
        if (!$existing) {
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("
                INSERT OR IGNORE INTO inspection_photos
                    (subject_type, subject_id, requirement_key, requirement_version, file_path, applies, created_at, updated_at)
                VALUES (:t, :s, :k, :v, '', 1, :now, :now)
            ")->execute([':t' => $subjectType, ':s' => $subjectId, ':k' => $requirementKey, ':v' => $requirementVersion, ':now' => $now]);
        }
        return null;
    }

    if (!$existing) return null;
    $pdo->prepare("DELETE FROM inspection_photos WHERE id = :id")->execute([':id' => $existing['id']]);
    return $existing['file_path'] !== '' ? $existing['file_path'] : null;
}

/** Edit the typed details of an existing photo; any change puts the photo back to 'pending' review. */
function db_update_inspection_photo_typed(PDO $pdo, int $photoId, ?string $typedJson): void {
    $pdo->prepare("
        UPDATE inspection_photos SET typed_value = :tv, review_status = 'pending', reviewer_note = NULL, updated_at = :now
        WHERE id = :id
    ")->execute([':tv' => $typedJson, ':now' => date('Y-m-d H:i:s'), ':id' => $photoId]);
}

function db_set_inspection_photo_review(PDO $pdo, int $photoId, string $status, ?string $note): void {
    $pdo->prepare("
        UPDATE inspection_photos SET review_status = :st, reviewer_note = :note, updated_at = :now WHERE id = :id
    ")->execute([':st' => $status, ':note' => $note, ':now' => date('Y-m-d H:i:s'), ':id' => $photoId]);
}

/** Sets review_status on every photo that has a file (placeholder rows are left alone). */
function db_set_all_photos_review_status(PDO $pdo, string $subjectType, int $subjectId, string $status): void {
    $pdo->prepare("
        UPDATE inspection_photos SET review_status = :st, updated_at = :now
        WHERE subject_type = :t AND subject_id = :s AND file_path != ''
    ")->execute([':st' => $status, ':now' => date('Y-m-d H:i:s'), ':t' => $subjectType, ':s' => $subjectId]);
}

/** Gear rows carry their driver's identity under the column names every consumer already uses. */
const DB_GEAR_SELECT = "
    SELECT g.*, d.owner_user_id AS owner_user_id, d.name AS driver_name,
           d.name_norm AS driver_name_norm, d.licence_no AS licence_no
    FROM gear_records g JOIN drivers d ON d.id = g.driver_id";

function db_insert_gear_record(PDO $pdo, int $driverId, int $season, string $discipline = DISCIPLINE_SUMMER): int {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO gear_records (driver_id, season, discipline, created_at, updated_at) VALUES (:d, :s, :disc, :now, :now)")
        ->execute([':d' => $driverId, ':s' => $season, ':disc' => $discipline, ':now' => $now]);
    return (int)$pdo->lastInsertId();
}

function db_get_gear_record(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(DB_GEAR_SELECT . " WHERE g.id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function db_find_gear_record(PDO $pdo, int $ownerId, string $driverNameNorm, int $season, string $discipline = DISCIPLINE_SUMMER): ?array {
    $stmt = $pdo->prepare(DB_GEAR_SELECT . " WHERE d.owner_user_id = :o AND d.name_norm = :n AND g.season = :s AND g.discipline = :disc");
    $stmt->execute([':o' => $ownerId, ':n' => $driverNameNorm, ':s' => $season, ':disc' => $discipline]);
    return $stmt->fetch() ?: null;
}

/** All of one owner's gear records, newest season first, then by driver name. */
function db_get_user_gear_records(PDO $pdo, int $ownerId): array {
    $stmt = $pdo->prepare(DB_GEAR_SELECT . " WHERE d.owner_user_id = :o ORDER BY g.season DESC, d.name ASC, g.id ASC");
    $stmt->execute([':o' => $ownerId]);
    return $stmt->fetchAll();
}

/** Every owner's records for a season, with the owner's name and email, by driver name. */
function db_get_gear_records_for_season(PDO $pdo, int $season, string $discipline = DISCIPLINE_SUMMER): array {
    $stmt = $pdo->prepare("
        SELECT g.*, d.owner_user_id AS owner_user_id, d.name AS driver_name, d.name_norm AS driver_name_norm,
               d.licence_no AS licence_no, u.name AS owner_name, u.email AS owner_email
        FROM gear_records g JOIN drivers d ON d.id = g.driver_id LEFT JOIN users u ON u.id = d.owner_user_id
        WHERE g.season = :s AND g.discipline = :disc ORDER BY d.name ASC, g.id ASC
    ");
    $stmt->execute([':s' => $season, ':disc' => $discipline]);
    return $stmt->fetchAll();
}

/** First photo activity on a gear record: photo_status NULL -> 'draft'. No-op otherwise or once accepted. */
function db_mark_gear_photos_draft(PDO $pdo, int $id): void {
    $pdo->prepare("
        UPDATE gear_records SET photo_status = 'draft', updated_at = :now
        WHERE id = :id AND photo_status IS NULL AND status = 'open'
    ")->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
}

/** A gear record whose photo set can move: an open record, or TA/Drift gear being upgraded to race (TA/Drift phase 2). */
const DB_GEAR_PHOTOS_OPEN_SQL = "(status = 'open' OR (status = 'accepted' AND level = 'ta_drift'))";

/** Atomic photo_status transition: true only if the record's photos can still move (open, or a race upgrade) and its status was one of $from. */
function db_transition_gear_photo_status(PDO $pdo, int $id, array $from, string $to): bool {
    if (empty($from)) return false;
    $marks = implode(',', array_fill(0, count($from), '?'));
    $stmt = $pdo->prepare("
        UPDATE gear_records SET photo_status = ?, updated_at = ?
        WHERE id = ? AND " . DB_GEAR_PHOTOS_OPEN_SQL . " AND photo_status IN ($marks)
    ");
    $stmt->execute(array_merge([$to, date('Y-m-d H:i:s'), $id], array_values($from)));
    return $stmt->rowCount() === 1;
}

/** Remote acceptance after reviewing photos. Atomic; only from a submitted photo set on an open record or a race upgrade. */
function db_accept_gear_by_photos(PDO $pdo, int $id, int $reviewerUserId): bool {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        UPDATE gear_records SET
            status = 'accepted', accepted_via = 'photos', photo_status = 'accepted', revoke_note = NULL,
            reviewed_by_user_id = :reviewer, reviewed_at = :now, updated_at = :now
        WHERE id = :id AND " . DB_GEAR_PHOTOS_OPEN_SQL . " AND photo_status = 'submitted'
    ");
    $stmt->execute([':reviewer' => $reviewerUserId, ':now' => $now, ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/** In-person acceptance at the track: atomic, from any open record. */
function db_accept_gear_in_person(PDO $pdo, int $id, int $reviewerUserId): bool {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        UPDATE gear_records SET
            status = 'accepted', accepted_via = 'in_person', revoke_note = NULL,
            reviewed_by_user_id = :reviewer, reviewed_at = :now, updated_at = :now
        WHERE id = :id AND status = 'open'
    ");
    $stmt->execute([':reviewer' => $reviewerUserId, ':now' => $now, ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/** Undo an acceptance: back to open; a photo-accepted set returns to the review queue. $note says why. False if not accepted. */
function db_revoke_gear_acceptance(PDO $pdo, int $id, ?string $note = null): bool {
    $stmt = $pdo->prepare("
        UPDATE gear_records SET
            status = 'open', accepted_via = NULL,
            photo_status = CASE WHEN photo_status = 'accepted' THEN 'submitted' ELSE photo_status END,
            reviewed_by_user_id = NULL, reviewed_at = NULL, revoke_note = :note, updated_at = :now
        WHERE id = :id AND status = 'accepted'
    ");
    $stmt->execute([':note' => $note, ':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/**
 * The gear level an inspector confirmed: ice 'street_safe' or 'caged'; summer GEAR_LEVEL_TA_DRIFT;
 * or null to clear it (on a summer record, null is race level).
 */
function db_set_gear_level(PDO $pdo, int $id, ?string $level): void {
    if ($level !== null && !in_array($level, ['street_safe', 'caged', GEAR_LEVEL_TA_DRIFT], true)) {
        throw new InvalidArgumentException('Unknown gear level: ' . $level);
    }
    $pdo->prepare("UPDATE gear_records SET level = :l, updated_at = :now WHERE id = :id")
        ->execute([':l' => $level, ':now' => date('Y-m-d H:i:s'), ':id' => $id]);
}

/** A summer gear record's photo list: NULL (race) or GEAR_LEVEL_TA_DRIFT, and whether the car is caged. */
function db_set_gear_photo_tier(PDO $pdo, int $id, ?string $tier, bool $caged): void {
    if ($tier !== null && $tier !== GEAR_LEVEL_TA_DRIFT) throw new InvalidArgumentException('Unknown photo tier: ' . $tier);
    $pdo->prepare("UPDATE gear_records SET photo_tier = :t, caged = :c, updated_at = :now WHERE id = :id")
        ->execute([':t' => $tier, ':c' => $caged ? 1 : 0, ':now' => date('Y-m-d H:i:s'), ':id' => $id]);
}

/** Starts race gear photos on gear accepted at TA/Drift: back to the race list, photos in draft; it stays accepted at TA/Drift. */
function db_start_gear_race_upgrade(PDO $pdo, int $id): bool {
    $stmt = $pdo->prepare("
        UPDATE gear_records SET photo_tier = NULL, caged = 0, photo_status = 'draft', updated_at = :now
        WHERE id = :id AND discipline = 'summer' AND status = 'accepted' AND level = 'ta_drift'
          AND (photo_status IS NULL OR photo_status = 'accepted')
    ");
    $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/** An inspector checked the race gear in person: gear accepted at TA/Drift becomes race level. */
function db_upgrade_gear_to_race_in_person(PDO $pdo, int $id, int $reviewerUserId): bool {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        UPDATE gear_records SET level = NULL, accepted_via = 'in_person', revoke_note = NULL,
            reviewed_by_user_id = :reviewer, reviewed_at = :now, updated_at = :now
        WHERE id = :id AND discipline = 'summer' AND status = 'accepted' AND level = 'ta_drift'
    ");
    $stmt->execute([':reviewer' => $reviewerUserId, ':now' => $now, ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/** Additional drivers for many sheets at once: sheet id => rows ordered by driver number. Sheets with none are absent. */
function db_get_drivers_for_sheets(PDO $pdo, array $sheetIds): array {
    $ids = array_values(array_unique(array_map('intval', $sheetIds)));
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM tech_sheet_drivers WHERE tech_sheet_id IN ($marks) ORDER BY tech_sheet_id ASC, driver_number ASC");
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['tech_sheet_id']][] = $row;
    }
    return $map;
}

// ── Clubs ─────────────────────────────────────────────────────────────────────
/** The ice clubs seeded into `clubs` (their names match ice-rules.php ICE_CLUBS; ClubsTest checks). */
const DB_ICE_CLUB_SEED = ['NASCC' => 'Northern Alberta Sports Car Club', 'WSCC' => 'Winnipeg Sports Car Club'];

/** MotorsportReg organization IDs found on the clubs' MSR pages on 2026-09-29 (seeded once). */
const DB_MSR_ORG_SEED = ['NASCC' => '2386B6E3-96BC-AE58-0812CF4B556BCBC2', 'WSCC' => '4D45EE74-0A85-F011-ACBD5982F016139D'];

function db_get_clubs(PDO $pdo, bool $activeOnly = false): array {
    return $pdo->query("SELECT * FROM clubs" . ($activeOnly ? " WHERE active = 1" : "") . " ORDER BY name COLLATE NOCASE ASC")->fetchAll();
}

function db_get_club(PDO $pdo, string $code): ?array {
    $stmt = $pdo->prepare("SELECT * FROM clubs WHERE code = :c");
    $stmt->execute([':c' => $code]);
    return $stmt->fetch() ?: null;
}

function db_create_club(PDO $pdo, string $code, string $name, string $url): void {
    $pdo->prepare("INSERT INTO clubs (code, name, msr_url, active, created_at) VALUES (:c, :n, :u, 1, :t)")
        ->execute([':c' => $code, ':n' => $name, ':u' => $url, ':t' => date('Y-m-d H:i:s')]);
}

function db_update_club(PDO $pdo, string $code, string $name, string $url, bool $active): void {
    $pdo->prepare("UPDATE clubs SET name = :n, msr_url = :u, active = :a WHERE code = :c")
        ->execute([':c' => $code, ':n' => $name, ':u' => $url, ':a' => $active ? 1 : 0]);
}

function db_set_club_msr_org_id(PDO $pdo, string $code, string $orgId): void {
    $pdo->prepare("UPDATE clubs SET msr_org_id = :o WHERE code = :c")->execute([':o' => $orgId, ':c' => $code]);
}

// ── MotorsportReg calendar import ─────────────────────────────────────────────

function db_get_msr_events(PDO $pdo, ?string $club = null): array {
    if ($club === null) return $pdo->query("SELECT * FROM msr_events ORDER BY start_date, name")->fetchAll();
    $stmt = $pdo->prepare("SELECT * FROM msr_events WHERE club_code = :c ORDER BY start_date, name");
    $stmt->execute([':c' => $club]);
    return $stmt->fetchAll();
}

function db_get_msr_event(PDO $pdo, string $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM msr_events WHERE msr_id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

/** Insert a feed event, or refresh its details; status, hub link and snapshot are kept. */
function db_upsert_msr_event(PDO $pdo, string $club, array $e, string $now): void {
    $pdo->prepare("
        INSERT INTO msr_events (msr_id, club_code, name, start_date, end_date, type, venue, detail_url, cancelled, first_seen_at, last_seen_at)
        VALUES (:id, :club, :name, :start, :end, :type, :venue, :url, :cancelled, :now, :now)
        ON CONFLICT(msr_id) DO UPDATE SET club_code = excluded.club_code, name = excluded.name,
            start_date = excluded.start_date, end_date = excluded.end_date, type = excluded.type,
            venue = excluded.venue, detail_url = excluded.detail_url, cancelled = excluded.cancelled,
            last_seen_at = excluded.last_seen_at
    ")->execute([':id' => $e['msr_id'], ':club' => $club, ':name' => $e['name'], ':start' => $e['start_date'],
                 ':end' => $e['end_date'], ':type' => $e['type'], ':venue' => $e['venue'], ':url' => $e['detail_url'],
                 ':cancelled' => $e['cancelled'], ':now' => $now]);
}

function db_set_msr_status(PDO $pdo, string $id, string $status): void {
    $pdo->prepare("UPDATE msr_events SET status = :s WHERE msr_id = :id")->execute([':s' => $status, ':id' => $id]);
}

function db_delete_msr_event(PDO $pdo, string $id): void {
    $pdo->prepare("DELETE FROM msr_events WHERE msr_id = :id")->execute([':id' => $id]);
}

/** The row now belongs to hub event $eventId; its current details become the snapshot. */
function db_mark_msr_added(PDO $pdo, string $id, int $eventId, bool $primary): void {
    $pdo->prepare("UPDATE msr_events SET status = 'added', hub_event_id = :e, is_primary = :p,
        snap_name = name, snap_start = start_date, snap_venue = venue, snap_cancelled = cancelled WHERE msr_id = :id")
        ->execute([':e' => $eventId, ':p' => $primary ? 1 : 0, ':id' => $id]);
}

/** The admin has seen the current details: they become the snapshot. */
function db_snapshot_msr_event(PDO $pdo, string $id): void {
    $pdo->prepare("UPDATE msr_events SET snap_name = name, snap_start = start_date, snap_venue = venue, snap_cancelled = cancelled
        WHERE msr_id = :id")->execute([':id' => $id]);
}

// ── Season links ──────────────────────────────────────────────────────────────

function db_get_season_links(PDO $pdo, bool $activeOnly = false): array {
    return $pdo->query("SELECT * FROM season_links" . ($activeOnly ? " WHERE active = 1" : "") . " ORDER BY sort_order ASC, id ASC")->fetchAll();
}

function db_create_season_link(PDO $pdo, string $label, string $url, int $sortOrder): int {
    $pdo->prepare("INSERT INTO season_links (label, url, sort_order) VALUES (:l, :u, :s)")
        ->execute([':l' => $label, ':u' => $url, ':s' => $sortOrder]);
    return (int)$pdo->lastInsertId();
}

function db_update_season_link(PDO $pdo, int $id, string $label, string $url, int $sortOrder, bool $active): void {
    $pdo->prepare("UPDATE season_links SET label = :l, url = :u, sort_order = :s, active = :a WHERE id = :id")
        ->execute([':l' => $label, ':u' => $url, ':s' => $sortOrder, ':a' => $active ? 1 : 0, ':id' => $id]);
}

function db_delete_season_link(PDO $pdo, int $id): void {
    $pdo->prepare("DELETE FROM season_links WHERE id = :id")->execute([':id' => $id]);
}

// ── Inspector (spec §5) ───────────────────────────────────────────────────────

/** Cars on an event's roster: tagged for the event or with a tech sheet for it, with the owner and `tagged` (0/1). */
function db_get_event_roster_cars(PDO $pdo, int $eventId): array {
    $stmt = $pdo->prepare("
        SELECT c.*, u.name AS owner_name, u.email AS owner_email,
               EXISTS (SELECT 1 FROM event_plans p WHERE p.event_id = :e AND p.car_id = c.id) AS tagged,
               (SELECT p.formats FROM event_plans p WHERE p.event_id = :e AND p.car_id = c.id) AS formats
        FROM cars c JOIN users u ON u.id = c.owner_user_id
        WHERE c.id IN (SELECT car_id FROM event_plans WHERE event_id = :e
                       UNION SELECT car_id FROM tech_sheets WHERE event_id = :e)
        ORDER BY (c.car_number_norm GLOB '[0-9]*') DESC, CAST(c.car_number_norm AS INTEGER) ASC, c.car_number_norm ASC, c.id ASC
    ");
    $stmt->execute([':e' => $eventId]);
    return $stmt->fetchAll();
}

/** car id => that car's declarations, newest first. Cars with none are absent. */
function db_get_declarations_for_cars(PDO $pdo, array $carIds): array {
    $ids = array_values(array_unique(array_map('intval', $carIds)));
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE car_id IN ($marks) ORDER BY submitted_at DESC, id DESC");
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['car_id']][] = $row;
    }
    return $map;
}

/** user id => that account's own driver profile (drivers.user_id). Accounts without one are absent. */
function db_get_self_drivers_for_users(PDO $pdo, array $userIds): array {
    $ids = array_values(array_unique(array_map('intval', $userIds)));
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM drivers WHERE user_id IN ($marks)");
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['user_id']] = $row;
    }
    return $map;
}

/** Declarations waiting on an inspector, oldest first, with the car's number. */
function db_get_declarations_awaiting_review(PDO $pdo): array {
    return $pdo->query("
        SELECT s.*, c.car_number AS car_number
        FROM submissions s JOIN cars c ON c.id = s.car_id
        WHERE s.review_status = 'submitted'
        ORDER BY s.submitted_at ASC, s.id ASC
    ")->fetchAll();
}

/** Tech sheets whose car pre-tech photos wait on an inspector, oldest first, with the event name. */
function db_get_sheets_awaiting_photo_review(PDO $pdo): array {
    return $pdo->query("
        SELECT ts.*, e.name AS event_name
        FROM tech_sheets ts LEFT JOIN events e ON e.id = ts.event_id
        WHERE ts.photo_status = 'submitted' AND ts.status = 'submitted'
        ORDER BY ts.updated_at ASC, ts.id ASC
    ")->fetchAll();
}

/** Gear records whose photos wait on an inspector, oldest first, with the driver and the owning account. */
function db_get_gear_awaiting_photo_review(PDO $pdo): array {
    return $pdo->query("
        SELECT g.*, d.owner_user_id AS owner_user_id, d.name AS driver_name, d.name_norm AS driver_name_norm,
               d.licence_no AS licence_no, u.name AS owner_name
        FROM gear_records g JOIN drivers d ON d.id = g.driver_id LEFT JOIN users u ON u.id = d.owner_user_id
        WHERE g.photo_status = 'submitted' AND (g.status = 'open' OR (g.status = 'accepted' AND g.level = 'ta_drift'))
        ORDER BY g.updated_at ASC, g.id ASC
    ")->fetchAll();
}

/**
 * The Classing tab's search. $f keys (all optional): q (name, email, make, model or car number;
 * % and _ match literally), class, season (year submitted), status (review_status), car (car id).
 *
 * @return array{rows: array, total: int} rows are submissions.* plus car_number, newest first
 */
function db_search_declarations(PDO $pdo, array $f, int $limit, int $offset): array {
    $where = [];
    $params = [];
    if ((string)($f['q'] ?? '') !== '') {
        $where[] = "(s.name LIKE :q ESCAPE '\\' OR s.email LIKE :q ESCAPE '\\' OR s.make LIKE :q ESCAPE '\\'
                     OR s.model LIKE :q ESCAPE '\\' OR c.car_number LIKE :q ESCAPE '\\')";
        $params[':q'] = '%' . addcslashes((string)$f['q'], '%_\\') . '%';
    }
    if ((string)($f['class'] ?? '') !== '') {
        $where[] = 's.calculated_class = :class';
        $params[':class'] = (string)$f['class'];
    }
    if ((int)($f['season'] ?? 0) > 0) {
        $where[] = 'substr(s.submitted_at, 1, 4) = :season';
        $params[':season'] = (string)(int)$f['season'];
    }
    if ((string)($f['status'] ?? '') !== '') {
        $where[] = 's.review_status = :status';
        $params[':status'] = (string)$f['status'];
    }
    if ((int)($f['car'] ?? 0) > 0) {
        $where[] = 's.car_id = :car';
        $params[':car'] = (int)$f['car'];
    }
    $from = ' FROM submissions s LEFT JOIN cars c ON c.id = s.car_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

    $count = $pdo->prepare('SELECT COUNT(*)' . $from);
    $count->execute($params);

    $stmt = $pdo->prepare('SELECT s.*, c.car_number AS car_number' . $from . ' ORDER BY s.submitted_at DESC, s.id DESC LIMIT :limit OFFSET :offset');
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return ['rows' => $stmt->fetchAll(), 'total' => (int)$count->fetchColumn()];
}

/** event id => how many cars are tagged for it. Events nobody tagged are absent. */
function db_count_event_plans(PDO $pdo): array {
    $counts = [];
    foreach ($pdo->query("SELECT event_id, COUNT(*) AS n FROM event_plans GROUP BY event_id")->fetchAll() as $row) {
        $counts[(int)$row['event_id']] = (int)$row['n'];
    }
    return $counts;
}

// ── Reminders (spec §7) ───────────────────────────────────────────────────────

/** Turns reminder emails on or off. Either way the user has now chosen, so the one-time offer stops. */
function db_set_user_reminders(PDO $pdo, int $userId, bool $on): void {
    $pdo->prepare("UPDATE users SET reminder_emails = :on, reminder_prompted_at = COALESCE(reminder_prompted_at, :now) WHERE id = :id")
        ->execute([':on' => $on ? 1 : 0, ':now' => date('Y-m-d H:i:s'), ':id' => $userId]);
}

/** Active accounts that turned reminder emails on, by id. */
function db_get_reminder_users(PDO $pdo): array {
    return $pdo->query("SELECT * FROM users WHERE reminder_emails = 1 AND active = 1 ORDER BY id ASC")->fetchAll();
}

function db_reminder_logged(PDO $pdo, int $userId, int $eventId, int $daysOut): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM reminder_log WHERE user_id = :u AND event_id = :e AND days_out = :d");
    $stmt->execute([':u' => $userId, ':e' => $eventId, ':d' => $daysOut]);
    return $stmt->fetchColumn() !== false;
}

/** Records a sent reminder. False when it was already recorded. */
function db_log_reminder(PDO $pdo, int $userId, int $eventId, int $daysOut): bool {
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO reminder_log (user_id, event_id, days_out, sent_at) VALUES (:u, :e, :d, :now)");
    $stmt->execute([':u' => $userId, ':e' => $eventId, ':d' => $daysOut, ':now' => date('Y-m-d H:i:s')]);
    return $stmt->rowCount() === 1;
}

// ── Schema helper ─────────────────────────────────────────────────────────────

/**
 * Adds a column to an existing table if it isn't there yet, keeping every row. Safe to call on
 * each request. Identifiers are checked because SQLite can't bind them. @return bool true if added
 */
function db_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): bool {
    foreach ([$table, $column] as $ident) {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $ident)) throw new InvalidArgumentException('Unsafe identifier: ' . $ident);
    }
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $col) {
        if ($col['name'] === $column) return false;
    }
    try {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    } catch (PDOException $e) {
        // Another deploy running the same migration got there first between the PRAGMA check
        // above and this ALTER; the column exists either way, so treat it like the PRAGMA hit.
        if (stripos($e->getMessage(), 'duplicate column name') !== false) return false;
        throw $e;
    }
    return true;
}

function db_has_column(PDO $pdo, string $table, string $column): bool {
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $table)) throw new InvalidArgumentException('Unsafe identifier: ' . $table);
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $col) {
        if ($col['name'] === $column) return true;
    }
    return false;
}

/**
 * Rebuilds $table from the `CREATE TABLE IF NOT EXISTS {table} (...)` template in $createSql, for
 * changes SQLite can't make in place (dropping NOT NULL, changing UNIQUE). Every row and id is kept.
 * Columns the old table lacks take their defaults. Guarded by $markerColumn (a column only the new
 * schema has): does nothing if it is already there, including when a concurrent deploy migrated
 * first, because it re-checks inside BEGIN IMMEDIATE. Recreate indexes after calling this.
 * @return bool true if the table was rebuilt
 */
function db_rebuild_table(PDO $pdo, string $table, string $markerColumn, string $createSql): bool {
    foreach ([$table, $markerColumn] as $ident) {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $ident)) throw new InvalidArgumentException('Unsafe identifier: ' . $ident);
    }
    if (db_has_column($pdo, $table, $markerColumn)) return false;
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        if (db_has_column($pdo, $table, $markerColumn)) {
            $pdo->exec('ROLLBACK');
            return false;
        }
        $tmp = $table . '__rebuild';
        $old = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        $pdo->exec("DROP TABLE IF EXISTS $tmp");
        $pdo->exec(str_replace('{table}', $tmp, $createSql));
        $new = array_column($pdo->query("PRAGMA table_info($tmp)")->fetchAll(), 'name');
        $missing = array_diff($old, $new);
        if (!empty($missing)) {
            throw new RuntimeException('db_rebuild_table would drop column(s): ' . implode(', ', $missing));
        }
        $cols = implode(', ', array_values(array_intersect($old, $new)));
        $pdo->exec("INSERT INTO $tmp ($cols) SELECT $cols FROM $table");
        $pdo->exec("DROP TABLE $table");
        $pdo->exec("ALTER TABLE $tmp RENAME TO $table");
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    return true;
}

// ── Media profiles (2026-09-27 spec) ──────────────────────────────────────────

function db_get_media_profile(PDO $pdo, int $driverId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM driver_media_profiles WHERE driver_id = :d");
    $stmt->execute([':d' => $driverId]);
    return $stmt->fetch() ?: null;
}

function db_save_media_profile(PDO $pdo, int $driverId, array $f): void {
    $now = date('Y-m-d H:i:s');
    $params = [
        ':d' => $driverId, ':b' => (string)$f['blurb'], ':p' => $f['pronunciation'], ':h' => $f['hometown'],
        ':r' => $f['racing_since'], ':s' => $f['social_handle'], ':photo' => $f['photo_path'],
        ':st' => (string)$f['public_status'], ':now' => $now,
    ];
    $pdo->prepare("
        INSERT INTO driver_media_profiles (driver_id, blurb, pronunciation, hometown, racing_since, social_handle,
                                           photo_path, public_status, created_at, updated_at)
        VALUES (:d, :b, :p, :h, :r, :s, :photo, :st, :now, :now)
        ON CONFLICT(driver_id) DO UPDATE SET blurb = excluded.blurb, pronunciation = excluded.pronunciation,
            hometown = excluded.hometown, racing_since = excluded.racing_since, social_handle = excluded.social_handle,
            photo_path = excluded.photo_path, public_status = excluded.public_status, updated_at = excluded.updated_at
    ")->execute($params);
}

function db_set_media_public_status(PDO $pdo, int $driverId, string $status, ?int $reviewerId, ?string $note): void {
    $pdo->prepare("
        UPDATE driver_media_profiles
        SET public_status = :s, public_reviewed_by = :r, public_reviewed_at = :at, public_note = :n
        WHERE driver_id = :d
    ")->execute([':s' => $status, ':r' => $reviewerId, ':at' => date('Y-m-d H:i:s'), ':n' => $note, ':d' => $driverId]);
}

function db_set_media_hidden(PDO $pdo, int $driverId, ?int $byUserId, ?string $reason): void {
    $pdo->prepare("UPDATE driver_media_profiles SET hidden_at = :at, hidden_by = :b, hidden_reason = :r WHERE driver_id = :d")
        ->execute([':at' => $byUserId === null ? null : date('Y-m-d H:i:s'), ':b' => $byUserId, ':r' => $reason, ':d' => $driverId]);
}

/** Removes the profile and sponsors. Consent rows stay: they are the record. */
function db_delete_media_profile(PDO $pdo, int $driverId): void {
    $pdo->prepare("DELETE FROM driver_sponsors WHERE driver_id = :d")->execute([':d' => $driverId]);
    $pdo->prepare("DELETE FROM driver_media_profiles WHERE driver_id = :d")->execute([':d' => $driverId]);
}

/** Deleting a profile WCMA has hidden must not undo the hide: instead of removing the row, this
 *  clears its content and sponsors but keeps the row and the hidden_* columns as a tombstone, so
 *  the driver cannot re-create the profile and slip straight back onto the announcer/kit. */
function db_tombstone_media_profile(PDO $pdo, int $driverId): void {
    $pdo->prepare("DELETE FROM driver_sponsors WHERE driver_id = :d")->execute([':d' => $driverId]);
    $pdo->prepare("
        UPDATE driver_media_profiles
        SET blurb = '', pronunciation = NULL, hometown = NULL, racing_since = NULL, social_handle = NULL,
            photo_path = NULL, public_status = 'none', updated_at = :now
        WHERE driver_id = :d
    ")->execute([':now' => date('Y-m-d H:i:s'), ':d' => $driverId]);
}

function db_get_sponsors(PDO $pdo, int $driverId): array {
    $stmt = $pdo->prepare("SELECT * FROM driver_sponsors WHERE driver_id = :d ORDER BY sort_order ASC, id ASC");
    $stmt->execute([':d' => $driverId]);
    return $stmt->fetchAll();
}

/** @param array<int, array{name: string, url: ?string}> $sponsors */
function db_replace_sponsors(PDO $pdo, int $driverId, array $sponsors): void {
    $pdo->prepare("DELETE FROM driver_sponsors WHERE driver_id = :d")->execute([':d' => $driverId]);
    $ins = $pdo->prepare("INSERT INTO driver_sponsors (driver_id, name, url, sort_order) VALUES (:d, :n, :u, :o)");
    foreach (array_values($sponsors) as $i => $s) {
        $ins->execute([':d' => $driverId, ':n' => (string)$s['name'], ':u' => $s['url'] ?? null, ':o' => $i]);
    }
}

function db_insert_media_consent(PDO $pdo, array $row): int {
    $pdo->prepare("
        INSERT INTO media_consents (driver_id, consent_media, consent_public, is_minor, guardian_name,
                                    given_by_user_id, on_behalf, wording_version, created_at)
        VALUES (:d, :m, :p, :minor, :g, :by, :ob, :v, :at)
    ")->execute([
        ':d' => (int)$row['driver_id'], ':m' => (int)$row['consent_media'], ':p' => (int)$row['consent_public'],
        ':minor' => (int)$row['is_minor'], ':g' => $row['guardian_name'], ':by' => (int)$row['given_by_user_id'],
        ':ob' => (int)$row['on_behalf'], ':v' => (int)$row['wording_version'], ':at' => date('Y-m-d H:i:s'),
    ]);
    return (int)$pdo->lastInsertId();
}

function db_get_latest_media_consent(PDO $pdo, int $driverId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM media_consents WHERE driver_id = :d ORDER BY id DESC LIMIT 1");
    $stmt->execute([':d' => $driverId]);
    return $stmt->fetch() ?: null;
}

/** driver id => ['profile' => ?row, 'consent' => ?row (newest), 'sponsors' => rows]. */
function db_get_media_bundle(PDO $pdo, array $driverIds): array {
    $out = [];
    foreach (array_values(array_unique(array_map('intval', $driverIds))) as $id) {
        $out[$id] = ['profile' => db_get_media_profile($pdo, $id), 'consent' => db_get_latest_media_consent($pdo, $id),
                     'sponsors' => db_get_sponsors($pdo, $id)];
    }
    return $out;
}

/** Drivers whose newest consent row allows announcing and club promotion. */
function db_get_consented_driver_ids(PDO $pdo): array {
    return array_map('intval', $pdo->query("
        SELECT c.driver_id FROM media_consents c
        WHERE c.id = (SELECT MAX(id) FROM media_consents WHERE driver_id = c.driver_id) AND c.consent_media = 1
        ORDER BY c.driver_id ASC
    ")->fetchAll(PDO::FETCH_COLUMN));
}

function db_get_media_review_queue(PDO $pdo): array {
    return $pdo->query("
        SELECT p.*, d.name AS driver_name FROM driver_media_profiles p JOIN drivers d ON d.id = p.driver_id
        WHERE p.public_status = 'pending_review' AND p.hidden_at IS NULL
        ORDER BY p.updated_at ASC, p.id ASC
    ")->fetchAll();
}

function db_search_media_profiles(PDO $pdo, string $q, int $limit = 20): array {
    $stmt = $pdo->prepare("
        SELECT p.*, d.name AS driver_name FROM driver_media_profiles p JOIN drivers d ON d.id = p.driver_id
        WHERE d.name_norm LIKE :q ESCAPE '\\' ORDER BY d.name_norm ASC LIMIT :lim
    ");
    $like = '%' . addcslashes(db_driver_name_norm($q), '%_\\') . '%';
    $stmt->bindValue(':q', $like);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function db_set_user_media(PDO $pdo, int $userId, bool $on): void {
    $pdo->prepare("UPDATE users SET is_media = :m WHERE id = :id")->execute([':m' => $on ? 1 : 0, ':id' => $userId]);
}

function db_dismiss_media_prompt(PDO $pdo, int $userId): void {
    $pdo->prepare("UPDATE users SET media_prompt_dismissed = 1 WHERE id = :id")->execute([':id' => $userId]);
}

/** The driver's newest tech sheet (as driver 1 or an added driver), of either discipline; limited to $season when given. */
function db_get_driver_latest_sheet(PDO $pdo, int $driverId, ?int $season = null): ?array {
    $where = '(driver_id = :d OR id IN (SELECT tech_sheet_id FROM tech_sheet_drivers WHERE driver_id = :d))';
    $params = [':d' => $driverId];
    if ($season !== null) { $where .= ' AND season = :s'; $params[':s'] = $season; }
    $stmt = $pdo->prepare("SELECT * FROM tech_sheets WHERE $where ORDER BY id DESC LIMIT 1");
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

/** The driver's newest sheet from a current season: summer $summerSeason or ice $iceSeason (so last year's car doesn't show). */
function db_get_driver_current_sheet(PDO $pdo, int $driverId, int $summerSeason, int $iceSeason): ?array {
    $stmt = $pdo->prepare("SELECT * FROM tech_sheets
        WHERE (driver_id = :d OR id IN (SELECT tech_sheet_id FROM tech_sheet_drivers WHERE driver_id = :d))
          AND ((discipline = 'ice' AND season = :ice) OR (discipline <> 'ice' AND season = :summer))
        ORDER BY id DESC LIMIT 1");
    $stmt->execute([':d' => $driverId, ':ice' => $iceSeason, ':summer' => $summerSeason]);
    return $stmt->fetch() ?: null;
}
