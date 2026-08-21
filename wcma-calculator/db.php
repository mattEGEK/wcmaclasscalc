<?php
/**
 * Database layer — SQLite via PDO.
 * Define DB_PATH before requiring this file to override (e.g. in tests).
 */
if (!defined('DB_PATH')) {
    define('DB_PATH', __DIR__ . '/data/submissions.db');
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
            email_sent              INTEGER DEFAULT 0
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            ip              TEXT PRIMARY KEY,
            attempts        INTEGER NOT NULL DEFAULT 0,
            last_attempt_at DATETIME NOT NULL
        )
    ");
}

function db_insert_submission(PDO $pdo, array $data): int {
    $stmt = $pdo->prepare("
        INSERT INTO submissions (
            submitted_at, name, email, year, make, model, comments,
            competition_weight, declared_hp, dyno_hp,
            chassis_display, body_mods_display, transmission_display,
            drivetrain_display, tires_display, brake_suspension,
            chassis_value, body_mods_value, transmission_value,
            drivetrain_value, tires_value, brake_suspension_value,
            weight_factor, modification_factor, base_ratio, modified_ratio,
            calculated_class, email_sent
        ) VALUES (
            :submitted_at, :name, :email, :year, :make, :model, :comments,
            :competition_weight, :declared_hp, :dyno_hp,
            :chassis_display, :body_mods_display, :transmission_display,
            :drivetrain_display, :tires_display, :brake_suspension,
            :chassis_value, :body_mods_value, :transmission_value,
            :drivetrain_value, :tires_value, :brake_suspension_value,
            :weight_factor, :modification_factor, :base_ratio, :modified_ratio,
            :calculated_class, 0
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
    $pdo->prepare("UPDATE submissions SET email_sent = :sent WHERE id = :id")
        ->execute([':sent' => $sent, ':id' => $id]);
}

function db_get_submissions(PDO $pdo, string $sort = 'submitted_at', string $dir = 'desc'): array {
    $allowed_sorts = ['submitted_at', 'name', 'year', 'make', 'model',
                      'competition_weight', 'declared_hp', 'calculated_class', 'email_sent'];
    $allowed_dirs  = ['asc', 'desc'];
    $sort = in_array($sort, $allowed_sorts, true) ? $sort : 'submitted_at';
    $dir  = in_array($dir,  $allowed_dirs,  true) ? $dir  : 'desc';
    return $pdo->query("SELECT * FROM submissions ORDER BY {$sort} {$dir}")->fetchAll();
}

function db_get_submission(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function db_delete_submission(PDO $pdo, int $id): void {
    $pdo->prepare("DELETE FROM submissions WHERE id = :id")->execute([':id' => $id]);
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
