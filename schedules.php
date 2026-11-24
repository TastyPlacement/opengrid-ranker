<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $s = row('SELECT * FROM schedules WHERE id = ?', [$id]);
    if ($s) {
        switch ($_POST['action'] ?? '') {
            case 'toggle':
                update('schedules', ['active' => $s['active'] ? 0 : 1], 'id = ?', [$id]);
                flash($s['active'] ? 'Schedule paused.' : 'Schedule resumed.');
                break;
            case 'run':
                update('schedules', ['next_run_at' => now(-1), 'active' => 1], 'id = ?', [$id]);
                $started = run_due_schedules();
                if ($started) redirect('scan.php?id=' . end($started));
                flash('Could not start the scan. Check Settings.', 'bad');
                break;
            case 'frequency':
                $d = (int) ($_POST['frequency_days'] ?? 0);
                if (isset(FREQUENCIES[$d])) { update('schedules', ['frequency_days' => $d], 'id = ?', [$id]); flash('Frequency updated.'); }
                break;
            case 'delete':
                q('DELETE FROM schedules WHERE id = ?', [$id]);
                flash('Schedule deleted. Past scans are kept.');
                break;
        }
    }
    redirect('schedules.php');
}

$list = rows('SELECT sc.*, b.name AS biz_name FROM schedules sc JOIN businesses b ON b.id = sc.business_id ORDER BY sc.active DESC, b.name, sc.keyword');
page_header('Schedules', 'schedules.php');
?>
<div class="page-head"><h1>Schedules</h1></div>
<?php if (!$list): ?>
  <div class="card"><p class="muted">No scheduled scans. When you start a scan, choose “Weekly”, “Every 2 weeks” or “Monthly” under Repeat.</p></div>
<?php else: ?>
<div class="card">
  <table class="table">
    <thead><tr><th>Business</th><th>Keyword</th><th>Grid</th><th>Repeats</th><th>Next run</th><th>Last scan</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $s): ?>
      <tr class="<?= $s['active'] ? '' : 'dim' ?>">
        <td><a href="business.php?id=<?= $s['business_id'] ?>"><?= h($s['biz_name']) ?></a></td>
        <td><?= h($s['keyword']) ?></td>
        <td class="small"><?= $s['grid_size'] ?>×<?= $s['grid_size'] ?> · <?= fmt_num($s['radius_miles'], 1) ?> mi · <?= h($s['mode']) ?></td>
        <td>
          <form method="post" class="inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="frequency"><input type="hidden" name="id" value="<?= $s['id'] ?>">
            <select name="frequency_days" onchange="this.form.submit()" aria-label="Frequency">
              <?php foreach (FREQUENCIES as $d => $l): ?><option value="<?= $d ?>" <?= (int) $s['frequency_days'] === $d ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
            </select>
          </form>
        </td>
        <td class="small"><?= $s['active'] ? fmt_time($s['next_run_at']) : 'Paused' ?></td>
        <td class="small"><?= $s['last_scan_id'] ? '<a href="scan.php?id=' . (int) $s['last_scan_id'] . '">View</a>' : '—' ?></td>
        <td class="actions">
          <?php foreach ([['run', 'Run now', 'btn-primary'], ['toggle', $s['active'] ? 'Pause' : 'Resume', ''], ['delete', 'Delete', 'btn-ghost']] as [$act, $label, $cls]): ?>
            <form method="post" <?= $act === 'delete' ? 'onsubmit="return confirm(\'Delete this schedule?\')"' : '' ?>>
              <?= csrf_field() ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="id" value="<?= $s['id'] ?>">
              <button class="btn btn-sm <?= $cls ?>"><?= $label ?></button>
            </form>
          <?php endforeach; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php page_footer();
