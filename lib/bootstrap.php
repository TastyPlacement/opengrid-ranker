<?php
// Loaded at the top of every page. Sets up config, database, session and helpers.
define('APP', true);
define('ROOT', dirname(__DIR__));

if (!file_exists(ROOT . '/config.php')) {
    if (PHP_SAPI !== 'cli') { header('Location: install.php'); }
    exit("Not installed yet. Open install.php in your browser.\n");
}

$GLOBALS['CONFIG'] = require ROOT . '/config.php';
date_default_timezone_set('UTC'); // everything is stored in UTC; displayed in the configured timezone

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/grid.php';
require_once __DIR__ . '/dataforseo.php';
require_once __DIR__ . '/demo.php';
require_once __DIR__ . '/scans.php';
require_once __DIR__ . '/schedules.php';
require_once __DIR__ . '/layout.php';

if (PHP_SAPI !== 'cli') {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('gridrank_sess');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $secure]);
    session_start();
}

function cfg(string $key, $default = null) {
    return $GLOBALS['CONFIG'][$key] ?? $default;
}
