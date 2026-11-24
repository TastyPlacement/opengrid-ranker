<?php
// Small JSON endpoints used by the pages (progress polling, point details).
require __DIR__ . '/lib/bootstrap.php';
require_login();
session_write_close(); // don't block other tabs while a scan step runs
@set_time_limit(120);

$action = $_GET['action'] ?? '';
try {
    switch ($action) {
        case 'process': // advance a running scan one step and report progress
            $p = process_scan((int) ($_GET['scan'] ?? 0));
            if (!empty($_GET['points'])) $p['points'] = scan_points_for_map($p['id']);
            json_out($p);

        case 'point': // full result list for one grid point
            $pt = row('SELECT p.*, s.business_id FROM scan_points p JOIN scans s ON s.id = p.scan_id WHERE p.id = ?', [(int) ($_GET['id'] ?? 0)]);
            if (!$pt) json_out(['error' => 'Not found'], 404);
            $biz = row('SELECT * FROM businesses WHERE id = ?', [$pt['business_id']]);
            $items = json_decode($pt['results_json'] ?? '[]', true) ?: [];
            foreach ($items as &$it) $it['self'] = $biz && match_rank([$it], $biz) !== null;
            unset($it);
            json_out(['rank' => $pt['rank_pos'] === null ? null : (int) $pt['rank_pos'], 'status' => $pt['status'],
                'error' => $pt['error'], 'lat' => (float) $pt['lat'], 'lng' => (float) $pt['lng'], 'items' => $items]);

        default:
            json_out(['error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    json_out(['error' => $e->getMessage()], 500);
}
