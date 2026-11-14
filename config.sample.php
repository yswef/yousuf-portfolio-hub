<?php
// Copy to config.php (the installer does this for you). NEVER publish this file.
return [
    'db_driver' => 'mysql',               // 'mysql' (Hostinger) or 'sqlite' (local testing)
    'db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'db_sqlite' => __DIR__ . '/data/site.sqlite',
    'secret' => 'CHANGE-ME-long-random-string',   // used to hash visitor ids / rate-limit keys
    'site_url' => '',                     // e.g. https://example.com  (blank = auto-detect)
    'github_user' => 'yswef',
    'github_token' => '',                 // optional: raises the GitHub API limit from 60 to 5000 requests/hour
    'trust_cf' => false,                  // true only if the site is behind Cloudflare
    'debug' => false,                     // true shows PHP errors on screen (never leave on)
];
