<?php
declare(strict_types=1);
/* Manual GitHub sync (no background jobs needed). Press "sync" in the panel, or point a Hostinger cron at cron_sync.php. */

function gh_get(string $path, bool $raw = false): array {
    $h = ['User-Agent: portfolio-sync', 'Accept: ' . ($raw ? 'application/vnd.github.raw+json' : 'application/vnd.github+json')];
    if ($tok = gh_token()) $h[] = 'Authorization: Bearer ' . $tok;
    $ch = curl_init(rtrim((string)cfg('github_api', 'https://api.github.com'), '/') . $path);
    $remaining = null;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$remaining) {
            if (stripos($line, 'x-ratelimit-remaining:') === 0) $remaining = (int)trim(substr($line, 22));
            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('curl: ' . $err);
    if (($code === 403 || $code === 429) && $remaining === 0) throw new RuntimeException('rate_limit');
    return [$code, (string)$body, $remaining];
}
function kind_of_asset(string $n): string {
    $n = strtolower($n);
    if (preg_match('/\.(sha256|sha512|md5|sig|asc)$|checksums?/', $n)) return 'checksum';
    if (preg_match('/\.(exe|msi|apk|dmg|pkg|deb|rpm|appimage)$/', $n)) return 'installer';
    if (preg_match('/portable|\.(zip|tar\.gz|tgz|7z)$/', $n)) return 'portable';
    return 'other';
}
function platform_of(array $names): string {
    $p = [];
    foreach ($names as $n) {
        $n = strtolower($n);
        if (preg_match('/\.(exe|msi)$|win/', $n)) $p['Windows'] = 1;
        if (preg_match('/\.apk$|android/', $n)) $p['Android'] = 1;
        if (preg_match('/\.(dmg|pkg)$|mac|darwin/', $n)) $p['macOS'] = 1;
        if (preg_match('/\.(deb|rpm|appimage)$|linux/', $n)) $p['Linux'] = 1;
    }
    return implode(' / ', array_keys($p));
}

/** One sync pass (idempotent). Handles at most $budget repos' README/release calls per run; press again to continue. */
function sync_run(bool $force = false, int $budget = 25): array {
    $user = gh_user();
    if ($user === '') throw new RuntimeException('github_user is not set in config.php');
    @set_time_limit(120);
    $st = ['repos' => 0, 'new' => 0, 'processed' => 0, 'pending' => 0, 'apps' => 0, 'files' => 0, 'warn' => []];
    $repos = [];
    for ($page = 1; $page <= 5; $page++) {
        [$code, $body] = gh_get('/users/' . rawurlencode($user) . "/repos?per_page=100&type=owner&sort=pushed&page=$page");
        if ($code !== 200) throw new RuntimeException("GitHub HTTP $code: " . mb_substr($body, 0, 150));
        $batch = json_decode($body, true) ?: [];
        $repos = array_merge($repos, $batch);
        if (count($batch) < 100) break;
    }
    $withForks = setting('sync_forks', '0') === '1';
    $now = time();
    foreach ($repos as $r) {
        if (!$withForks && !empty($r['fork'])) continue;
        $st['repos']++;
        $p = row('SELECT * FROM projects WHERE gh_id=?', [$r['id']]);
        $common = [
            'language' => (string)($r['language'] ?? ''), 'stars' => (int)$r['stargazers_count'], 'forks' => (int)$r['forks_count'],
            'license' => (string)($r['license']['spdx_id'] ?? ''), 'pushed_at' => (string)$r['pushed_at'],
            'html_url' => (string)$r['html_url'], 'homepage' => (string)($r['homepage'] ?? ''), 'topics' => implode(',', $r['topics'] ?? []), 'updated' => $now,
        ];
        if (!$p) {
            $id = ins('projects', $common + [
                'gh_id' => $r['id'], 'name' => $r['name'], 'description' => (string)($r['description'] ?? ''),
                'slug' => unique_slug('projects', slugify($r['name'])), 'created' => $now,
            ]);
            $p = row('SELECT * FROM projects WHERE id=?', [$id]);
            $st['new']++;
        } else {
            if (!$p['locked']) { $common['name'] = $r['name']; $common['description'] = (string)($r['description'] ?? ''); }
            upd('projects', $common, 'id=?', [$p['id']]);
            $p = array_merge($p, $common);
        }
        $hasApp = table_exists('apps') && (bool)val('SELECT COUNT(*) FROM apps WHERE project_id=? AND ignored=0', [$p['id']]);
        $need = $force || !$p['checked_at'] || $p['synced_pushed'] !== $r['pushed_at'] || $hasApp;
        if (!$need) continue;
        if ($budget <= 0) { $st['pending']++; continue; }
        $budget--; $st['processed']++;
        try {
            if ($force || $p['synced_pushed'] !== $r['pushed_at'] || $p['readme'] === null || $p['readme'] === '') {
                [$c, $b] = gh_get('/repos/' . rawurlencode($user) . '/' . rawurlencode($r['name']) . '/readme', true);
                if ($c === 200 && !$p['locked']) upd('projects', ['readme' => mb_strcut($b, 0, 60000)], 'id=?', [$p['id']]);
            }
            [$c, $b] = gh_get('/repos/' . rawurlencode($user) . '/' . rawurlencode($r['name']) . '/releases/latest');
            if ($c === 200 && table_exists('apps')) sync_release((int)$p['id'], $p, json_decode($b, true) ?: [], $st);
            upd('projects', ['synced_pushed' => $r['pushed_at'], 'checked_at' => $now], 'id=?', [$p['id']]);
        } catch (RuntimeException $ex) {
            if ($ex->getMessage() === 'rate_limit') { $st['warn'][] = 'rate_limit'; $st['pending'] += 1; break; }
            $st['warn'][] = $r['name'] . ': ' . $ex->getMessage();
        }
    }
    return $st;
}
function sync_release(int $pid, array $p, array $rel, array &$st): void {
    $assets = $rel['assets'] ?? [];
    if (!$assets) return;
    $app = row('SELECT * FROM apps WHERE project_id=?', [$pid]);
    if ($app && $app['ignored']) return;
    $meta = ['version' => (string)($rel['tag_name'] ?? ''), 'release_notes' => (string)($rel['body'] ?? ''),
             'release_url' => (string)($rel['html_url'] ?? ''), 'published_at' => (string)($rel['published_at'] ?? '')];
    if (!$app) {
        $aid = ins('apps', $meta + ['project_id' => $pid, 'slug' => unique_slug('apps', slugify($p['name'])), 'name' => $p['name'],
            'description' => (string)$p['description'], 'platform' => platform_of(array_column($assets, 'name')), 'created' => time()]);
        $st['apps']++;
    } else {
        $aid = (int)$app['id'];
        if (!$app['locked'] && $app['platform'] === '') $meta['platform'] = platform_of(array_column($assets, 'name'));
        upd('apps', $meta, 'id=?', [$aid]);
    }
    $keep = [0];
    foreach ($assets as $a) {
        $keep[] = (int)$a['id'];
        $f = row('SELECT id FROM app_files WHERE gh_asset_id=?', [$a['id']]);
        $d = ['app_id' => $aid, 'name' => $a['name'], 'size' => (int)$a['size'], 'gh_count' => (int)($a['download_count'] ?? 0),
              'digest' => isset($a['digest']) ? (string)$a['digest'] : null, 'url' => (string)$a['browser_download_url']];
        if ($f) upd('app_files', $d, 'id=?', [$f['id']]);
        else ins('app_files', $d + ['gh_asset_id' => $a['id'], 'label' => '', 'kind' => kind_of_asset($a['name'])]);
        $st['files']++;
    }
    q('DELETE FROM app_files WHERE app_id=? AND gh_asset_id IS NOT NULL AND gh_asset_id NOT IN (' . implode(',', $keep) . ')', [$aid]);
}
function sync_and_log(bool $force = false): array {
    try {
        $st = sync_run($force);
        $sum = "repos {$st['repos']} · new {$st['new']} · processed {$st['processed']} · pending {$st['pending']} · apps+ {$st['apps']} · files {$st['files']}"
             . ($st['warn'] ? ' · warn: ' . implode('; ', $st['warn']) : '');
        ins('sync_log', ['ts' => time(), 'ok' => $st['warn'] ? 0 : 1, 'summary' => $sum]);
        return [true, $sum, $st];
    } catch (Throwable $ex) {
        $sum = 'ERROR: ' . $ex->getMessage();
        try { ins('sync_log', ['ts' => time(), 'ok' => 0, 'summary' => $sum]); } catch (Throwable $e2) {}
        return [false, $sum, []];
    }
}
