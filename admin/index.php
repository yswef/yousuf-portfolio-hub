<?php
declare(strict_types=1);
/**
 * Admin panel front controller.
 *   ?m=<module>             → module view        POST m=<module>&do=<action> → module action
 * Modules live in admin/modules/<id>/module.php (see admin/MODULES.md).
 */
define('ROOT', dirname(__DIR__));

// Open the admin session BEFORE the core is loaded, so the fatal-error screen can tell a signed-in
// admin exactly what broke (visitors never see details).
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_name('ysid_admin');
    @session_start();
}

// An update is being applied (files are being replaced one by one): show the standalone progress
// screen instead of booting the panel against a half-updated tree. It polls update_step.php and
// returns here automatically — no data is read or written by this guard.
$__updFile = ROOT . '/data/update/state.json';
if (is_file($__updFile)) {
    $__upd = json_decode((string)@file_get_contents($__updFile), true);
    $__ph = is_array($__upd) ? (string)($__upd['phase'] ?? '') : '';
    if (in_array($__ph, ['download', 'extract', 'plan', 'apply', 'verify'], true) && is_file(__DIR__ . '/core/update_wait.php')) {
        require __DIR__ . '/core/update_wait.php';
        exit;
    }
}
unset($__updFile, $__upd, $__ph);

require ROOT . '/core/bootstrap.php';
if (!is_file(ROOT . '/config.php')) redirect(url('install.php'));
define('LANG', 'ar');                                   // the panel UI is Arabic / RTL
foreach (['ui', 'crud', 'auth'] as $f) require __DIR__ . '/core/' . $f . '.php';

send_security_headers(true);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
start_session('ysid_admin');

$nx = (string)($_GET['next'] ?? '');   // return-to after login: same-site paths only
if ($nx !== '' && $nx[0] === '/' && !str_starts_with($nx, '//') && !preg_match('/[\\\r\n]/', $nx)) $_SESSION['next'] = $nx;
if (!admin_gate()) exit;                                // login / 2FA screens handled inside (exit when not authenticated)

/**
 * Rescue wrapper: whatever throws afterwards (a broken module, a missing helper, a bad migration)
 * is shown to the signed-in admin as a readable page with the fix — the panel never dies silently.
 */
try {
try { migrate_all(); } catch (Throwable $e) { adm_fatal('Migration failed: ' . $e->getMessage()); }
team_ensure_primary();
team_session_guard();
$MODS = modules_load();
$ALL_MODS = $MODS;                                      // full registry (dashboard counters, links)
$MODS = team_filter_modules($MODS);                     // only what the current account may open
$ADMIN = [
    'id'     => (int)$_SESSION['admin_id'],
    'name'   => (string)$_SESSION['admin_name'],
    'role'   => team_role(),
    'role_label' => (team_roles()[team_role()] ?? team_roles()['editor'])['label'],
    'owner'  => team_is_owner(),
];

/* ---------- POST: actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('رمز الحماية غير صالح، أعد المحاولة.', false); adm_back('dashboard'); }
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'logout') { audit('logout'); $_SESSION = []; session_destroy(); redirect(url('admin/')); }
    $mid = (string)($_POST['m'] ?? '');
    if (isset($MODS[$mid]['actions'][$do]) && is_callable($MODS[$mid]['actions'][$do])) {
        if (!team_can_action($mid, $do)) { flash('لا تملك صلاحية تنفيذ هذا الإجراء (' . e($do) . ').', false); adm_back('dashboard'); }
        try { ($MODS[$mid]['actions'][$do])(); }
        catch (Throwable $e) { error_log('[admin:' . $mid . '/' . $do . '] ' . $e->getMessage()); flash('خطأ: ' . (cfg('debug') ? $e->getMessage() : 'راجع data/php-error.log'), false); }
        adm_back($mid);
    }
    flash('إجراء غير معروف.', false); adm_back('dashboard');
}

/* ---------- GET: view ---------- */
$cur = (string)($_GET['m'] ?? 'dashboard');
if ($cur !== 'dashboard' && !isset($ALL_MODS[$cur])) $cur = 'dashboard';
if ($cur !== 'dashboard' && !isset($MODS[$cur])) {
    adm_head('dashboard');
    adm_h('صلاحية غير كافية', 'هذا التبويب غير متاح لدورك الحالي (' . $ADMIN['role_label'] . '). تواصل مع امبراطور السحر يوسف لمنحك الصلاحية.');
    echo '<div class="note bad">🚫 لا تملك صلاحية فتح «' . e(adm_title($ALL_MODS[$cur])) . '».</div>';
    echo '<div class="row"><a class="btn" href="' . adm_url('dashboard') . '">⌂ الرئيسية</a></div>';
    adm_foot();
    exit;
}
if ($cur !== 'dashboard' && isset($MODS[$cur]['download']) && isset($_GET['dl'])) { ($MODS[$cur]['download'])((string)$_GET['dl']); exit; }

adm_head($cur);
if ($cur === 'dashboard') require __DIR__ . '/core/dashboard.php';
else {
    $v = $MODS[$cur]['view'] ?? null;
    if (is_callable($v)) $v($MODS[$cur]);
    elseif (is_string($v) && is_file($MODS[$cur]['dir'] . '/' . $v)) { $M = $MODS[$cur]; require $MODS[$cur]['dir'] . '/' . $v; }
    else echo '<div class="note bad">هذه الوحدة لا تحتوي على واجهة (view).</div>';
}
adm_foot();
} catch (Throwable $e) {
    error_log('[admin] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); }
    $msg = e($e->getMessage());
    $where = e(basename($e->getFile()) . ':' . $e->getLine());
    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>خطأ في لوحة التحكم</title><body style="margin:0;background:#050b14;color:#e8f1f9;font-family:system-ui,Tahoma,sans-serif;padding:22px;max-width:100%;overflow-x:hidden">'
       . '<div style="max-width:620px;margin:6vh auto;background:#0a1626;border:1px solid #1b324e;border-radius:16px;padding:22px">'
       . '<h2 style="margin:0 0 8px;font-size:1.05rem">تعذّر تحميل هذه الصفحة — الخطأ مسجَّل في data/php-error.log</h2>'
       . '<p style="color:#8fa9c3;font-size:.86rem;line-height:1.9;margin:0 0 12px">بقية الموقع يعمل. جرّب الصفحة الرئيسية للوحة، وإن تكرر الخطأ أرسل السطر التالي للمطوّر.</p>'
       . '<pre dir="ltr" style="white-space:pre-wrap;overflow-wrap:anywhere;background:#0b1622;border:1px solid #1e3346;border-radius:12px;padding:12px;font-size:.78rem">'
       . $msg . "\n" . $where . '</pre>'
       . '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">'
       . '<a href="' . e(adm_url('dashboard')) . '" style="background:#2dd4bf;color:#04121a;font-weight:700;padding:9px 18px;border-radius:10px;text-decoration:none">⌂ الرئيسية</a>'
       . '<a href="' . e(url('')) . '" style="color:#2dd4bf;padding:9px 0;text-decoration:none;font-size:.85rem">↗ الموقع</a></div>'
       . '</div></body></html>';
}
