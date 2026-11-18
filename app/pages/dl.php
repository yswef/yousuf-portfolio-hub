<?php
/* /dl/{file_id}: log the click, then 302 to the file (this script never streams the file itself). */
$id = (int)($segs[1] ?? 0);
$f = $id ? row('SELECT f.id, f.app_id, f.url FROM app_files f JOIN apps a ON a.id=f.app_id WHERE f.id=? AND f.hidden=0 AND a.hidden=0 AND a.ignored=0', [$id]) : null;
if (!$f || !preg_match('#^https://#i', (string)$f['url'])) { http_response_code(404); echo 'Not found'; exit; }
try {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (!is_bot_ua($ua) && rate_hit('dl:' . hmac(client_ip()), 60, 3600)) {
        $ref = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST) ?: '';
        ins('downloads', ['file_id' => $f['id'], 'app_id' => $f['app_id'], 'ts' => time(), 'country' => $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '', 'device' => device_of($ua), 'referrer' => mb_substr((string)$ref, 0, 190)]);
    }
} catch (Throwable $e) {}
header('Cache-Control: no-store'); header('Location: ' . $f['url'], true, 302); exit;
