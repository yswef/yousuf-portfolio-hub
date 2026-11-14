<?php
declare(strict_types=1);
/**
 * Schema + module registry.
 * A module is a folder admin/modules/<id>/module.php returning a manifest array (see admin/MODULES.md).
 * Each module may declare 'schema' (callable($T) => SQL[]), 'version' (int) and 'seed' (callable, runs once).
 * Tables are created automatically the first time the admin loads a new/updated module.
 */
function schema_types(): array {
    $my = db_driver() === 'mysql';
    return [
        'driver' => db_driver(),
        'id' => $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'big' => $my ? 'MEDIUMTEXT' : 'TEXT',
        'tail' => $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '',
    ];
}
function core_schema(array $T): array {
    return [
        "CREATE TABLE IF NOT EXISTS settings (k VARCHAR(100) PRIMARY KEY, v {$T['big']})" . $T['tail'],
        "CREATE TABLE IF NOT EXISTS admins (id {$T['id']}, username VARCHAR(100) UNIQUE, pass_hash VARCHAR(255), must_change INT DEFAULT 0, totp_secret VARCHAR(64) NULL, created INT)" . $T['tail'],
        "CREATE TABLE IF NOT EXISTS rate_limits (id {$T['id']}, bucket VARCHAR(190), ts INT)" . $T['tail'],
        "CREATE TABLE IF NOT EXISTS audit_log (id {$T['id']}, ts INT, admin VARCHAR(100), action VARCHAR(100), detail VARCHAR(255))" . $T['tail'],
        "CREATE INDEX idx_rl ON rate_limits (bucket, ts)",
    ];
}
function run_sql_list(array $sqls, array &$log, string $who): void {
    foreach ($sqls as $sql) {
        try { db()->exec($sql); }
        catch (Throwable $e) {
            if (preg_match('/already exists|duplicate (key|column)/i', $e->getMessage())) continue;
            $log[] = "ERROR [$who]: " . $e->getMessage();
            throw $e;
        }
    }
}
function modules_dir(): string { return ROOT . '/admin/modules'; }
function modules_load(): array {
    static $mods = null;
    if ($mods !== null) return $mods;
    $mods = [];
    foreach (['ui', 'crud'] as $h) if (is_file(ROOT . "/admin/core/$h.php")) require_once ROOT . "/admin/core/$h.php";   // helpers modules rely on (crud_module, P, f_*)
    foreach (glob(modules_dir() . '/*/module.php') ?: [] as $f) {
        $id = basename(dirname($f));
        if ($id[0] === '_') continue;                       // folders starting with "_" are disabled / examples
        $m = require $f;
        if (!is_array($m)) continue;
        $m['id'] = $id; $m['dir'] = dirname($f);
        $m += ['title' => ['ar' => $id, 'en' => $id], 'icon' => '•', 'group' => 'app', 'order' => 100, 'version' => 1, 'actions' => []];
        $mods[$id] = $m;
    }
    uasort($mods, fn($a, $b) => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
    return $mods;
}
/** Creates/updates all tables (core + modules) and runs each module's seed once. Returns a log. */
function migrate_all(): array {
    $log = []; $T = schema_types();
    $coreV = 1;
    if ((int)setting('mv__core', '0') < $coreV) {
        run_sql_list(core_schema($T), $log, 'core');
        set_setting('mv__core', (string)$coreV); $log[] = 'core: ok';
    }
    foreach (modules_load() as $id => $m) {
        $have = (int)setting('mv_' . $id, '0');
        if ($have >= (int)$m['version']) continue;
        if (isset($m['schema'])) run_sql_list(($m['schema'])($T), $log, $id);
        if ($have === 0 && isset($m['seed'])) ($m['seed'])();
        set_setting('mv_' . $id, (string)$m['version']);
        $log[] = "$id: v{$m['version']} ok";
    }
    return $log;
}
