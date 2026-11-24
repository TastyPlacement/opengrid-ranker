<?php
require __DIR__ . '/lib/bootstrap.php';
require_login();

$scan = row('SELECT * FROM scans WHERE id = ?', [(int) ($_GET['scan'] ?? 0)]);
if (!$scan) { http_response_code(404); exit('Not found'); }
$biz = row('SELECT name FROM businesses WHERE id = ?', [$scan['business_id']]);

$fname = preg_replace('/[^a-z0-9]+/i', '-', ($biz['name'] ?? 'scan') . '-' . $scan['keyword'] . '-' . fmt_time($scan['created_at'], 'Y-m-d'));
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . trim($fname, '-') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['row', 'col', 'lat', 'lng', 'status', 'rank', '#1', '#2', '#3']);
foreach (rows('SELECT * FROM scan_points WHERE scan_id = ? ORDER BY grid_row, grid_col', [$scan['id']]) as $p) {
    $items = json_decode($p['results_json'] ?? '[]', true) ?: [];
    $top = array_map(fn($i) => $i['title'], array_slice($items, 0, 3));
    // Prefix values that spreadsheets could treat as formulas.
    $top = array_map(fn($t) => preg_match('/^[=+\-@]/', $t) ? "'" . $t : $t, $top);
    fputcsv($out, array_merge([$p['grid_row'] + 1, $p['grid_col'] + 1, $p['lat'], $p['lng'], $p['status'],
        $p['status'] === 'done' ? ($p['rank_pos'] ?? '20+') : ''], array_pad($top, 3, '')));
}
fclose($out);
