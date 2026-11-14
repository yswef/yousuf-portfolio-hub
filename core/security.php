<?php
declare(strict_types=1);

function start_session(string $name = 'ysid'): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name($name);
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function csrf_ok(): bool {
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)($_POST['_csrf'] ?? ''));
}

/* rate limiting by bucket (table rate_limits) */
function rate_count(string $bucket, int $window): int {
    return (int)val('SELECT COUNT(*) FROM rate_limits WHERE bucket=? AND ts>?', [$bucket, time() - $window]);
}
function rate_add(string $bucket): void {
    ins('rate_limits', ['bucket' => $bucket, 'ts' => time()]);
    if (random_int(1, 100) === 1) q('DELETE FROM rate_limits WHERE ts<?', [time() - 86400]);
}
function rate_clear(string $bucket): void { q('DELETE FROM rate_limits WHERE bucket=?', [$bucket]); }
function rate_hit(string $bucket, int $limit, int $window): bool {
    if (rate_count($bucket, $window) >= $limit) return false;
    rate_add($bucket);
    return true;
}

function audit(string $action, string $detail = ''): void {
    try { ins('audit_log', ['ts' => time(), 'admin' => (string)($_SESSION['admin_name'] ?? ''), 'action' => mb_substr($action, 0, 100), 'detail' => mb_substr($detail, 0, 250)]); } catch (Throwable $e) {}
}

function send_security_headers(bool $admin = false): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: ' . ($admin ? 'DENY' : 'SAMEORIGIN'));
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
    if (is_https()) header('Strict-Transport-Security: max-age=15552000');
}
