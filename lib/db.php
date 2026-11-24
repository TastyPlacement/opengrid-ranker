<?php
defined('APP') || exit;

function db_connect(array $c): PDO {
    if (($c['driver'] ?? 'mysql') === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['path']);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'] ?? 3306, $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass']);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) $pdo = db_connect(cfg('db'));
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function row(string $sql, array $params = []): ?array { $r = q($sql, $params)->fetch(); return $r ?: null; }
function rows(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function val(string $sql, array $params = []) { $r = q($sql, $params)->fetchColumn(); return $r === false ? null : $r; }

function insert(string $table, array $data): int {
    $cols = array_keys($data);
    $sql = "INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));
    return (int) db()->lastInsertId();
}

function update(string $table, array $data, string $where, array $params = []): int {
    $set = implode(',', array_map(fn($c) => "$c = ?", array_keys($data)));
    return q("UPDATE $table SET $set WHERE $where", array_merge(array_values($data), $params))->rowCount();
}

function now(int $offsetSeconds = 0): string { return gmdate('Y-m-d H:i:s', time() + $offsetSeconds); }

function get_setting(string $k, $default = null) {
    static $cache = null;
    if ($cache === null || $k === '__reset') {
        $cache = [];
        foreach (rows('SELECT k, v FROM settings') as $r) $cache[$r['k']] = $r['v'];
        if ($k === '__reset') return null;
    }
    return array_key_exists($k, $cache) ? $cache[$k] : $default;
}

function set_setting(string $k, $v): void {
    q('DELETE FROM settings WHERE k = ?', [$k]);
    q('INSERT INTO settings (k, v) VALUES (?, ?)', [$k, (string) $v]);
    get_setting('__reset');
}

function schema_statements(string $driver): array {
    $pk = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $big = $driver === 'sqlite' ? 'TEXT' : 'MEDIUMTEXT';
    $tail = $driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    return [
        "CREATE TABLE IF NOT EXISTS users (
            id $pk, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL
        )$tail",
        "CREATE TABLE IF NOT EXISTS settings (k VARCHAR(100) NOT NULL PRIMARY KEY, v TEXT)$tail",
        "CREATE TABLE IF NOT EXISTS businesses (
            id $pk, name VARCHAR(255) NOT NULL, address VARCHAR(255), place_id VARCHAR(255), cid VARCHAR(64),
            lat DOUBLE NOT NULL, lng DOUBLE NOT NULL, category VARCHAR(255), notes TEXT, created_at DATETIME NOT NULL
        )$tail",
        "CREATE TABLE IF NOT EXISTS scans (
            id $pk, business_id INT NOT NULL, keyword VARCHAR(255) NOT NULL, grid_size INT NOT NULL, radius_miles DOUBLE NOT NULL,
            zoom INT NOT NULL, center_lat DOUBLE NOT NULL, center_lng DOUBLE NOT NULL, mode VARCHAR(20) NOT NULL,
            device VARCHAR(20) NOT NULL, language_code VARCHAR(10) NOT NULL, demo INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL, points_total INT NOT NULL DEFAULT 0, points_done INT NOT NULL DEFAULT 0,
            agr DOUBLE, atgr DOUBLE, solv DOUBLE, found_count INT, cost DOUBLE NOT NULL DEFAULT 0, error TEXT,
            schedule_id INT, lock_until DATETIME, last_sweep_at DATETIME,
            created_at DATETIME NOT NULL, completed_at DATETIME
        )$tail",
        "CREATE TABLE IF NOT EXISTS scan_points (
            id $pk, scan_id INT NOT NULL, grid_row INT NOT NULL, grid_col INT NOT NULL, lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
            task_id VARCHAR(64), status VARCHAR(20) NOT NULL, rank_pos INT, results_json $big, error TEXT,
            posted_at DATETIME, updated_at DATETIME
        )$tail",
        "CREATE TABLE IF NOT EXISTS schedules (
            id $pk, business_id INT NOT NULL, keyword VARCHAR(255) NOT NULL, grid_size INT NOT NULL, radius_miles DOUBLE NOT NULL,
            zoom INT NOT NULL, center_lat DOUBLE NOT NULL, center_lng DOUBLE NOT NULL, mode VARCHAR(20) NOT NULL,
            device VARCHAR(20) NOT NULL, language_code VARCHAR(10) NOT NULL, frequency_days INT NOT NULL,
            next_run_at DATETIME NOT NULL, active INT NOT NULL DEFAULT 1, last_scan_id INT, created_at DATETIME NOT NULL
        )$tail",
        "CREATE INDEX idx_points_scan ON scan_points (scan_id, status)",
        "CREATE INDEX idx_points_task ON scan_points (task_id)",
        "CREATE INDEX idx_scans_biz ON scans (business_id, keyword)",
        "CREATE INDEX idx_scans_status ON scans (status)",
    ];
}

function run_schema(PDO $pdo, string $driver): void {
    foreach (schema_statements($driver) as $sql) {
        try { $pdo->exec($sql); }
        catch (PDOException $e) {
            if (stripos($sql, 'CREATE INDEX') === 0) continue; // index already exists on re-run
            throw $e;
        }
    }
}
