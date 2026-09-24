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
            email_send_count        INTEGER NOT NULL DEFAULT 0
        )
    ");

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
            role          TEXT NOT NULL DEFAULT 'user',
            created_at    DATETIME NOT NULL,
            active        INTEGER NOT NULL DEFAULT 1
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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tech_sheets (
            id                      INTEGER PRIMARY KEY AUTOINCREMENT,
            submission_id           INTEGER NOT NULL,
            user_id                 INTEGER NOT NULL,
            event_id                INTEGER NOT NULL,
            sheet_type              TEXT NOT NULL,

            entrant_name            TEXT NOT NULL,
            driver_name             TEXT NOT NULL,
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

            created_at              DATETIME NOT NULL,
            updated_at              DATETIME NOT NULL
        )
    ");

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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gear_records (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_user_id       INTEGER NOT NULL,
            driver_name         TEXT NOT NULL,
            driver_name_norm    TEXT NOT NULL,
            licence_no          TEXT,
            season              INTEGER NOT NULL,
            photo_status        TEXT,
            status              TEXT NOT NULL DEFAULT 'open',
            accepted_via        TEXT,
            reviewed_by_user_id INTEGER,
            reviewed_at         DATETIME,
            created_at          DATETIME NOT NULL,
            updated_at          DATETIME NOT NULL,
            UNIQUE (owner_user_id, driver_name_norm, season)
        )
    ");

    // Add user_id to submissions if migrating an existing DB
    $columns = $pdo->query("PRAGMA table_info(submissions)")->fetchAll();
    $hasUserId = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'user_id') { $hasUserId = true; break; }
    }
    if (!$hasUserId) {
        $pdo->exec("ALTER TABLE submissions ADD COLUMN user_id INTEGER");
    }

    // Add email-history tracking columns if migrating an existing DB
    $hasLastEmailedAt = false;
    $hasEmailSendCount = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'last_emailed_at') { $hasLastEmailedAt = true; }
        if ($col['name'] === 'email_send_count') { $hasEmailSendCount = true; }
    }
    if (!$hasLastEmailedAt) {
        $pdo->exec("ALTER TABLE submissions ADD COLUMN last_emailed_at DATETIME");
    }
    if (!$hasEmailSendCount) {
        $pdo->exec("ALTER TABLE submissions ADD COLUMN email_send_count INTEGER NOT NULL DEFAULT 0");
    }

    // Add active flag to users if migrating an existing DB
    $userColumns = $pdo->query("PRAGMA table_info(users)")->fetchAll();
    $hasActive = false;
    foreach ($userColumns as $col) {
        if ($col['name'] === 'active') { $hasActive = true; break; }
    }
    if (!$hasActive) {
        $pdo->exec("ALTER TABLE users ADD COLUMN active INTEGER NOT NULL DEFAULT 1");
    }

    // Annual tech status columns and car identity on tech_sheets, if migrating an existing DB
    $techCols = array_column($pdo->query("PRAGMA table_info(tech_sheets)")->fetchAll(), 'name');
    foreach (['accepted_via' => 'TEXT', 'photo_status' => 'TEXT', 'car_number_norm' => 'TEXT', 'season' => 'INTEGER'] as $col => $type) {
        if (!in_array($col, $techCols, true)) {
            $pdo->exec("ALTER TABLE tech_sheets ADD COLUMN {$col} {$type}");
        }
    }
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tech_sheets_car ON tech_sheets (user_id, car_number_norm, season)");
    $unfilled = $pdo->query("
        SELECT ts.id, ts.car_number, ts.created_at, e.event_date
        FROM tech_sheets ts LEFT JOIN events e ON e.id = ts.event_id
        WHERE ts.car_number_norm IS NULL OR ts.season IS NULL
    ")->fetchAll();
    if ($unfilled) {
        $fill = $pdo->prepare("UPDATE tech_sheets SET car_number_norm = :n, season = :s WHERE id = :id");
        foreach ($unfilled as $row) {
            $fill->execute([
                ':n' => techCarNumberNorm((string)$row['car_number']),
                ':s' => techSeasonFromDate($row['event_date'] ?? $row['created_at']),
                ':id' => $row['id'],
            ]);
        }
    }
}

function db_insert_submission(PDO $pdo, array $data): int {
    $data[':user_id'] = $data[':user_id'] ?? null;
    $stmt = $pdo->prepare("
        INSERT INTO submissions (
            submitted_at, name, email, year, make, model, comments,
            competition_weight, declared_hp, dyno_hp,
            chassis_display, body_mods_display, transmission_display,
            drivetrain_display, tires_display, brake_suspension,
            chassis_value, body_mods_value, transmission_value,
            drivetrain_value, tires_value, brake_suspension_value,
            weight_factor, modification_factor, base_ratio, modified_ratio,
            calculated_class, email_sent, user_id
        ) VALUES (
            :submitted_at, :name, :email, :year, :make, :model, :comments,
            :competition_weight, :declared_hp, :dyno_hp,
            :chassis_display, :body_mods_display, :transmission_display,
            :drivetrain_display, :tires_display, :brake_suspension,
            :chassis_value, :body_mods_value, :transmission_value,
            :drivetrain_value, :tires_value, :brake_suspension_value,
            :weight_factor, :modification_factor, :base_ratio, :modified_ratio,
            :calculated_class, 0, :user_id
        )
    ");
    $stmt->execute($data);
    return (int)$pdo->lastInsertId();
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

function db_link_submissions_by_email(PDO $pdo, int $user_id, string $email): int {
    $stmt = $pdo->prepare("UPDATE submissions SET user_id = :user_id WHERE user_id IS NULL AND email = :email COLLATE NOCASE");
    $stmt->execute([':user_id' => $user_id, ':email' => $email]);
    return $stmt->rowCount();
}

function db_delete_submission(PDO $pdo, int $id): void {
    $pdo->prepare("DELETE FROM submissions WHERE id = :id")->execute([':id' => $id]);
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

function db_create_event(PDO $pdo, string $name, string $event_date, ?string $location): int {
    $pdo->prepare("
        INSERT INTO events (name, event_date, location, active, created_at)
        VALUES (:name, :event_date, :location, 1, :created_at)
    ")->execute([
        ':name' => $name, ':event_date' => $event_date, ':location' => $location,
        ':created_at' => date('Y-m-d H:i:s'),
    ]);
    return (int)$pdo->lastInsertId();
}

function db_get_active_events(PDO $pdo): array {
    return $pdo->query("SELECT * FROM events WHERE active = 1 ORDER BY event_date ASC")->fetchAll();
}

function db_get_all_events(PDO $pdo): array {
    return $pdo->query("SELECT * FROM events ORDER BY event_date DESC")->fetchAll();
}

function db_get_event(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function db_update_event(PDO $pdo, int $id, string $name, string $event_date, ?string $location): void {
    $pdo->prepare("UPDATE events SET name = :name, event_date = :event_date, location = :location WHERE id = :id")
        ->execute([':name' => $name, ':event_date' => $event_date, ':location' => $location, ':id' => $id]);
}

function db_set_event_active(PDO $pdo, int $id, bool $active): void {
    $pdo->prepare("UPDATE events SET active = :active WHERE id = :id")
        ->execute([':active' => $active ? 1 : 0, ':id' => $id]);
}

// ── Tech Sheets ───────────────────────────────────────────────────────────────

function db_insert_tech_sheet(PDO $pdo, array $data): int {
    $now = date('Y-m-d H:i:s');
    $identity = db_tech_sheet_identity($pdo, (string)$data['car_number'], (int)$data['event_id']);
    $stmt = $pdo->prepare("
        INSERT INTO tech_sheets (
            submission_id, user_id, event_id, sheet_type,
            entrant_name, driver_name, car_make, car_model, car_colour, car_number,
            class, engine_cc, engine_hp, car_weight,
            checklist_json, driver1_equipment_json, log_book_turned_in,
            car_number_norm, season,
            status, created_at, updated_at
        ) VALUES (
            :submission_id, :user_id, :event_id, :sheet_type,
            :entrant_name, :driver_name, :car_make, :car_model, :car_colour, :car_number,
            :class, :engine_cc, :engine_hp, :car_weight,
            :checklist_json, :driver1_equipment_json, :log_book_turned_in,
            :car_number_norm, :season,
            'submitted', :created_at, :updated_at
        )
    ");
    $stmt->execute([
        ':submission_id' => $data['submission_id'], ':user_id' => $data['user_id'], ':event_id' => $data['event_id'],
        ':sheet_type' => $data['sheet_type'], ':entrant_name' => $data['entrant_name'], ':driver_name' => $data['driver_name'],
        ':car_make' => $data['car_make'], ':car_model' => $data['car_model'], ':car_colour' => $data['car_colour'],
        ':car_number' => $data['car_number'], ':class' => $data['class'], ':engine_cc' => $data['engine_cc'] ?? null,
        ':engine_hp' => $data['engine_hp'] ?? null, ':car_weight' => $data['car_weight'],
        ':checklist_json' => $data['checklist_json'], ':driver1_equipment_json' => $data['driver1_equipment_json'],
        ':log_book_turned_in' => $data['log_book_turned_in'] ?? null,
        ':car_number_norm' => $identity['car_number_norm'], ':season' => $identity['season'],
        ':created_at' => $now, ':updated_at' => $now,
    ]);
    return (int)$pdo->lastInsertId();
}

function db_get_tech_sheet(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM tech_sheets WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
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
    $identity = db_tech_sheet_identity($pdo, (string)$data['car_number'], (int)$data['event_id']);
    $pdo->prepare("
        UPDATE tech_sheets SET
            event_id = :event_id, sheet_type = :sheet_type,
            entrant_name = :entrant_name, driver_name = :driver_name,
            car_make = :car_make, car_model = :car_model, car_colour = :car_colour, car_number = :car_number,
            class = :class, engine_cc = :engine_cc, engine_hp = :engine_hp, car_weight = :car_weight,
            checklist_json = :checklist_json, driver1_equipment_json = :driver1_equipment_json,
            log_book_turned_in = :log_book_turned_in,
            car_number_norm = :car_number_norm, season = :season, updated_at = :updated_at
        WHERE id = :id
    ")->execute([
        ':event_id' => $data['event_id'], ':sheet_type' => $data['sheet_type'],
        ':entrant_name' => $data['entrant_name'], ':driver_name' => $data['driver_name'],
        ':car_make' => $data['car_make'], ':car_model' => $data['car_model'], ':car_colour' => $data['car_colour'],
        ':car_number' => $data['car_number'], ':class' => $data['class'], ':engine_cc' => $data['engine_cc'] ?? null,
        ':engine_hp' => $data['engine_hp'] ?? null, ':car_weight' => $data['car_weight'],
        ':checklist_json' => $data['checklist_json'], ':driver1_equipment_json' => $data['driver1_equipment_json'],
        ':log_book_turned_in' => $data['log_book_turned_in'] ?? null,
        ':car_number_norm' => $identity['car_number_norm'], ':season' => $identity['season'],
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
    $pdo->prepare("
        INSERT INTO tech_sheet_drivers (tech_sheet_id, driver_number, driver_name, equipment_json)
        VALUES (:tech_sheet_id, :driver_number, :driver_name, :equipment_json)
    ")->execute([
        ':tech_sheet_id' => $tech_sheet_id, ':driver_number' => $driver_number,
        ':driver_name' => $driver_name, ':equipment_json' => $equipment_json,
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
    return (int)$pdo->lastInsertId();
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

/** Normalised car number and season (calendar year of the sheet's event) for a tech sheet. */
function db_tech_sheet_identity(PDO $pdo, string $carNumber, int $eventId): array {
    $event = db_get_event($pdo, $eventId);
    return [
        'car_number_norm' => techCarNumberNorm($carNumber),
        'season' => techSeasonFromDate($event['event_date'] ?? null),
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
            tech_signature_path = :sig, tech_signed_at = :now, updated_at = :now
        WHERE id = :id AND status = 'submitted'
    ");
    $stmt->execute([':reviewer' => $reviewerUserId, ':now' => $now, ':sig' => $signaturePath, ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/** Returns an accepted sheet to 'submitted' and clears its review fields. False if it was not accepted. */
function db_revoke_tech_sheet_acceptance(PDO $pdo, int $id): bool {
    $stmt = $pdo->prepare("
        UPDATE tech_sheets SET
            status = 'submitted', accepted_via = NULL,
            photo_status = CASE WHEN photo_status = 'accepted' THEN 'submitted' ELSE photo_status END,
            reviewed_by_user_id = NULL, reviewed_at = NULL,
            tech_signature_path = NULL, tech_signed_at = NULL, updated_at = :now
        WHERE id = :id AND status = 'teched'
    ");
    $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/** All of one owner's sheets for a car identity (owner + normalised number + season). */
function db_get_identity_sheets(PDO $pdo, int $userId, string $carNumberNorm, int $season): array {
    $stmt = $pdo->prepare("SELECT * FROM tech_sheets WHERE user_id = :u AND car_number_norm = :n AND season = :s ORDER BY id ASC");
    $stmt->execute([':u' => $userId, ':n' => $carNumberNorm, ':s' => $season]);
    return $stmt->fetchAll();
}

/** Every tech sheet in a season (used to derive each car's status on a roster). */
function db_get_season_sheets(PDO $pdo, int $season): array {
    $stmt = $pdo->prepare("SELECT * FROM tech_sheets WHERE season = :s ORDER BY id ASC");
    $stmt->execute([':s' => $season]);
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
            status = 'teched', accepted_via = 'photos', photo_status = 'accepted',
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

function db_insert_gear_record(PDO $pdo, int $ownerId, string $driverName, string $driverNameNorm, ?string $licenceNo, int $season): int {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO gear_records (owner_user_id, driver_name, driver_name_norm, licence_no, season, created_at, updated_at)
        VALUES (:o, :n, :norm, :l, :s, :now, :now)
    ")->execute([':o' => $ownerId, ':n' => $driverName, ':norm' => $driverNameNorm, ':l' => $licenceNo, ':s' => $season, ':now' => $now]);
    return (int)$pdo->lastInsertId();
}

function db_get_gear_record(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM gear_records WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function db_find_gear_record(PDO $pdo, int $ownerId, string $driverNameNorm, int $season): ?array {
    $stmt = $pdo->prepare("SELECT * FROM gear_records WHERE owner_user_id = :o AND driver_name_norm = :n AND season = :s");
    $stmt->execute([':o' => $ownerId, ':n' => $driverNameNorm, ':s' => $season]);
    return $stmt->fetch() ?: null;
}

/** All of one owner's gear records, newest season first, then by driver name. */
function db_get_user_gear_records(PDO $pdo, int $ownerId): array {
    $stmt = $pdo->prepare("SELECT * FROM gear_records WHERE owner_user_id = :o ORDER BY season DESC, driver_name ASC, id ASC");
    $stmt->execute([':o' => $ownerId]);
    return $stmt->fetchAll();
}

/** Every owner's records for a season, with the owner's name and email, by driver name. */
function db_get_gear_records_for_season(PDO $pdo, int $season): array {
    $stmt = $pdo->prepare("
        SELECT g.*, u.name AS owner_name, u.email AS owner_email
        FROM gear_records g LEFT JOIN users u ON u.id = g.owner_user_id
        WHERE g.season = :s ORDER BY g.driver_name ASC, g.id ASC
    ");
    $stmt->execute([':s' => $season]);
    return $stmt->fetchAll();
}

/** First photo activity on a gear record: photo_status NULL -> 'draft'. No-op otherwise or once accepted. */
function db_mark_gear_photos_draft(PDO $pdo, int $id): void {
    $pdo->prepare("
        UPDATE gear_records SET photo_status = 'draft', updated_at = :now
        WHERE id = :id AND photo_status IS NULL AND status = 'open'
    ")->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
}

/** Atomic photo_status transition: true only if the record is still open and its status was one of $from. */
function db_transition_gear_photo_status(PDO $pdo, int $id, array $from, string $to): bool {
    if (empty($from)) return false;
    $marks = implode(',', array_fill(0, count($from), '?'));
    $stmt = $pdo->prepare("
        UPDATE gear_records SET photo_status = ?, updated_at = ?
        WHERE id = ? AND status = 'open' AND photo_status IN ($marks)
    ");
    $stmt->execute(array_merge([$to, date('Y-m-d H:i:s'), $id], array_values($from)));
    return $stmt->rowCount() === 1;
}

/** Remote acceptance after reviewing photos. Atomic; only from a submitted photo set on an open record. */
function db_accept_gear_by_photos(PDO $pdo, int $id, int $reviewerUserId): bool {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        UPDATE gear_records SET
            status = 'accepted', accepted_via = 'photos', photo_status = 'accepted',
            reviewed_by_user_id = :reviewer, reviewed_at = :now, updated_at = :now
        WHERE id = :id AND status = 'open' AND photo_status = 'submitted'
    ");
    $stmt->execute([':reviewer' => $reviewerUserId, ':now' => $now, ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/** In-person acceptance at the track: atomic, from any open record. */
function db_accept_gear_in_person(PDO $pdo, int $id, int $reviewerUserId): bool {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        UPDATE gear_records SET
            status = 'accepted', accepted_via = 'in_person',
            reviewed_by_user_id = :reviewer, reviewed_at = :now, updated_at = :now
        WHERE id = :id AND status = 'open'
    ");
    $stmt->execute([':reviewer' => $reviewerUserId, ':now' => $now, ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/** Undo an acceptance: back to open; a photo-accepted set returns to the review queue. False if not accepted. */
function db_revoke_gear_acceptance(PDO $pdo, int $id): bool {
    $stmt = $pdo->prepare("
        UPDATE gear_records SET
            status = 'open', accepted_via = NULL,
            photo_status = CASE WHEN photo_status = 'accepted' THEN 'submitted' ELSE photo_status END,
            reviewed_by_user_id = NULL, reviewed_at = NULL, updated_at = :now
        WHERE id = :id AND status = 'accepted'
    ");
    $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
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
