<?php
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';

if (!is_file(ROOT . '/config.php')) {
    if (is_file(ROOT . '/install.php')) redirect(url('install.php'));
    http_response_code(503); echo 'Not installed'; exit;
}

// Safe updater: while files are being replaced the whole public site answers with the branded
// maintenance page (API callers get JSON 503) so nobody ever loads a half-updated tree.
$__path = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'));
$__base = base();
if ($__base !== '' && str_starts_with($__path, $__base)) $__path = substr($__path, strlen($__base));
$__first = explode('/', trim($__path, '/'))[0] ?? '';
update_guard_public($__first === 'api' ? 'api' : '');

require ROOT . '/app/layout.php';
require ROOT . '/app/parts.php';

$uri = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'));
$b = base();
if ($b !== '' && str_starts_with($uri, $b)) $uri = substr($uri, strlen($b));
$segs = array_values(array_filter(explode('/', trim($uri, '/')), 'strlen'));
$top = $segs[0] ?? '';

// Mother Panel JSON API gateway: /api/... is ALWAYS JSON (never HTML, even on errors)
if ($top === 'api') {
    app_api_dispatch(implode('/', array_slice($segs, 1)));
}
send_security_headers();

try {
    /* ---- language-less routes ---- */
    if ($top === '') {
        $pick = DEFAULT_LANG;
        foreach (explode(',', strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
            $c = substr(trim($part), 0, 2);
            if (isset(LANGS[$c])) { $pick = $c; break; }
        }
        redirect(url($pick . '/'));
    }
    if ($top === 's') { require_once ROOT . '/core/sites.php'; site_serve($segs[1] ?? '', implode('/', array_slice($segs, 2))); }
    if ($top === 'dl')          { require ROOT . '/app/pages/dl.php'; exit; }
    if ($top === 'sitemap.xml') { define('LANG', DEFAULT_LANG); require ROOT . '/app/pages/sitemap.php'; exit; }
    if ($top === 'robots.txt')  {
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\nAllow: /\nDisallow: " . base() . "/admin/\nDisallow: " . base() . "/dl/\nSitemap: " . site_origin() . "/sitemap.xml\n"; exit;
    }

    $known = isset(LANGS[$top]);
    define('LANG', $known ? $top : DEFAULT_LANG);
    $parts = $known ? array_slice($segs, 1) : [];
    $REST = implode('/', $parts);
    $route = $parts[0] ?? 'home';
    $arg = $parts[1] ?? null;

    $simple = ['docs', 'library', 'home', 'about', 'resume', 'projects', 'apps', 'services', 'learning', 'blog', 'contact', 'social', 'privacy'];
    $detail = ['projects', 'apps', 'blog', 'docs'];
    $file = null;
    if ($known && count($parts) <= 2) {
        if ($route === 'p' && $arg !== null) $file = 'custom';
        elseif (in_array($route, $simple, true)) { if ($arg === null) $file = $route; elseif (in_array($route, $detail, true)) $file = $route . '_one'; }
    }
    if ($file) {
        $ok = require ROOT . "/app/pages/$file.php";       // a page returns false when its record doesn't exist
        if ($ok !== false) {
            try { if (setting('track', '1') === '1' && $_SERVER['REQUEST_METHOD'] === 'GET') track_visit('/' . LANG . ($REST !== '' ? '/' . $REST : '')); } catch (Throwable $e) {}
            exit;
        }
        if (ob_get_level()) ob_clean();
    }
    http_response_code(404);
    $REST = '';
    require ROOT . '/app/pages/notfound.php';
} catch (Throwable $e) {
    // Always log; never show the exception (it contains absolute server paths and breaks the layout).
    error_log('[site] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); }
    if (function_exists('update_active') && update_active()) { update_guard_public($top === 'api' ? 'api' : ''); }
    $detail = '';
    if (cfg('debug') && function_exists('is_admin_viewer') && is_admin_viewer()) {
        $detail = '<pre dir="ltr" style="white-space:pre-wrap;overflow-wrap:anywhere;max-width:100%;background:#0b1622;border:1px solid #1e3346;border-radius:12px;padding:14px;font-size:.78rem">'
                . e(get_class($e) . ': ' . $e->getMessage() . '\n' . $e->getFile() . ':' . $e->getLine()) . '</pre>';
    }
    echo '<!doctype html><html lang="' . e(defined('LANG') && isset(LANGS[LANG]) ? LANG : DEFAULT_LANG) . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>خطأ مؤقت</title><body style="font-family:system-ui,Tahoma,sans-serif;background:#050b14;color:#e8f1f9;display:grid;place-items:center;min-height:100svh;margin:0;padding:24px;text-align:center;overflow-x:hidden">'
       . '<div style="max-width:560px;width:100%"><div style="font-size:40px;margin-bottom:12px">🛠</div>'
       . '<h2 style="margin:0 0 8px;font-size:1.1rem">خطأ مؤقت — تم تسجيله وسيُعالج</h2>'
       . '<p style="color:#8fa9c3;font-size:.9rem;line-height:1.8">أعد تحميل الصفحة بعد قليل. إن استمر الخطأ فراجع لوحة التحكم ← فحص النظام.</p>'
       . '<a href="' . e(url('')) . '" style="display:inline-block;margin-top:14px;padding:10px 22px;border-radius:12px;background:#2dd4bf;color:#04121a;font-weight:700;text-decoration:none">العودة للرئيسية</a>'
       . $detail . '</div></body></html>';
}

/** First-party analytics: no IP stored; visitor id = daily-salted hash. */
function track_visit(string $path): void {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ref = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST) ?: '';
    if ($ref === ($_SERVER['HTTP_HOST'] ?? '')) $ref = '';
    ins('visits', ['ts' => time(), 'visitor' => substr(hash_hmac('sha256', client_ip() . '|' . $ua . '|' . gmdate('Y-m-d'), (string)cfg('secret', 'x')), 0, 16),
        'path' => mb_substr($path, 0, 190), 'lang' => LANG, 'country' => $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '', 'device' => device_of($ua), 'browser' => browser_of($ua),
        'referrer' => mb_substr((string)$ref, 0, 190), 'is_bot' => is_bot_ua($ua) ? 1 : 0]);
}
