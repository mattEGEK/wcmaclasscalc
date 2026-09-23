<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../feedback-lib.php';

function make_temp_pdo(): PDO {
    $path = sys_get_temp_dir() . '/wcma_test_' . uniqid() . '.db';
    if (!defined('DB_PATH')) {
        define('DB_PATH', $path);
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    db_init($pdo);
    return $pdo;
}
