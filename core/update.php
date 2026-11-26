<?php
declare(strict_types=1);
/**
 * Safe, resumable, file-by-file site updater (staged updates).
 *
 * Flow (never touches the live tree before the plan is complete):
 *   1. download  → data/update/dl/<id>.zip            (GitHub zipball or uploaded zip)
 *   2. extract   → data/update/stage/<id>/…           (sandboxed, protected paths skipped)
 *   3. plan      → data/update/manifests/<id>.json/md (changed-files log per tag: add/replace/remove + sha256)
 *   4. apply     → file by file: back up the old file, write the new one atomically, keep progress in state.json
 *   5. verify    → PHP syntax check of the changed files, then migrate_all(), then history + opcache reset
 *
 * During phases 4–5 the whole site answers with a branded maintenance page (HTTP 503) and the admin panel
 * shows a floating progress badge; both poll update_step.php?token=… so the update finishes even if the
 * browser is closed (and cron_update.php can resume from CLI).
 *
 * Rollback restores the per-file backups created during the last update.
 */

const UPDATE_PHASES = ['download', 'extract', 'plan', 'apply', 'verify'];
const UPDATE_PROTECTED_EXACT = ['config.php', 'install.php', 'install.lock', 'AGENTS.md'];
const UPDATE_PROTECTED_PREFIX = ['data/', 'assets/uploads/', '.git/', '.well-known/', 'node_modules/'];

/* ------------------------------------------------------------------ paths / state */

function update_dir(string $sub = ''): string {
    $root = ROOT . '/data/update';
    if (!is_dir($root)) {
        @mkdir($root, 0755, true);
        // belt & braces: the whole folder must never be reachable over HTTP
        @file_put_contents($root . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    $d = $root . ($sub !== '' ? '/' . trim($sub, '/') : '');
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

function update_state_path(): string { return update_dir() . '/state.json'; }

function update_state_read(bool $fresh = false): ?array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $f = update_state_path();
    if (!is_file($f)) return $cache = null;
    $j = json_decode((string)@file_get_contents($f), true);
    return $cache = is_array($j) ? $j : null;
}

function update_state_write(array $s): void {
    $s['updated'] = time();
    $f = update_state_path();
    $tmp = $f . '.tmp';
    // prune verbose fields so the state file stays tiny and fast to read on every front request
    if (isset($s['log']) && is_array($s['log'])) $s['log'] = array_slice($s['log'], -40);
    if (isset($s['errors']) && is_array($s['errors'])) $s['errors'] = array_slice($s['errors'], -20);
    @file_put_contents($tmp, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @rename($tmp, $f);
    @chmod($f, 0640);
    update_state_read(true);
}

function update_state_clear(): void {
    @unlink(update_state_path());
    update_state_read(true);
}

/** True while the site must answer with the maintenance page. */
function update_active(?array $s = null): bool {
    $s = $s ?? update_state_read();
    if (!$s) return false;
    return in_array((string)($s['phase'] ?? ''), UPDATE_PHASES, true) && empty($s['paused']);
}

/** Total progress 0–100 across phases. */
function update_percent(array $s): int {
    $phase = (string)($s['phase'] ?? '');
    $total = max(1, (int)($s['total'] ?? 0));
    $done  = (int)($s['done'] ?? 0);
    switch ($phase) {
        case 'download': return 4;
        case 'extract':  return 10;
        case 'plan':     return 18;
        case 'apply':    return 20 + (int)floor(72 * min(1, $done / $total));
        case 'verify':   return 96;
        case 'done':     return 100;
        default:         return 0;
    }
}

/** Rough remaining seconds, from the measured per-file speed. */
function update_eta(array $s): int {
    $phase = (string)($s['phase'] ?? '');
    if ($phase !== 'apply') return $phase === 'verify' ? 3 : 0;
    $total = (int)($s['total'] ?? 0); $done = (int)($s['done'] ?? 0);
    $elapsed = max(1, time() - (int)($s['apply_started'] ?? $s['started'] ?? time()));
    if ($done < 1) return 0;
    $per = $elapsed / $done;
    return (int)max(0, ceil($per * max(0, $total - $done)));
}

/** A per-update bearer token: only shown inside the maintenance / wait pages. */
function update_token(array $s): string { return (string)($s['token'] ?? ''); }

function update_token_ok(string $token): bool {
    $s = update_state_read();
    if (!$s) return false;
    $t = update_token($s);
    return $t !== '' && $token !== '' && hash_equals($t, $token);
}

/* ------------------------------------------------------------------ lock */

function update_lock(): bool {
    $f = fopen(update_dir() . '/.lock', 'c');
    if (!$f) return false;
    if (!flock($f, LOCK_EX | LOCK_NB)) { fclose($f); return false; }
    return true;                                  // released automatically at process end
}

/* ------------------------------------------------------------------ begin / download / extract */

function update_begin(string $source, string $tag = '', string $ref = ''): array {
    if (update_active()) throw new RuntimeException('هناك تحديث قيد التنفيذ بالفعل — انتظر انتهاءه أو أعد تحميل الصفحة.');
    $id = 'upd_' . gmdate('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
    $s = [
        'id'        => $id,
        'token'     => bin2hex(random_bytes(16)),
        'source'    => mb_substr($source, 0, 190),
        'tag'       => mb_substr($tag, 0, 60),
        'ref'       => mb_substr($ref, 0, 80),
        'phase'     => 'download',
        'started'   => time(),
        'updated'   => time(),
        'paused'    => 0,
        'total'     => 0,
        'done'      => 0,
        'current'   => '',
        'index'     => 0,
        'errors'    => [],
        'log'       => ['بدأ التحديث من: ' . $source],
        'stage'     => 'data/update/stage/' . $id,
        'backup'    => 'data/update/backup/' . $id,
        'manifest'  => 'data/update/manifests/' . $id . '.json',
    ];
    @mkdir(ROOT . '/' . $s['stage'], 0755, true);
    @mkdir(ROOT . '/' . $s['backup'], 0755, true);
    update_state_write($s);
    return $s;
}

/** Download a GitHub branch/tag zipball (cURL when available, stream fallback otherwise). */
function update_download_github(string $repo, string $branch, array $s): array {
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) throw new RuntimeException('صيغة المستودع غير صحيحة (owner/repo).');
    if (!preg_match('#^[A-Za-z0-9_.\-/]+$#', $branch)) throw new RuntimeException('اسم الفرع غير صحيح.');

    $api = rtrim((string)cfg('github_api', 'https://api.github.com'), '/');
    $headers = ['User-Agent: portfolio-self-updater', 'Accept: application/vnd.github+json'];
    if ($tok = gh_token()) $headers[] = 'Authorization: Bearer ' . $tok;

    // Resolve the exact commit + nearest tag so the changed-files log is tied to a tag.
    $ref = $branch; $tag = '';
    $commit = update_http_json($api . '/repos/' . $repo . '/commits/' . rawurlencode($branch), $headers);
    if (is_array($commit) && !empty($commit['sha'])) {
        $ref = substr((string)$commit['sha'], 0, 12);
        $tags = update_http_json($api . '/repos/' . $repo . '/tags?per_page=30', $headers);
        if (is_array($tags)) {
            foreach ($tags as $t) {
                if (!is_array($t) || empty($t['name']) || empty($t['commit']['sha'])) continue;
                if ((string)$t['commit']['sha'] === (string)$commit['sha']) { $tag = (string)$t['name']; break; }
            }
        }
    }

    $zip = update_dir('dl') . '/' . $s['id'] . '.zip';
    $url = $api . '/repos/' . $repo . '/zipball/' . rawurlencode($branch);
    $ok = false;
    if (function_exists('curl_init')) {
        $fp = fopen($zip, 'wb');
        if (!$fp) throw new RuntimeException('تعذّر إنشاء ملف التنزيل داخل data/update/dl.');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $code !== 200) {
            @unlink($zip);
            throw new RuntimeException('فشل جلب الحزمة من GitHub (HTTP ' . $code . ($err ? ' — ' . $err : '') . ').');
        }
        $ok = true;
    } else {
        $ctx = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 120, 'follow_location' => 1]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false || strlen($data) < 1024) throw new RuntimeException('تعذّر تنزيل الحزمة من GitHub (لا cURL ولا stream ناجح).');
        @file_put_contents($zip, $data);
        $ok = true;
    }
    if (!$ok || !is_file($zip) || filesize($zip) < 1024) throw new RuntimeException('الحزمة المنزّلة فارغة أو غير صالحة.');

    return ['zip' => $zip, 'ref' => $ref, 'tag' => $tag];
}

/** Small JSON GET helper (returns null on any failure — never throws for metadata lookups). */
function update_http_json(string $url, array $headers): ?array {
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code !== 200) return null;
            $j = json_decode((string)$body, true);
            return is_array($j) ? $j : null;
        }
        $ctx = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 20, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) return null;
        $j = json_decode((string)$body, true);
        return is_array($j) ? $j : null;
    } catch (Throwable $e) { return null; }
}

/** Extract a package into the sandbox stage folder and find its real root. */
function update_extract(string $zipPath, array $s): string {
    if (!class_exists('ZipArchive')) throw new RuntimeException('امتداد ZipArchive غير مفعّل على الاستضافة — فعّله من إعدادات PHP في هوستنجر.');
    $stage = ROOT . '/' . $s['stage'];
    if (!is_dir($stage)) @mkdir($stage, 0755, true);

    $z = new ZipArchive();
    if ($z->open($zipPath) !== true) throw new RuntimeException('الملف المضغوط غير صالح أو تالف.');
    if ($z->numFiles > 6000) { $z->close(); throw new RuntimeException('عدد الملفات داخل الأرشيف كبير جداً.'); }

    $bytes = 0;
    for ($i = 0; $i < $z->numFiles; $i++) {
        $st = $z->statIndex($i);
        if (!$st) continue;
        $entry = (string)$st['name'];
        if (str_ends_with($entry, '/')) continue;
        if (isset($st['external_attributes']) && (((int)$st['external_attributes'] >> 16) & 0120000) === 0120000) continue;   // skip symlinks
        $clean = update_clean_rel($entry);
        if ($clean === null) continue;
        $bytes += (int)$st['size'];
        if ($bytes > 200 * 1024 * 1024) { $z->close(); throw new RuntimeException('حجم الحزمة يتجاوز الحد الآمن (200MB).'); }
        $target = $stage . '/' . $clean;
        if (!is_dir(dirname($target))) @mkdir(dirname($target), 0755, true);
        $stream = $z->getStream($entry);
        if (!$stream) continue;
        $out = @fopen($target, 'wb');
        if ($out) { stream_copy_to_stream($stream, $out); fclose($out); }
        fclose($stream);
    }
    $z->close();

    $root = null;
    if (is_file($stage . '/index.php') && is_file($stage . '/core/bootstrap.php')) $root = $stage;
    else foreach (glob($stage . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
        if (is_file($sub . '/index.php') && is_file($sub . '/core/bootstrap.php') && is_file($sub . '/admin/index.php')) { $root = $sub; break; }
    }
    if ($root === null || !is_file($root . '/admin/index.php')) {
        throw new RuntimeException('الحزمة لا تبدو إصداراً صالحاً للموقع (يلزم index.php و core/bootstrap.php و admin/index.php).');
    }
    return $root;
}

/* ------------------------------------------------------------------ plan (changed-files log) */

function update_clean_rel(string $p): ?string {
    $p = ltrim(str_replace('\\', '/', $p), '/');
    if ($p === '' || str_contains($p, "\0") || preg_match('#(^|/)\.\.?(/|$)|^[A-Za-z]:#', $p)) return null;
    return $p;
}

/** Never overwrite these (config, data, uploads, git, docs for AI agents…). */
function update_is_protected(string $rel): bool {
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if ($rel === '') return true;
    if (in_array($rel, UPDATE_PROTECTED_EXACT, true)) return true;
    foreach (UPDATE_PROTECTED_PREFIX as $p) if ($rel === rtrim($p, '/') || str_starts_with($rel, $p)) return true;
    if (preg_match('#^(\.htaccess|\.env|config\..*\.php)$#i', $rel)) return true;
    return false;
}

/** Update order: assets → pages → admin → core → root (core last so a half-applied tree can still boot). */
function update_apply_rank(string $rel): int {
    if (str_starts_with($rel, 'assets/')) return 1;
    if (str_starts_with($rel, 'lang/')) return 2;
    if (str_starts_with($rel, 'app/')) return 3;
    if (str_starts_with($rel, 'admin/')) return 4;
    if (str_starts_with($rel, 'core/')) return 5;
    return 6;
}

/** Build the change list (add / replace / remove) and write the per-tag changed-files log. */
function update_plan(string $pkgRoot, array $s): array {
    $files = []; $skipped = []; $bytes = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pkgRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->isLink()) continue;
        $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen(str_replace('\\', '/', $pkgRoot)) + 1);
        if (update_is_protected($rel)) { $skipped[] = $rel; continue; }
        $live = ROOT . '/' . $rel;
        $newHash = hash_file('sha256', $f->getPathname());
        $op = 'add';
        if (is_file($live)) {
            if (hash_file('sha256', $live) === $newHash) continue;         // identical → nothing to do
            $op = 'replace';
        }
        $size = (int)filesize($f->getPathname());
        $bytes += $size;
        $files[] = ['path' => $rel, 'op' => $op, 'sha256' => $newHash, 'size' => $size, 'rank' => update_apply_rank($rel)];
    }

    // Removals: files listed by the previous manifest that vanished from the package (backed up before delete).
    $remove = [];
    $prev = update_last_applied_manifest();
    if ($prev && !empty($prev['files'])) {
        $now = array_flip(array_column($files, 'path'));
        $still = [];
        foreach ($prev['files'] as $pf) $still[(string)($pf['path'] ?? '')] = true;
        foreach ($still as $path => $_) {
            if ($path === '' || isset($now[$path]) || update_is_protected($path)) continue;
            if (!is_file(ROOT . '/' . $path)) continue;
            if (is_file($pkgRoot . '/' . $path)) continue;                 // still shipped (just unchanged) → not a removal
            $remove[] = ['path' => $path, 'op' => 'remove', 'sha256' => hash_file('sha256', ROOT . '/' . $path), 'size' => (int)filesize(ROOT . '/' . $path)];
        }
    }

    usort($files, fn($a, $b) => [$a['rank'], $a['path']] <=> [$b['rank'], $b['path']]);
    foreach ($files as &$ff) unset($ff['rank']);
    unset($ff);

    $manifest = [
        'id'        => $s['id'],
        'source'    => $s['source'],
        'tag'       => $s['tag'] !== '' ? $s['tag'] : ($s['ref'] !== '' ? $s['ref'] : 'main'),
        'ref'       => $s['ref'],
        'created'   => time(),
        'files'     => $files,
        'remove'    => $remove,
        'skipped'   => array_slice($skipped, 0, 60),
        'skipped_count' => count($skipped),
        'totals'    => [
            'add'     => count(array_filter($files, fn($x) => $x['op'] === 'add')),
            'replace' => count(array_filter($files, fn($x) => $x['op'] === 'replace')),
            'remove'  => count($remove),
            'bytes'   => $bytes,
        ],
    ];
    update_write_manifest($manifest);

    // Optional package-provided changed-files.json is kept as a cross-check for the AI agent log.
    if (is_file($pkgRoot . '/changed-files.json')) {
        @copy($pkgRoot . '/changed-files.json', update_dir('manifests') . '/' . $s['id'] . '-package-changed-files.json');
    }
    return $manifest;
}

function update_write_manifest(array $m): void {
    $base = update_dir('manifests') . '/' . $m['id'];
    @file_put_contents($base . '.json', json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    $lines = [];
    $lines[] = '# سجل الملفات المتغيّرة — ' . ($m['tag'] ?: $m['ref']);
    $lines[] = '';
    $lines[] = '- المصدر: ' . $m['source'];
    $lines[] = '- المرجع: ' . ($m['ref'] !== '' ? $m['ref'] : '—');
    $lines[] = '- التاريخ: ' . gmdate('Y-m-d H:i:s', (int)$m['created']) . ' UTC';
    $lines[] = '- الإجمالي: إضافة ' . (int)$m['totals']['add'] . ' · استبدال ' . (int)$m['totals']['replace'] . ' · حذف ' . (int)$m['totals']['remove'] . ' · الحجم ' . fmt_size((int)$m['totals']['bytes']);
    $lines[] = '';
    $lines[] = '| الملف | العملية | SHA-256 | الحجم |';
    $lines[] = '|---|---|---|---|';
    foreach ($m['files'] as $f) $lines[] = '| `' . $f['path'] . '` | ' . $f['op'] . ' | `' . substr((string)$f['sha256'], 0, 16) . '…` | ' . fmt_size((int)$f['size']) . ' |';
    foreach ($m['remove'] as $f) $lines[] = '| `' . $f['path'] . '` | remove | `' . substr((string)$f['sha256'], 0, 16) . '…` | ' . fmt_size((int)$f['size']) . ' |';
    @file_put_contents($base . '.md', implode("\n", $lines) . "\n");
    $applied = update_dir('manifests') . '/last-applied.json';
    if (!is_file($applied)) @file_put_contents($applied, json_encode($m, JSON_UNESCAPED_UNICODE));
}

function update_manifest_by_id(string $id): ?array {
    $f = update_dir('manifests') . '/' . basename($id) . '.json';
    if (!is_file($f)) return null;
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : null;
}

function update_last_applied_manifest(): ?array {
    $f = update_dir('manifests') . '/last-applied.json';
    if (!is_file($f)) return null;
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : null;
}

/* ------------------------------------------------------------------ apply (file by file) */

/**
 * Apply the next slice of the plan. Returns the (fresh) state.
 * Each touched file is backed up first, then replaced atomically; the queue index is persisted so a
 * timeout / closed browser never loses progress — the next call simply continues.
 */
function update_apply_batch(array $s, int $budgetSeconds = 12, int $maxFiles = 120): array {
    $manifest = update_manifest_by_id((string)$s['id']);
    if (!$manifest) throw new RuntimeException('سجل الملفات المتغيّرة مفقود — أعد بدء التحديث.');

    $queue = array_merge($manifest['files'] ?? [], $manifest['remove'] ?? []);
    $total = count($queue);
    $s['total'] = $total;
    if (($s['phase'] ?? '') !== 'apply') { $s['phase'] = 'apply'; $s['apply_started'] = time(); }
    update_state_write($s);

    $stage = ROOT . '/' . $s['stage'];
    $pkgRoot = update_pkg_root($stage);
    $backup = ROOT . '/' . $s['backup'];
    if (!is_dir($backup)) @mkdir($backup, 0755, true);

    $start = microtime(true);
    $sinceWrite = 0;
    for ($i = (int)($s['index'] ?? 0); $i < $total; $i++) {
        if (microtime(true) - $start > $budgetSeconds || ($sinceWrite >= $maxFiles)) break;
        $item = $queue[$i];
        $rel = (string)$item['path'];
        $dest = ROOT . '/' . $rel;

        try {
            if (($item['op'] ?? '') === 'remove') {
                if (is_file($dest)) {
                    update_backup_file($dest, $rel, $backup);
                    if (!@unlink($dest)) throw new RuntimeException('تعذّر حذف الملف: ' . $rel);
                }
            } else {
                $src = $pkgRoot . '/' . $rel;
                if (!is_file($src)) throw new RuntimeException('الملف غير موجود في الحزمة: ' . $rel);
                if (is_file($dest)) update_backup_file($dest, $rel, $backup);
                if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0755, true);
                $tmp = $dest . '.upd_' . substr(bin2hex(random_bytes(3)), 0, 6);
                if (!@copy($src, $tmp)) throw new RuntimeException('تعذّر كتابة الملف المؤقت: ' . $rel);
                @chmod($tmp, 0644);
                if (!@rename($tmp, $dest)) { @unlink($tmp); if (!@copy($src, $dest)) throw new RuntimeException('تعذّر استبدال الملف: ' . $rel); }
                @chmod($dest, 0644);
            }
            $s['done'] = $i + 1;
        } catch (Throwable $e) {
            $s['errors'][] = $rel . ': ' . $e->getMessage();
            $s['log'][] = '✗ ' . $rel . ' — ' . $e->getMessage();
            $s['done'] = $i + 1;                                   // keep moving; report at the end
        }
        $s['index'] = $i + 1;
        $s['current'] = $rel;
        $s['percent'] = update_percent($s);
        $sinceWrite++;
        if ($sinceWrite >= 5) { update_state_write($s); $sinceWrite = 0; }
    }

    @clearstatcache(true);
    $s['percent'] = update_percent($s);
    if ((int)$s['index'] >= $total) {
        $s['phase'] = 'verify';
        $s['current'] = 'التحقق النهائي';
    }
    update_state_write($s);
    return $s;
}

function update_pkg_root(string $stage): string {
    if (is_file($stage . '/index.php') && is_file($stage . '/core/bootstrap.php')) return $stage;
    foreach (glob($stage . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
        if (is_file($sub . '/index.php') && is_file($sub . '/core/bootstrap.php')) return $sub;
    }
    return $stage;
}

function update_backup_file(string $src, string $rel, string $backupDir): void {
    $target = $backupDir . '/' . $rel;
    if (!is_dir(dirname($target))) @mkdir(dirname($target), 0755, true);
    @copy($src, $target);
}

/* ------------------------------------------------------------------ verify / finish */

function update_verify(array $s): array {
    $manifest = update_manifest_by_id((string)$s['id']);
    $bad = [];
    if ($manifest) {
        foreach (($manifest['files'] ?? []) as $f) {
            if (!str_ends_with((string)$f['path'], '.php')) continue;
            $live = ROOT . '/' . $f['path'];
            if (!is_file($live)) continue;
            try { token_get_all((string)file_get_contents($live), TOKEN_PARSE); }
            catch (ParseError $e) { $bad[] = $f['path'] . ':' . $e->getLine(); }
        }
    }
    if ($bad) {
        $s['errors'] = array_merge($s['errors'] ?? [], ['أخطاء صياغة في: ' . implode(', ', array_slice($bad, 0, 8))]);
        $s['phase'] = 'failed';
        $s['log'][] = '✗ فشل التحقق من صياغة الملفات — يمكنك التراجع بنقرة.';
        update_state_write($s);
        return $s;
    }

    // Run migrations in a guarded way: a broken migration must never take the site down.
    $mig = 0;
    try { if (function_exists('migrate_all')) $mig = count(migrate_all()); }
    catch (Throwable $e) { $s['errors'][] = 'ترحيل القاعدة: ' . $e->getMessage(); error_log('[update] migrate: ' . $e->getMessage()); }

    if (function_exists('opcache_reset')) @opcache_reset();
    @clearstatcache(true);

    // Publish this manifest as "applied" so the next update can compute removals.
    if ($manifest) {
        $manifest['applied'] = time();
        @file_put_contents(update_dir('manifests') . '/last-applied.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
    }

    $hist = json_decode(setting('updater_history', '[]'), true) ?: [];
    array_unshift($hist, [
        'ts'        => time(),
        'source'    => (string)$s['source'],
        'tag'       => (string)($s['tag'] ?: $s['ref']),
        'files'     => (int)($s['total'] ?? 0),
        'migrations'=> $mig,
        'id'        => (string)$s['id'],
        'added'     => (int)($manifest['totals']['add'] ?? 0),
        'replaced'  => (int)($manifest['totals']['replace'] ?? 0),
        'removed'   => (int)($manifest['totals']['remove'] ?? 0),
    ]);
    set_setting('updater_history', json_encode(array_slice($hist, 0, 20), JSON_UNESCAPED_UNICODE));
    set_setting('updater_last_ok', (string)time());

    $s['phase'] = 'done';
    $s['percent'] = 100;
    $s['current'] = '';
    $s['finished'] = time();
    $s['log'][] = '✓ اكتمل التحديث (' . (int)($s['total'] ?? 0) . ' ملف · ' . $mig . ' ترحيل).';
    update_state_write($s);
    update_cleanup();
    return $s;
}

/** Keep the last few stages / backups / manifests so the disk never fills. */
function update_cleanup(int $keep = 3): void {
    foreach (['stage', 'backup'] as $kind) {
        $dirs = glob(update_dir($kind) . '/*', GLOB_ONLYDIR) ?: [];
        usort($dirs, fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($dirs, $keep) as $d) update_rrmdir($d);
    }
    $mans = glob(update_dir('manifests') . '/*.json') ?: [];
    usort($mans, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($mans, 12) as $f) { @unlink($f); @unlink(preg_replace('/\.json$/', '.md', $f)); }
    foreach (glob(update_dir('dl') . '/*.zip') ?: [] as $z) if (filemtime($z) < time() - 86400 * 7) @unlink($z);
}

function update_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    } catch (Throwable $e) {}
    @rmdir($dir);
}

/* ------------------------------------------------------------------ rollback */

function update_rollback(?array $s = null): array {
    $s = $s ?? update_state_read();
    if (!$s) throw new RuntimeException('لا يوجد تحديث لاسترجاعه.');
    $backup = ROOT . '/' . (string)($s['backup'] ?? '');
    if (!is_dir($backup)) throw new RuntimeException('نسخة الاسترجاع لهذا التحديث غير موجودة.');
    $restored = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backup, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen(str_replace('\\', '/', $backup)) + 1);
        if (update_is_protected($rel)) continue;
        $dest = ROOT . '/' . $rel;
        if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0755, true);
        if (@copy($f->getPathname(), $dest)) { @chmod($dest, 0644); $restored++; }
    }
    if (function_exists('opcache_reset')) @opcache_reset();
    $s['phase'] = 'rolledback';
    $s['current'] = '';
    $s['restored'] = $restored;
    $s['log'][] = '⏪ تم استرجاع ' . $restored . ' ملف من نسخة ما قبل التحديث.';
    update_state_write($s);
    try { migrate_all(); } catch (Throwable $e) {}
    return $s;
}

/* ------------------------------------------------------------------ run / resume loop */

/** Run one step of the pipeline (used by the AJAX step endpoint, the wait page and cron). */
function update_step(): array {
    $s = update_state_read();
    if (!$s) return ['phase' => 'idle', 'percent' => 0];
    $phase = (string)($s['phase'] ?? '');
    if (in_array($phase, ['done', 'failed', 'rolledback'], true)) {
        return update_public_state($s);
    }
    if (!update_lock()) return update_public_state($s);      // another request is already working on it

    try {
        if ($phase === 'download') {
            // The starting request died before the package arrived: resume the download here so the
            // pipeline never gets stuck in "starting" (the browser only needs the token).
            if (!preg_match('#^GitHub: ([^@]+)@(.+)$#', (string)($s['source'] ?? ''), $mm)) {
                throw new RuntimeException('تعذّر استئناف التنزيل — أعد بدء التحديث من اللوحة.');
            }
            $dl = update_download_github(trim($mm[1]), trim($mm[2]), $s);
            $s = update_state_read() ?? $s;
            $s['tag'] = (string)$dl['tag'];
            $s['ref'] = (string)$dl['ref'];
            $s['zip'] = 'data/update/dl/' . $s['id'] . '.zip';
            $s['phase'] = 'extract';
            update_state_write($s);
        } elseif ($phase === 'extract') {
            $zip = update_dir('dl') . '/' . $s['id'] . '.zip';
            if (!is_file($zip)) throw new RuntimeException('ملف الحزمة مفقود.');
            update_extract($zip, $s);
            $s['phase'] = 'plan';
            update_state_write($s);
        } elseif ($phase === 'plan') {
            $pkg = update_pkg_root(ROOT . '/' . $s['stage']);
            $m = update_plan($pkg, $s);
            $s['total'] = count($m['files']) + count($m['remove']);
            $s['phase'] = 'apply';
            $s['apply_started'] = time();
            update_state_write($s);
        } elseif ($phase === 'apply') {
            $s = update_apply_batch($s);
        } elseif ($phase === 'verify') {
            $s = update_verify($s);
        }
    } catch (Throwable $e) {
        $s['errors'][] = $e->getMessage();
        $s['log'][] = '✗ ' . $e->getMessage();
        $s['phase'] = 'failed';
        update_state_write($s);
    }
    return update_public_state(update_state_read() ?? $s);
}

/** Small JSON-safe snapshot for the UI. */
function update_public_state(?array $s = null): array {
    $s = $s ?? update_state_read();
    if (!$s) return ['phase' => 'idle', 'percent' => 0, 'done' => 0, 'total' => 0, 'current' => '', 'eta' => 0, 'errors' => [], 'active' => false];
    return [
        'id'      => (string)$s['id'],
        'phase'   => (string)$s['phase'],
        'percent' => update_percent($s),
        'done'    => (int)($s['done'] ?? 0),
        'total'   => (int)($s['total'] ?? 0),
        'current' => (string)($s['current'] ?? ''),
        'eta'     => update_eta($s),
        'errors'  => array_slice((array)($s['errors'] ?? []), -5),
        'active'  => update_active($s),
        'tag'     => (string)($s['tag'] ?? ''),
        'source'  => (string)($s['source'] ?? ''),
        'age'     => max(0, time() - (int)($s['updated'] ?? time())),
    ];
}

/** Start (or continue) an update from GitHub and return the initial snapshot. */
function update_start_github(string $repo, string $branch): array {
    if (!update_lock()) throw new RuntimeException('هناك عملية تحديث أخرى تعمل الآن.');
    $s = update_begin("GitHub: $repo@$branch");
    $dl = update_download_github($repo, $branch, $s);
    $s['tag'] = (string)$dl['tag'];
    $s['ref'] = (string)$dl['ref'];
    $s['zip'] = 'data/update/dl/' . $s['id'] . '.zip';
    $s['phase'] = 'extract';
    update_state_write($s);

    $pkg = update_extract($dl['zip'], $s);
    $s = update_state_read() ?? $s;
    $manifest = update_plan($pkg, $s);
    $s['total'] = count($manifest['files']) + count($manifest['remove']);
    $s['phase'] = 'apply';
    $s['apply_started'] = time();
    $s['log'][] = 'الخطة: ' . $manifest['totals']['add'] . ' إضافة · ' . $manifest['totals']['replace'] . ' استبدال · ' . $manifest['totals']['remove'] . ' حذف';
    update_state_write($s);

    // Apply the first slice inside this request (kept short so the panel never times out).
    $s = update_apply_batch($s, 8);
    return update_public_state($s);
}

/** Uploaded ZIP variant of update_start_github(). */
function update_start_zip(string $tmpPath, string $label): array {
    if (!update_lock()) throw new RuntimeException('هناك عملية تحديث أخرى تعمل الآن.');
    $s = update_begin($label, '', 'zip');
    $zip = update_dir('dl') . '/' . $s['id'] . '.zip';
    if (!@copy($tmpPath, $zip)) throw new RuntimeException('تعذّر نسخ الملف المرفوع إلى مجلد التحديث.');
    $s['zip'] = 'data/update/dl/' . $s['id'] . '.zip';
    $s['phase'] = 'extract';
    update_state_write($s);

    $pkg = update_extract($zip, $s);
    $manifest = update_plan($pkg, $s);
    $s['total'] = count($manifest['files']) + count($manifest['remove']);
    $s['phase'] = 'apply';
    $s['apply_started'] = time();
    $s['log'][] = 'الخطة: ' . $manifest['totals']['add'] . ' إضافة · ' . $manifest['totals']['replace'] . ' استبدال · ' . $manifest['totals']['remove'] . ' حذف';
    update_state_write($s);
    return update_public_state(update_apply_batch($s, 8));
}

/** Human label of the current phase. */
function update_phase_label(string $phase): string {
    return [
        'download' => 'تنزيل الحزمة',
        'extract'  => 'فكّ الضغط',
        'plan'     => 'قراءة سجل الملفات المتغيّرة',
        'apply'    => 'استبدال الملفات',
        'verify'   => 'التحقق النهائي',
        'done'     => 'اكتمل',
        'failed'   => 'توقّف',
        'rolledback' => 'تم التراجع',
        'idle'     => 'لا تحديث',
    ][$phase] ?? $phase;
}

/* ------------------------------------------------------------------ branded maintenance page */

function update_logo_url(): string {
    foreach (['logo', 'avatar'] as $k) {
        $v = trim((string)setting($k));
        if ($v !== '') return str_starts_with($v, 'http') ? $v : url('assets/uploads/' . $v);
    }
    return url('assets/img/favicon-192.png');
}

/** Maintenance shell used by the public site, the admin wait screen and the API (JSON) variant. */
function update_maintenance_page(?array $s = null, bool $adminShell = false): void {
    $s = $s ?? update_state_read();
    $pct = $s ? update_percent($s) : 0;
    $token = $s ? update_token($s) : '';
    $tag = $s ? (string)($s['tag'] ?: $s['ref']) : '';
    $slow = $s && (time() - (int)($s['updated'] ?? time())) > 90;
    $logo = update_logo_url();
    $name = setting_l('name') ?: 'Yousef Alhmazy';
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Retry-After: 5');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex');
    }
    $stepUrl = url('update_step.php');
    ?><!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex">
<title>جاري تحديث الموقع — <?= e($name) ?></title>
<link rel="icon" href="<?= e(url('assets/img/favicon-32.png')) ?>">
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;min-height:100svh;display:grid;place-items:center;background:radial-gradient(1200px 600px at 50% -10%,#0f2b3d,#050b14 60%);color:#e8f1f9;font-family:Cairo,system-ui,Tahoma,sans-serif;padding:24px;overflow:hidden}
.card{width:100%;max-width:420px;text-align:center}
.logo-wrap{position:relative;width:150px;height:150px;margin:0 auto 26px;display:grid;place-items:center}
.logo-wrap::before{content:"";position:absolute;inset:-14px;border-radius:50%;border:3px solid rgba(45,212,191,.18);border-top-color:#2dd4bf;animation:spin 1.1s linear infinite}
.logo-wrap::after{content:"";position:absolute;inset:6px;border-radius:50%;box-shadow:0 0 40px rgba(45,212,191,.35);animation:pulse 2.2s ease-in-out infinite}
.logo-wrap img{width:104px;height:104px;border-radius:26px;object-fit:cover;box-shadow:0 12px 34px rgba(0,0,0,.45);position:relative;z-index:2}
h1{font-size:1.22rem;margin:0 0 8px;font-weight:800}
p{margin:0;color:#8fa9c3;font-size:.92rem;line-height:1.75}
.bar{height:8px;border-radius:99px;background:rgba(255,255,255,.08);overflow:hidden;margin:20px 0 10px}
.bar i{display:block;height:100%;width:<?= (int)$pct ?>%;background:linear-gradient(90deg,#0d9488,#0284c7);transition:width .4s ease}
.meta{font-size:.78rem;color:#6d8aa5;letter-spacing:.02em}
.dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#2dd4bf;margin-inline-end:6px;animation:blink 1.4s infinite}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes pulse{0%,100%{opacity:.35}50%{opacity:.9}}
@keyframes blink{0%,100%{opacity:.25}50%{opacity:1}}
a.home{display:inline-block;margin-top:22px;color:#2dd4bf;font-size:.82rem;text-decoration:none}
@media (prefers-reduced-motion: reduce){.logo-wrap::before,.logo-wrap::after,.dot{animation:none}}
</style></head>
<body>
<div class="card">
  <div class="logo-wrap"><img src="<?= e($logo) ?>" alt="<?= e($name) ?>"></div>
  <h1>جاري تحديث الموقع</h1>
  <p><?= $slow ? 'التحديث يستغرق وقتاً أطول من المعتاد — يتم استكماله تلقائياً، أعد تحميل الصفحة بعد قليل.' : 'نُحدّث ملفات الموقع بأمان ملفاً بملف، وبياناتك ومحتواك محفوظة كما هي. الصفحة تعود تلقائياً بعد الانتهاء.' ?></p>
  <div class="bar"><i></i></div>
  <div class="meta"><span class="dot"></span><?= (int)$pct ?>% · <span id="upd-phase"><?= e(update_phase_label((string)($s['phase'] ?? 'idle'))) ?></span><span id="upd-tag"><?= $tag !== '' ? ' · ' . e($tag) : '' ?></span></div>
  <?php if ($adminShell): ?><a class="home" href="<?= e(url('admin/')) ?>">↗ لوحة التحكم</a><?php endif; ?>
</div>
<script>
(function(){
  var token = <?= json_encode($token) ?>, url = <?= json_encode($stepUrl) ?>, done = false;
  var bar = document.querySelector('.bar i'), label = document.getElementById('upd-phase'), meta = document.querySelector('.meta');
  function tick(){
    if (done || !token) return;
    fetch(url + '?token=' + encodeURIComponent(token), {credentials:'same-origin', cache:'no-store'})
      .then(function(r){ return r.json(); })
      .then(function(s){
        if (!s) return;
        if (typeof s.percent === 'number' && bar) bar.style.width = s.percent + '%';
        if (label && s.phase) label.textContent = s.phase_label || s.phase;
        if (s.done_all || s.phase === 'idle') { done = true; location.reload(); return; }
        setTimeout(tick, 1200);
      })
      .catch(function(){ setTimeout(tick, 2500); });
  }
  setTimeout(tick, 1200);
})();
</script>
</body></html><?php
}

/** Public entry: called from index.php; JSON for /api, HTML otherwise. */
function update_guard_public(string $uriTop = ''): void {
    if (!update_active()) return;
    $s = update_state_read();
    if ($uriTop === 'api') {
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: 10');
            header('Cache-Control: no-store');
        }
        echo json_encode([
            'ok' => false,
            'error' => ['code' => 'maintenance', 'message' => 'الموقع قيد التحديث الآن — أعد المحاولة بعد لحظات.'],
            'retry_after' => 10,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    update_maintenance_page($s);
    exit;
}

/* ------------------------------------------------------------------ admin progress overlay */

function update_admin_overlay(): string {
    $s = update_state_read();
    if (!$s) return '';
    $phase = (string)($s['phase'] ?? '');
    $pct = update_percent($s);
    $token = update_token($s);
    $failed = $phase === 'failed';
    $finished = in_array($phase, ['done', 'rolledback'], true);
    if (!$failed && !$finished && !update_active($s)) return '';
    // Show the "finished" badge only once per session so it never nags on every panel page.
    if ($finished && session_status() === PHP_SESSION_ACTIVE) {
        if ((string)($_SESSION['upd_overlay_seen'] ?? '') === (string)($s['id'] ?? '')) return '';
        $_SESSION['upd_overlay_seen'] = (string)($s['id'] ?? '');
    }

    $stepUrl = url('update_step.php');
    $meta = $finished
        ? 'اكتمل التحديث — أعد تحميل الصفحة لرؤية النسخة الجديدة.'
        : ($failed ? 'توقّف التحديث — يمكنك التراجع بأمان.' : update_phase_label($phase) . ($s['total'] ? ' · ' . (int)($s['done'] ?? 0) . '/' . (int)$s['total'] . ' ملف' : ''));

    ob_start(); ?>
<div id="updOverlay" class="upd-overlay<?= $failed ? ' bad' : '' ?>" data-token="<?= e($token) ?>" data-url="<?= e($stepUrl) ?>" data-phase="<?= e($phase) ?>">
  <div class="upd-ring" aria-hidden="true"><span></span></div>
  <div class="upd-body">
    <b>جاري التحديث</b>
    <small data-upd-meta><?= e($meta) ?></small>
    <div class="upd-bar"><i style="width:<?= (int)$pct ?>%"></i></div>
    <div class="upd-actions">
      <?php if ($failed): ?>
        <a class="btn sm danger" href="<?= e($stepUrl . '?token=' . urlencode($token) . '&rollback=1') ?>">⏪ تراجع عن التحديث</a>
        <a class="btn sm ghost" href="<?= e(adm_url('updater')) ?>">إعادة المحاولة</a>
      <?php else: ?>
        <small data-upd-pct><?= (int)$pct ?>%</small>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
(function(){
  var o = document.getElementById('updOverlay'); if (!o) return;
  var token = o.dataset.token, url = o.dataset.url, bar = o.querySelector('.upd-bar i');
  var meta = o.querySelector('[data-upd-meta]'), pctEl = o.querySelector('[data-upd-pct]');
  var finished = false;
  function tick(){
    if (finished || !token) return;
    fetch(url + '?token=' + encodeURIComponent(token) + '&json=1', {credentials:'same-origin', cache:'no-store'})
      .then(function(r){ return r.json(); })
      .then(function(s){
        if (!s) return;
        if (typeof s.percent === 'number') { if (bar) bar.style.width = s.percent + '%'; if (pctEl) pctEl.textContent = s.percent + '%'; }
        if (meta && s.meta) meta.textContent = s.meta;
        if (s.phase === 'done' || s.phase === 'rolledback' || s.phase === 'idle') { finished = true; setTimeout(function(){ location.reload(); }, 700); return; }
        if (s.phase === 'failed') { finished = true; location.reload(); return; }
        setTimeout(tick, 1000);
      })
      .catch(function(){ setTimeout(tick, 2500); });
  }
  setTimeout(tick, 900);
})();
</script>
<?php
    return (string)ob_get_clean();
}
