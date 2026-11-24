<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();

$scan = row('SELECT * FROM scans WHERE id = ?', [(int) ($_GET['id'] ?? $_POST['id'] ?? 0)]);
if (!$scan) { flash('Scan not found.', 'bad'); redirect('index.php'); }
$biz = row('SELECT * FROM businesses WHERE id = ?', [$scan['business_id']]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['action'] ?? '') === 'delete') {
        delete_scan((int) $scan['id']);
        flash('Scan deleted.');
        redirect($biz ? 'business.php?id=' . $biz['id'] : 'index.php');
    }
}

$competitors = $scan['status'] === 'complete' && $biz ? scan_competitors((int) $scan['id'], $biz) : [];
$points = scan_points_for_map((int) $scan['id']);
$errors = (int) val("SELECT COUNT(*) FROM scan_points WHERE scan_id = ? AND status = 'error'", [$scan['id']]);
$firstErr = $errors ? val("SELECT error FROM scan_points WHERE scan_id = ? AND status = 'error' LIMIT 1", [$scan['id']]) : null;

page_header($scan['keyword'] . ' · ' . ($biz['name'] ?? ''), 'businesses.php', true);
?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="businesses.php">Businesses</a> / <?php if ($biz): ?><a href="business.php?id=<?= $biz['id'] ?>"><?= h($biz['name']) ?></a> /<?php endif; ?></div>
    <h1>“<?= h($scan['keyword']) ?>” <?= status_badge($scan['status']) ?><?= $scan['demo'] ? ' <span class="pill pill-warn">demo data</span>' : '' ?></h1>
    <div class="muted small">
      <?= fmt_time($scan['created_at']) ?> · <?= $scan['grid_size'] ?>×<?= $scan['grid_size'] ?> grid · <?= fmt_num($scan['radius_miles'], 1) ?> mi radius ·
      <?= h(ucfirst($scan['device'])) ?> · zoom <?= (int) $scan['zoom'] ?> · <?= h(ucfirst($scan['mode'])) ?><?= $scan['demo'] ? '' : ' · cost ' . money($scan['cost']) ?>
    </div>
  </div>
  <div class="head-actions">
    <?php if ($biz): ?><a class="btn" href="scan_new.php?business=<?= $biz['id'] ?>&from=<?= $scan['id'] ?>">Run again</a><?php endif; ?>
    <?php if ($scan['status'] === 'complete'): ?>
      <a class="btn btn-ghost" href="export.php?scan=<?= $scan['id'] ?>">CSV</a>
      <?php if ($biz): ?><button class="btn btn-primary" type="button" id="pdfBtn">Download PDF</button><?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($scan['status'] === 'running'): ?>
  <div class="card progress-card" id="progressCard">
    <div class="progress-row"><span id="progressText">Working… <?= (int) $scan['points_done'] ?> of <?= (int) $scan['points_total'] ?> points</span><span class="muted small" id="progressHint"><?= $scan['mode'] === 'live' || $scan['demo'] ? '' : 'Queued scans usually finish in 1–5 minutes. You can leave this page; it keeps going in the background.' ?></span></div>
    <div class="bar"><i id="progressBar" style="width:<?= $scan['points_total'] ? round(100 * $scan['points_done'] / $scan['points_total']) : 0 ?>%"></i></div>
    <div class="flash flash-bad" id="progressErr" style="display:none;margin-top:12px"></div>
  </div>
<?php elseif ($scan['status'] === 'failed'): ?>
  <div class="flash flash-bad">This scan failed: <?= h($scan['error'] ?: $firstErr ?: 'unknown error') ?></div>
<?php endif; ?>
<?php if ($errors && $scan['status'] === 'complete'): ?>
  <div class="flash flash-warn"><?= $errors ?> grid point<?= $errors > 1 ? 's' : '' ?> returned an error and are left out of the metrics (shown grey). First error: <?= h($firstErr) ?></div>
<?php endif; ?>

<div class="scan-view">
  <div class="card map-card">
    <div id="map" class="map map-tall"></div>
    <?= legend_html() ?>
  </div>
  <div class="side">
    <?= metric_tiles($scan) ?>
    <div class="card point-panel" id="pointPanel">
      <h3>Results at a grid point</h3>
      <p class="muted small">Click any circle on the map to see the full top 20 from that spot.</p>
    </div>
  </div>
</div>

<?php if ($competitors): ?>
<h2 class="section-title">Top competitors across this grid</h2>
<div class="card">
  <table class="table">
    <thead><tr><th>Business</th><th class="num">Top-3 share</th><th class="num">Points in top 20</th><th class="num">Avg rank</th><th class="num">Rating</th></tr></thead>
    <tbody>
      <tr class="self-row"><td><?= h($biz['name']) ?> <span class="pill pill-xs">you</span></td><td class="num"><?= fmt_num($scan['solv'], 1) ?>%</td>
        <td class="num"><?= (int) $scan['found_count'] ?></td><td class="num"><?= fmt_num($scan['agr'], 1) ?></td><td></td></tr>
      <?php foreach ($competitors as $c): ?>
        <tr><td><?= h($c['title']) ?></td><td class="num"><?= fmt_num($c['solv'], 1) ?>%</td><td class="num"><?= $c['n'] ?></td>
          <td class="num"><?= fmt_num($c['avg'], 1) ?></td><td class="num small"><?= $c['rating'] ? '★ ' . h($c['rating']) . ' <span class="muted">(' . (int) $c['votes'] . ')</span>' : '' ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<form method="post" class="danger-zone" onsubmit="return confirm('Delete this scan?')">
  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $scan['id'] ?>">
  <button class="btn btn-ghost btn-sm">Delete scan</button>
</form>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const scanId = <?= (int) $scan['id'] ?>;
  const biz = <?= json_encode($biz ? ['lat' => (float) $biz['lat'], 'lng' => (float) $biz['lng'], 'name' => $biz['name']] : null) ?>;
  const map = GR.makeMap('map', [<?= (float) $scan['center_lat'] ?>, <?= (float) $scan['center_lng'] ?>], 12);
  const grid = GR.renderGrid(map, <?= json_encode($points) ?>, { size: <?= (int) $scan['grid_size'] ?>, onClick: p => GR.showPoint(p, 'pointPanel') });
  if (biz) L.marker([biz.lat, biz.lng], { icon: GR.bizIcon(), zIndexOffset: -1000, interactive: false }).addTo(map);
  map.fitBounds(grid.bounds.pad(0.08));
<?php if ($scan['status'] === 'complete' && $biz):
    $report = report_base($biz, $scan) + report_scan_data($scan) + [
        'competitors' => array_map(fn($c) => ['title' => $c['title'], 'solv' => $c['solv'], 'n' => $c['n'], 'avg' => $c['avg'], 'rating' => $c['rating'], 'votes' => $c['votes']], array_slice($competitors, 0, 12)),
    ]; ?>
  const report = <?= json_encode($report, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  GR.pdfButton(document.getElementById('pdfBtn'), () => GR.scanPdf(report));
<?php endif; ?>

  <?php if ($scan['status'] === 'running'): ?>
  const fast = <?= ($scan['demo'] || $scan['mode'] === 'live') ? 'true' : 'false' ?>;
  let busy = false;
  async function tick() {
    if (busy) return; busy = true;
    try {
      const r = await fetch(`api.php?action=process&points=1&scan=${scanId}`, { headers: { 'X-CSRF': GR.csrf } });
      const d = await r.json();
      if (d.error && !d.status) throw new Error(d.error);
      document.getElementById('progressText').textContent = `Working… ${d.done} of ${d.total} points`;
      document.getElementById('progressBar').style.width = (d.total ? Math.round(100 * d.done / d.total) : 0) + '%';
      const err = document.getElementById('progressErr');
      if (d.error) { err.style.display = ''; err.textContent = d.error; } else err.style.display = 'none';
      if (d.points) grid.update(d.points);
      if (d.status !== 'running') { location.reload(); return; }
    } catch (e) {
      const err = document.getElementById('progressErr'); err.style.display = ''; err.textContent = e.message;
    } finally { busy = false; }
    setTimeout(tick, fast ? 800 : 5000);
  }
  tick();
  <?php endif; ?>
});
</script>
<?php page_footer();
