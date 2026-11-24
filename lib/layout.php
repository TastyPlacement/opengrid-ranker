<?php
defined('APP') || exit;

function page_header(string $title, string $active = '', bool $maps = false): void {
    $app = cfg('app_name', 'OpenGrid Ranker');
    $u = current_user();
    $nav = ['index.php' => 'Dashboard', 'businesses.php' => 'Businesses', 'schedules.php' => 'Schedules', 'settings.php' => 'Settings'];
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> · <?= h($app) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php if ($maps): ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<?php endif; ?>
<link rel="stylesheet" href="assets/app.css?v=6">
<?= favicon_tag() ?>
<script>window.GR = { csrf: <?= json_encode(csrf_token()) ?>, tiles: <?= json_encode(get_setting('tile_url', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png')) ?> };</script>
<script src="assets/app.js?v=5" defer></script>
<?php if ($maps): ?><script src="assets/report.js?v=2" defer></script><?php endif; ?>
</head>
<body>
<?php if ($u): ?>
<header class="topbar">
  <div class="wrap topbar-inner">
    <a class="brand" href="index.php"><span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span><?= h($app) ?></a>
    <nav class="nav">
      <?php foreach ($nav as $href => $label): ?>
        <a href="<?= $href ?>" class="<?= $active === $href ? 'on' : '' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="nav-right">
      <?php if (demo_mode()): ?><a href="settings.php" class="pill pill-warn" title="Results are simulated. Turn off in Settings.">Demo mode</a><?php endif; ?>
      <a href="logout.php" class="muted small">Log out</a>
    </div>
  </div>
</header>
<?php endif; ?>
<main class="wrap">
<?php foreach (take_flashes() as [$type, $msg]): ?>
  <div class="flash flash-<?= h($type) ?>"><?= h($msg) ?></div>
<?php endforeach;
}

function page_footer(): void {
    ?></main>
<footer class="wrap site-foot muted small">OpenGrid Ranker · free and open source · built by <a href="https://www.tastyplacement.com" target="_blank" rel="noopener">TastyPlacement</a></footer>
</body>
</html><?php
}

function metric_tiles(array $scan): string {
    $tiles = [
        ['AGR', 'agr', 'Average rank where you appear (top 20)', 2],
        ['ATGR', 'atgr', 'Average rank across all points; 20+ counts as 21', 2],
        ['SoLV', 'solv', 'Share of points where you rank in the top 3', 1],
    ];
    $html = '<div class="tiles">';
    foreach ($tiles as [$label, $k, $tip, $dec]) {
        $v = $scan[$k];
        $shown = $v === null ? '—' : fmt_num($v, $dec) . ($k === 'solv' ? '%' : '');
        $html .= '<div class="tile" title="' . h($tip) . '"><div class="tile-label">' . $label . '</div><div class="tile-value">' . $shown . '</div></div>';
    }
    $found = $scan['found_count'] !== null ? (int) $scan['found_count'] . ' / ' . (int) $scan['points_total'] : '—';
    $html .= '<div class="tile" title="Grid points where the business appears in the top 20"><div class="tile-label">Found in</div><div class="tile-value">' . $found . '</div></div>';
    return $html . '</div>';
}

function status_badge(string $status): string {
    $cls = ['complete' => 'ok', 'running' => 'run', 'failed' => 'bad'][$status] ?? 'run';
    return '<span class="badge badge-' . $cls . '">' . h(ucfirst($status)) . '</span>';
}

function legend_html(): string {
    $items = [['1–3', 1], ['4–6', 5], ['7–10', 8], ['11–15', 12], ['16–20', 18], ['20+', null]];
    $html = '<div class="legend">';
    foreach ($items as [$l, $r]) $html .= '<span><i style="background:' . rank_color($r) . '"></i>' . $l . '</span>';
    return $html . '</div>';
}
