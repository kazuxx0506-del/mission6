<?php
// /public_html/home.php
require_once __DIR__ . '/config.php';

/* ============= 認証 ============= */
if (empty($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}
$uid   = (int)$_SESSION['user_id'];
$uname = $_SESSION['username'] ?? '';

/* 全体共通のチーム名・アイコン（team_settings） */
$brandTeam = null; $brandAvatar = null;
try {
  $st = $pdo->query("SELECT team_name, avatar_path FROM team_settings WHERE id=1");
  if ($row = $st->fetch()) { $brandTeam = $row['team_name']; $brandAvatar = $row['avatar_path']; }
} catch (PDOException $e) { /* ignore */ }

/* ============= 4:00区切りの “アプリ日付” ============= */
function app_date_for_now(): string {
  $now = new DateTime('now');
  if ((int)$now->format('H') < 4) { $now->modify('-1 day'); }
  return $now->format('Y-m-d');
}
function app_day_start_dt(): DateTime {
  $now = new DateTime('now');
  if ((int)$now->format('H') < 4) { $now->modify('-1 day'); }
  $now->setTime(4,0,0);
  return $now;
}
$appDate = app_date_for_now();

/* ============= テーブル自動作成 ============= */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS daily_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    kind ENUM('checkin','report') NOT NULL,
    target_date DATE NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_kind_date (user_id, kind, target_date),
    INDEX (user_id), INDEX (target_date), INDEX (kind), INDEX (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("
  CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id), INDEX (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("
  CREATE TABLE IF NOT EXISTS call_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    slot VARCHAR(16) NOT NULL,
    is_join TINYINT(1) NOT NULL DEFAULT 0,
    voted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id), INDEX (slot), INDEX (voted_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("
  CREATE TABLE IF NOT EXISTS checklist_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_rule TINYINT(1) NOT NULL DEFAULT 0
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("
  CREATE TABLE IF NOT EXISTS checklist_checks (
    item_id INT NOT NULL,
    user_id INT NOT NULL,
    date CHAR(10) NOT NULL,
    checked TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (item_id, user_id, date)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

/* 絵文字対応のため既存テーブルを変換（実行済みならスキップ） */
try { $pdo->exec("ALTER TABLE daily_updates CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE messages CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (PDOException $e) {}

/* To-Do を指定4項目に合わせる */
$desiredItems = [
  ['定時連絡(13:00まで)',                10, 0],
  ['報告会参加可否チェック',             20, 0],
  ['日時報告(翌朝4:00まで)',             30, 0],
  ['日報提出@TechBaseサイト(24:00まで)', 40, 0],
];
$exists = $pdo->query("SELECT id,label FROM checklist_items ORDER BY sort_order, id")->fetchAll();
$needReset = (count($exists) !== 4);
if (!$needReset) {
  $labels = array_map(function($r){ return $r['label']; }, $exists);
  $needReset = count(array_intersect($labels, array_column($desiredItems, 0))) !== 4;
}
if ($needReset) {
  $pdo->exec("DELETE FROM checklist_items");
  $ins = $pdo->prepare("INSERT INTO checklist_items (label, sort_order, is_rule) VALUES (?,?,?)");
  foreach ($desiredItems as $d) { $ins->execute($d); }
}

/* ============= CSRF ============= */
if (empty($_SESSION['csrf_home'])) $_SESSION['csrf_home'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_home'];

/* ============= 期限判定 ============= */
function is_late(string $kind, string $target_date, string $created_at): bool {
  $deadline = new DateTime($target_date);
  if ($kind === 'checkin') { $deadline->setTime(13, 0, 0); }
  else { $deadline->modify('+1 day')->setTime(12, 0, 0); }
  return (new DateTime($created_at)) > $deadline;
}

/* ============= 自動チェック（前方一致OK） ============= */
function checklist_autocheck(PDO $pdo, int $uid, string $appDate, string $labelPrefix): void {
  // 完全一致 → 前方一致の順で検索
  $st = $pdo->prepare("SELECT id FROM checklist_items WHERE label = ? LIMIT 1");
  $st->execute([$labelPrefix]);
  $item_id = $st->fetchColumn();
  if (!$item_id) {
    $st2 = $pdo->prepare("SELECT id FROM checklist_items WHERE label LIKE CONCAT(?, '%') ORDER BY sort_order LIMIT 1");
    $st2->execute([$labelPrefix]);
    $item_id = $st2->fetchColumn();
  }
  if ($item_id) {
    $pdo->prepare("
      INSERT INTO checklist_checks (item_id, user_id, date, checked)
      VALUES (?,?,?,1)
      ON DUPLICATE KEY UPDATE checked=1, updated_at=CURRENT_TIMESTAMP
    ")->execute([(int)$item_id, $uid, $appDate]);
  }
}

/* ============= POSTハンドラ ============= */
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    $flash = '不正なリクエスト（CSRF）です。ページを更新してください。';
  } else {
    if ($action === 'post_checkin' || $action === 'post_report') {
      $kind = $action === 'post_checkin' ? 'checkin' : 'report';
      $body = trim($_POST['body'] ?? '');
      if ($body === '') {
        $flash = ($kind==='checkin') ? '定時連絡を入力してください。' : '日報報告を入力してください。';
      } else {
        $pdo->prepare("
          INSERT INTO daily_updates (user_id, kind, target_date, body)
          VALUES (?,?,?,?)
          ON DUPLICATE KEY UPDATE body=VALUES(body), created_at=CURRENT_TIMESTAMP
        ")->execute([$uid, $kind, $appDate, $body]);

        if ($kind === 'checkin') {
          $flash = '定時連絡を保存しました。';
          checklist_autocheck($pdo, $uid, $appDate, '定時連絡');          // 自動チェック①
        } else {
          $flash = '日報を保存しました。';
          checklist_autocheck($pdo, $uid, $appDate, '日時報告');          // 自動チェック③
        }
      }
    }
    elseif ($action === 'post_message') {
      $body = trim($_POST['body'] ?? '');
      if ($body === '') { $flash = 'メッセージを入力してください。'; }
      else {
        $pdo->prepare("INSERT INTO messages (user_id, body) VALUES (?,?)")->execute([$uid, $body]);
        $flash='メッセージを投稿しました。';
      }
    }
    elseif ($action === 'vote_bulk') {
      // 朝・夜まとめて投票（未投票のみINSERT）
      $inputs = [];
      if (isset($_POST['join_morning'])) $inputs['morning'] = ($_POST['join_morning'] === '1') ? 1 : 0;
      if (isset($_POST['join_night']))   $inputs['night']   = ($_POST['join_night']   === '1') ? 1 : 0;

      if (count($inputs) === 0) {
        $flash = '朝・夜の参加/不参加を選択してください。';
      } else {
        $anyInserted = false;
        $msgs = [];
        foreach ($inputs as $slot => $isJoin) {
          $chk = $pdo->prepare("SELECT id FROM call_votes WHERE user_id=? AND slot=? AND DATE(voted_at)=CURDATE() LIMIT 1");
          $chk->execute([$uid, $slot]);
          if ($chk->fetch()) {
            $msgs[] = (($slot==='morning')?'朝':'夜').'は既に投票済みです。';
          } else {
            $pdo->prepare("INSERT INTO call_votes (user_id, slot, is_join) VALUES (?,?,?)")
                ->execute([$uid, $slot, $isJoin ? 1 : 0]);
            $anyInserted = true;
            $msgs[] = (($slot==='morning')?'朝':'夜').'に'.($isJoin?'参加':'不参加').'で投票しました。';
          }
        }
        if ($anyInserted) {
          checklist_autocheck($pdo, $uid, $appDate, '報告会参加可否チェック'); // 自動チェック②
        }
        $flash = implode(' ', $msgs);
      }
    }
    elseif ($action === 'toggle_check') {
      $item_id = (int)($_POST['item_id'] ?? 0);
      $checked = (int)($_POST['checked'] ?? 0);
      $pdo->prepare("
        INSERT INTO checklist_checks (item_id, user_id, date, checked)
        VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE checked=VALUES(checked), updated_at=CURRENT_TIMESTAMP
      ")->execute([$item_id, $uid, $appDate, $checked?1:0]);
      $flash = 'To-Doを更新しました。';
    }
  }
}

/* ============= 表示用データ ============= */
/* 自分の本日ターゲット分（定時連絡/日報） */
$st = $pdo->prepare("SELECT * FROM daily_updates WHERE user_id=? AND kind='checkin' AND target_date=?");
$st->execute([$uid, $appDate]);
$myCheckin = $st->fetch();

$st = $pdo->prepare("SELECT * FROM daily_updates WHERE user_id=? AND kind='report' AND target_date=?");
$st->execute([$uid, $appDate]);
$myReport = $st->fetch();

/* 入力欄の表示モード */
$items = $pdo->query("SELECT id, label, sort_order FROM checklist_items ORDER BY sort_order, id")->fetchAll();
$myChecks = [];
if ($items) {
  $st = $pdo->prepare("SELECT item_id, checked FROM checklist_checks WHERE user_id=? AND date=?");
  $st->execute([$uid, $appDate]);
  while ($r = $st->fetch()) { $myChecks[(int)$r['item_id']] = (int)$r['checked']; }
}
$allChecked = false;
if ($items) {
  $total = count($items); $cnt = 0;
  foreach ($items as $it) { if (!empty($myChecks[(int)$it['id']])) $cnt++; }
  $allChecked = ($cnt === $total);
}
$reportEntered  = (bool)$myReport;
$checkinEntered = (bool)$myCheckin;
$mode = !$checkinEntered ? 'CHECKIN_FORM' : (!$reportEntered ? 'REPORT_FORM' : ($allChecked ? 'DONE' : 'TODO_REMINDER'));

/* 6人分の一覧（個別のteam_nameは表示しない） */
$users6 = $pdo->query("SELECT id, username FROM users ORDER BY id ASC LIMIT 6")->fetchAll();
$cards = [];
if ($users6) {
  $ids = array_column($users6, 'id');
  $in  = implode(',', array_fill(0, count($ids), '?'));

  $rep = $pdo->prepare("SELECT d.*, u.username
                        FROM daily_updates d JOIN users u ON u.id=d.user_id
                        WHERE d.kind='report' AND d.target_date=? AND d.user_id IN ($in)");
  $rep->execute(array_merge([$appDate], $ids));
  while ($r = $rep->fetch()) {
    $cards[$r['user_id']] = [
      'kind'=>'report', 'body'=>$r['body'], 'created_at'=>$r['created_at'],
      'username'=>$r['username'],
      'late'=>is_late('report', $appDate, $r['created_at']),
    ];
  }
  $chk = $pdo->prepare("SELECT d.*, u.username
                        FROM daily_updates d JOIN users u ON u.id=d.user_id
                        WHERE d.kind='checkin' AND d.target_date=? AND d.user_id IN ($in)");
  $chk->execute(array_merge([$appDate], $ids));
  while ($r = $chk->fetch()) {
    if (!isset($cards[$r['user_id']])) {
      $cards[$r['user_id']] = [
        'kind'=>'checkin', 'body'=>$r['body'], 'created_at'=>$r['created_at'],
        'username'=>$r['username'],
        'late'=>is_late('checkin', $appDate, $r['created_at']),
      ];
    }
  }
  foreach ($users6 as $u) {
    if (!isset($cards[$u['id']])) {
      $cards[$u['id']] = [
        'kind'=>'none','body'=>'','created_at'=>null,
        'username'=>$u['username'],
        'late'=>false
      ];
    }
  }
}

/* チャット：最新50件 */
$msgs = $pdo->query("
  SELECT m.id, m.body, m.created_at, u.username
  FROM messages m JOIN users u ON u.id = m.user_id
  ORDER BY m.id DESC
  LIMIT 50
")->fetchAll();

/* 通話投票：本日（カレンダー日）集計 & 参加者一覧 */
$votes = ['morning'=>['yes'=>0,'no'=>0,'list'=>[]], 'night'=>['yes'=>0,'no'=>0,'list'=>[]]];
foreach (['morning','night'] as $slot) {
  $st = $pdo->prepare("SELECT is_join, COUNT(*) c FROM call_votes WHERE slot=? AND DATE(voted_at)=CURDATE() GROUP BY is_join");
  $st->execute([$slot]);
  while ($r = $st->fetch()) {
    if ((int)$r['is_join'] === 1) $votes[$slot]['yes'] = (int)$r['c']; else $votes[$slot]['no'] = (int)$r['c'];
  }
  $st = $pdo->prepare("
    SELECT u.username
    FROM call_votes v
    JOIN users u ON u.id = v.user_id
    WHERE v.slot = ? AND DATE(v.voted_at) = CURDATE() AND v.is_join = 1
    GROUP BY u.id, u.username
    ORDER BY u.id ASC
  ");
  $st->execute([$slot]);
  $votes[$slot]['list'] = $st->fetchAll(PDO::FETCH_COLUMN);
}
$myVote = ['morning'=>null,'night'=>null];
foreach (['morning','night'] as $slot) {
  $st = $pdo->prepare("SELECT is_join FROM call_votes WHERE user_id=? AND slot=? AND DATE(voted_at)=CURDATE() ORDER BY id DESC LIMIT 1");
  $st->execute([$uid,$slot]);
  $row = $st->fetch();
  $myVote[$slot] = $row ? (int)$row['is_join'] : null;
}

/* HTMLエスケープ */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>TeamHub ホーム</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{ --fg:#222; --muted:#666; --bd:#e5e5e5; --bg:#fff; --chip:#f6f6f6; --accent:#0a6; --danger:#c00; }
    *{box-sizing:border-box}
    body{font-family: system-ui, -apple-system, Segoe UI, Roboto, Noto Sans JP, sans-serif; color:var(--fg); background:var(--bg); margin:0; line-height:1.5;}
    header{display:flex; flex-wrap:wrap; gap:12px 16px; align-items:center; justify-content:space-between; padding:16px; border-bottom:1px solid var(--bd); position:sticky; top:0; background:#fff;}
    .container{max-width:1100px; margin: 24px auto; padding: 0 16px;}

    /* ブランド */
    .brand{display:flex; align-items:center; gap:14px;}
    .brand-logo{width:84px; height:84px; border-radius:16px; object-fit:cover; border:1px solid var(--bd);}
    .brand-logo.placeholder{display:flex; align-items:center; justify-content:center; background:#f2f2f2; color:#666; font-weight:800; font-size:32px;}
    .brand-text{display:flex; flex-direction:column; line-height:1.2;}
    .brand-text .teamname{font-weight:900; font-size:22px;}
    .brand-text .sub{color:var(--muted); font-size:12px; margin-top:4px;}
    @media (min-width:900px){
      .brand-logo{width:96px; height:96px;}
      .brand-text .teamname{font-size:26px;}
    }

    .btn{border:1px solid var(--bd); background:#fff; padding:8px 12px; border-radius:8px; cursor:pointer; text-decoration:none; color:#111;}
    .btn:hover{background:#f9f9f9}
    .flash{background:#f0fff5; border:1px solid #b7ebc6; padding:10px 12px; border-radius:8px; margin-bottom:16px;}

    h2{font-size:18px; margin:0 0 8px;}
    .card{border:1px solid var(--bd); border-radius:12px; padding:16px; background:#fff;}
    .actions{display:flex; gap:8px; align-items:center; flex-wrap:wrap;}
    textarea{width:100%; padding:10px; border:1px solid var(--bd); border-radius:8px; resize:vertical;}
    .muted{color:var(--muted)}
    .msg{border-top:1px solid var(--bd); padding:10px 0;}
    .msg:first-child{border-top:none}
    .footer-note{margin-top:24px; color:var(--muted); font-size:12px;}

    /* レイアウト：左（今日の報告）＝中央（チャット）＝右 */
    .columns{display:grid; gap:16px; grid-template-columns: 1fr;}
    @media (min-width:900px){
      .columns{grid-template-columns: 1fr 1fr 1fr;}
    }
    .col-left, .col-center, .col-right{display:flex; flex-direction:column; gap:16px;}

    /* 報告ボード */
    .report-board{display:grid; grid-template-columns: 1fr; gap:20px;}
    .tile{border:1px solid var(--bd); border-radius:10px; padding:20px;}
    .tile .title{display:flex; justify-content:space-between; align-items:center; font-weight:600; margin-bottom:8px;}
    .meta .name{font-weight:700;}
    .late{color:var(--danger); font-weight:700;}
    .pre{white-space: pre-wrap;}
    .placeholder{color:var(--muted);}

    /* 投票UI */
    .vote-block{display:flex; flex-direction:column; gap:12px; align-items:flex-start; flex-wrap:wrap;}
    .vote-pill{display:inline-flex; align-items:center; gap:8px; padding:8px 10px; border:1px solid var(--bd); border-radius:999px;}
    .vote-pill .count{font-weight:600;}
    .fieldset{border:1px solid var(--bd); border-radius:10px; padding:10px 12px;}
    .fieldset legend{font-size:13px; color:#333; padding:0 6px;}
    .radio-row{display:flex; gap:10px; align-items:center;}
  </style>
</head>
<body>
<header>
  <div class="brand">
    <?php if ($brandAvatar): ?>
      <img class="brand-logo" src="<?= h($brandAvatar) ?>" alt="team">
    <?php else: ?>
      <div class="brand-logo placeholder"><?= h(mb_substr($brandTeam ?: 'T', 0, 1)) ?></div>
    <?php endif; ?>
    <div class="brand-text">
      <div class="teamname"><?= h($brandTeam ?: 'チーム名未設定') ?></div>
      <div class="sub">ようこそ <?= h($uname) ?> ｜ <?= h($appDate) ?></div>
    </div>
  </div>
  <div class="right">
    <a class="btn" href="profile.php">チーム設定</a>
    <a class="btn" href="https://p3-php.tech-base.net/" target="_blank" rel="noopener">Tech-Baseサイト</a>
    <a class="btn" href="login.php?logout=1">ログアウト</a>
  </div>
</header>

<div class="container">
  <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>

  <div class="columns">

    <!-- 左：今日の報告 -->
    <div class="col-left">
      <section class="card">
        <h2>今日の報告（定時連絡 → 日報報告）</h2>
        <p class="muted" style="margin:6px 0 12px;">
          【定時連絡】13:00まで / 【日報】翌朝早朝4:00まで
        </p>

        <?php if ($mode === 'CHECKIN_FORM'): ?>
          <form method="post" action="" style="margin-bottom:12px;">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="post_checkin">
            <textarea name="body" rows="3" required><?=
              $myCheckin ? h($myCheckin['body']) : "⏰にやります\n✔をやります"
            ?></textarea>
            <div class="actions"><button type="submit">定時連絡を保存</button></div>
          </form>
        <?php elseif ($mode === 'REPORT_FORM'): ?>
          <form method="post" action="">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="post_report">
            <textarea name="body" rows="4" required><?=
              $myReport ? h($myReport['body']) : "💯まで完了しました！\n📝"
            ?></textarea>
            <div class="actions"><button type="submit">日報を保存</button></div>
          </form>
        <?php elseif ($mode === 'DONE'): ?>
          <p class="muted">今日の業務完了！お疲れ様でした！</p>
        <?php else: ?>
          <p class="muted" style="color:#b85;">To-Doリストをチェック！すべて☑にしよう！</p>
        <?php endif; ?>

        <hr style="margin:16px 0; border:none; border-top:1px solid var(--bd);">

        <!-- 6人分の一覧（個人のteam_nameは表示しない） -->
        <div class="report-board">
          <?php foreach ($users6 as $u):
            $c = $cards[$u['id']];
            $title = ($c['kind']==='report' ? '【日報報告】' : ($c['kind']==='checkin' ? '【定時連絡】' : '【未提出】'));
          ?>
          <div class="tile">
            <div class="title">
              <div class="meta">
                <span class="name"><?= h($c['username']) ?></span>
              </div>
              <div>
                <span><?= h($title) ?><?php if ($c['late']): ?><span class="late">（期限超過）</span><?php endif; ?></span>
              </div>
            </div>

            <?php if ($c['kind']==='none'): ?>
              <div class="placeholder">未提出です。</div>
            <?php else: ?>
              <div class="pre"><?= h($c['body']) ?></div>
              <div class="muted" style="margin-top:6px; font-size:12px;">提出：<?= h($c['created_at']) ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </section>
    </div>

    <!-- 中央：チャット -->
    <div class="col-center">
      <section class="card">
        <h2>チャット</h2>
        <form method="post" action="">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="post_message">
          <textarea name="body" rows="3" placeholder="連絡事項を入力" required></textarea>
          <div class="actions"><button type="submit">送信</button></div>
        </form>
        <div style="margin-top:12px">
          <?php foreach ($msgs as $m): ?>
            <article class="msg">
              <div class="muted" style="font-size:12px;"><?= h($m['username']) ?> ｜ <?= h($m['created_at']) ?></div>
              <div class="pre"><?= h($m['body']) ?></div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    </div>

    <!-- 右：通話参加可否 / To-Do -->
    <div class="col-right">
      <section class="card">
        <h2>通話参加可否</h2>
        <p class="muted">朝・夜を選んでボタンで投票！</p>

        <form method="post" action="" class="vote-block" style="margin-bottom:12px;">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="vote_bulk">

          <fieldset class="fieldset">
            <legend>朝（10:00）</legend>
            <div class="radio-row">
              <label><input type="radio" name="join_morning" value="1" <?= $myVote['morning']===1?'checked':''; ?> required> 参加</label>
              <label><input type="radio" name="join_morning" value="0" <?= $myVote['morning']===0?'checked':''; ?> required> 不参加</label>
            </div>
          </fieldset>

          <fieldset class="fieldset">
            <legend>夜（23:00）</legend>
            <div class="radio-row">
              <label><input type="radio" name="join_night" value="1" <?= $myVote['night']===1?'checked':''; ?> required> 参加</label>
              <label><input type="radio" name="join_night" value="0" <?= $myVote['night']===0?'checked':''; ?> required> 不参加</label>
            </div>
          </fieldset>

          <button type="submit">投票</button>
        </form>

        <!-- 集計＋参加者 -->
        <?php foreach (['morning'=>'朝', 'night'=>'夜'] as $slotKey => $slotLabel): ?>
          <div class="vote-pill" style="margin-bottom:6px;">
            <span><?= h($slotLabel) ?></span>
            <span class="count">参加: <?= h((string)$votes[$slotKey]['yes']) ?></span> /
            <span class="count">不参加: <?= h((string)$votes[$slotKey]['no']) ?></span>
          </div>
          <?php if (!empty($votes[$slotKey]['list'])): ?>
            <div class="muted" style="margin: -2px 0 12px 0; font-size:13px;">
              参加予定：<?= h(implode('、', $votes[$slotKey]['list'])) ?>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </section>

      <section class="card">
        <h2>To-Doリスト</h2>
        <p class="muted">翌朝早朝4:00リセット</p>
        <?php if ($items): foreach ($items as $it):
          $checked = !empty($myChecks[$it['id']]);
        ?>
          <form method="post" action="" style="display:flex; align-items:center; gap:10px; border-top:1px solid var(--bd); padding:10px 0;">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="toggle_check">
            <input type="hidden" name="item_id" value="<?= h((string)$it['id']) ?>">
            <input type="hidden" name="checked" value="<?= $checked?0:1 ?>">
            <button type="submit" class="btn"><?= $checked ? '✅ 済' : '⬜ 未' ?></button>
            <div><?= h($it['label']) ?></div>
          </form>
        <?php endforeach; else: ?>
          <p class="muted">To-Do項目が未設定です。</p>
        <?php endif; ?>
      </section>
    </div>

  </div>

  <p class="footer-note">※ 期限判定はサーバー時刻基準。必要であればタイムゾーンを調整してください。</p>
</div>
</body>
</html>
