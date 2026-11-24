<?php
require __DIR__ . '/lib/bootstrap.php';
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        switch ($_POST['action'] ?? '') {
            case 'dfs':
                set_setting('dfs_login', trim($_POST['dfs_login'] ?? ''));
                if (($_POST['dfs_password'] ?? '') !== '') set_setting('dfs_password', trim($_POST['dfs_password']));
                set_setting('demo_mode', isset($_POST['demo_mode']) ? '1' : '0');
                if (!demo_mode()) {
                    if (!dfs_configured()) throw new RuntimeException('Saved, but the DataForSEO login is incomplete, so scans can’t run yet.');
                    $b = dfs_balance();
                    flash('Connected to DataForSEO' . ($b['balance'] !== null ? '. Account balance: $' . number_format((float) $b['balance'], 2) : '') . '.');
                } else flash('Saved. Demo mode is on, so scans use simulated results.');
                break;
            case 'test':
                $b = dfs_balance();
                flash('Connection works. Account: ' . $b['login'] . ($b['balance'] !== null ? ', balance $' . number_format((float) $b['balance'], 2) : '') . '.');
                break;
            case 'defaults':
                $g = (int) $_POST['default_grid']; $r = (float) $_POST['default_radius']; $z = (int) $_POST['default_zoom'];
                if ($g < 3 || $g > 21 || $g % 2 === 0) throw new InvalidArgumentException('Default grid must be odd, 3–21.');
                if ($r < 0.1 || $r > 50 || $z < 3 || $z > 21) throw new InvalidArgumentException('Check the radius (0.1–50) and zoom (3–21).');
                set_setting('default_grid', $g); set_setting('default_radius', $r); set_setting('default_zoom', $z);
                set_setting('default_mode', isset(MODES[$_POST['default_mode']]) ? $_POST['default_mode'] : 'standard');
                set_setting('default_device', $_POST['default_device'] === 'mobile' ? 'mobile' : 'desktop');
                set_setting('default_language', preg_replace('/[^a-z\-]/i', '', $_POST['default_language']) ?: 'en');
                $tiles = trim($_POST['tile_url'] ?? '');
                if ($tiles !== '' && !preg_match('#^https://[^\s"\'<>]+\{z\}[^\s"\'<>]*$#', $tiles)) throw new InvalidArgumentException('The map tile URL must start with https:// and contain {z}, {x}, {y}.');
                set_setting('tile_url', $tiles ?: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');
                flash('Defaults saved.');
                break;
            case 'add_user':
                $email = strtolower(trim($_POST['email'] ?? ''));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email.');
                if (strlen($_POST['password'] ?? '') < 10) throw new InvalidArgumentException('Passwords need at least 10 characters.');
                if (val('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) throw new InvalidArgumentException('That email already has a login.');
                insert('users', ['email' => $email, 'password_hash' => password_hash($_POST['password'], PASSWORD_DEFAULT), 'created_at' => now()]);
                flash('Login created for ' . $email . '.');
                break;
            case 'password':
                $u = row('SELECT * FROM users WHERE id = ?', [$me['id']]);
                if (!password_verify($_POST['current'] ?? '', $u['password_hash'])) throw new InvalidArgumentException('Current password is wrong.');
                if (strlen($_POST['new'] ?? '') < 10) throw new InvalidArgumentException('New password needs at least 10 characters.');
                update('users', ['password_hash' => password_hash($_POST['new'], PASSWORD_DEFAULT)], 'id = ?', [$me['id']]);
                flash('Password changed.');
                break;
            case 'delete_user':
                $id = (int) $_POST['id'];
                if ($id === (int) $me['id']) throw new InvalidArgumentException('You can’t delete your own login.');
                q('DELETE FROM users WHERE id = ?', [$id]);
                flash('Login removed.');
                break;
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'bad');
    }
    redirect('settings.php');
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-site') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
$cronUrl = $base . '/cron.php?key=' . cfg('cron_key');
$users = rows('SELECT id, email, created_at FROM users ORDER BY email');
$d = scan_defaults();

page_header('Settings', 'settings.php');
?>
<div class="page-head"><h1>Settings</h1></div>

<div class="grid-2col">
  <div class="card">
    <h2>DataForSEO</h2>
    <p class="muted small">Use the <b>API login and API password</b> from your DataForSEO dashboard (under API Access). They are not the same as your website login.</p>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="dfs">
      <label>API login <input name="dfs_login" value="<?= h(get_setting('dfs_login', '')) ?>" autocomplete="off"></label>
      <label>API password <input type="password" name="dfs_password" placeholder="<?= get_setting('dfs_password', '') !== '' ? '•••••••• (saved, leave blank to keep)' : '' ?>" autocomplete="new-password"></label>
      <label class="check"><input type="checkbox" name="demo_mode" <?= demo_mode() ? 'checked' : '' ?>> Demo mode: use simulated results, no API calls, no cost</label>
      <div class="row-gap"><button class="btn btn-primary">Save</button></div>
    </form>
    <?php if (dfs_configured()): ?>
      <form method="post" class="inline-gap"><?= csrf_field() ?><input type="hidden" name="action" value="test"><button class="btn btn-sm">Test connection &amp; show balance</button></form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Scan defaults</h2>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="defaults">
      <div class="grid2">
        <label>Grid size <input type="number" name="default_grid" min="3" max="21" step="2" value="<?= $d['grid_size'] ?>"></label>
        <label>Radius (miles) <input type="number" name="default_radius" step="0.1" value="<?= $d['radius_miles'] ?>"></label>
        <label>Zoom <input type="number" name="default_zoom" min="3" max="21" value="<?= $d['zoom'] ?>"></label>
        <label>Language <input name="default_language" value="<?= h($d['language_code']) ?>"></label>
        <label>Speed
          <select name="default_mode"><?php foreach (MODES as $k => $l): ?><option value="<?= $k ?>" <?= $d['mode'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
        <label>Device
          <select name="default_device"><option value="desktop">Desktop</option><option value="mobile" <?= $d['device'] === 'mobile' ? 'selected' : '' ?>>Mobile</option></select></label>
        <label class="span2">Map tiles URL <span class="muted small">(OpenStreetMap by default; fine for internal use)</span>
          <input name="tile_url" value="<?= h(get_setting('tile_url', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png')) ?>"></label>
      </div>
      <button class="btn">Save defaults</button>
    </form>
  </div>
</div>

<div class="card" id="cron">
  <h2>Background job</h2>
  <p>Scheduled scans, and queued scans when nobody has the page open, are handled by a background job that should run <b>every 5 minutes</b>. Set it up once, either way:</p>
  <ul class="small">
    <li><b>Free pinger (easiest):</b> create a job at cron-job.org that opens this URL every 5 minutes:</li>
  </ul>
  <div class="copy-row"><input class="code" readonly value="<?= h($cronUrl) ?>" id="cronUrl"><button class="btn btn-sm" type="button" onclick="GR.copy('cronUrl', this)">Copy</button></div>
  <ul class="small">
    <li><b>Server cron</b> (if your host offers it): <code>*/5 * * * * php <?= h(ROOT) ?>/cron.php</code></li>
  </ul>
  <p class="small muted">Last run: <?= fmt_time(get_setting('last_cron_at')) ?>. Keep this URL private; it only runs the job, but there's no reason to share it.</p>
</div>

<div class="grid-2col">
  <div class="card">
    <h2>Team logins</h2>
    <table class="table">
      <?php foreach ($users as $u): ?>
        <tr><td><?= h($u['email']) ?><?= (int) $u['id'] === (int) $me['id'] ? ' <span class="pill pill-xs">you</span>' : '' ?></td>
          <td class="actions"><?php if ((int) $u['id'] !== (int) $me['id']): ?>
            <form method="post" onsubmit="return confirm('Remove this login?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn btn-sm btn-ghost">Remove</button></form>
          <?php endif; ?></td></tr>
      <?php endforeach; ?>
    </table>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_user">
      <div class="grid2">
        <label>Email <input type="email" name="email" required></label>
        <label>Password <input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
      </div>
      <button class="btn">Add login</button>
    </form>
  </div>
  <div class="card">
    <h2>Change your password</h2>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <label>Current password <input type="password" name="current" required></label>
      <label>New password <input type="password" name="new" minlength="10" required autocomplete="new-password"></label>
      <button class="btn">Change password</button>
    </form>
  </div>
</div>
<?php page_footer();
