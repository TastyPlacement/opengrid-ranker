<?php
require __DIR__ . '/lib/bootstrap.php';

$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $u = row('SELECT * FROM users WHERE email = ?', [strtolower(trim($_POST['email'] ?? ''))]);
    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        $next = $_GET['next'] ?? 'index.php';
        // only allow local redirects
        if (!preg_match('#^/?[a-zA-Z0-9_\-./?=&%]*$#', $next) || str_contains($next, '//')) $next = 'index.php';
        redirect($next);
    }
    sleep(1); // slow down password guessing
    $err = 'That email and password don’t match.';
}
$app = cfg('app_name', 'OpenGrid Ranker');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Log in · <?= h($app) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=5">
<?= favicon_tag() ?></head>
<body class="auth-body">
<div class="auth-card">
  <div class="brand brand-lg"><span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span><?= h($app) ?></div>
  <?php if (isset($_GET['installed'])): ?><div class="flash flash-ok">Installed. Log in with the account you just created.</div><?php endif; ?>
  <?php if ($err): ?><div class="flash flash-bad"><?= h($err) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Email <input type="email" name="email" required autofocus value="<?= h($_POST['email'] ?? '') ?>"></label>
    <label>Password <input type="password" name="password" required></label>
    <button class="btn btn-primary btn-block" type="submit">Log in</button>
  </form>
</div>
</body></html>
