<?php
declare(strict_types=1);
/**
 * Starts an update in the background (AJAX) so the admin keeps the page they are on.
 * The browser then polls update_step.php; the public site shows the maintenance page meanwhile.
 * Admin session + CSRF are required, and only roles allowed to open the updater may call it.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$fail = static function (int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

try {
    require __DIR__ . '/core/bootstrap.php';
    start_session('ysid_admin');
    if (empty($_SESSION['admin_id'])) $fail(401, 'انتهت الجلسة — سجّل الدخول ثم أعد المحاولة.');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $fail(405, 'POST فقط.');
    if (!csrf_ok()) $fail(419, 'رمز الحماية غير صالح — أعد تحميل الصفحة.');
    try { if (function_exists('team_can') && !team_can('updater')) $fail(403, 'لا تملك صلاحية التحديث.'); } catch (Throwable $e) {}

    $mode = (string)($_POST['mode'] ?? 'github');
    $state = update_state_read();
    if ($state && update_active($state)) $fail(409, 'هناك تحديث قيد التنفيذ بالفعل — المتابعة جارية.');

    if ($mode === 'github') {
        $repo = trim((string)($_POST['repo'] ?? ''), "/ \t\n\r");
        $branch = trim((string)($_POST['branch'] ?? ''));
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) $fail(422, 'صيغة المستودع غير صحيحة (owner/repo).');
        if (!preg_match('#^[A-Za-z0-9_./-]+$#', $branch) || $branch === '') $fail(422, 'اسم الفرع غير صحيح.');
        set_setting('updater_repo', $repo);
        set_setting('updater_branch', $branch);
        $snap = update_start_github($repo, $branch);
        audit('site_update_github', $repo . '@' . $branch);
    } elseif ($mode === 'zip') {
        $f = $_FILES['release_zip'] ?? null;
        if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($f['tmp_name'])) $fail(422, 'لم يصل ملف ZIP صالح (تحقّق من حد الرفع في السيرفر).');
        $name = basename((string)($f['name'] ?? 'release.zip'));
        if (!preg_match('/\.zip$/i', $name)) $fail(422, 'الملف يجب أن يكون .zip');
        $snap = update_start_zip((string)$f['tmp_name'], 'ZIP: ' . $name);
        audit('site_update_zip', $name);
    } else {
        $fail(422, 'نوع تحديث غير معروف.');
    }

    $st = update_state_read();
    echo json_encode([
        'ok'      => true,
        'token'   => (string)($st['token'] ?? ''),
        'phase'   => (string)($snap['phase'] ?? 'apply'),
        'percent' => (int)($snap['percent'] ?? 0),
        'total'   => (int)($snap['total'] ?? 0),
        'done'    => (int)($snap['done'] ?? 0),
        'tag'     => (string)($snap['tag'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[update_start] ' . $e->getMessage());
    $st = update_state_read();
    if ($st && ($st['phase'] ?? '') === 'download') { $st['phase'] = 'failed'; $st['errors'][] = $e->getMessage(); update_state_write($st); }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'فشل بدء التحديث: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
