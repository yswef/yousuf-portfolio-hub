<?php
declare(strict_types=1);
/**
 * Progress + resume endpoint for the safe updater.
 * Authorised only by the per-update token that is printed inside the maintenance / wait pages,
 * and rate-limited. It never accepts file paths — it only advances the already-planned queue.
 */
$__out = ['ok' => false];
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    require __DIR__ . '/core/bootstrap.php';

    $token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
    $s = update_state_read();

    $respond = static function (array $state) use ($s): void {
        $phase = (string)($state['phase'] ?? 'idle');
        $total = (int)($state['total'] ?? 0);
        $done = (int)($state['done'] ?? 0);
        $meta = [
            'download' => 'تنزيل الحزمة',
            'extract'  => 'فكّ الضغط',
            'plan'     => 'قراءة سجل الملفات المتغيّرة',
            'apply'    => 'استبدال الملفات',
            'verify'   => 'التحقق النهائي',
            'done'     => 'اكتمل التحديث',
            'failed'   => 'توقّف التحديث',
            'rolledback' => 'تم التراجع',
            'idle'     => 'لا تحديث جارٍ',
        ][$phase] ?? $phase;
        $tail = ($phase === 'apply' && $total > 0) ? ' · ' . $done . '/' . $total . ' ملف' : '';
        echo json_encode([
            'ok'          => true,
            'phase'       => $phase,
            'phase_label' => $meta . $tail,
            'meta'        => $meta . $tail,
            'percent'     => (int)($state['percent'] ?? 0),
            'done'        => $done,
            'total'       => $total,
            'eta'         => (int)($state['eta'] ?? 0),
            'current'     => (string)($state['current'] ?? ''),
            'errors'      => array_slice((array)($state['errors'] ?? []), -5),
            'done_all'    => in_array($phase, ['done', 'idle', 'rolledback'], true),
        ], JSON_UNESCAPED_UNICODE);
    };

    if (!$s || !update_token_ok($token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Abuse guard: a page can poll ~once per second, nothing more.
    if (function_exists('rate_hit')) rate_hit('update_step:' . hmac(client_ip()), 240, 60);

    // Manual rollback from the maintenance / wait page (token-authorised, same update only).
    if (isset($_GET['rollback'])) {
        try {
            $s = update_rollback($s);
            $respond(['phase' => $s['phase'], 'percent' => 0, 'total' => 0, 'done' => 0]);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    $before = (string)($s['phase'] ?? '');
    $state = update_step();
    $state['phase'] = (string)($state['phase'] ?? $before);
    $state['eta'] = isset($state['eta']) ? (int)$state['eta'] : 0;
    $respond($state);
} catch (Throwable $e) {
    error_log('[update_step] ' . $e->getMessage());
    http_response_code(200);                       // never break the waiting page
    echo json_encode(['ok' => false, 'phase' => 'failed', 'phase_label' => 'تعذّر إكمال الخطوة', 'meta' => 'تعذّر إكمال الخطوة — راجع لوحة التحكم', 'percent' => 0, 'errors' => [$e->getMessage()]], JSON_UNESCAPED_UNICODE);
}
