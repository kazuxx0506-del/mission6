<?php
// /public_html/admin_users.php
require_once __DIR__ . '/config.php';

// セキュリティ：検索エンジンに拾われないように
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate');

// ====== 開発者用 簡易認証（このファイル専用） ======
// ★必ず変更してください
const ADMIN_PASSWORD = 'admin_users';

// ログアウト（開発者認証の解除）
if (isset($_GET['logout'])) {
    unset($_SESSION['is_admin']);
    header('Location: ' . basename(__FILE__));
    exit;
}

$auth_error = '';
if (($_SESSION['is_admin'] ?? false) !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pass = (string)($_POST['admin_pass'] ?? '');
        // 時間一定比較
        if (hash_equals(ADMIN_PASSWORD, $pass)) {
            $_SESSION['is_admin'] = true;
            header('Location: ' . basename(__FILE__));
            exit;
        } else {
            $auth_error = '認証に失敗しました。';
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
      <meta charset="UTF-8">
      <title>開発者認証 | ユーザー管理</title>
      <style>
        body { font-family: system-ui, sans-serif; max-width: 420px; margin: 60px auto; }
        form { border: 1px solid #ddd; padding: 20px; border-radius: 8px; }
        label { display:block; margin: 12px 0 6px; }
        input[type=password]{ width:100%; padding:10px; }
        .err{ color:#c00; margin:8px 0; }
        small{ color:#666; }
      </style>
    </head>
    <body>
      <h1>開発者認証</h1>
      <?php if ($auth_error): ?><p class="err"><?= h($auth_error) ?></p><?php endif; ?>
      <form method="post" action="">
        <label>管理用パスワード</label>
        <input type="password" name="admin_pass" required autofocus>
        <p><button type="submit">入室する</button></p>
        <small>※このページは開発者のみ利用可。パスワードは <code>admin_users.php</code> 内の <code>ADMIN_PASSWORD</code> を変更してください。</small>
        <a class="btn" href="home.php">ホームへ</a>
      </form>
    </body>
    </html>
    <?php
    exit;
}
// ====== /開発者用 簡易認証 ======

// ユーザー一覧取得
$stmt = $pdo->query("SELECT id, username, password_hash, created_at FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll();
$total = count($users);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>ユーザー管理（開発者用）</title>
  <style>
    :root { --fg:#222; --muted:#666; --bd:#e5e5e5; --bg:#fff; --chip:#f5f5f5; }
    * { box-sizing: border-box; }
    body { font-family: system-ui, sans-serif; color:var(--fg); background:var(--bg);
           max-width: 1000px; margin: 40px auto; padding: 0 16px; }
    header { display:flex; justify-content:space-between; align-items:center; margin-bottom: 16px; }
    header h1 { font-size: 20px; margin: 0; }
    header .right { display:flex; gap:8px; align-items:center; }
    .chip { background:var(--chip); border:1px solid var(--bd); padding:6px 10px; border-radius: 999px; font-size: 12px; }
    .note { font-size: 12px; color: var(--muted); margin: 0 0 16px; }
    table { width: 100%; border-collapse: collapse; border:1px solid var(--bd); }
    th, td { border-top:1px solid var(--bd); padding: 10px 12px; text-align: left; vertical-align: top; }
    th { background: #fafafa; font-weight: 600; }
    code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .hash { white-space: nowrap; }
    .btn { border:1px solid var(--bd); background:#fff; padding:6px 10px; border-radius:6px; cursor:pointer; font-size:12px; }
    .btn:hover { background:#f9f9f9; }
    .controls { display:flex; gap:8px; align-items:center; margin: 8px 0 16px; }
    input[type=search]{ padding:8px 10px; border:1px solid var(--bd); border-radius:6px; min-width: 240px; }
    .footer-note{ margin-top: 16px; color:var(--muted); font-size:12px; }
  </style>
</head>
<body>
  <header>
    <h1>ユーザー管理（開発者用）</h1>
    <div class="right">
      <span class="chip">登録数: <?= h((string)$total) ?></span>
      <a class="btn" href="<?= h(basename(__FILE__)) ?>?logout=1">管理ページからログアウト</a>
      <a class="btn" href="home.php">ホームへ</a>
    </div>
  </header>

  <p class="note">※パスワードはデータベース上でも平文では保持していないため、ここでは
    <strong>ハッシュ</strong>のみを表示します（既定：<code>password_hash()</code>）。</p>

  <div class="controls">
    <input type="search" id="q" placeholder="ユーザー名で絞り込み">
    <button class="btn" id="clear">クリア</button>
  </div>

  <table id="users">
    <thead>
      <tr>
        <th style="width:72px;">ID</th>
        <th style="width:220px;">ユーザー名</th>
        <th>パスワードハッシュ</th>
        <th style="width:180px;">登録日時</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u):
        $hash = (string)$u['password_hash'];
        $short = mb_substr($hash, 0, 12) . '…' . mb_substr($hash, -6);
      ?>
      <tr>
        <td><?= h((string)$u['id']) ?></td>
        <td><?= h($u['username']) ?></td>
        <td class="hash-cell" data-full="<?= h($hash) ?>" data-short="<?= h($short) ?>">
          <code class="hash"><?= h($short) ?></code>
          <button class="btn toggle" type="button">表示</button>
          <button class="btn copy" type="button">コピー</button>
        </td>
        <td><?= h($u['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <p class="footer-note">開発者向けの閲覧ページです。アクセス制御のため <code>ADMIN_PASSWORD</code> を必ず変更し、公開ディレクトリ直下に置く場合は第三者にURLが漏洩しないよう注意してください。</p>

  <script>
  // 行の絞り込み
  const q = document.getElementById('q');
  const clear = document.getElementById('clear');
  const rows = Array.from(document.querySelectorAll('#users tbody tr'));
  function filter() {
    const v = q.value.trim().toLowerCase();
    rows.forEach(tr => {
      const name = tr.children[1].textContent.toLowerCase();
      tr.style.display = name.includes(v) ? '' : 'none';
    });
  }
  q.addEventListener('input', filter);
  clear.addEventListener('click', () => { q.value=''; filter(); q.focus(); });

  // ハッシュの表示切替 & コピー
  document.querySelectorAll('.hash-cell').forEach(cell => {
    const codeEl = cell.querySelector('.hash');
    const btnToggle = cell.querySelector('.toggle');
    const btnCopy = cell.querySelector('.copy');
    let shown = false;
    btnToggle.addEventListener('click', () => {
      shown = !shown;
      codeEl.textContent = shown ? cell.dataset.full : cell.dataset.short;
      btnToggle.textContent = shown ? '隠す' : '表示';
    });
    btnCopy.addEventListener('click', async () => {
      const text = shown ? cell.dataset.full : cell.dataset.short;
      try {
        await navigator.clipboard.writeText(text);
        btnCopy.textContent = 'コピー済み';
        setTimeout(() => btnCopy.textContent = 'コピー', 1200);
      } catch (_) {
        alert('クリップボードにコピーできませんでした');
      }
    });
  });
  </script>
</body>
</html>
