<?php
defined('APP') || exit;
/*
 * Scan engine.
 * Lifecycle of a grid point: pending -> posted (task queued at DataForSEO) -> done | error
 * Live mode skips "posted": each point is fetched in real time.
 * Demo mode fills points with simulated results.
 */

const MODES = ['standard' => 'Standard queue (cheapest, ~1-5 min)', 'priority' => 'Priority queue (~1 min)', 'live' => 'Live (fastest)'];

function scan_defaults(): array {
    return [
        'grid_size' => (int) get_setting('default_grid', 9),
        'radius_miles' => (float) get_setting('default_radius', 5),
        'zoom' => (int) get_setting('default_zoom', 13),
        'mode' => get_setting('default_mode', 'standard'),
        'device' => get_setting('default_device', 'desktop'),
        'language_code' => get_setting('default_language', 'en'),
    ];
}

function validate_scan_options(array $o): array {
    $o['keyword'] = trim((string) ($o['keyword'] ?? ''));
    if ($o['keyword'] === '') throw new InvalidArgumentException('Enter a keyword.');
    $o['grid_size'] = (int) $o['grid_size'];
    if ($o['grid_size'] < 3 || $o['grid_size'] > 21 || $o['grid_size'] % 2 === 0) throw new InvalidArgumentException('Grid size must be an odd number from 3 to 21.');
    $o['radius_miles'] = (float) $o['radius_miles'];
    if ($o['radius_miles'] < 0.1 || $o['radius_miles'] > 50) throw new InvalidArgumentException('Radius must be between 0.1 and 50 miles.');
    $o['zoom'] = (int) $o['zoom'];
    if ($o['zoom'] < 3 || $o['zoom'] > 21) throw new InvalidArgumentException('Zoom must be between 3 and 21.');
    if (!isset(MODES[$o['mode']])) $o['mode'] = 'standard';
    if (!in_array($o['device'], ['desktop', 'mobile'], true)) $o['device'] = 'desktop';
    $o['language_code'] = preg_replace('/[^a-z\-]/i', '', (string) $o['language_code']) ?: 'en';
    $o['center_lat'] = (float) $o['center_lat'];
    $o['center_lng'] = (float) $o['center_lng'];
    if (abs($o['center_lat']) > 90 || abs($o['center_lng']) > 180 || ($o['center_lat'] == 0 && $o['center_lng'] == 0)) throw new InvalidArgumentException('Pick a valid center point.');
    return $o;
}

function create_scan(array $biz, array $o, ?int $scheduleId = null): int {
    $o = validate_scan_options($o);
    $grid = make_grid($o['center_lat'], $o['center_lng'], $o['grid_size'], $o['radius_miles']);
    db()->beginTransaction();
    try {
        $id = insert('scans', [
            'business_id' => $biz['id'], 'keyword' => $o['keyword'], 'grid_size' => $o['grid_size'],
            'radius_miles' => $o['radius_miles'], 'zoom' => $o['zoom'], 'center_lat' => $o['center_lat'],
            'center_lng' => $o['center_lng'], 'mode' => $o['mode'], 'device' => $o['device'],
            'language_code' => $o['language_code'], 'demo' => demo_mode() ? 1 : 0, 'status' => 'running',
            'points_total' => count($grid), 'schedule_id' => $scheduleId, 'created_at' => now(),
        ]);
        $st = db()->prepare('INSERT INTO scan_points (scan_id, grid_row, grid_col, lat, lng, status, updated_at) VALUES (?,?,?,?,?,?,?)');
        foreach ($grid as $p) $st->execute([$id, $p['grid_row'], $p['grid_col'], $p['lat'], $p['lng'], 'pending', now()]);
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    // Queue tasks right away so the clock starts at DataForSEO.
    $scan = row('SELECT * FROM scans WHERE id = ?', [$id]);
    if (!$scan['demo'] && $scan['mode'] !== 'live') {
        try { post_pending($scan); } catch (Throwable $e) { update('scans', ['error' => $e->getMessage()], 'id = ?', [$id]); }
    }
    return $id;
}

function add_cost(int $scanId, float $cost): void {
    if ($cost > 0) q('UPDATE scans SET cost = cost + ? WHERE id = ?', [$cost, $scanId]);
}

/** Sends pending points to DataForSEO's task queue, 100 per request. */
function post_pending(array $scan): void {
    $points = rows("SELECT * FROM scan_points WHERE scan_id = ? AND status = 'pending' ORDER BY id", [$scan['id']]);
    if (!$points) return;
    $requests = [];
    foreach (array_chunk($points, 100) as $i => $chunk) {
        $requests[$i] = ['method' => 'POST', 'path' => 'serp/google/maps/task_post',
            'body' => array_map(fn($p) => dfs_task($scan, $p, 'p' . $p['id']), $chunk)];
    }
    $lastError = null;
    foreach (dfs_multi($requests, 5) as $res) {
        if (isset($res['__error'])) { $lastError = $res['__error']; continue; }
        $cost = 0.0;
        foreach (($res['tasks'] ?? []) as $t) {
            $pid = (int) substr((string) ($t['data']['tag'] ?? ''), 1);
            if (!$pid) continue;
            $cost += (float) ($t['cost'] ?? 0);
            if (($t['status_code'] ?? 0) === 20100) {
                update('scan_points', ['task_id' => $t['id'], 'status' => 'posted', 'posted_at' => now(), 'updated_at' => now()],
                    'id = ? AND scan_id = ?', [$pid, $scan['id']]);
            } else {
                update('scan_points', ['status' => 'error', 'error' => ($t['status_code'] ?? '') . ' ' . ($t['status_message'] ?? 'rejected'), 'updated_at' => now()],
                    'id = ? AND scan_id = ?', [$pid, $scan['id']]);
            }
        }
        add_cost((int) $scan['id'], $cost);
    }
    update('scans', ['error' => $lastError], 'id = ?', [$scan['id']]);
}

/** Returns the 1-based rank of the business in a result list, or null if not in it. */
function match_rank(array $items, array $biz): ?int {
    foreach ($items as $it) {
        if (!empty($biz['cid']) && !empty($it['cid']) && (string) $it['cid'] === (string) $biz['cid']) return (int) $it['rank'];
        if (!empty($biz['place_id']) && !empty($it['place_id']) && $it['place_id'] === $biz['place_id']) return (int) $it['rank'];
    }
    // Fallback when no IDs are stored: exact name match (after normalizing punctuation/case).
    $want = normalize_name($biz['name']);
    foreach ($items as $it) {
        if ($want !== '' && normalize_name($it['title']) === $want) return (int) $it['rank'];
    }
    return null;
}

function store_point(array $point, array $biz, array $items): void {
    update('scan_points', [
        'status' => 'done', 'rank_pos' => match_rank($items, $biz),
        'results_json' => json_encode($items, JSON_UNESCAPED_UNICODE), 'error' => null, 'updated_at' => now(),
    ], 'id = ?', [$point['id']]);
}

function fail_point(array $point, string $msg): void {
    update('scan_points', ['status' => 'error', 'error' => mb_substr($msg, 0, 500), 'updated_at' => now()], 'id = ?', [$point['id']]);
}

/** Fetches finished queue tasks for the given posted points. */
function fetch_tasks(array $points, array $biz, int $scanId): int {
    $requests = [];
    foreach ($points as $p) $requests[$p['id']] = ['method' => 'GET', 'path' => 'serp/google/maps/task_get/advanced/' . rawurlencode($p['task_id'])];
    $byId = array_column($points, null, 'id');
    $done = 0;
    foreach (dfs_multi($requests, 25) as $pid => $res) {
        if (isset($res['__error'])) { update('scans', ['error' => $res['__error']], 'id = ?', [$scanId]); continue; }
        $t = $res['tasks'][0] ?? [];
        $code = (int) ($t['status_code'] ?? 0);
        if ($code === 20000) { store_point($byId[$pid], $biz, dfs_items($t['result'][0] ?? null)); $done++; }
        elseif (in_array($code, DFS_PENDING_CODES, true)) continue;
        else { fail_point($byId[$pid], "$code " . ($t['status_message'] ?? 'error')); $done++; }
    }
    return $done;
}

function acquire_lock(int $scanId, int $seconds = 90): bool {
    return q('UPDATE scans SET lock_until = ? WHERE id = ? AND (lock_until IS NULL OR lock_until < ?)',
        [now($seconds), $scanId, now()])->rowCount() === 1;
}
function release_lock(int $scanId): void { update('scans', ['lock_until' => null], 'id = ?', [$scanId]); }

/**
 * Does one round of work on a scan and returns its progress. Safe to call repeatedly
 * from the browser (progress polling) and from cron at the same time.
 */
function process_scan(int $scanId): array {
    $scan = row('SELECT * FROM scans WHERE id = ?', [$scanId]);
    if (!$scan) throw new RuntimeException('Scan not found.');
    if (in_array($scan['status'], ['running'], true) && acquire_lock($scanId)) {
        try {
            $biz = row('SELECT * FROM businesses WHERE id = ?', [$scan['business_id']]);
            if (!$biz) { update('scans', ['status' => 'failed', 'error' => 'Business was deleted.'], 'id = ?', [$scanId]); }
            elseif ($scan['demo']) process_demo($scan, $biz);
            elseif ($scan['mode'] === 'live') process_live($scan, $biz);
            else process_queue($scan, $biz);
            finalize_if_done($scanId);
        } finally { release_lock($scanId); }
        $scan = row('SELECT * FROM scans WHERE id = ?', [$scanId]);
    }
    return scan_progress($scan);
}

function process_demo(array $scan, array $biz): void {
    $points = rows("SELECT * FROM scan_points WHERE scan_id = ? AND status IN ('pending','posted') ORDER BY id LIMIT 45", [$scan['id']]);
    foreach ($points as $p) store_point($p, $biz, demo_results($scan, $p, $biz));
}

function process_live(array $scan, array $biz): void {
    $points = rows("SELECT * FROM scan_points WHERE scan_id = ? AND status = 'pending' ORDER BY id LIMIT 40", [$scan['id']]);
    if (!$points) return;
    $requests = [];
    foreach ($points as $p) $requests[$p['id']] = ['method' => 'POST', 'path' => 'serp/google/maps/live/advanced', 'body' => [dfs_task($scan, $p)]];
    $byId = array_column($points, null, 'id');
    $cost = 0.0; $err = null;
    foreach (dfs_multi($requests, 20, 90) as $pid => $res) {
        if (isset($res['__error'])) { $err = $res['__error']; continue; } // stays pending, retried next round
        $t = $res['tasks'][0] ?? [];
        $cost += (float) ($t['cost'] ?? 0);
        if (($t['status_code'] ?? 0) === 20000) store_point($byId[$pid], $biz, dfs_items($t['result'][0] ?? null));
        else fail_point($byId[$pid], ($t['status_code'] ?? '') . ' ' . ($t['status_message'] ?? 'error'));
    }
    add_cost((int) $scan['id'], $cost);
    update('scans', ['error' => $err], 'id = ?', [$scan['id']]);
}

function process_queue(array $scan, array $biz): void {
    post_pending($scan);

    // 1) Ask DataForSEO which tasks are finished (rate limited to 20/min, so throttle).
    $lastCheck = (int) get_setting('last_tasks_ready', 0);
    if (time() - $lastCheck >= 6) {
        set_setting('last_tasks_ready', time());
        try {
            $res = dfs_request('GET', 'serp/google/maps/tasks_ready', null, 30);
            $ids = array_column($res['tasks'][0]['result'] ?? [], 'id');
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $ready = rows("SELECT * FROM scan_points WHERE scan_id = ? AND status = 'posted' AND task_id IN ($in)", array_merge([$scan['id']], $ids));
                if ($ready) fetch_tasks(array_slice($ready, 0, 120), $biz, (int) $scan['id']);
            }
        } catch (Throwable $e) {
            update('scans', ['error' => $e->getMessage()], 'id = ?', [$scan['id']]);
        }
    }

    // 2) Safety net: once tasks have been waiting a while, check the oldest ones directly.
    $sweepDue = !$scan['last_sweep_at'] || strtotime($scan['last_sweep_at'] . ' UTC') < time() - 45;
    if ($sweepDue) {
        $old = rows("SELECT * FROM scan_points WHERE scan_id = ? AND status = 'posted' AND posted_at < ? ORDER BY updated_at LIMIT 60",
            [$scan['id'], now(-75)]);
        if ($old) {
            update('scans', ['last_sweep_at' => now()], 'id = ?', [$scan['id']]);
            // touch updated_at so the next sweep picks different points
            foreach ($old as $p) update('scan_points', ['updated_at' => now()], 'id = ?', [$p['id']]);
            fetch_tasks($old, $biz, (int) $scan['id']);
        }
    }

    // 3) Give up on tasks that never came back.
    q("UPDATE scan_points SET status = 'error', error = 'Timed out waiting for DataForSEO' WHERE scan_id = ? AND status = 'posted' AND posted_at < ?",
        [$scan['id'], now(-3 * 3600)]);
}

function finalize_if_done(int $scanId): void {
    $open = (int) val("SELECT COUNT(*) FROM scan_points WHERE scan_id = ? AND status IN ('pending','posted')", [$scanId]);
    $done = (int) val("SELECT COUNT(*) FROM scan_points WHERE scan_id = ? AND status IN ('done','error')", [$scanId]);
    update('scans', ['points_done' => $done], 'id = ?', [$scanId]);
    if ($open > 0) return;
    $ranks = array_map(fn($r) => $r['rank_pos'] === null ? null : (int) $r['rank_pos'],
        rows("SELECT rank_pos FROM scan_points WHERE scan_id = ? AND status = 'done'", [$scanId]));
    if (!$ranks) {
        update('scans', ['status' => 'failed', 'completed_at' => now(), 'error' => 'No grid points returned results.'], 'id = ?', [$scanId]);
        return;
    }
    $m = grid_metrics($ranks);
    update('scans', ['status' => 'complete', 'completed_at' => now(), 'agr' => $m['agr'], 'atgr' => $m['atgr'],
        'solv' => $m['solv'], 'found_count' => $m['found'], 'error' => null], 'id = ?', [$scanId]);
}

function scan_progress(array $scan): array {
    return ['id' => (int) $scan['id'], 'status' => $scan['status'], 'done' => (int) $scan['points_done'],
        'total' => (int) $scan['points_total'], 'error' => $scan['error'], 'cost' => (float) $scan['cost']];
}

function scan_points_for_map(int $scanId): array {
    return array_map(fn($p) => [
        'id' => (int) $p['id'], 'r' => (int) $p['grid_row'], 'c' => (int) $p['grid_col'],
        'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'],
        'rank' => $p['rank_pos'] === null ? null : (int) $p['rank_pos'], 'status' => $p['status'],
    ], rows('SELECT id, grid_row, grid_col, lat, lng, rank_pos, status FROM scan_points WHERE scan_id = ? ORDER BY grid_row, grid_col', [$scanId]));
}

/** Who else shows up across the grid: appearances, top-3 count, average rank. */
function scan_competitors(int $scanId, array $biz, int $limit = 15): array {
    $agg = []; $points = 0;
    foreach (rows("SELECT results_json FROM scan_points WHERE scan_id = ? AND status = 'done'", [$scanId]) as $r) {
        $points++;
        foreach (json_decode($r['results_json'] ?? '[]', true) ?: [] as $it) {
            if (match_rank([$it], $biz) !== null) continue; // skip the tracked business itself
            $key = $it['cid'] ?: normalize_name($it['title']);
            $a = &$agg[$key];
            $a ??= ['title' => $it['title'], 'rating' => $it['rating'], 'votes' => $it['votes'], 'n' => 0, 'top3' => 0, 'sum' => 0];
            $a['n']++; $a['sum'] += $it['rank']; if ($it['rank'] <= 3) $a['top3']++;
            unset($a);
        }
    }
    foreach ($agg as &$a) { $a['avg'] = round($a['sum'] / $a['n'], 1); $a['solv'] = $points ? round(100 * $a['top3'] / $points, 1) : 0; }
    unset($a);
    usort($agg, fn($x, $y) => [$y['top3'], $y['n'], $x['avg']] <=> [$x['top3'], $x['n'], $y['avg']]);
    return array_slice($agg, 0, $limit);
}

function delete_scan(int $id): void {
    q('DELETE FROM scan_points WHERE scan_id = ?', [$id]);
    q('DELETE FROM scans WHERE id = ?', [$id]);
}

function estimate_cost(int $gridSize, string $mode): float { return $gridSize * $gridSize * (DFS_PRICES[$mode] ?? DFS_PRICES['standard']); }

/** Everything the PDF builder (assets/report.js) needs for one scan. */
function report_scan_data(array $scan): array {
    return [
        'date' => fmt_time($scan['created_at']),
        'dateShort' => fmt_time($scan['created_at'], 'Y-m-d'),
        'metrics' => ['agr' => $scan['agr'], 'atgr' => $scan['atgr'], 'solv' => $scan['solv'],
            'found' => $scan['found_count'] === null ? null : (int) $scan['found_count'], 'total' => (int) $scan['points_total']],
        'points' => scan_points_for_map((int) $scan['id']),
    ];
}

function report_base(array $biz, array $scan): array {
    $logo = null;
    foreach (['logo.png', 'logo.jpg', 'logo.jpeg'] as $f) {
        if (is_file(ROOT . '/' . $f)) { $logo = $f . '?v=' . filemtime(ROOT . '/' . $f); break; }
    }
    return [
        'app' => cfg('app_name', 'OpenGrid Ranker'),
        'logo' => $logo,
        'generated' => fmt_time(now(), 'M j, Y'),
        'keyword' => $scan['keyword'],
        'gridLabel' => $scan['grid_size'] . '×' . $scan['grid_size'] . ' grid · ' . fmt_num($scan['radius_miles'], 1) . ' mi radius',
        'business' => ['name' => $biz['name'], 'address' => $biz['address'], 'lat' => (float) $biz['lat'], 'lng' => (float) $biz['lng']],
    ];
}
