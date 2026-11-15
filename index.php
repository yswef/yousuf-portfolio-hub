<?php
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';

if (!is_file(ROOT . '/config.php')) {
    if (is_file(ROOT . '/install.php')) redirect(url('install.php'));
    http_response_code(503); echo 'Not installed'; exit;
}
require ROOT . '/app/layout.php';
require ROOT . '/app/parts.php';
send_security_headers();

$uri = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'));
$b = base();
if ($b !== '' && str_starts_with($uri, $b)) $uri = substr($uri, strlen($b));
$segs = array_values(array_filter(explode('/', trim($uri, '/')), 'strlen'));
$top = $segs[0] ?? '';

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

    $simple = ['docs', 'home', 'about', 'resume', 'projects', 'apps', 'services', 'learning', 'blog', 'contact', 'social', 'privacy'];
    $detail = ['projects', 'apps', 'blog'];
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
    error_log('[site] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);
    echo cfg('debug') ? '<pre>' . e((string)$e) . '</pre>' : '<!doctype html><meta charset="utf-8"><p style="font-family:sans-serif;padding:40px">Temporary error — please try again later.</p>';
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
