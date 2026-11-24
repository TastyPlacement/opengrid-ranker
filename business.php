<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();

$biz = row('SELECT * FROM businesses WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$biz) { flash('Business not found.', 'bad'); redirect('businesses.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['action'] ?? '') === 'edit') {
        $name = trim($_POST['name'] ?? '');
        $cid = trim($_POST['cid'] ?? '');
        if ($name === '' || !is_numeric($_POST['lat'] ?? '') || !is_numeric($_POST['lng'] ?? '') || ($cid !== '' && !ctype_digit($cid))) {
            flash('Name, latitude and longitude are required, and the CID must be digits.', 'bad');
        } else {
            update('businesses', ['name' => $name, 'address' => trim($_POST['address'] ?? '') ?: null, 'cid' => $cid ?: null,
                'place_id' => trim($_POST['place_id'] ?? '') ?: null, 'lat' => (float) $_POST['lat'], 'lng' => (float) $_POST['lng']], 'id = ?', [$biz['id']]);
            flash('Saved. New scans will use these details.');
        }
        redirect('business.php?id=' . $biz['id']);
    }
}

$scans = rows('SELECT * FROM scans WHERE business_id = ? ORDER BY created_at DESC, id DESC', [$biz['id']]);
// Latest finished scan per keyword.
$byKeyword = [];
foreach ($scans as $s) {
    if ($s['status'] !== 'complete') continue;
    $k = mb_strtolower($s['keyword']);
    if (!isset($byKeyword[$k])) $byKeyword[$k] = ['latest' => $s, 'count' => 0];
    $byKeyword[$k]['count']++;
}
$schedules = rows('SELECT * FROM schedules WHERE business_id = ? ORDER BY keyword', [$biz['id']]);

page_header($biz['name'], 'businesses.php');
?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="businesses.php">Businesses</a> /</div>
    <h1><?= h($biz['name']) ?></h1>
    <div class="muted small"><?= h(implode(' · ', array_filter([$biz['category'], $biz['address'], $biz['cid'] ? 'CID ' . $biz['cid'] : null]))) ?></div>
  </div>
  <a class="btn btn-primary" href="scan_new.php?business=<?= $biz['id'] ?>">New scan</a>
</div>

<?php if ($byKeyword): ?>
<h2 class="section-title">Keywords</h2>
<div class="kw-grid">
  <?php foreach ($byKeyword as $kw): $s = $kw['latest']; ?>
    <a class="card kw-card" href="scan.php?id=<?= $s['id'] ?>">
      <div class="kw-head"><span class="strong"><?= h($s['keyword']) ?></span><span class="muted small"><?= $kw['count'] ?> scan<?= $kw['count'] > 1 ? 's' : '' ?></span></div>
      <?= metric_tiles($s) ?>
      <div class="muted small">Latest: <?= fmt_time($s['created_at']) ?> · <?= $s['grid_size'] ?>×<?= $s['grid_size'] ?>, <?= fmt_num($s['radius_miles'], 1) ?> mi</div>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<h2 class="section-title">Scan history</h2>
<div class="card">
<?php if (!$scans): ?>
  <p class="muted">No scans yet. <a href="scan_new.php?business=<?= $biz['id'] ?>">Run the first one</a>.</p>
<?php else: ?>
  <table class="table">
    <thead><tr><th>Date</th><th>Keyword</th><th>Grid</th><th>Status</th><th class="num">AGR</th><th class="num">ATGR</th><th class="num">SoLV</th><th class="num">Cost</th></tr></thead>
    <tbody>
    <?php foreach ($scans as $s): ?>
      <tr class="click-row" onclick="location='scan.php?id=<?= $s['id'] ?>'">
        <td><a href="scan.php?id=<?= $s['id'] ?>"><?= fmt_time($s['created_at']) ?></a><?= $s['demo'] ? ' <span class="pill pill-warn pill-xs">demo</span>' : '' ?><?= $s['schedule_id'] ? ' <span class="pill pill-xs">scheduled</span>' : '' ?></td>
        <td><?= h($s['keyword']) ?></td>
        <td class="small"><?= $s['grid_size'] ?>×<?= $s['grid_size'] ?> · <?= fmt_num($s['radius_miles'], 1) ?> mi</td>
        <td><?= status_badge($s['status']) ?></td>
        <td class="num"><?= fmt_num($s['agr']) ?></td>
        <td class="num"><?= fmt_num($s['atgr']) ?></td>
        <td class="num"><?= $s['solv'] === null ? '—' : fmt_num($s['solv'], 1) . '%' ?></td>
        <td class="num small"><?= $s['demo'] ? '—' : money($s['cost']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>

<?php if ($schedules): ?>
<h2 class="section-title">Schedules</h2>
<div class="card">
  <table class="table">
    <thead><tr><th>Keyword</th><th>Repeats</th><th>Grid</th><th>Next run</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($schedules as $sc): ?>
      <tr><td><?= h($sc['keyword']) ?></td><td><?= h(FREQUENCIES[$sc['frequency_days']] ?? 'Every ' . $sc['frequency_days'] . ' days') ?></td>
        <td class="small"><?= $sc['grid_size'] ?>×<?= $sc['grid_size'] ?> · <?= fmt_num($sc['radius_miles'], 1) ?> mi</td>
        <td><?= $sc['active'] ? fmt_time($sc['next_run_at']) : '—' ?></td><td><?= $sc['active'] ? '<span class="badge badge-ok">Active</span>' : '<span class="badge">Paused</span>' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="small"><a href="schedules.php">Manage schedules</a></p>
</div>
<?php endif; ?>

<details class="card">
  <summary class="strong">Edit business details</summary>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="edit">
    <div class="grid2">
      <label class="span2">Name <input name="name" value="<?= h($biz['name']) ?>"></label>
      <label>CID <input name="cid" value="<?= h($biz['cid']) ?>"></label>
      <label>Place ID <input name="place_id" value="<?= h($biz['place_id']) ?>"></label>
      <label>Latitude <input name="lat" value="<?= h($biz['lat']) ?>"></label>
      <label>Longitude <input name="lng" value="<?= h($biz['lng']) ?>"></label>
      <label class="span2">Address <input name="address" value="<?= h($biz['address']) ?>"></label>
    </div>
    <button class="btn">Save</button>
  </form>
</details>
<?php page_footer();
