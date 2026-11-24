<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();

$candidates = null; $lookupQuery = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'lookup') {
            $lookupQuery = trim($_POST['q'] ?? '');
            if ($lookupQuery === '') throw new InvalidArgumentException('Type a business name and city.');
            $candidates = dfs_lookup_business($lookupQuery);
            if (!$candidates) flash('No businesses found. Try adding the city, or add it manually below.', 'warn');
        } elseif ($action === 'add') {
            $d = ['name' => trim($_POST['name'] ?? ''), 'address' => trim($_POST['address'] ?? ''), 'cid' => trim($_POST['cid'] ?? ''),
                'place_id' => trim($_POST['place_id'] ?? ''), 'lat' => trim($_POST['lat'] ?? ''), 'lng' => trim($_POST['lng'] ?? ''),
                'category' => trim($_POST['category'] ?? '')];
            if (!empty($_POST['maps_url'])) {
                $parsed = parse_maps_url($_POST['maps_url']);
                foreach (['lat', 'lng', 'cid', 'name'] as $k) if (($d[$k] === '' || $d[$k] === null) && isset($parsed[$k])) $d[$k] = $parsed[$k];
            }
            if ($d['name'] === '') throw new InvalidArgumentException('Business name is required.');
            if (!is_numeric($d['lat']) || !is_numeric($d['lng'])) throw new InvalidArgumentException('Add the location: paste the Google Maps link, or enter latitude and longitude.');
            if ($d['cid'] !== '' && !ctype_digit($d['cid'])) throw new InvalidArgumentException('The CID should be digits only.');
            $id = insert('businesses', [
                'name' => $d['name'], 'address' => $d['address'] ?: null, 'cid' => $d['cid'] ?: null, 'place_id' => $d['place_id'] ?: null,
                'lat' => (float) $d['lat'], 'lng' => (float) $d['lng'], 'category' => $d['category'] ?: null, 'created_at' => now(),
            ]);
            if (!$d['cid'] && !$d['place_id']) flash('Added. Without a CID or Place ID, rankings are matched by exact business name, which is less reliable.', 'warn');
            else flash('Business added.');
            redirect('business.php?id=' . $id);
        } elseif ($action === 'delete') {
            $id = (int) $_POST['id'];
            foreach (rows('SELECT id FROM scans WHERE business_id = ?', [$id]) as $s) delete_scan((int) $s['id']);
            q('DELETE FROM schedules WHERE business_id = ?', [$id]);
            q('DELETE FROM businesses WHERE id = ?', [$id]);
            flash('Business and its scans deleted.');
            redirect('businesses.php');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'bad');
    }
}

$list = rows('SELECT b.*, (SELECT COUNT(*) FROM scans s WHERE s.business_id = b.id) AS scan_count,
    (SELECT MAX(created_at) FROM scans s WHERE s.business_id = b.id) AS last_scan FROM businesses b ORDER BY b.name');

page_header('Businesses', 'businesses.php');
?>
<div class="page-head"><h1>Businesses</h1></div>

<?php if ($list): ?>
<div class="card">
  <table class="table">
    <thead><tr><th>Business</th><th>Match by</th><th class="num">Scans</th><th>Last scan</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $b): ?>
      <tr>
        <td><a class="strong" href="business.php?id=<?= $b['id'] ?>"><?= h($b['name']) ?></a><div class="muted small"><?= h($b['address'] ?: sprintf('%.5f, %.5f', $b['lat'], $b['lng'])) ?></div></td>
        <td class="small"><?= $b['cid'] ? 'CID' : ($b['place_id'] ? 'Place ID' : '<span class="warn-text">Name only</span>') ?></td>
        <td class="num"><?= (int) $b['scan_count'] ?></td>
        <td class="small"><?= fmt_time($b['last_scan']) ?></td>
        <td class="actions">
          <a class="btn btn-sm btn-primary" href="scan_new.php?business=<?= $b['id'] ?>">New scan</a>
          <form method="post" onsubmit="return confirm('Delete this business and all of its scans and schedules?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $b['id'] ?>">
            <button class="btn btn-sm btn-ghost">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="grid-2col">
  <div class="card">
    <h2>Find on Google Maps</h2>
    <p class="muted small">Searches Google Maps through DataForSEO (one live request, about $0.002)<?= demo_mode() ? '. In demo mode the results are made up.' : '.' ?></p>
    <form method="post" class="form inline-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="lookup">
      <input name="q" placeholder="e.g. Acme Electric Springfield MA" value="<?= h($lookupQuery) ?>" required>
      <button class="btn btn-primary">Search</button>
    </form>
    <?php if ($candidates): ?>
      <div class="candidates">
      <?php foreach ($candidates as $c): ?>
        <form method="post" class="candidate">
          <?= csrf_field() ?><input type="hidden" name="action" value="add">
          <?php foreach (['name' => $c['title'], 'address' => $c['address'], 'cid' => $c['cid'], 'place_id' => $c['place_id'], 'lat' => $c['lat'], 'lng' => $c['lng'], 'category' => $c['category']] as $k => $v): ?>
            <input type="hidden" name="<?= $k ?>" value="<?= h($v) ?>">
          <?php endforeach; ?>
          <div>
            <div class="strong"><?= h($c['title']) ?></div>
            <div class="muted small"><?= h(implode(' · ', array_filter([$c['category'], $c['address'], $c['rating'] ? '★ ' . $c['rating'] . ' (' . (int) $c['votes'] . ')' : null]))) ?></div>
          </div>
          <button class="btn btn-sm">Add</button>
        </form>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Add manually</h2>
    <p class="muted small">Paste the business's Google Maps link and the location and CID are filled in for you when the link contains them.</p>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="add">
      <label>Business name (exactly as on Google) <input name="name"></label>
      <label>Google Maps link <input name="maps_url" placeholder="https://www.google.com/maps/place/..."></label>
      <details>
        <summary class="small">Enter IDs and coordinates yourself</summary>
        <div class="grid2">
          <label>CID <input name="cid" inputmode="numeric"></label>
          <label>Place ID <input name="place_id"></label>
          <label>Latitude <input name="lat" inputmode="decimal"></label>
          <label>Longitude <input name="lng" inputmode="decimal"></label>
          <label class="span2">Address <input name="address"></label>
        </div>
      </details>
      <button class="btn btn-primary">Add business</button>
    </form>
  </div>
</div>
<?php page_footer();
