<?php
declare(strict_types=1);
/* Optional: run from a Hostinger Cron Job → php /home/USER/public_html/cron_sync.php  (CLI only). */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT', __DIR__);
require ROOT . '/core/bootstrap.php';
require_once modules_dir() . '/projects/github.php';
[$ok, $sum] = sync_and_log(false);
echo ($ok ? 'OK ' : 'FAIL ') . $sum . "\n";
exit($ok ? 0 : 1);
