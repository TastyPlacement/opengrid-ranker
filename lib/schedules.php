<?php
defined('APP') || exit;

const FREQUENCIES = [7 => 'Weekly', 14 => 'Every 2 weeks', 30 => 'Monthly'];

function create_schedule(array $biz, array $o, int $days, bool $ranNow, ?int $scanId): int {
    $o = validate_scan_options($o);
    return insert('schedules', [
        'business_id' => $biz['id'], 'keyword' => $o['keyword'], 'grid_size' => $o['grid_size'], 'radius_miles' => $o['radius_miles'],
        'zoom' => $o['zoom'], 'center_lat' => $o['center_lat'], 'center_lng' => $o['center_lng'], 'mode' => $o['mode'],
        'device' => $o['device'], 'language_code' => $o['language_code'], 'frequency_days' => $days,
        'next_run_at' => $ranNow ? now($days * 86400) : now(), 'active' => 1, 'last_scan_id' => $scanId, 'created_at' => now(),
    ]);
}

/** Starts scans for every schedule that is due. Returns the new scan IDs. */
function run_due_schedules(): array {
    $started = [];
    foreach (rows('SELECT * FROM schedules WHERE active = 1 AND next_run_at <= ? ORDER BY next_run_at', [now()]) as $s) {
        $biz = row('SELECT * FROM businesses WHERE id = ?', [$s['business_id']]);
        if (!$biz) { update('schedules', ['active' => 0], 'id = ?', [$s['id']]); continue; }
        // Move the next run forward first so a slow scan can't trigger a duplicate.
        $next = strtotime($s['next_run_at'] . ' UTC');
        while ($next <= time()) $next += $s['frequency_days'] * 86400;
        update('schedules', ['next_run_at' => gmdate('Y-m-d H:i:s', $next)], 'id = ?', [$s['id']]);
        try {
            $id = create_scan($biz, $s, (int) $s['id']);
            update('schedules', ['last_scan_id' => $id], 'id = ?', [$s['id']]);
            $started[] = $id;
        } catch (Throwable $e) {
            error_log('OpenGrid Ranker schedule ' . $s['id'] . ' failed: ' . $e->getMessage());
        }
    }
    return $started;
}

/** One full background pass: start due schedules, then advance every running scan. */
function run_background(int $maxSeconds = 50): array {
    $t0 = time();
    $started = run_due_schedules();
    $worked = 0;
    foreach (rows("SELECT id FROM scans WHERE status = 'running' ORDER BY id") as $s) {
        if (time() - $t0 > $maxSeconds) break;
        try {
            // Live/demo scans advance ~40 points per round, so keep going while time allows.
            for ($i = 0; $i < 10 && time() - $t0 <= $maxSeconds; $i++) {
                $before = (int) val('SELECT points_done FROM scans WHERE id = ?', [$s['id']]);
                $p = process_scan((int) $s['id']);
                if ($p['status'] !== 'running' || $p['done'] === $before) break;
            }
            $worked++;
        } catch (Throwable $e) { error_log('OpenGrid Ranker scan ' . $s['id'] . ': ' . $e->getMessage()); }
    }
    set_setting('last_cron_at', now());
    return ['started' => $started, 'processed' => $worked];
}
