<?php
declare(strict_types=1);
/**
 * Core bootstrap — shared by the public site, the admin panel and the installer.
 * The panel package (core/ + admin/) is self-contained and only needs this folder.
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

// A single missing file must never take the whole site down (incomplete FTP uploads are the #1 cause
// of "HTTP 500 after an update"). Optional modules are only loaded when they exist, and every caller
// guards with function_exists().
$__coreFiles = ['helpers', 'db', 'security', 'i18n', 'autotranslate', 'markdown', 'upload', 'totp', 'mother_schema', 'team', 'handoff', 'app_api', 'mother_verify', 'migrate', 'sites', 'backup', 'update'];
$__coreMissing = [];
foreach ($__coreFiles as $__f) {
    if (is_file(__DIR__ . '/' . $__f . '.php')) require __DIR__ . '/' . $__f . '.php';
    else $__coreMissing[] = 'core/' . $__f . '.php';
}
if ($__coreMissing) @error_log('[bootstrap] core files missing: ' . implode(', ', $__coreMissing));
unset($__coreFiles, $__f, $__coreMissing);

// Errors: never shown to visitors; written to data/php-error.log (set 'debug' => true in config.php to display).
ini_set('log_errors', '1');
ini_set('error_log', ROOT . '/data/php-error.log');
// Errors are ALWAYS logged and NEVER echoed: a warning printed mid-page leaks absolute server paths
// and widens the layout on mobile (that is exactly what broke the site on phones).
// Debug details reach the admin through data/php-error.log and the system-check module only.
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

// A fatal error (memory/time limit/parse) must still end in a branded page, never a white screen.
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!$err || !in_array((int)$err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    @error_log('[fatal] ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
    if (headers_sent()) return;
    $isApi = str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/');
    http_response_code(500);
    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => ['code' => 'internal_error', 'message' => 'internal_error']], JSON_UNESCAPED_UNICODE);
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    // Everything below is best-effort: an error inside the error page must not cascade.
    $logo = 'assets/img/favicon-192.png';
    try { if (function_exists('update_logo_url')) $logo = (string)update_logo_url(); } catch (Throwable $e) {}
    $home = '/';
    try { if (function_exists('url')) $home = (string)url(''); } catch (Throwable $e) {}
    // A signed-in admin sees the real cause straight away (visitors never do).
    $detail = '';
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['admin_id'])) {
        $detail = '<pre dir="ltr" style="margin:18px auto 0;max-width:640px;text-align:start;white-space:pre-wrap;overflow-wrap:anywhere;'
                . 'background:#0b1622;border:1px solid #1e3346;border-radius:12px;padding:14px;font-size:.76rem;color:#cbd5e1">'
                . htmlspecialchars($err['message'] . "\n" . $err['file'] . ':' . $err['line'], ENT_QUOTES) . '</pre>';
    }
    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>خطأ مؤقت</title><style>html,body{margin:0;max-width:100%;overflow-x:hidden}body{font-family:system-ui,Tahoma,sans-serif;background:#050b14;color:#e8f1f9;min-height:100svh;display:grid;place-items:center;padding:24px;text-align:center}'
       . 'img{width:64px;height:64px;border-radius:18px;margin-bottom:14px;object-fit:cover}h1{font-size:1.1rem;margin:0 0 8px}p{color:#8fa9c3;font-size:.9rem;line-height:1.9;margin:0 0 16px}'
       . 'a{display:inline-block;padding:10px 22px;border-radius:12px;background:#2dd4bf;color:#04121a;font-weight:700;text-decoration:none}</style>'
       . '<body><div><img src="' . htmlspecialchars($logo, ENT_QUOTES) . '" alt=""><h1>خطأ مؤقت — تم تسجيله</h1>'
       . '<p>أعد تحميل الصفحة بعد لحظات. إن تكرر الخطأ فراجع لوحة التحكم ← فحص النظام.</p>'
       . '<a href="' . htmlspecialchars($home, ENT_QUOTES) . '">العودة للرئيسية</a>'
       . $detail . '</div></body></html>';
});
