<?php
declare(strict_types=1);
/* Smart GitHub sync + multi-signal App & Version discovery (releases, pre-releases, tags, version manifests, and tool/app heuristics). */

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
    if (preg_match('/\.(sha256|sha512|md5|sig|asc|txt)$|checksums?|sha256sums/', $n)) return 'checksum';
    if (preg_match('/\.(exe|msi|apk|aab|dmg|pkg|deb|rpm|appimage|crx|xpi)$/', $n)) return 'installer';
    if (preg_match('/portable|\.(zip|tar\.gz|tgz|7z|ps1|bat|pyz|whl)$/', $n)) return 'portable';
    return 'other';
}

/** Infer human-friendly platform string from asset filenames + repo metadata (language, topics, description). */
function detect_app_platform(array $assetNames, array $repo): string {
    $p = [];
    foreach ($assetNames as $n) {
        $n = strtolower($n);
        if (preg_match('/\.(exe|msi|bat|ps1)$|win|win64|win32|x64/', $n)) $p['Windows'] = 1;
        if (preg_match('/\.(apk|aab)$|android/', $n)) $p['Android'] = 1;
        if (preg_match('/\.(dmg|pkg)$|mac|darwin|osx/', $n)) $p['macOS'] = 1;
        if (preg_match('/\.(deb|rpm|appimage)$|linux/', $n)) $p['Linux'] = 1;
        if (preg_match('/\.(crx|xpi)$|extension/', $n)) $p['Browser Extension'] = 1;
    }
    if ($p) return implode(' / ', array_keys($p));

    $lang = strtolower((string)($repo['language'] ?? ''));
    $hay = strtolower(($repo['name'] ?? '') . ' ' . ($repo['description'] ?? '') . ' ' . implode(' ', (array)($repo['topics'] ?? [])));

    if (preg_match('/\b(android|apk|kotlin)\b/', $hay) || $lang === 'kotlin') $p['Android'] = 1;
    if (preg_match('/\b(ios|swift)\b/', $hay) || $lang === 'swift') $p['iOS'] = 1;
    if (preg_match('/\b(react[- ]native|expo|flutter|mobile app)\b/', $hay) || $lang === 'dart') { $p['Android'] = 1; $p['iOS'] = 1; }
    if (preg_match('/\b(chrome extension|browser extension|webextension|firefox addon)\b/', $hay)) $p['Browser Extension'] = 1;
    if (preg_match('/\b(whatsapp|telegram|discord)\s*bot\b|\bw-bot\b|\bbot\b/', $hay)) $p['Bot'] = 1;
    if (preg_match('/\b(windows|winui|wpf|winforms|pyside6|pyqt|customtkinter|pywebview|pyinstaller|desktop|powershell|exe)\b/', $hay)
        || in_array($lang, ['c#', 'powershell', 'batchfile'], true)) {
        $p['Windows'] = 1;
    }
    if (!$p) {
        if ($lang === 'python') $p['Windows / Python'] = 1;
        elseif ($lang === 'c++' || $lang === 'c' || $lang === 'rust' || $lang === 'go') $p['Desktop / CLI'] = 1;
        elseif ($lang === 'javascript' || $lang === 'typescript') $p['Cross-platform'] = 1;
        else $p['Web App'] = 1;
    }
    return implode(' / ', array_keys($p));
}

/** Decide whether a repository without binary releases is still a downloadable user-facing App/Tool (vs website/portfolio/config). */
function is_repo_an_app(array $repo, string $user): bool {
    $name = strtolower((string)($repo['name'] ?? ''));
    $desc = strtolower((string)($repo['description'] ?? ''));
    $lang = strtolower((string)($repo['language'] ?? ''));
    $topics = array_map('strtolower', (array)($repo['topics'] ?? []));
    $hay = $name . ' ' . $desc . ' ' . implode(' ', $topics);

    // Explicit non-app exclusions (profile README, portfolios, website templates, pure catalogs/docs)
    if ($name === strtolower($user)) return false;
    if (preg_match('/(^|[-_])(portfolio|website|template|blog|docs|my-message|catalog)($|[-_])/', $name)) return false;
    if (in_array('github-config', $topics, true) || in_array('portfolio', $topics, true)) return false;
    if (preg_match('/\b(e-commerce platform|company website|html5\/css3 template|multilingual php portfolio|official public catalog)\b/', $desc)) return false;
    if (in_array($lang, ['css', 'html', 'scss'], true)) return false;
    if ($lang === 'php' && !preg_match('/\b(cli|desktop|tool)\b/', $hay)) return false;

    // Positive app / desktop / mobile / CLI / bot / utility signals
    if (in_array($lang, ['kotlin', 'java', 'dart', 'swift', 'c#', 'c++', 'rust', 'go', 'powershell'], true)) return true;
    if ($lang === 'python') return true;
    if (preg_match('/\b(app|application|desktop|mobile|android|windows|tool|cli|utility|bot|extension|cleaner|organizer|scraper|backup|splitter|calculator|remover|firewall|monitor|downloader|maker|pass|qr|zakat|erp)\b/', $hay)) {
        return true;
    }
    return false;
}

/** Extract a clean version string from release, tags, or README badges/headings. */
function extract_version_from_readme(?string $readme): string {
    if (!$readme) return '';
    if (preg_match('/\bv?(\d+\.\d+(?:\.\d+)?(?:-[A-Za-z0-9.]+)?)\b/', mb_substr($readme, 0, 2500), $m)) {
        return 'v' . ltrim($m[1], 'vV');
    }
    return '';
}

/** Pick the best release from /releases list: prefer latest release WITH assets (stable first, then pre-release), else newest release. */
function pick_best_release(array $releases): ?array {
    if (!$releases) return null;
    foreach ($releases as $r) {
        if (empty($r['draft']) && empty($r['prerelease']) && !empty($r['assets'])) return $r;
    }
    foreach ($releases as $r) {
        if (empty($r['draft']) && !empty($r['assets'])) return $r;   // catches pre-release APKs/EXEs like yousuf-toolkit-app!
    }
    foreach ($releases as $r) {
        if (empty($r['draft'])) return $r;
    }
    return null;
}

/** One sync pass (idempotent). Default budget=80 so typical GitHub accounts sync all repos in a single click. */
function sync_run(bool $force = false, int $budget = 80): array {
    $user = gh_user();
    if ($user === '') throw new RuntimeException('github_user is not set in config.php');
    @set_time_limit(180);
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
    $detectSourceApps = setting('apps_detect_source', '1') === '1';
    $now = time();

    foreach ($repos as $r) {
        if (!$withForks && !empty($r['fork'])) continue;
        $st['repos']++;
        $p = row('SELECT * FROM projects WHERE gh_id=? OR (gh_id IS NULL AND LOWER(name)=LOWER(?))', [$r['id'], $r['name']]);
        $common = [
            'gh_id' => $r['id'],
            'language' => (string)($r['language'] ?? ''), 'stars' => (int)$r['stargazers_count'], 'forks' => (int)$r['forks_count'],
            'license' => (string)($r['license']['spdx_id'] ?? ''), 'pushed_at' => (string)$r['pushed_at'],
            'html_url' => (string)$r['html_url'], 'homepage' => (string)($r['homepage'] ?? ''), 'topics' => implode(',', $r['topics'] ?? []), 'updated' => $now,
        ];
        if (!$p) {
            $id = ins('projects', $common + [
                'name' => $r['name'], 'description' => (string)($r['description'] ?? ''),
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
        $candidateApp = is_repo_an_app($r, $user);
        $need = $force || !$p['checked_at'] || $p['synced_pushed'] !== $r['pushed_at'] || $hasApp || ($detectSourceApps && $candidateApp && !$hasApp);
        if (!$need) continue;
        if ($budget <= 0) { $st['pending']++; continue; }
        $budget--; $st['processed']++;

        try {
            $readmeText = (string)($p['readme'] ?? '');
            if ($force || $p['synced_pushed'] !== $r['pushed_at'] || $readmeText === '') {
                [$c, $b] = gh_get('/repos/' . rawurlencode($user) . '/' . rawurlencode($r['name']) . '/readme', true);
                if ($c === 200) {
                    $readmeText = mb_strcut($b, 0, 60000);
                    if (!$p['locked']) upd('projects', ['readme' => $readmeText], 'id=?', [$p['id']]);
                }
            }

            if (table_exists('apps')) {
                // 1) Check ALL recent releases (stable + pre-releases, up to 10)
                [$rc, $rb] = gh_get('/repos/' . rawurlencode($user) . '/' . rawurlencode($r['name']) . '/releases?per_page=10');
                $releases = ($rc === 200) ? (json_decode($rb, true) ?: []) : [];
                $bestRel = pick_best_release($releases);

                // 2) Check tags when no release exists or to spot a newer tag version
                $latestTag = '';
                if (!$bestRel && ($candidateApp || $hasApp)) {
                    [$tc, $tb] = gh_get('/repos/' . rawurlencode($user) . '/' . rawurlencode($r['name']) . '/tags?per_page=5');
                    $tags = ($tc === 200) ? (json_decode($tb, true) ?: []) : [];
                    if (!empty($tags[0]['name'])) $latestTag = (string)$tags[0]['name'];
                }

                if ($bestRel) {
                    sync_release_or_app((int)$p['id'], $p, $r, $user, $bestRel, $latestTag, $readmeText, $st);
                } elseif ($latestTag !== '' || ($detectSourceApps && $candidateApp)) {
                    sync_release_or_app((int)$p['id'], $p, $r, $user, null, $latestTag, $readmeText, $st);
                }
            }
            upd('projects', ['synced_pushed' => $r['pushed_at'], 'checked_at' => $now], 'id=?', [$p['id']]);
        } catch (RuntimeException $ex) {
            if ($ex->getMessage() === 'rate_limit') { $st['warn'][] = 'rate_limit (أضف GitHub Token في الإعدادات لرفع الحد إلى 5000/ساعة)'; $st['pending'] += 1; break; }
            $st['warn'][] = $r['name'] . ': ' . $ex->getMessage();
        }
    }
    return $st;
}

/** Upsert an app + its downloadable files from either a GitHub Release, a Git Tag, or a detected App Repository. */
function sync_release_or_app(int $pid, array $p, array $repo, string $user, ?array $rel, string $latestTag, string $readmeText, array &$st): void {
    $app = row('SELECT * FROM apps WHERE project_id=?', [$pid]);
    if ($app && $app['ignored']) return;

    $assets = $rel['assets'] ?? [];
    $defBranch = (string)($repo['default_branch'] ?? 'main');
    $version = (string)($rel['tag_name'] ?? '');
    if ($version === '' && $latestTag !== '') $version = $latestTag;
    if ($version === '') $version = extract_version_from_readme($readmeText);

    $relUrl = (string)($rel['html_url'] ?? '');
    if ($relUrl === '' && $latestTag !== '') {
        $relUrl = 'https://github.com/' . rawurlencode($user) . '/' . rawurlencode($repo['name']) . '/releases/tag/' . rawurlencode($latestTag);
    } elseif ($relUrl === '') {
        $relUrl = (string)($repo['html_url'] ?? '');
    }

    $pubAt = (string)($rel['published_at'] ?? ($repo['pushed_at'] ?? gmdate('c')));
    $platform = detect_app_platform(array_column($assets, 'name'), $repo);

    $meta = [
        'version' => $version !== '' ? $version : 'latest',
        'release_notes' => (string)($rel['body'] ?? ''),
        'release_url' => $relUrl,
        'published_at' => $pubAt,
    ];

    if (!$app) {
        $aid = ins('apps', $meta + [
            'project_id' => $pid,
            'slug' => unique_slug('apps', slugify($p['name'])),
            'name' => $p['name'],
            'description' => (string)$p['description'],
            'platform' => $platform,
            'created' => time(),
        ]);
        $st['apps']++;
    } else {
        $aid = (int)$app['id'];
        if (!$app['locked']) {
            if ($platform !== '') $meta['platform'] = $platform;
            if ((string)$app['description'] === '' && (string)$p['description'] !== '') $meta['description'] = (string)$p['description'];
        } elseif ($app['platform'] === '' && $platform !== '') {
            $meta['platform'] = $platform;
        }
        upd('apps', $meta, 'id=?', [$aid]);
    }

    $keep = [0];
    if ($assets) {
        foreach ($assets as $a) {
            $keep[] = (int)$a['id'];
            $f = row('SELECT id FROM app_files WHERE gh_asset_id=?', [$a['id']]);
            $d = [
                'app_id' => $aid, 'name' => $a['name'], 'size' => (int)$a['size'],
                'gh_count' => (int)($a['download_count'] ?? 0),
                'digest' => isset($a['digest']) ? (string)$a['digest'] : null,
                'url' => (string)$a['browser_download_url'],
            ];
            if ($f) upd('app_files', $d, 'id=?', [$f['id']]);
            else ins('app_files', $d + ['gh_asset_id' => $a['id'], 'label' => '', 'kind' => kind_of_asset($a['name'])]);
            $st['files']++;
        }
    } else {
        // Fallback downloadable package (tag archive or branch archive) using negative synthetic gh_asset_id = -$pid
        $synId = -$pid;
        $keep[] = $synId;
        $ref = $latestTag !== '' ? ('refs/tags/' . rawurlencode($latestTag)) : ('refs/heads/' . rawurlencode($defBranch));
        $zipUrl = 'https://github.com/' . rawurlencode($user) . '/' . rawurlencode($repo['name']) . '/archive/' . $ref . '.zip';
        $zipName = $repo['name'] . '-' . ($version !== '' ? ltrim($version, 'v') : $defBranch) . '.zip';
        $f = row('SELECT id FROM app_files WHERE gh_asset_id=?', [$synId]);
        $d = ['app_id' => $aid, 'name' => $zipName, 'size' => 0, 'gh_count' => 0, 'digest' => null, 'url' => $zipUrl];
        if ($f) upd('app_files', $d, 'id=?', [$f['id']]);
        else ins('app_files', $d + ['gh_asset_id' => $synId, 'label' => 'حزمة التطبيق (ZIP)', 'kind' => 'portable']);
        $st['files']++;
    }
    q('DELETE FROM app_files WHERE app_id=? AND gh_asset_id IS NOT NULL AND gh_asset_id NOT IN (' . implode(',', $keep) . ')', [$aid]);

    // Auto-sync linked Board (لوحة الإدارة) if one matches this repo's GitHub URL or name
    if (table_exists('boards')) {
        $b = row('SELECT id, downloads_count, app_version FROM boards WHERE LOWER(name)=LOWER(?) OR LOWER(github_url)=LOWER(?)', [$p['name'], (string)$repo['html_url']]);
        if ($b) {
            $ghDlSum = (int)array_sum(array_map(fn($x) => (int)($x['download_count'] ?? 0), $assets));
            $siteDlSum = table_exists('downloads') ? (int)val('SELECT COUNT(*) FROM downloads WHERE app_id=?', [$aid]) : 0;
            $bdUpd = [
                'github_synced' => 1,
                'version_uploaded' => $assets ? 1 : 0,
                'downloads_count' => max((int)$b['downloads_count'], $ghDlSum + $siteDlSum),
                'updated' => time(),
            ];
            if ($version !== '') $bdUpd['app_version'] = $version;
            upd('boards', $bdUpd, 'id=?', [$b['id']]);
        }
    }
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
