<?php
defined('APP') || exit;

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function redirect(string $url): void { header('Location: ' . $url); exit; }

function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = [$type, $msg]; }
function take_flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function check_csrf(): void {
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!hash_equals(csrf_token(), (string) $t)) { http_response_code(400); exit('Security token expired. Go back, refresh the page and try again.'); }
}

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    static $u = false;
    if ($u === false) $u = row('SELECT id, email FROM users WHERE id = ?', [$_SESSION['uid']]);
    return $u;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', 'api.php')) { http_response_code(401); exit(json_encode(['error' => 'Not logged in'])); }
        redirect('login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? 'index.php'));
    }
    return $u;
}

function fmt_time(?string $utc, string $format = 'M j, Y g:i A'): string {
    if (!$utc) return '—';
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone(cfg('timezone', 'America/Chicago')));
    return $d->format($format);
}

function fmt_num($n, int $dec = 2): string { return $n === null ? '—' : number_format((float) $n, $dec); }

function money($n): string { return '$' . number_format((float) $n, ((float) $n) < 1 ? 4 : 2); }

// Colors match the common geo-grid convention: green = top 3, red = 20+.
function rank_color(?int $rank): string {
    if ($rank === null) return '#9b1c1c';
    if ($rank <= 3) return '#16a34a';
    if ($rank <= 6) return '#65b32e';
    if ($rank <= 10) return '#eab308';
    if ($rank <= 15) return '#f08a24';
    return '#e2462b';
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Accepts a pasted Google Maps URL and pulls out coordinates and CID if present.
function parse_maps_url(string $url): array {
    $out = [];
    if (preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $url, $m)) { $out['lat'] = (float) $m[1]; $out['lng'] = (float) $m[2]; }
    elseif (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) { $out['lat'] = (float) $m[1]; $out['lng'] = (float) $m[2]; }
    if (preg_match('/[?&]cid=(\d+)/', $url, $m)) $out['cid'] = $m[1];
    elseif (preg_match('/!1s0x[0-9a-f]+:(0x[0-9a-f]+)/i', $url, $m)) {
        // The hex after the colon in the "!1s" data blob is the CID.
        $out['cid'] = function_exists('gmp_init') ? gmp_strval(gmp_init($m[1], 16)) : hex_to_dec($m[1]);
    }
    if (preg_match('#/maps/place/([^/@]+)#', $url, $m)) $out['name'] = trim(str_replace('+', ' ', urldecode($m[1])));
    return $out;
}

function hex_to_dec(string $hex): string {
    $hex = strtolower(preg_replace('/^0x/i', '', $hex));
    $dec = '0';
    foreach (str_split($hex) as $ch) {
        $dec = bc_like_mul_add($dec, 16, hexdec($ch));
    }
    return $dec;
}
// Arbitrary-precision decimal string * mul + add, so 64-bit CIDs don't overflow.
function bc_like_mul_add(string $num, int $mul, int $add): string {
    $res = ''; $carry = $add;
    for ($i = strlen($num) - 1; $i >= 0; $i--) {
        $p = ((int) $num[$i]) * $mul + $carry;
        $res = ($p % 10) . $res; $carry = intdiv($p, 10);
    }
    while ($carry > 0) { $res = ($carry % 10) . $res; $carry = intdiv($carry, 10); }
    return ltrim($res, '0') ?: '0';
}

function normalize_name(string $s): string {
    $s = mb_strtolower($s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    $s = preg_replace('/\b(llc|inc|co|corp|ltd|the)\b/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

// Uses favicon.png / favicon.svg / favicon.ico from the app folder, whichever you upload.
function favicon_tag(): string {
    foreach (['favicon.svg' => 'image/svg+xml', 'favicon.png' => 'image/png', 'favicon.ico' => 'image/x-icon'] as $f => $type) {
        if (is_file(ROOT . '/' . $f)) return '<link rel="icon" type="' . $type . '" href="' . $f . '?v=' . filemtime(ROOT . '/' . $f) . '">';
    }
    return '';
}
