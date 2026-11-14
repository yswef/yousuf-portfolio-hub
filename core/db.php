<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (cfg('db_driver') === 'sqlite') {
        $pdo = new PDO('sqlite:' . cfg('db_sqlite', ROOT . '/data/site.sqlite'));
    } else {
        $pdo = new PDO('mysql:host=' . cfg('db_host', 'localhost') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4',
            (string)cfg('db_user'), (string)cfg('db_pass'));
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}
function db_driver(): string { return cfg('db_driver') === 'sqlite' ? 'sqlite' : 'mysql'; }
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function row(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
function ins(string $t, array $d): int {
    $c = array_keys($d);
    q("INSERT INTO $t (" . implode(',', $c) . ") VALUES (" . implode(',', array_fill(0, count($c), '?')) . ")", array_values($d));
    return (int)db()->lastInsertId();
}
function upd(string $t, array $d, string $where, array $wp = []): void {
    $set = implode(',', array_map(fn($c) => "$c=?", array_keys($d)));
    q("UPDATE $t SET $set WHERE $where", array_merge(array_values($d), $wp));
}
function table_exists(string $t): bool {
    try { q("SELECT 1 FROM $t LIMIT 1"); return true; } catch (Throwable $e) { return false; }
}

/* ---------- settings (k/v) ---------- */
function settings_all(bool $reload = false): array {
    static $c = null;
    if ($c === null || $reload) { $c = []; try { foreach (rows('SELECT k,v FROM settings') as $r) $c[$r['k']] = (string)$r['v']; } catch (Throwable $e) {} }
    return $c;
}
function setting(string $k, string $d = ''): string { return settings_all()[$k] ?? $d; }
function set_setting(string $k, string $v): void {
    if (val('SELECT COUNT(*) FROM settings WHERE k=?', [$k])) upd('settings', ['v' => $v], 'k=?', [$k]);
    else ins('settings', ['k' => $k, 'v' => $v]);
    settings_all(true);
}
