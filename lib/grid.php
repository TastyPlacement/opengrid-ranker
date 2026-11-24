<?php
defined('APP') || exit;

/**
 * Builds an evenly spaced square grid around a center point.
 * $radiusMiles is the distance from the center to the outer edge (N/S/E/W).
 * Row 0 is the northernmost row; column 0 is the westernmost.
 */
function make_grid(float $lat, float $lng, int $size, float $radiusMiles): array {
    $points = [];
    $half = ($size - 1) / 2;
    $step = $size > 1 ? (2 * $radiusMiles) / ($size - 1) : 0;
    $milesPerDegLng = 69.172 * cos(deg2rad($lat));
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            $dy = ($half - $r) * $step;
            $dx = ($c - $half) * $step;
            $points[] = [
                'grid_row' => $r,
                'grid_col' => $c,
                'lat' => round($lat + $dy / 69.0, 7),
                'lng' => round($lng + $dx / $milesPerDegLng, 7),
            ];
        }
    }
    return $points;
}

function haversine_miles(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 3958.8;
    $dLat = deg2rad($lat2 - $lat1); $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($a)));
}

/**
 * Grid metrics.
 * AGR  = average rank across points where the business ranked in the top 20.
 * ATGR = average rank across ALL points, counting "not in top 20" as 21.
 * SoLV = share of points where the business ranked 1-3.
 * $ranks: array of int|null (null = not found). Errored points must be excluded beforehand.
 */
function grid_metrics(array $ranks): array {
    $n = count($ranks);
    if ($n === 0) return ['agr' => null, 'atgr' => null, 'solv' => null, 'found' => 0];
    $found = array_values(array_filter($ranks, fn($r) => $r !== null));
    $total = 0; $top3 = 0;
    foreach ($ranks as $r) { $total += $r ?? 21; if ($r !== null && $r <= 3) $top3++; }
    return [
        'agr' => $found ? round(array_sum($found) / count($found), 2) : null,
        'atgr' => round($total / $n, 2),
        'solv' => round(100 * $top3 / $n, 1),
        'found' => count($found),
    ];
}
