<?php
// /public_html/profile.php  → 「チーム設定（全体共通）」に変更
require_once __DIR__ . '/config.php';

// 認証チェック
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

/* CSRF */
if (empty($_SESSION['csrf_profile'])) $_SESSION['csrf_profile'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_profile'];

/* 現在のチーム設定（単一行） */
$st = $pdo->query("SELECT team_name, avatar_path FROM team_settings WHERE id=1");
$team = $st->fetch() ?: ['team_name'=>null,'avatar_path'=>null];

$err = '';
$ok  = '';

/* POST処理：全体に即時反映 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    $err = '不正なリクエスト（CSRF）です。ページを更新してください。';
  } else {
    $team_name = trim($_POST['team_name'] ?? '');
    if (mb_strlen($team_name) > 50) {
      $err = 'チーム名は50文字以内で入力してください。';
    } else {
      $avatar_path = $team['avatar_path']; 
      $remove = !empty($_POST['remove_avatar']);

      $dir = __DIR__ . '/uploads/avatars';
      if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

      // 画像削除
      if ($remove && $avatar_path) {
        $abs = __DIR__ . '/' . $avatar_path;
        if (is_file($abs)) @unlink($abs);
        $avatar_path = null;
      }

      // 画像アップロード
      if (!$err && !empty($_FILES['avatar']['name']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
        if ($_FILES['avatar']['size'] > 2*1024*1024) {
          $err = 'アイコン画像は2MB以内にしてください。';
        } else {
          // MIME判別
          $mime = null; $ext = null;
          if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['avatar']['tmp_name']);
          }
          if (!$mime) {
            $imgInfo = @getimagesize($_FILES['avatar']['tmp_name']);
            if ($imgInfo && !empty($imgInfo['mime'])) $mime = $imgInfo['mime'];
          }
          if ($mime === 'image/jpeg') $ext = 'jpg';
          elseif ($mime === 'image/png') $ext = 'png';
          elseif ($mime === 'image/gif') $ext = 'gif';

          if (!$ext) {
            $err = 'JPEG/PNG/GIF の画像をアップロードしてください。';
          } else {
            $fname = 'team_' . time() . '.' . $ext;
            $destAbs = $dir . '/' . $fname;
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $destAbs)) {
              if ($team['avatar_path']) {
                $oldAbs = __DIR__ . '/' . $team['avatar_path'];
                if (is_file($oldAbs)) @unlink($oldAbs);
              }
              $avatar_path = 'uploads/avatars/' . $fname;
              @chmod($destAbs, 0644);
            } else {
              $err = 'ファイルの保存に失敗しました。権限を確認してください。';
            }
          }
        }
      }

      // 更新（全体共通：id=1固定）
      if (!$err) {
        $upd = $pdo->prepare("UPDATE team_settings SET team_name=?, avatar_path=? WHERE id=1");
        $upd->execute([$team_name ?: null, $avatar_path ?: null]);
        $ok = 'チーム設定を更新しました（全員に反映されます）。';

        // 再読込
        $st = $pdo->query("SELECT team_name, avatar_path FROM team_settings WHERE id=1");
        $team = $st->fetch() ?: ['team_name'=>null,'avatar_path'=>null];
      }
    }
  }
}

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>チーム設定</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{ --bd:#e5e5e5; --muted:#666; }
    body{font-family:system-ui, -apple-system, Segoe UI, Roboto, Noto Sans JP, sans-serif; max-width:720px; margin:30px auto; padding:0 16px;}
    h1{font-size:20px; margin:0 0 16px;}
    .card{border:1px solid var(--bd); border-radius:12px; padding:16px; background:#fff;}
    .row{margin:14px 0;}
    label{display:block; margin-bottom:6px; font-weight:600;}
    input[type=text]{width:100%; padding:10px; border:1px solid var(--bd); border-radius:8px;}
    input[type=file]{display:block; margin-top:8px;}
    .actions{display:flex; gap:8px; align-items:center; margin-top:16px;}
    .btn{border:1px solid var(--bd); background:#fff; padding:8px 12px; border-radius:8px; cursor:pointer; text-decoration:none; color:#111; display:inline-block;}
    .flash-ok{background:#f0fff5; border:1px solid #b7ebc6; padding:10px; border-radius:8px; margin-bottom:12px;}
    .flash-err{background:#fff5f5; border:1px solid #f1c0c0; padding:10px; border-radius:8px; margin-bottom:12px;}
    .avatar{width:96px; height:96px; border-radius:16px; object-fit:cover; border:1px solid var(--bd);}
    .placeholder{width:96px; height:96px; border-radius:16px; display:flex; align-items:center; justify-content:center; background:#f2f2f2; font-weight:800; color:#666; font-size:28px; border:1px solid var(--bd);}
    .hint{color:var(--muted); font-size:12px; margin-top:4px;}
    .grid{display:grid; grid-template-columns:96px 1fr; gap:12px; align-items:center;}
  </style>
</head>
<body>
  <h1>チーム設定</h1>

  <?php if ($ok): ?><div class="flash-ok"><?= h($ok) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash-err"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <form method="post" action="" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

      <div class="row grid">
        <?php if (!empty($team['avatar_path'])): ?>
          <img class="avatar" src="<?= h($team['avatar_path']) ?>" alt="team">
        <?php else: ?>
          <div class="placeholder"><?= h(mb_substr($team['team_name'] ?: 'T', 0, 1)) ?></div>
        <?php endif; ?>
        <div>
          <label>チーム名</label>
          <input type="text" name="team_name" maxlength="50" value="<?= h((string)$team['team_name']) ?>">
          <div class="hint">※ ここでの変更は全員に反映されます。</div>
        </div>
      </div>

      <div class="row">
        <label>チームアイコン（JPEG/PNG/GIF・2MB以内）</label>
        <?php if (!empty($team['avatar_path'])): ?>
          <label style="display:block; margin:6px 0;">
            <input type="checkbox" name="remove_avatar" value="1"> アイコンを削除する
          </label>
        <?php else: ?>
          <div class="hint">※ 現在、未設定です</div>
        <?php endif; ?>
        <input type="file" name="avatar" accept="image/*">
        <div class="hint">※ アップロードすると既存のアイコンは置き換えられます。</div>
      </div>

      <div class="actions">
        <button class="btn" type="submit">保存する</button>
        <a class="btn" href="home.php">ホームへ戻る</a>
      </div>
    </form>
  </div>
</body>
</html>
