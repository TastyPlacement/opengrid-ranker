<?php
defined('APP') || exit;

// Task-level status codes that mean "not finished yet" (DataForSEO uses 4060x; 2000x listed for safety).
const DFS_PENDING_CODES = [40601, 40602, 20001, 20002];
// Per-request price in USD for the cost estimate shown before a scan.
const DFS_PRICES = ['standard' => 0.0006, 'priority' => 0.0012, 'live' => 0.002];

function demo_mode(): bool { return get_setting('demo_mode', '1') === '1'; }

function dfs_configured(): bool { return get_setting('dfs_login', '') !== '' && get_setting('dfs_password', '') !== ''; }

function dfs_curl(string $method, string $path, $body = null, int $timeout = 60) {
    $ch = curl_init(rtrim(cfg('dfs_base', 'https://api.dataforseo.com/v3/'), '/') . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => get_setting('dfs_login', '') . ':' . get_setting('dfs_password', ''),
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_ENCODING => '', // accept gzip
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    return $ch;
}

function dfs_decode($ch, $raw): array {
    if ($raw === false || $raw === null) throw new RuntimeException('Network error talking to DataForSEO: ' . curl_error($ch));
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException("DataForSEO returned an unreadable response (HTTP $code).");
    if ($code === 401 || ($data['status_code'] ?? 0) === 40100) throw new RuntimeException('DataForSEO rejected the login. Check the API login and password in Settings.');
    if (($data['status_code'] ?? 0) !== 20000) {
        throw new RuntimeException('DataForSEO error ' . ($data['status_code'] ?? $code) . ': ' . ($data['status_message'] ?? 'unknown'));
    }
    return $data;
}

function dfs_request(string $method, string $path, $body = null, int $timeout = 60): array {
    if (!dfs_configured()) throw new RuntimeException('DataForSEO login is not set. Add it in Settings, or turn on demo mode.');
    $ch = dfs_curl($method, $path, $body, $timeout);
    $raw = curl_exec($ch);
    try { return dfs_decode($ch, $raw); } finally { curl_close($ch); }
}

/** Runs several requests in parallel. Returns [key => decoded array | ['__error' => message]]. */
function dfs_multi(array $requests, int $concurrency = 20, int $timeout = 60): array {
    if (!dfs_configured()) throw new RuntimeException('DataForSEO login is not set. Add it in Settings, or turn on demo mode.');
    $results = [];
    foreach (array_chunk($requests, $concurrency, true) as $chunk) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunk as $key => $req) {
            $ch = dfs_curl($req['method'], $req['path'], $req['body'] ?? null, $timeout);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $status === CURLM_OK);
        foreach ($handles as $key => $ch) {
            try { $results[$key] = dfs_decode($ch, curl_multi_getcontent($ch)); }
            catch (Throwable $e) { $results[$key] = ['__error' => $e->getMessage()]; }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return $results;
}

function dfs_task(array $scan, array $point, ?string $tag = null): array {
    $t = [
        'keyword' => $scan['keyword'],
        'location_coordinate' => sprintf('%.7F,%.7F,%dz', $point['lat'], $point['lng'], $scan['zoom']),
        'language_code' => $scan['language_code'],
        'device' => $scan['device'],
        'os' => $scan['device'] === 'mobile' ? 'android' : 'windows',
        'depth' => 20,
    ];
    if ($tag !== null) $t['tag'] = $tag;
    if ($scan['mode'] === 'priority') $t['priority'] = 2;
    return $t;
}

/** Pulls the organic map results out of a task result into a compact list. Ads (maps_paid_item) are skipped. */
function dfs_items(?array $result): array {
    $out = [];
    foreach (($result['items'] ?? []) as $it) {
        if (($it['type'] ?? '') !== 'maps_search') continue;
        $out[] = [
            'rank' => (int) ($it['rank_group'] ?? (count($out) + 1)),
            'title' => (string) ($it['title'] ?? ''),
            'cid' => isset($it['cid']) ? (string) $it['cid'] : null,
            'place_id' => $it['place_id'] ?? null,
            'rating' => $it['rating']['value'] ?? null,
            'votes' => $it['rating']['votes_count'] ?? null,
            'category' => $it['category'] ?? null,
            'address' => $it['address'] ?? null,
            'lat' => $it['latitude'] ?? null,
            'lng' => $it['longitude'] ?? null,
        ];
        if (count($out) >= 20) break;
    }
    return $out;
}

/** Finds businesses by name for the "Add business" screen (one live request, about $0.002). */
function dfs_lookup_business(string $query, ?float $lat = null, ?float $lng = null): array {
    if (demo_mode()) return demo_lookup($query, $lat, $lng);
    $task = ['keyword' => $query, 'language_code' => 'en', 'depth' => 20];
    if ($lat !== null && $lng !== null) $task['location_coordinate'] = sprintf('%.7F,%.7F,12z', $lat, $lng);
    else $task['location_code'] = 2840; // United States
    $data = dfs_request('POST', 'serp/google/maps/live/advanced', [$task]);
    $t = $data['tasks'][0] ?? [];
    if (($t['status_code'] ?? 0) !== 20000) throw new RuntimeException('Lookup failed: ' . ($t['status_message'] ?? 'unknown error'));
    return dfs_items($t['result'][0] ?? null);
}

function dfs_balance(): array {
    $data = dfs_request('GET', 'appendix/user_data', null, 20);
    $r = $data['tasks'][0]['result'][0] ?? [];
    return ['login' => $r['login'] ?? '', 'balance' => $r['money']['balance'] ?? null];
}
