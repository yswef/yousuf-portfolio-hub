<?php
declare(strict_types=1);
/**
 * Admin panel front controller.
 *   ?m=<module>             → module view        POST m=<module>&do=<action> → module action
 * Modules live in admin/modules/<id>/module.php (see admin/MODULES.md).
 */
define('ROOT', dirname(__DIR__));

// An update is being applied (files are being replaced one by one): show the standalone progress
// screen instead of booting the panel against a half-updated tree. It polls update_step.php and
// returns here automatically — no data is read or written by this guard.
$__updFile = ROOT . '/data/update/state.json';
if (is_file($__updFile)) {
    $__upd = json_decode((string)@file_get_contents($__updFile), true);
    $__ph = is_array($__upd) ? (string)($__upd['phase'] ?? '') : '';
    if (in_array($__ph, ['download', 'extract', 'plan', 'apply', 'verify'], true)) {
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
