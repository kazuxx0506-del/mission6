<?php
// /public_html/config.php
session_start();

$host = 'localhost';
$db   = 'XXXXDB';
$user = 'XXXUSER';
$password = 'XXXPASSWORD';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
  $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
  exit('データベース接続失敗：' . $e->getMessage());
}

/* 文字コード（絵文字対応） */
$pdo->exec("SET NAMES utf8mb4");
$pdo->exec("SET CHARACTER SET utf8mb4");
$pdo->exec("SET SESSION collation_connection = 'utf8mb4_unicode_ci'");

/* カラム存在確認ヘルパー */
if (!function_exists('db_column_exists')) {
  function db_column_exists(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$column]);
    return (bool)$st->fetch();
  }
}

/* users テーブル（なければ作成：team_name / avatar_path も含める） */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(32) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    team_name VARCHAR(50) NULL,
    avatar_path VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS team_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    team_name VARCHAR(50) NULL,
    avatar_path VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                 ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
/* なければ初期行を作る（id=1固定） */
$pdo->exec("INSERT IGNORE INTO team_settings (id, team_name, avatar_path) VALUES (1, NULL, NULL)");

/* 既存テーブルに列が無い場合のみ追加（Duplicate回避） */
if (!db_column_exists($pdo, 'users', 'team_name')) {
  $pdo->exec("ALTER TABLE `users` ADD COLUMN `team_name` VARCHAR(50) NULL");
}
if (!db_column_exists($pdo, 'users', 'avatar_path')) {
  $pdo->exec("ALTER TABLE `users` ADD COLUMN `avatar_path` VARCHAR(255) NULL");
}

/* HTMLエスケープ */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
