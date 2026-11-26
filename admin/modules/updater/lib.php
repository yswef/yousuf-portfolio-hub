<?php
declare(strict_types=1);
/**
 * Core logic for the In-Panel Site Self-Updater (loaded via require_once so migrate_all() can re-read module.php safely).
 */

function updater_rollback_dir(): string {
    $d = ROOT . '/data/updater_rollbacks';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

function updater_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/** Paths relative to ROOT that MUST NEVER be overwritten or touched during site code updates. */
function updater_is_protected(string $rel): bool {
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if ($rel === '') return true;
    $exact = ['config.php', 'install.php', 'install.lock', '.netrc', '.git-credentials'];
    if (in_array($rel, $exact, true)) return true;
    foreach (['data/', 'assets/uploads/', '.git/', '.well-known/'] as $prefix) {
        if ($rel === rtrim($prefix, '/') || str_starts_with($rel, $prefix)) return true;
    }
    if (preg_match('/^(repo_info\.md|GIT_COMMANDS\.md|git_.*\.sh|commit_helper.*)$/i', basename($rel))) return true;
    return false;
}

/** Validate and normalize a relative path from inside an uploaded ZIP; returns null if unsafe. */
function updater_clean_rel(string $p): ?string {
    $p = ltrim(str_replace('\\', '/', $p), '/');
    if ($p === '' || str_contains($p, "\0") || preg_match('#(^|/)\.\.?(/|$)|^[A-Za-z]:#', $p)) return null;
    return $p;
}

/** List existing code-only rollback snapshots (newest first). */
function updater_rollbacks(): array {
    $out = [];
    foreach (glob(updater_rollback_dir() . '/rollback-*.zip') ?: [] as $f) {
        $n = basename($f);
        if (preg_match('/^rollback-\d{8}-\d{6}(?:-[a-f0-9]{4})?\.zip$/', $n)) {
            $out[] = ['name' => $n, 'size' => (int)filesize($f), 'ts' => (int)filemtime($f)];
        }
    }
    usort($out, fn($a, $b) => $b['ts'] <=> $a['ts']);
    return $out;
}

/** Save a fast code-only rollback zip before applying an update, keeping the latest 3 snapshots. */
function updater_create_rollback(): string {
    if (!class_exists('ZipArchive')) return '';
    $name = 'rollback-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4) . '.zip';
    $path = updater_rollback_dir() . '/' . $name;
    $z = new ZipArchive();
    if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return '';

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->isLink()) continue;
        $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen(str_replace('\\', '/', ROOT)) + 1);
        if (updater_is_protected($rel)) continue;
        $z->addFile($f->getPathname(), $rel);
    }
    $z->close();

    $all = updater_rollbacks();
    foreach (array_slice($all, 3) as $old) @unlink(updater_rollback_dir() . '/' . $old['name']);
    return $name;
}

/** Extract $zipPath safely, auto-locate package root containing index.php + core/bootstrap.php, and apply code files. */
function updater_apply_from_zip(string $zipPath, string $sourceLabel, bool $isRollback = false): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('امتداد ZipArchive غير مفعّل في PHP على الاستضافة.');
    @set_time_limit(180);

    $stage = ROOT . '/data/.updater_stage_' . bin2hex(random_bytes(6));
    if (!@mkdir($stage, 0755, true)) throw new RuntimeException('تعذّر إنشاء مجلد الفحص المؤقت داخل data/.');

    try {
        $z = new ZipArchive();
        if ($z->open($zipPath) !== true) throw new RuntimeException('تعذّر فتح الملف المضغوط (تأكد أنه ملف ZIP سليم).');
        if ($z->numFiles > 5000) { $z->close(); throw new RuntimeException('عدد الملفات داخل الأرشيف كبير جداً.'); }

        $totalBytes = 0;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i);
            if (!$st) continue;
            $entry = (string)$st['name'];
            if (str_ends_with($entry, '/')) continue;
            if (isset($st['external_attributes']) && (((int)$st['external_attributes'] >> 16) & 0120000) === 0120000) continue;
            $clean = updater_clean_rel($entry);
            if ($clean === null) continue;
            $totalBytes += (int)$st['size'];
            if ($totalBytes > 150 * 1024 * 1024) { $z->close(); throw new RuntimeException('حجم الملفات المستخرجة يتجاوز الحد الآمن (150MB).'); }

            $stream = $z->getStream($entry);
            if (!$stream) continue;
            $target = $stage . '/' . $clean;
            $parent = dirname($target);
            if (!is_dir($parent)) @mkdir($parent, 0755, true);
            $out = fopen($target, 'wb');
            if ($out) { stream_copy_to_stream($stream, $out); fclose($out); }
            fclose($stream);
        }
        $z->close();

        // Auto-detect package root (direct root or single GitHub wrapper folder like yousuf-portfolio-hub-main/)
        $pkgRoot = null;
        if (is_file($stage . '/index.php') && is_file($stage . '/core/bootstrap.php')) {
            $pkgRoot = $stage;
        } else {
            foreach (glob($stage . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
                if (is_file($sub . '/index.php') && is_file($sub . '/core/bootstrap.php')) {
                    $pkgRoot = $sub;
                    break;
                }
            }
        }
        if ($pkgRoot === null || !is_file($pkgRoot . '/admin/index.php')) {
            throw new RuntimeException('الملف المضغوط لا يبدو كإصدار صالح للموقع (يجب أن يحتوي على index.php و core/bootstrap.php و admin/index.php).');
        }

        $rollbackName = $isRollback ? '' : updater_create_rollback();

        $updated = 0;
        $skippedProtected = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pkgRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->isLink()) continue;
            $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen(str_replace('\\', '/', $pkgRoot)) + 1);
            if (updater_is_protected($rel)) {
                $skippedProtected++;
                continue;
            }
            $dest = ROOT . '/' . $rel;
            $destDir = dirname($dest);
            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
                throw new RuntimeException('تعذّر إنشاء المجلد: ' . $rel);
            }
            $tmpDest = $dest . '.upd_' . bin2hex(random_bytes(3));
            if (!@copy($f->getPathname(), $tmpDest) || !@rename($tmpDest, $dest)) {
                @unlink($tmpDest);
                if (!@copy($f->getPathname(), $dest)) throw new RuntimeException('تعذّر استبدال الملف: ' . $rel);
            }
            @chmod($dest, 0644);
            $updated++;
        }

        clearstatcache(true);
        if (function_exists('opcache_reset')) @opcache_reset();

        $migCount = 0;
        try {
            $migLog = migrate_all();
            $migCount = count($migLog);
        } catch (Throwable $me) {
            error_log('[updater] migrate warning: ' . $me->getMessage());
        }

        $hist = json_decode(setting('updater_history', '[]'), true) ?: [];
        array_unshift($hist, [
            'ts' => time(),
            'source' => $sourceLabel,
            'files' => $updated,
            'protected' => $skippedProtected,
            'rollback' => $rollbackName,
        ]);
        set_setting('updater_history', json_encode(array_slice($hist, 0, 15), JSON_UNESCAPED_UNICODE));

        return ['updated' => $updated, 'protected' => $skippedProtected, 'migrations' => $migCount, 'rollback' => $rollbackName];
    } finally {
        updater_rrmdir($stage);
    }
}

/** Download repository zipball from GitHub into a temporary file. */
function updater_download_github_zip(string $repoSlug, string $branch): string {
    if (!function_exists('curl_init')) throw new RuntimeException('امتداد cURL غير مفعّل في PHP.');
    $url = rtrim((string)cfg('github_api', 'https://api.github.com'), '/') . '/repos/' . $repoSlug . '/zipball/' . rawurlencode($branch);
    $tmp = ROOT . '/data/.gh_update_' . bin2hex(random_bytes(5)) . '.zip';
    $fp = fopen($tmp, 'wb');
    if (!$fp) throw new RuntimeException('تعذّر إنشاء ملف التنزيل المؤقت داخل data/.');

    $headers = ['User-Agent: portfolio-self-updater', 'Accept: application/vnd.github+json'];
    if ($tok = gh_token()) $headers[] = 'Authorization: Bearer ' . $tok;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $code !== 200 || !is_file($tmp) || filesize($tmp) < 1024) {
        @unlink($tmp);
        throw new RuntimeException("فشل جلب الحزمة من GitHub (HTTP $code" . ($err ? " — $err" : '') . '). تأكد من اسم المستودع والفرع أو التوكن إن كان خاصاً.');
    }
    return $tmp;
}
