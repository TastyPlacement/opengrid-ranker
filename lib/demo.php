<?php
defined('APP') || exit;
/*
 * Demo mode: produces realistic-looking fake map results so the whole app can be
 * tried without a DataForSEO account. Nothing here touches the network.
 * Each new demo scan for the same business + keyword ranks a bit better than the
 * previous one, so scan history shows some movement.
 */

const DEMO_PREFIXES = ['Bright Spark', 'Summit', 'Liberty', 'Ace', 'Riverside', 'Keystone', 'Northstar',
    'Precision', 'All Pro', 'Hometown', 'Blue Line', 'Patriot', 'Evergreen', 'First Choice', 'Reliable',
    'Valley', 'Elite', 'Red Barn', 'Cornerstone', 'Main Street', 'Lighthouse', 'Heritage', 'Copperline', 'Benchmark',
    'Oak Ridge', 'Quick Fix', 'Integrity', 'Sterling'];

function demo_title(string $keyword, int $i): string {
    $k = ucwords(trim($keyword));
    $suffix = match (true) {
        str_contains(strtolower($keyword), 'electric') => ['Electric', 'Electrical Services', 'Electric Co.'][$i % 3],
        str_contains(strtolower($keyword), 'plumb') => ['Plumbing', 'Plumbing & Heating', 'Plumbing Co.'][$i % 3],
        default => [$k, "$k Co.", "$k Pros"][$i % 3],
    };
    return DEMO_PREFIXES[$i % count(DEMO_PREFIXES)] . ' ' . $suffix;
}

function demo_competitors(array $biz, string $keyword, float $radius): array {
    mt_srand(crc32($biz['id'] . '|' . strtolower($keyword)));
    $list = [];
    for ($i = 0; $i < 60; $i++) {
        $ang = mt_rand(0, 6283) / 1000; $dist = sqrt(mt_rand(0, 1000) / 1000) * $radius * 2.2;
        $list[] = [
            'title' => demo_title($keyword, $i) . ($i >= 30 ? ' of ' . ['Northside', 'Eastgate', 'Westbrook', 'Southfield', 'Lakeview'][$i % 5] : ''),
            'cid' => (string) (1000000000000000000 + mt_rand(1, 999999999) * 1000 + $i),
            'lat' => $biz['lat'] + ($dist * sin($ang)) / 69.0,
            'lng' => $biz['lng'] + ($dist * cos($ang)) / (69.172 * cos(deg2rad($biz['lat']))),
            'strength' => 0.6 + mt_rand(0, 900) / 1000,
            'rating' => round(3.6 + mt_rand(0, 14) / 10, 1),
            'votes' => mt_rand(4, 480),
        ];
    }
    mt_srand();
    return $list;
}

function demo_results(array $scan, array $point, array $biz): array {
    static $cache = [];
    $key = $scan['id'];
    if (!isset($cache[$key])) {
        $prev = (int) val('SELECT COUNT(*) FROM scans WHERE business_id = ? AND keyword = ? AND demo = 1 AND id < ?',
            [$biz['id'], $scan['keyword'], $scan['id']]);
        $cache[$key] = [
            'comps' => demo_competitors($biz, $scan['keyword'], (float) $scan['radius_miles']),
            'strength' => min(1.6, 0.72 + 0.12 * $prev), // grows with each scan
            'radius' => (float) $scan['radius_miles'],
        ];
    }
    $c = $cache[$key];
    mt_srand(crc32($scan['id'] . ':' . $point['grid_row'] . ':' . $point['grid_col']));
    $scored = [];
    foreach ($c['comps'] as $comp) {
        $d = haversine_miles($point['lat'], $point['lng'], $comp['lat'], $comp['lng']);
        $scored[] = [$d / $comp['strength'] + mt_rand(0, 1000) / 1000 * $c['radius'] * 0.35, $comp];
    }
    $d = haversine_miles($point['lat'], $point['lng'], $biz['lat'], $biz['lng']);
    $self = ['title' => $biz['name'], 'cid' => $biz['cid'] ?: 'self', 'place_id' => $biz['place_id'], 'lat' => $biz['lat'], 'lng' => $biz['lng'], 'rating' => 4.8, 'votes' => 212];
    $scored[] = [$d / $c['strength'] + mt_rand(0, 1000) / 1000 * $c['radius'] * 0.25, $self];
    mt_srand();
    usort($scored, fn($a, $b) => $a[0] <=> $b[0]);
    $items = [];
    foreach (array_slice($scored, 0, 20) as $i => [$score, $b]) {
        $items[] = [
            'rank' => $i + 1, 'title' => $b['title'], 'cid' => $b['cid'], 'place_id' => $b['place_id'] ?? null,
            'rating' => $b['rating'], 'votes' => $b['votes'], 'category' => ucfirst(trim($scan['keyword'])),
            'address' => null, 'lat' => round($b['lat'], 6), 'lng' => round($b['lng'], 6),
        ];
    }
    return $items;
}

function demo_lookup(string $query, ?float $lat, ?float $lng): array {
    $lat ??= 42.1015; $lng ??= -72.5898; // Springfield, MA
    mt_srand(crc32(strtolower($query)));
    $out = [];
    $out[] = ['rank' => 1, 'title' => ucwords($query), 'cid' => (string) (1200000000000000000 + mt_rand(1, 999999999)),
        'place_id' => 'ChIJdemo' . substr(md5($query), 0, 16), 'rating' => 4.8, 'votes' => 212,
        'category' => 'Electrician', 'address' => '120 Main St, Springfield, MA 01103',
        'lat' => round($lat + 0.004, 6), 'lng' => round($lng - 0.006, 6)];
    for ($i = 2; $i <= 4; $i++) {
        $out[] = ['rank' => $i, 'title' => demo_title($query, mt_rand(0, 29)), 'cid' => (string) (1300000000000000000 + mt_rand(1, 999999999)),
            'place_id' => 'ChIJdemo' . substr(md5($query . $i), 0, 16), 'rating' => round(3.9 + mt_rand(0, 10) / 10, 1),
            'votes' => mt_rand(10, 300), 'category' => 'Electrician', 'address' => mt_rand(10, 999) . ' Boston Rd, Springfield, MA',
            'lat' => round($lat + mt_rand(-300, 300) / 10000, 6), 'lng' => round($lng + mt_rand(-300, 300) / 10000, 6)];
    }
    mt_srand();
    return $out;
}
