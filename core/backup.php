<?php
declare(strict_types=1);
/* Full-site backup: ONE zip = every site file + database.sql + restore.php (re-install script for another host). Stored in data/backups/ (web-blocked). */

const BACKUP_SEP = "\n-- ~~STMT~~\n";
const BACKUP_KEEP = 2;           // newest copies kept
const BACKUP_MAX_AGE = 28 * 86400; // older than 4 weeks → removed (the newest copy is never removed)

function backup_dir(): string { return ROOT . '/data/backups'; }
function backup_list(): array {
    $out = [];
    foreach (glob(backup_dir() . '/backup-*.zip') ?: [] as $f) {
        $n = basename($f); if (!preg_match('/^backup-(\d{8})-(\d{6})\.zip$/', $n, $m)) continue;
        $out[] = ['name' => $n, 'path' => $f, 'size' => (int)filesize($f), 'ts' => (int)filemtime($f), 'key' => trim((string)@file_get_contents($f . '.key'))];
    }
    usort($out, fn($a, $b) => $b['ts'] <=> $a['ts']);
    return $out;
}
function backup_sql_value(PDO $pdo, $v): string {
    if ($v === null) return 'NULL';
    if (is_int($v) || is_float($v)) return (string)$v;
    return $pdo->quote((string)$v);
}
/** Portable dump (MySQL or SQLite): DROP + CREATE + batched INSERTs, statements separated by BACKUP_SEP. Returns table count. */
function backup_sql_dump(string $file): int {
    $pdo = db(); $my = db_driver() === 'mysql';
    $o = fopen($file, 'wb'); if (!$o) throw new RuntimeException('تعذّر إنشاء ملف SQL مؤقت.');
    $w = fn(string $s) => fwrite($o, $s . BACKUP_SEP);
    if ($my) { $w('SET NAMES utf8mb4'); $w('SET FOREIGN_KEY_CHECKS=0'); $w("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'"); $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false); }
    try {
        $tables = $my ? $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) : $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $t = (string)$t;
            $w("DROP TABLE IF EXISTS `$t`");
            if ($my) { $c = $pdo->query("SHOW CREATE TABLE `$t`")->fetchAll(PDO::FETCH_NUM); $w((string)$c[0][1]); }
            else {
                $w((string)$pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($t))->fetchColumn());
                foreach ($pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND sql IS NOT NULL AND tbl_name=" . $pdo->quote($t))->fetchAll(PDO::FETCH_COLUMN) as $ix) $w((string)$ix);
            }
            $st = $pdo->query("SELECT * FROM `$t`"); $cols = null; $batch = [];
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $cols ??= '(`' . implode('`,`', array_keys($r)) . '`)';
                $batch[] = '(' . implode(',', array_map(fn($v) => backup_sql_value($pdo, $v), array_values($r))) . ')';
                if (count($batch) >= 100) { $w("INSERT INTO `$t` $cols VALUES " . implode(',', $batch)); $batch = []; }
            }
            if ($batch) $w("INSERT INTO `$t` $cols VALUES " . implode(',', $batch));
            $st->closeCursor();
        }
        if ($my) $w('SET FOREIGN_KEY_CHECKS=1');
    } finally { if ($my) $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); fclose($o); }
    return count($tables);
}
/** Builds the full backup zip. Returns ['name','size','key','tables','files']. */
function backup_create(): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('امتداد zip غير مفعّل في PHP.');
    @set_time_limit(900); @ignore_user_abort(true);
    $dir = backup_dir(); if (!is_dir($dir)) mkdir($dir, 0755, true);
    $stamp = gmdate('Ymd-His'); $name = "backup-$stamp.zip"; $part = "$dir/$name.part"; $sql = "$dir/.db-$stamp.sql";
    $key = bin2hex(random_bytes(6));
    $tpl = (string)@file_get_contents(ROOT . '/core/restore.tpl'); if ($tpl === '') throw new RuntimeException('core/restore.tpl مفقود.');
    $tables = backup_sql_dump($sql);
    $z = new ZipArchive(); if ($z->open($part, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { @unlink($sql); throw new RuntimeException('تعذّر إنشاء الملف المضغوط (المساحة/الصلاحيات).'); }
    $sqliteFile = db_driver() === 'sqlite' ? realpath((string)cfg('db_sqlite', ROOT . '/data/site.sqlite')) : false;
    $root = realpath(ROOT); $n = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!$f->isFile() || $f->isLink()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if (str_starts_with($rel, 'data/backups/') || str_starts_with($rel, '.git/') || $rel === 'data/php-error.log' || ($sqliteFile && $f->getRealPath() === $sqliteFile) || str_ends_with($rel, '.part')) continue;
        $z->addFile($f->getPathname(), $rel); $n++;
    }
    $z->addFile($sql, 'data/_backup/database.sql');
    $z->addFromString('data/_backup/manifest.json', json_encode(['generated' => gmdate('c'), 'driver' => db_driver(), 'tables' => $tables, 'files' => $n, 'php' => PHP_VERSION, 'site_url' => site_origin(), 'old_root' => $root], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $z->addFromString('restore.php', str_replace('__KEYHASH__', hash('sha256', $key), $tpl));
    $z->addFromString('README-RESTORE.txt', "1) Create a MySQL database on the new host.\n2) Extract this zip into public_html (keep the data folder).\n3) Open https://YOUR-DOMAIN/restore.php, enter the restore key + the new DB details.\n4) The script imports database.sql, rewrites config.php and deletes itself.\nRestore key: shown in the admin panel next to this backup.\n");
    if (!$z->close()) { @unlink($sql); @unlink($part); throw new RuntimeException('فشل إغلاق الملف المضغوط (غالباً المساحة ممتلئة).'); }
    @unlink($sql);
    rename($part, "$dir/$name"); file_put_contents("$dir/$name.key", $key); @chmod("$dir/$name.key", 0640);
    return ['name' => $name, 'size' => (int)filesize("$dir/$name"), 'key' => $key, 'tables' => $tables, 'files' => $n];
}
function backup_delete(string $name): void {
    if (!preg_match('/^backup-\d{8}-\d{6}\.zip$/', $name)) return;
    @unlink(backup_dir() . "/$name"); @unlink(backup_dir() . "/$name.key");
}
/** Copies beyond the newest BACKUP_KEEP, plus anything older than 4 weeks — except the newest copy, which is always kept. */
function backup_retention_candidates(): array {
    $out = [];
    foreach (backup_list() as $i => $b) if ($i > 0 && ($i >= BACKUP_KEEP || $b['ts'] < time() - BACKUP_MAX_AGE)) $out[] = $b;
    return $out;
}
function backup_email(): string { $m = trim(setting('backup_email')); return filter_var($m, FILTER_VALIDATE_EMAIL) ? $m : ''; }
function backup_notify(string $subject, string $body): bool {
    $to = backup_email(); if ($to === '') return false;
    $host = parse_url(site_origin(), PHP_URL_HOST) ?: 'localhost';
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, "From: backup@$host\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit");
}
/** Create → notify → prune (old copies are deleted only after the notice went out). Returns a human summary; throws on backup failure. */
function backup_run(string $by): string {
    $b = backup_create();
    $old = backup_retention_candidates(); $names = array_map(fn($x) => $x['name'], $old);
    $lines = ["تم إنشاء نسخة احتياطية كاملة ($by)", $b['name'] . ' · ' . fmt_size($b['size']) . " · {$b['tables']} جدول · {$b['files']} ملف"];
    if ($old) $lines[] = 'النسخ التي سيتم حذفها الآن: ' . implode('، ', $names);
    $mailOn = backup_email() !== ''; $sent = backup_notify('نسخة احتياطية جديدة — ' . (parse_url(site_origin(), PHP_URL_HOST) ?: 'site'), implode("\n", $lines));
    $msg = $b['name'] . ' (' . fmt_size($b['size']) . ')';
    if ($old) {
        if ($mailOn && !$sent) $msg .= ' · تعذّر إرسال الإشعار فلم تُحذف النسخ القديمة (' . count($old) . ')';
        else { foreach ($old as $x) backup_delete($x['name']); $msg .= ' · حُذفت ' . count($old) . ' نسخة قديمة' . ($mailOn ? ' بعد إرسال الإشعار' : ' (لا بريد للإشعارات — سُجّل في سجل النشاط)'); audit('backup_pruned', implode(',', $names)); }
    }
    if ($mailOn && $sent) $msg .= ' · أُرسل إشعار';
    audit('backup_created', $b['name']); set_setting('backup_last', json_encode(['ts' => time(), 'ok' => 1, 'msg' => $msg], JSON_UNESCAPED_UNICODE));
    return $msg;
}
