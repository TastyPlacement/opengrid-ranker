<?php
// Background worker. Call every 5 minutes, either:
//   web:  https://your-site/cron.php?key=YOUR_CRON_KEY   (e.g. from cron-job.org)
//   cli:  php /path/to/cron.php
require __DIR__ . '/lib/bootstrap.php';

if (PHP_SAPI !== 'cli' && !hash_equals((string) cfg('cron_key'), (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit("Forbidden\n");
}
@set_time_limit(120);
ignore_user_abort(true);
header('Content-Type: text/plain');
$r = run_background(50);
echo 'OK ' . now() . ' | scans started: ' . count($r['started']) . ' | scans advanced: ' . $r['processed'] . "\n";
