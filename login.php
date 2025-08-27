<?php
require_once __DIR__ . '/config.php'; // ★必ず require_once

// --- ログアウト処理（login.php?logout=1 または POST: logout=1 で実行） ---
if (isset($_REQUEST['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: login.php?loggedout=1');
    exit;
}


// --- 既ログインならホームへ ---
if (!empty($_SESSION['user_id'])) {
    header('Location: home.php');
    exit;
}

$info = '';
if (isset($_GET['registered'])) $info = '登録が完了しました。ログインしてください。';
if (isset($_GET['loggedout']))  $info = 'ログアウトしました。';

$err  = '';

// --- ログイン処理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $err = 'ユーザー名とパスワードを入力してください。';
    } else {
        $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id']  = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            header('Location: home.php');
            exit;
        } else {
            $err = 'ユーザー名またはパスワードが正しくありません。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>ログイン</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 480px; margin: 40px auto; }
    form { border: 1px solid #ddd; padding: 20px; border-radius: 8px; }
    label { display:block; margin: 12px 0 6px; }
    input[type=text], input[type=password]{ width:100%; padding:10px; }
    .err{ color:#c00; margin: 8px 0; }
    .info{ color:#0a6; margin: 8px 0; }
    .actions{ margin-top: 16px; display:flex; gap:8px; align-items:center; }
  </style>
</head>
<body>
  <h1>ログイン</h1>
  <?php if ($info): ?><p class="info"><?= h($info) ?></p><?php endif; ?>
  <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>

  <form method="post" action="">
    <label>ユーザー名</label>
    <input type="text" name="username" required autofocus>

    <label>パスワード</label>
    <input type="password" name="password" required>

    <div class="actions">
      <button type="submit">ログイン</button>
      <a href="register.php">新規登録はこちら</a>
      <!-- 統合版ログアウト用リンク（確認用） -->
      <!-- <a href="login.php?logout=1">ログアウト</a> -->
    </div>
  </form>
</body>
</html>
