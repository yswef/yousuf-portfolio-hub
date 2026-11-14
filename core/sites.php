<?php
declare(strict_types=1);
/* Hosted documentation sites: files live in data/sites/<slug>/ (blocked by data/.htaccess) and are served ONLY through /s/<slug>/... */

const SITE_EXT = ['html', 'htm', 'css', 'js', 'mjs', 'json', 'txt', 'md', 'xml', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'map', 'mp4', 'webm', 'mp3', 'pdf'];
const SITE_MIME = ['html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8', 'mjs' => 'application/javascript; charset=utf-8',
    'json' => 'application/json; charset=utf-8', 'txt' => 'text/plain; charset=utf-8', 'md' => 'text/plain; charset=utf-8', 'xml' => 'application/xml', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
    'map' => 'application/json', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'pdf' => 'application/pdf'];
const SITE_MAX_FILES = 3000;
const SITE_MAX_BYTES = 150 * 1048576;     // total uncompressed size (zip-bomb guard)

function sites_dir(): string { return ROOT . '/data/sites'; }
function site_dir(string $slug): string { return sites_dir() . '/' . $slug; }
function rm_tree(string $d): void {
    if (!is_dir($d)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($d);
}
function site_clean_path(string $p): ?string {          // normalise an in-site relative path; null = unsafe
    $p = str_replace('\\', '/', $p);
    if ($p === '' || $p[0] === '/' || str_contains($p, "\0") || preg_match('#(^|/)\.\.?(/|$)#', $p)) return null;
    foreach (explode('/', $p) as $seg) if ($seg !== '' && $seg[0] === '.') return null;        // no dotfiles (.htaccess, .git ...)
    return $p;
}
/** Extract a zip into $dest. Returns [files_written, skipped_names[]]. Throws on unsafe/oversized archives. */
function site_extract_zip(string $zipFile, string $dest): array {
    $z = new ZipArchive();
    if ($z->open($zipFile) !== true) throw new RuntimeException('الملف المضغوط تالف أو ليس zip.');
    if ($z->numFiles > SITE_MAX_FILES) throw new RuntimeException('عدد الملفات كبير (الحد ' . SITE_MAX_FILES . ').');
    $names = []; $total = 0;
    for ($i = 0; $i < $z->numFiles; $i++) { $st = $z->statIndex($i); $n = (string)$st['name']; if (str_ends_with($n, '/') || str_starts_with($n, '__MACOSX/')) continue; $names[$i] = $n; $total += (int)$st['size']; }
    if ($total > SITE_MAX_BYTES) throw new RuntimeException('الحجم بعد الفك أكبر من ' . fmt_size(SITE_MAX_BYTES) . '.');
    $prefix = '';                                           // a zip made from a folder has one top folder → strip it
    $tops = array_unique(array_map(fn($n) => explode('/', str_replace('\\', '/', $n))[0], $names));
    if (count($tops) === 1 && count(array_filter($names, fn($n) => str_contains(str_replace('\\', '/', $n), '/'))) === count($names)) $prefix = reset($tops) . '/';
    $ok = 0; $skipped = [];
    foreach ($names as $i => $n) {
        $rel = str_replace('\\', '/', $n); if ($prefix !== '' && str_starts_with($rel, $prefix)) $rel = substr($rel, strlen($prefix));
        $clean = site_clean_path($rel);
        $ext = strtolower(pathinfo((string)$clean, PATHINFO_EXTENSION));
        if ($clean === null || !in_array($ext, SITE_EXT, true)) { $skipped[] = $rel; continue; }       // php/exe/sh/... never stored
        $target = $dest . '/' . $clean; if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
        $in = $z->getStream($n); if (!$in) { $skipped[] = $rel; continue; }
        $out = fopen($target, 'wb'); stream_copy_to_stream($in, $out); fclose($out); fclose($in); $ok++;
    }
    $z->close();
    return [$ok, $skipped];
}
/** Every .html/.htm file of a site → [relative path => title guessed from <title>/<h1>/file name]. */
function site_scan_pages(string $dir): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!$f->isFile() || !preg_match('/\.html?$/i', $f->getFilename())) continue;
        $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($dir))), '/');
        $head = (string)file_get_contents($f->getPathname(), false, null, 0, 20000);
        $t = preg_match('#<title[^>]*>(.*?)</title>#is', $head, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) : '';
        $out[$rel] = $t !== '' ? mb_substr($t, 0, 190) : preg_replace('/\.html?$/i', '', $rel);
    }
    ksort($out);
    return $out;
}
/** Adds new pages found on disk, drops rows whose file vanished. First "index.html" becomes the entry page. */
function site_sync_pages(int $siteId, string $slug): void {
    $found = site_scan_pages(site_dir($slug)); $have = [];
    foreach (rows('SELECT id, file FROM site_pages WHERE site_id=?', [$siteId]) as $r) { if (isset($found[$r['file']])) $have[$r['file']] = 1; else q('DELETE FROM site_pages WHERE id=?', [$r['id']]); }
    $n = 0;
    foreach ($found as $rel => $title) if (!isset($have[$rel])) ins('site_pages', ['site_id' => $siteId, 'file' => $rel, 'title' => $title, 'locked' => 0, 'sort' => ($rel === 'index.html' ? 0 : 10 + $n++)]);
}
/** True when the visitor holds a valid admin session (reads the admin cookie only; never creates a session for ordinary visitors). */
function is_admin_viewer(): bool {
    if (empty($_COOKIE['ysid_admin'])) return false;
    if (session_status() !== PHP_SESSION_ACTIVE) { session_name('ysid_admin'); @session_start(['read_and_close' => true, 'cookie_httponly' => true, 'cookie_secure' => is_https(), 'cookie_samesite' => 'Lax']); }
    return !empty($_SESSION['admin_id']) && (time() - (int)($_SESSION['last'] ?? 0)) <= 8 * 3600;
}
function site_lock_page(string $slug, string $path): never {
    http_response_code(403); header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
    $next = rawurlencode(url('s/' . $slug . '/' . $path));
    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>🔒</title>'
       . '<body style="font-family:system-ui,Tahoma,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#0a0a10;color:#f2efe4;text-align:center"><div><div style="font-size:64px">🔒</div>'
       . '<h2>هذه الصفحة خاصة</h2><p style="color:#a9a6b5">تظهر للمدير فقط بعد تسجيل الدخول.</p>'
       . '<a href="' . e(url('admin/') . '?next=' . $next) . '" style="display:inline-block;margin-top:8px;padding:10px 26px;border-radius:99px;background:#e8b923;color:#111;font-weight:700;text-decoration:none">تسجيل الدخول</a></div></body></html>';
    exit;
}
/** Serves /s/<slug>/<path>. Private site, or a locked page → admin session required. */
function site_serve(string $slug, string $path): never {
    $site = preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $slug) ? row('SELECT * FROM sites WHERE slug=?', [$slug]) : null;
    $path = trim($path, '/');
    $page = null;
    if ($site) {
        if ($path === '') { $e = row('SELECT file FROM site_pages WHERE site_id=? ORDER BY sort, id LIMIT 1', [$site['id']]); $path = $e['file'] ?? 'index.html'; }
        elseif (is_dir(site_dir($slug) . '/' . $path)) $path .= '/index.html';
        $page = row('SELECT * FROM site_pages WHERE site_id=? AND file=?', [$site['id'], $path]);
    }
    $clean = $site ? site_clean_path($path) : null;
    $ext = strtolower(pathinfo((string)$clean, PATHINFO_EXTENSION));
    $real = $clean !== null ? realpath(site_dir($slug) . '/' . $clean) : false;
    $base = realpath(site_dir($slug));
    if (!$real || !$base || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real) || !isset(SITE_MIME[$ext])) { http_response_code(404); echo 'Not found'; exit; }
    $admin = is_admin_viewer();
    $private = !(int)$site['is_public'] || ($page && (int)$page['locked']);
    if ($private && !$admin) { if ($page || in_array($ext, ['html', 'htm'], true)) site_lock_page($slug, $path); http_response_code(404); echo 'Not found'; exit; }
    header('Content-Type: ' . SITE_MIME[$ext]); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
    header($private ? 'Cache-Control: private, no-store' : 'Cache-Control: public, max-age=300');
    if ($private) header('X-Robots-Tag: noindex');
    if ((int)$site['is_public'] && in_array($ext, ['html', 'htm', 'svg'], true) && cfg('sites_sandbox', true)) header('Content-Security-Policy: sandbox allow-scripts allow-forms allow-popups allow-downloads');   // public sites run in an opaque origin → can never touch the admin session (private sites need the cookie for their assets, so no sandbox there)
    header('Content-Length: ' . filesize($real));
    readfile($real); exit;
}
