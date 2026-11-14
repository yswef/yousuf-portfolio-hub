<?php
declare(strict_types=1);

/* ---------- config / paths ---------- */
function cfg(string $k, $d = null) {
    if (isset($GLOBALS['CFG_OVERRIDE'])) return $GLOBALS['CFG_OVERRIDE'][$k] ?? $d;   // used by install.php before config.php exists
    static $c = null;
    if ($c === null) { $f = ROOT . '/config.php'; $c = is_file($f) ? (require $f) : []; }
    return $c[$k] ?? $d;
}
function base(): string {
    static $b = null;
    if ($b !== null) return $b;
    $b = cfg('base_path');
    if ($b === null) {
        $dr = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
        $r = realpath(ROOT) ?: ROOT;
        $b = ($dr !== '' && str_starts_with($r, $dr)) ? str_replace('\\', '/', substr($r, strlen($dr))) : '';
    }
    return $b = rtrim((string)$b, '/');
}
function url(string $p = ''): string { return base() . '/' . ltrim($p, '/'); }
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function site_origin(): string {
    $o = cfg('site_url');
    if ($o) return rtrim((string)$o, '/');
    return (is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base();
}
function client_ip(): string {
    if (cfg('trust_cf') && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
function hmac(string $v): string { return substr(hash_hmac('sha256', $v, (string)cfg('secret', 'x')), 0, 24); }
function redirect(string $to, int $code = 302): never { header('Location: ' . $to, true, $code); exit; }

/* ---------- text helpers ---------- */
function slugify(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = (string)preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
    $s = trim($s, '-');
    return $s !== '' ? mb_substr($s, 0, 80) : 'item';
}
function unique_slug(string $table, string $base, int $exceptId = 0): string {
    $slug = $base; $i = 2;
    while (val("SELECT COUNT(*) FROM $table WHERE slug=? AND id<>?", [$slug, $exceptId])) $slug = $base . '-' . $i++;
    return $slug;
}
function fmt_date($ts): string {
    if (!$ts) return '';
    if (!is_numeric($ts)) $ts = strtotime((string)$ts);
    return $ts ? gmdate('Y-m-d', (int)$ts) : '';
}
function fmt_size($b): string {
    $b = (float)$b; $u = ['B', 'KB', 'MB', 'GB']; $i = 0;
    while ($b >= 1024 && $i < 3) { $b /= 1024; $i++; }
    return round($b, $i ? 1 : 0) . ' ' . $u[$i];
}
function device_of(string $ua): string {
    if (preg_match('/ipad|tablet/i', $ua)) return 'tablet';
    if (preg_match('/mobi|android|iphone/i', $ua)) return 'mobile';
    return 'desktop';
}
function browser_of(string $ua): string {
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $k => $n)
        if (stripos($ua, $k) !== false) return $n;
    return 'Other';
}
function is_bot_ua(string $ua): bool {
    return (bool)preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|headless|curl|wget|python-requests/i', $ua);
}
function age_from(string $birth): int {
    try { return (int)(new DateTime($birth))->diff(new DateTime())->y; } catch (Throwable $e) { return 0; }
}
function safe_url(string $u): string {      // only http(s)/mailto/tel links are ever printed into href
    return preg_match('#^(https?://|mailto:|tel:)#i', $u) ? $u : '';
}

/** GitHub credentials: dashboard setting (Settings → GitHub) wins, config.php is the fallback. */
function gh_token(): string { return trim(setting('github_token')) !== '' ? trim(setting('github_token')) : (string)cfg('github_token', ''); }
function gh_user(): string { return trim(setting('github_user')) !== '' ? trim(setting('github_user')) : (string)cfg('github_user', ''); }
