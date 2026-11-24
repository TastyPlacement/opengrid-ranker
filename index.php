<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();

$bizCount = (int) val('SELECT COUNT(*) FROM businesses');
$monthStart = gmdate('Y-m-01 00:00:00');
$scansMonth = (int) val('SELECT COUNT(*) FROM scans WHERE created_at >= ?', [$monthStart]);
$spendMonth = (float) val('SELECT COALESCE(SUM(cost),0) FROM scans WHERE created_at >= ? AND demo = 0', [$monthStart]);
$activeSchedules = (int) val('SELECT COUNT(*) FROM schedules WHERE active = 1');
$recent = rows('SELECT s.*, b.name AS biz_name FROM scans s JOIN businesses b ON b.id = s.business_id ORDER BY s.created_at DESC, s.id DESC LIMIT 15');
$lastCron = get_setting('last_cron_at');
$cronStale = $activeSchedules && (!$lastCron || strtotime($lastCron . ' UTC') < time() - 3600);

page_header('Dashboard', 'index.php');
?>
<div class="page-head">
  <h1>Dashboard</h1>
  <?php if ($bizCount): ?><a class="btn btn-primary" href="businesses.php">Businesses</a><?php endif; ?>
</div>

<?php if (!demo_mode() && !dfs_configured()): ?>
  <div class="flash flash-warn">DataForSEO isn't connected yet. <a href="settings.php">Add your API login</a> or turn demo mode back on.</div>
<?php endif; ?>
<?php if ($cronStale): ?>
  <div class="flash flash-warn">You have active schedules, but the background job hasn't run in the last hour, so scheduled scans won't start. See <a href="settings.php#cron">Settings → Background job</a>.</div>
<?php endif; ?>

<?php if (!$bizCount): ?>
  <div class="card onboarding">
    <h2>Get started</h2>
    <ol>
      <li><a href="businesses.php">Add a business</a>: search Google Maps for it, or paste its Maps link.</li>
      <li>Run a scan: pick a keyword and grid size, and the tool checks the ranking at every grid point.</li>
      <li>Connect DataForSEO in <a href="settings.php">Settings</a> when you're ready for real data. <?= demo_mode() ? 'Until then, demo mode fills scans with simulated results so you can try everything for free.' : '' ?></li>
    </ol>
  </div>
<?php else: ?>
  <div class="stats">
    <div class="stat"><div class="stat-value"><?= $bizCount ?></div><div class="stat-label">Businesses</div></div>
    <div class="stat"><div class="stat-value"><?= $scansMonth ?></div><div class="stat-label">Scans this month</div></div>
    <div class="stat"><div class="stat-value"><?= money($spendMonth) ?></div><div class="stat-label">DataForSEO spend this month</div></div>
    <div class="stat"><div class="stat-value"><?= $activeSchedules ?></div><div class="stat-label">Active schedules</div></div>
  </div>

  <h2 class="section-title">Recent scans</h2>
  <div class="card">
    <?php if (!$recent): ?><p class="muted">No scans yet. Open a business and click “New scan”.</p><?php else: ?>
    <table class="table">
      <thead><tr><th>Date</th><th>Business</th><th>Keyword</th><th>Status</th><th class="num">AGR</th><th class="num">ATGR</th><th class="num">SoLV</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $s): ?>
        <tr class="click-row" onclick="location='scan.php?id=<?= $s['id'] ?>'">
          <td><a href="scan.php?id=<?= $s['id'] ?>"><?= fmt_time($s['created_at']) ?></a><?= $s['demo'] ? ' <span class="pill pill-warn pill-xs">demo</span>' : '' ?></td>
          <td><?= h($s['biz_name']) ?></td>
          <td><?= h($s['keyword']) ?></td>
          <td><?= status_badge($s['status']) ?><?= $s['status'] === 'running' ? ' <span class="muted small">' . (int) $s['points_done'] . '/' . (int) $s['points_total'] . '</span>' : '' ?></td>
          <td class="num"><?= fmt_num($s['agr']) ?></td>
          <td class="num"><?= fmt_num($s['atgr']) ?></td>
          <td class="num"><?= $s['solv'] === null ? '—' : fmt_num($s['solv'], 1) . '%' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php page_footer();
