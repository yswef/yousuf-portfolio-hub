<?php
declare(strict_types=1);
/**
 * Emergency status page — works even when the panel is down.
 * It does NOT load core/bootstrap.php, so a missing/broken core file cannot break it.
 *
 *   /status.php          → HTML (Arabic)
 *   /status.php?json=1   → JSON
 *
 * It never prints secrets: no paths except repo-relative file names, no SQL, no messages
 * (the full message is only returned when ?key= matches 'status_key' in config.php).
 */
$ROOT = __DIR__;

$cfg = [];
if (is_file($ROOT . '/config.php')) {
    try { $c = include $ROOT . '/config.php'; if (is_array($c)) $cfg = $c; } catch (Throwable $e) {}
}

$required = [
    'index.php', 'config.php', 'core/bootstrap.php', 'core/helpers.php', 'core/db.php', 'core/security.php',
    'core/i18n.php', 'core/update.php', 'core/team.php', 'core/migrate.php', 'admin/index.php',
    'admin/core/ui.php', 'admin/core/auth.php', 'update_start.php', 'update_step.php',
];
$missing = [];
foreach ($required as $f) if (!is_file($ROOT . '/' . $f)) $missing[] = $f;

$db = 'skip';
if ($cfg) {
    try {
        $pdo = new PDO(
            ($cfg['db_driver'] ?? '') === 'sqlite'
                ? 'sqlite:' . ($cfg['db_sqlite'] ?? $ROOT . '/data/site.sqlite')
                : 'mysql:host=' . ($cfg['db_host'] ?? 'localhost') . ';dbname=' . ($cfg['db_name'] ?? '') . ';charset=utf8mb4',
            (string)($cfg['db_user'] ?? ''),
            (string)($cfg['db_pass'] ?? ''),
            [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->query('SELECT 1');
        $db = 'ok';
    } catch (Throwable $e) { $db = 'fail'; }
}

$phase = 'none';
$st = $ROOT . '/data/update/state.json';
if (is_file($st)) {
    $j = json_decode((string)@file_get_contents($st), true);
    if (is_array($j)) $phase = (string)($j['phase'] ?? 'unknown');
}

$err = ['type' => '', 'at' => '', 'hash' => '', 'message' => ''];
$log = $ROOT . '/data/php-error.log';
if (is_file($log) && filesize($log) > 0) {
    $fp = @fopen($log, 'rb');
    if ($fp) {
        $size = (int)filesize($log);
        fseek($fp, max(0, $size - 8192));
        $tail = (string)fread($fp, 8192);
        fclose($fp);
        $lines = preg_split('/\R/', trim($tail)) ?: [];
        foreach (array_reverse($lines) as $line) {
            if ($line === '' || !preg_match('/PHP (Fatal error|Parse error|Recoverable fatal error)|Uncaught/', $line)) continue;
            $err['type'] = str_contains($line, 'Parse error') ? 'E_PARSE' : 'E_ERROR';
            $err['hash'] = substr(hash('sha256', $line), 0, 12);
            if (preg_match('# in (.+?) on line (\d+)#', $line, $m)) $err['at'] = basename($m[1]) . ':' . $m[2];
            $err['message'] = mb_substr(preg_replace('#(/[^\s:]+)+#', '<path>', $line), 0, 320);
            break;
        }
    }
}

$writable = is_writable($ROOT . '/data');

$out = [
    'ok'        => !$missing && $db !== 'fail' && $err['type'] === '',
    'php'       => PHP_VERSION,
    'missing'   => $missing,
    'db'        => $db,
    'data_writable' => $writable,
    'update_phase'  => $phase,
    'last_error'    => $err['type'] === '' ? null : ['type' => $err['type'], 'at' => $err['at'], 'hash' => $err['hash']],
    'hint'      => $missing ? 'ارفع الملفات الناقصة أعلاه عبر مدير الملفات ثم أعد المحاولة.'
                : ($db === 'fail' ? 'قاعدة البيانات لا تستجيب — تحقق من بيانات config.php في hPanel.'
                : ($err['type'] !== '' ? 'يوجد خطأ PHP مسجّل — راجع data/php-error.log أو أرسل بصمة الخطأ للمطوّر.'
                : 'كل شيء سليم.')),
];

$key = (string)($_GET['key'] ?? '');
if ($err['message'] !== '' && $key !== '' && hash_equals((string)($cfg['status_key'] ?? "\0"), $key)) {
    $out['last_error']['message'] = $err['message'];
}

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?><!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>حالة الموقع</title>
<style>
html,body{margin:0;max-width:100%;overflow-x:hidden}
body{background:#050b14;color:#e8f1f9;font-family:system-ui,Tahoma,sans-serif;padding:20px}
.card{max-width:640px;margin:5vh auto;background:#0a1626;border:1px solid #1b324e;border-radius:16px;padding:20px}
h1{font-size:1.1rem;margin:0 0 12px}
.row{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid #13263c;font-size:.88rem;flex-wrap:wrap}
.v{font-weight:700}
.ok{color:#2dd4bf}.bad{color:#f87171}.warn{color:#f59e0b}
code{background:#0f2036;padding:1px 6px;border-radius:6px;overflow-wrap:anywhere}
ul{padding-inline-start:18px;color:#f87171;font-size:.85rem;line-height:1.9}
a{color:#2dd4bf;text-decoration:none;font-size:.85rem}
</style></head><body>
<div class="card">
  <h1>حالة الموقع — فحص سريع</h1>
  <div class="row"><span>PHP</span><span class="v"><?= htmlspecialchars($out['php'], ENT_QUOTES) ?></span></div>
  <div class="row"><span>الملفات</span><span class="v <?= $missing ? 'bad' : 'ok' ?>"><?= $missing ? count($missing) . ' ملف ناقص' : 'مكتملة' ?></span></div>
  <div class="row"><span>قاعدة البيانات</span><span class="v <?= $db === 'ok' ? 'ok' : ($db === 'fail' ? 'bad' : 'warn') ?>"><?= htmlspecialchars($db, ENT_QUOTES) ?></span></div>
  <div class="row"><span>مجلد data</span><span class="v <?= $writable ? 'ok' : 'bad' ?>"><?= $writable ? 'قابل للكتابة' : 'غير قابل للكتابة' ?></span></div>
  <div class="row"><span>حالة التحديث</span><span class="v"><?= htmlspecialchars($phase, ENT_QUOTES) ?></span></div>
  <div class="row"><span>آخر خطأ</span><span class="v <?= $err['type'] ? 'bad' : 'ok' ?>"><?= $err['type'] ? htmlspecialchars($err['type'] . ' — ' . $err['at'], ENT_QUOTES) : 'لا يوجد' ?></span></div>
  <?php if ($missing): ?><h2 style="font-size:.95rem;margin:16px 0 6px">ملفات يجب رفعها:</h2><ul><?php foreach ($missing as $mf) echo '<li><code>' . htmlspecialchars($mf, ENT_QUOTES) . '</code></li>'; ?></ul><?php endif; ?>
  <p style="color:#8fa9c3;font-size:.84rem;line-height:1.9;margin:14px 0 0"><?= htmlspecialchars($out['hint'], ENT_QUOTES) ?></p>
  <p style="margin:14px 0 0"><a href="?json=1">JSON</a> · <a href="admin/">لوحة التحكم</a> · <a href="./">الموقع</a></p>
</div></body></html>
