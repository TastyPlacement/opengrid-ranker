<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();

$biz = row('SELECT * FROM businesses WHERE id = ?', [(int) ($_GET['business'] ?? $_POST['business'] ?? 0)]);
if (!$biz) { flash('Pick a business first.', 'warn'); redirect('businesses.php'); }

$o = scan_defaults() + ['keyword' => '', 'center_lat' => $biz['lat'], 'center_lng' => $biz['lng'], 'repeat' => 0];
if (!empty($_GET['from'])) { // "Run again" prefills from an earlier scan
    $src = row('SELECT * FROM scans WHERE id = ? AND business_id = ?', [(int) $_GET['from'], $biz['id']]);
    if ($src) foreach (['keyword', 'grid_size', 'radius_miles', 'zoom', 'mode', 'device', 'language_code', 'center_lat', 'center_lng'] as $k) $o[$k] = $src[$k];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $o = array_merge($o, array_intersect_key($_POST, $o));
    try {
        if (!demo_mode() && !dfs_configured()) throw new RuntimeException('Add your DataForSEO login in Settings first (or turn on demo mode).');
        $id = create_scan($biz, $o);
        $repeat = (int) ($_POST['repeat'] ?? 0);
        if (isset(FREQUENCIES[$repeat])) {
            $sid = create_schedule($biz, $o, $repeat, true, $id);
            update('scans', ['schedule_id' => $sid], 'id = ?', [$id]);
            flash('Scan started, and it will repeat ' . strtolower(FREQUENCIES[$repeat]) . '.');
        }
        redirect('scan.php?id=' . $id);
    } catch (Throwable $e) {
        flash($e->getMessage(), 'bad');
    }
}

$keywords = array_column(rows('SELECT DISTINCT keyword FROM scans WHERE business_id = ? ORDER BY keyword', [$biz['id']]), 'keyword');
page_header('New scan', 'businesses.php', true);
?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="businesses.php">Businesses</a> / <a href="business.php?id=<?= $biz['id'] ?>"><?= h($biz['name']) ?></a> /</div>
    <h1>New scan</h1>
  </div>
</div>

<form method="post" class="scan-layout" id="scanForm">
  <?= csrf_field() ?>
  <input type="hidden" name="business" value="<?= $biz['id'] ?>">
  <input type="hidden" name="center_lat" id="centerLat" value="<?= h($o['center_lat']) ?>">
  <input type="hidden" name="center_lng" id="centerLng" value="<?= h($o['center_lng']) ?>">

  <div class="card form">
    <label>Keyword
      <input name="keyword" list="kwList" value="<?= h($o['keyword']) ?>" placeholder="e.g. electrician" required autofocus>
      <datalist id="kwList"><?php foreach ($keywords as $k) echo '<option value="' . h($k) . '">'; ?></datalist>
    </label>
    <div class="grid2">
      <label>Grid size
        <select name="grid_size" id="gridSize">
          <?php foreach ([3, 5, 7, 9, 11, 13, 15, 17, 19, 21] as $n): ?>
            <option value="<?= $n ?>" <?= (int) $o['grid_size'] === $n ? 'selected' : '' ?>><?= $n ?> × <?= $n ?> (<?= $n * $n ?> points)</option>
          <?php endforeach; ?>
        </select></label>
      <label>Radius (miles, center to edge)
        <input name="radius_miles" id="radius" type="number" step="0.1" min="0.1" max="50" value="<?= h($o['radius_miles']) ?>"></label>
    </div>
    <div class="grid2">
      <label>Speed / price
        <select name="mode" id="mode">
          <?php foreach (MODES as $k => $label): ?><option value="<?= $k ?>" <?= $o['mode'] === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
        </select></label>
      <label>Device
        <select name="device">
          <option value="desktop" <?= $o['device'] === 'desktop' ? 'selected' : '' ?>>Desktop</option>
          <option value="mobile" <?= $o['device'] === 'mobile' ? 'selected' : '' ?>>Mobile</option>
        </select></label>
    </div>
    <details>
      <summary class="small">Advanced</summary>
      <div class="grid2">
        <label>Map zoom <span class="muted small">(13 is typical for a city)</span>
          <input name="zoom" type="number" min="3" max="21" value="<?= h($o['zoom']) ?>"></label>
        <label>Language code <input name="language_code" value="<?= h($o['language_code']) ?>"></label>
      </div>
    </details>
    <label>Repeat
      <select name="repeat">
        <option value="0">Just once</option>
        <?php foreach (FREQUENCIES as $d => $label): ?><option value="<?= $d ?>"><?= $label ?></option><?php endforeach; ?>
      </select></label>

    <div class="estimate" id="estimate"></div>
    <button class="btn btn-primary btn-block" type="submit">Run scan</button>
    <p class="muted small">Click the map or drag the pin to move the grid center. <a href="#" id="resetCenter">Reset to the business</a>.</p>
  </div>

  <div class="card map-card"><div id="map" class="map map-tall"></div></div>
</form>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const prices = <?= json_encode(DFS_PRICES) ?>, demo = <?= demo_mode() ? 'true' : 'false' ?>;
  const biz = { lat: <?= (float) $biz['lat'] ?>, lng: <?= (float) $biz['lng'] ?>, name: <?= json_encode($biz['name']) ?> };
  const $ = id => document.getElementById(id);
  const map = GR.makeMap('map', [+$('centerLat').value, +$('centerLng').value], 12);
  L.marker([biz.lat, biz.lng], { icon: GR.bizIcon(), zIndexOffset: 1000 }).addTo(map).bindTooltip(biz.name);
  const center = L.marker([+$('centerLat').value, +$('centerLng').value], { draggable: true, icon: GR.centerIcon() }).addTo(map);
  const layer = L.layerGroup().addTo(map);
  let fitted = false;

  function draw() {
    const n = +$('gridSize').value, r = +$('radius').value || 1, c = center.getLatLng();
    $('centerLat').value = c.lat.toFixed(7); $('centerLng').value = c.lng.toFixed(7);
    layer.clearLayers();
    const pts = GR.grid(c.lat, c.lng, n, r);
    pts.forEach(p => L.circleMarker([p.lat, p.lng], { radius: 4, color: '#2563eb', weight: 1, fillOpacity: .5 }).addTo(layer));
    const b = L.latLngBounds(pts.map(p => [p.lat, p.lng]));
    L.rectangle(b.pad(0.05), { color: '#2563eb', weight: 1, fill: false, dashArray: '4 4' }).addTo(layer);
    if (!fitted) { map.fitBounds(b.pad(0.15)); fitted = true; }
    const cost = n * n * prices[$('mode').value];
    $('estimate').innerHTML = `<b>${n * n}</b> grid points · ${(2 * r / Math.max(n - 1, 1)).toFixed(2)} mi apart · ` +
      (demo ? '<span class="pill pill-warn pill-xs">demo: free</span>' : `about <b>$${cost.toFixed(cost < 1 ? 3 : 2)}</b> per scan`);
  }
  ['gridSize', 'radius', 'mode'].forEach(id => $(id).addEventListener('input', () => { fitted = false; draw(); }));
  map.on('click', e => { center.setLatLng(e.latlng); draw(); });
  center.on('dragend', draw);
  $('resetCenter').addEventListener('click', e => { e.preventDefault(); center.setLatLng([biz.lat, biz.lng]); fitted = false; draw(); });
  draw();
});
</script>
<?php page_footer();
