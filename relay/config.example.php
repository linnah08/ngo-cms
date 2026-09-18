<?php
// Copy to config.php (same directory) on the server and fill in the Trello credentials.
// config.php is gitignored. It must live OUTSIDE public/ (the web root) — which it does if left here.
return [
    // Generate at https://trello.com/power-ups/admin → your Power-Up → API key → "Token" link.
    'trello_key'   => '',
    'trello_token' => '',

    // SHA-256 hashes of support codes → customer + Trello list. Managed with bin/add-install.php.
    'installs_file' => __DIR__ . '/installs.json',

    // Rate-limit state (data/ratelimit/) and the relay log (data/logs/relay.log).
    'data_dir' => __DIR__ . '/data',

    // Max tickets per install per rolling hour.
    'rate_limit_per_hour' => 10,
];
