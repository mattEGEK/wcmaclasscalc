<?php
/**
 * Database layer — SQLite via PDO.
 * Define DB_PATH before requiring this file to override (e.g. in tests).
 */
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
