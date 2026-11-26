<?php
declare(strict_types=1);
/**
 * Resumes an interrupted update without any browser (Hostinger cron, every minute is fine):
 *   php /home/USER/domains/SITE/public_html/cron_update.php
 * Safe to call at any time: it does nothing when no update is in flight, and it stops on its own
 * when the queue is finished. Add --status to print the current phase without advancing it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT', __DIR__);
require ROOT . '/core/bootstrap.php';

$args = $argv ?? [];
if (in_array('--status', $args, true) || in_array('-s', $args, true)) {
    $s = update_state_read();
    if (!$s) { echo "IDLE\n"; exit(0); }
    echo 'PHASE ' . ($s['phase'] ?? '?') . ' · ' . (int)($s['done'] ?? 0) . '/' . (int)($s['total'] ?? 0)
       . ' · ' . update_percent($s) . "% · updated " . max(0, time() - (int)($s['updated'] ?? time())) . "s ago\n";
    exit(0);
}

if (!update_active()) { echo "IDLE: no update in progress\n"; exit(0); }

$budget = (int)(getenv('UPDATE_BUDGET') ?: 90);      // seconds of wall clock per cron run
$started = microtime(true);
$steps = 0;
while (microtime(true) - $started < $budget) {
    $before = (string)(update_state_read()['phase'] ?? '');
    $s = update_step();
    $steps++;
    $phase = (string)($s['phase'] ?? '');
    echo 'STEP ' . $steps . ' · ' . $phase . ' · ' . (int)($s['done'] ?? 0) . '/' . (int)($s['total'] ?? 0) . ' · ' . update_percent($s) . "%\n";
    if (in_array($phase, ['done', 'failed', 'rolledback', 'idle'], true)) break;
    if ($phase === $before && $steps > 2) usleep(250000);   // e.g. lock held elsewhere: slow down
    if (!update_active()) break;
}
$fin = update_state_read();
if ($fin) {
    echo 'FINAL ' . ($fin['phase'] ?? '?') . ' · ' . update_percent($fin) . "%\n";
    if (!empty($fin['errors'])) foreach (array_slice((array)$fin['errors'], -3) as $e) echo 'ERR ' . $e . "\n";
}
exit(0);
