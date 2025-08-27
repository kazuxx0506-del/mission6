```php
<?php
/**
 * reset_tables.php
 * アプリの主要テーブルの「データだけ」をリセット（TRUNCATE）します。
 * スキーマは残し、行を全削除します。必要に応じて $dropUsers を true にすると users も空にします。
 * 実行前に必ずバックアップを取ってください！
 */

// --- DB接続設定（そのまま利用可） ---
$host = 'localhost';
$db   = 'tb270415db';
$user = 'tb-270415';
$pass = 'cpgZdKEs66';
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    exit('データベース接続失敗：' . $e->getMessage());
}

// --- 設定：users も空にする場合は true（既存アカウントを残したい場合は false のまま） ---
$dropUsers = true;

// --- リセット対象のテーブル（データのみ削除：TRUNCATE） ---
$truncateTargets = [
    'daily_updates',
    'messages',
    'call_votes',
    'checklist_checks',
    'checklist_items',
    // team_settings は後で別処理（初期行の復元が必要なため）
];

// users を空にする場合はここに追加
if ($dropUsers) {
    $truncateTargets[] = 'users';
}

echo "<pre>=== リセット開始 ===\n";

// 外部キー制約がある場合に備えて一時無効化（安全のため）
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

foreach ($truncateTargets as $t) {
    try {
        $pdo->exec("TRUNCATE TABLE `{$t}`");
        echo "TRUNCATE {$t} ... OK\n";
    } catch (PDOException $e) {
        echo "TRUNCATE {$t} ... NG ({$e->getMessage()})\n";
    }
}

// team_settings は単一レコード(id=1)を初期化したいので、TRUNCATE 後に初期行を復元
try {
    $pdo->exec("TRUNCATE TABLE `team_settings`");
    $pdo->exec("INSERT INTO `team_settings` (id, team_name, avatar_path) VALUES (1, NULL, NULL)");
    echo "TRUNCATE team_settings + 初期化 ... OK\n";
} catch (PDOException $e) {
    echo "team_settings 初期化 ... NG ({$e->getMessage()})\n";
}

// 外部キー制約を元に戻す
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// 完了メッセージ
echo "=== リセット完了 ===\n";

// 補足：checklist_items は空になりますが、home.php 側で自動補充される設計になっています。
echo "補足: checklist_items は次回アクセス時に自動補充されます。\n";
echo "</pre>";
