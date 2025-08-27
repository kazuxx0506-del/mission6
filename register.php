<?php
require_once __DIR__ . '/config.php';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $err = 'ユーザー名とパスワードは必須です。';
    } elseif (mb_strlen($username) > 32) {
        $err = 'ユーザー名は32文字以内で入力してください。';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
            $stmt->execute([$username, $hash]);
            header('Location: login.php?registered=1');
            exit;
        } catch (PDOException $e) {
            $err = '登録に失敗しました（同名ユーザーが存在する可能性があります）。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>新規登録</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 480px; margin: 40px auto; }
    form { border: 1px solid #ddd; padding: 20px; border-radius: 8px; }
    label { display:block; margin: 12px 0 6px; }
    input[type=text], input[type=password]{ width:100%; padding:10px; }
    .err{ color:#c00; margin: 8px 0; }
    .actions{ margin-top: 16px; display:flex; gap:8px; }
  </style>
</head>
<body>
  <h1>新規登録</h1>
  <?php if ($err): ?><p class="err"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
  <form method="post" action="">
    <label>ユーザー名</label>
    <input type="text" name="username" maxlength="32" required>

    <label>パスワード</label>
    <input type="password" name="password" required>

    <div class="actions">
      <button type="submit">登録する</button>
      <a href="login.php">ログインへ戻る</a>
    </div>
  </form>
</body>
</html>
