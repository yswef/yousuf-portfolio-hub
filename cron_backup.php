<?php
declare(strict_types=1);
/* Weekly full backup — Hostinger Cron Job (once a week):  php /home/USER/.../public_html/cron_backup.php   (CLI only; add --force to ignore the 6-day guard) */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT', __DIR__);
require ROOT . '/core/bootstrap.php';
$last = backup_list()[0]['ts'] ?? 0;
if (!in_array('--force', $argv ?? [], true) && $last > time() - 6 * 86400) { echo "SKIP: last backup is newer than 6 days\n"; exit(0); }
try { echo 'OK ' . backup_run('cron') . "\n"; exit(0); }
catch (Throwable $e) {
    error_log('[backup] ' . $e->getMessage());
    set_setting('backup_last', json_encode(['ts' => time(), 'ok' => 0, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
    backup_notify('فشل النسخ الاحتياطي', $e->getMessage());
    echo 'FAIL ' . $e->getMessage() . "\n"; exit(1);
}
