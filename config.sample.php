<?php
// Example only. install.php writes the real config.php for you.
// If the installer can't write files on your host, copy this to config.php and fill it in.
// Never commit config.php or share it publicly.
return [
    'db' => [
        'driver' => 'mysql',
        'host'   => 'localhost',
        'port'   => 3306,
        'name'   => 'your_database_name',
        'user'   => 'your_database_user',
        'pass'   => 'your_database_password',
    ],
    'app_name' => 'OpenGrid Ranker',            // shown in the header and on PDF reports
    'timezone' => 'America/Chicago',      // any PHP timezone, e.g. Europe/London
    'cron_key' => 'replace-with-a-long-random-string', // protects cron.php?key=...
];
