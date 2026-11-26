<?php
declare(strict_types=1);
/**
 * Unified Mother Panel JSON API Gateway (/api/v1/...).
 * - Mother Panel alone holds backend code and token hashes; child boards contain zero standalone PHP.
 * - Strict JSON-only output on EVERY path (including errors/exceptions/404s — NEVER HTML).
 * - Every query is strictly scoped by app_id (= boards.id).
 * - Every API call is logged in BOTH audit_log and sync_log with a unique req_id.
 */

function app_api_send(int $httpCode, array $payload, string $reqId, int $appId, string $endpoint, string $logNote = ''): never {
    while (ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        header('X-Request-Id: ' . $reqId);
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-App-Token, X-Session-Token');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    }
    $ok = !empty($payload['ok']);
    $payload = ['ok' => $ok, 'req_id' => $reqId, 'ts' => time()] + $payload;
    if (is_file(ROOT . '/config.php')) {
        try { mother_log_call($reqId, $appId, $endpoint, $ok, $logNote !== '' ? $logNote : "HTTP $httpCode"); } catch (Throwable $e) {}
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function app_api_err(int $httpCode, string $code, string $message, string $reqId, int $appId, string $endpoint): never {
    app_api_send($httpCode, [
        'ok' => false,
        'error' => ['code' => $code, 'message' => $message],
    ], $reqId, $appId, $endpoint, "$code ($httpCode)");
}

function app_api_input(): array {
    static $in = null;
    if ($in !== null) return $in;
    $in = $_GET + $_POST;
    $raw = (string)file_get_contents('php://input');
    if ($raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) $in = $j + $in;
    }
    return $in;
}

function app_api_header(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$key])) return trim((string)$_SERVER[$key]);
    if ($name === 'Authorization' && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }
    return '';
}

function app_api_extract_app_token(array $in): string {
    $h = app_api_header('X-App-Token');
    if ($h !== '') return $h;
    $auth = app_api_header('Authorization');
    if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) return trim($m[1]);
    return trim((string)($in['app_token'] ?? ''));
}

function app_api_extract_session_token(array $in): string {
    $h = app_api_header('X-Session-Token');
    if ($h !== '') return $h;
    return trim((string)($in['session_token'] ?? ''));
}

/** Build smart update check payload (full APK + startup data-only sub-updates + permissions re-prompt flag). */
function app_api_compute_updates(array $board, string $clientVer, int $clientCode, int $clientSubCode): array {
    $appId = (int)$board['id'];
    $minVer = trim((string)($board['min_app_version'] ?? ''));

    $latestFull = row(
        "SELECT id, version, version_code, min_version, channel, download_url, sha256, size_bytes, permissions_changed, permissions_note, changelog, created
           FROM releases WHERE app_id=? AND release_type='full' AND is_active=1 ORDER BY version_code DESC, id DESC LIMIT 1",
        [$appId]
    );
    if ($latestFull && trim((string)($latestFull['min_version'] ?? '')) !== '') {
        if ($minVer === '' || app_ver_cmp((string)$latestFull['min_version'], $minVer) > 0) {
            $minVer = (string)$latestFull['min_version'];
        }
    }

    $forceUpdate = ($minVer !== '' && $clientVer !== '' && app_ver_cmp($clientVer, $minVer) < 0);

    $fullUpdate = null;
    if ($latestFull) {
        $newerByCode = ($clientCode > 0 && (int)$latestFull['version_code'] > $clientCode);
        $newerByVer  = ($clientVer !== '' && app_ver_cmp((string)$latestFull['version'], $clientVer) > 0);
        if ($forceUpdate || $newerByCode || $newerByVer || ($clientVer === '' && $clientCode === 0)) {
            $fullUpdate = [
                'id'                  => (int)$latestFull['id'],
                'version'             => (string)$latestFull['version'],
                'version_code'        => (int)$latestFull['version_code'],
                'channel'             => (string)$latestFull['channel'],
                'download_url'        => (string)($latestFull['download_url'] ?? ''),
                'sha256'              => (string)($latestFull['sha256'] ?? ''),
                'size_bytes'          => (int)$latestFull['size_bytes'],
                'permissions_changed' => (bool)$latestFull['permissions_changed'],
                'permissions_note'    => (string)($latestFull['permissions_note'] ?? ''),
                'changelog'           => (string)($latestFull['changelog'] ?? ''),
                'published_at'        => (int)$latestFull['created'],
            ];
        }
    }

    $subRows = rows(
        "SELECT id, version, version_code, channel, download_url, sha256, size_bytes, changelog, payload_json, created
           FROM releases WHERE app_id=? AND release_type='sub' AND is_active=1 AND version_code>? ORDER BY version_code ASC, id ASC",
        [$appId, $clientSubCode]
    );
    $subUpdates = [];
    foreach ($subRows as $sr) {
        $subUpdates[] = [
            'id'           => (int)$sr['id'],
            'version'      => (string)$sr['version'],
            'sub_code'     => (int)$sr['version_code'],
            'download_url' => (string)($sr['download_url'] ?? ''),
            'sha256'       => (string)($sr['sha256'] ?? ''),
            'size_bytes'   => (int)$sr['size_bytes'],
            'changelog'    => (string)($sr['changelog'] ?? ''),
            'payload'      => json_decode((string)($sr['payload_json'] ?? ''), true),
            'published_at' => (int)$sr['created'],
        ];
    }

    return [
        'min_version'  => $minVer,
        'force_update' => $forceUpdate,
        'full_update'  => $fullUpdate,
        'sub_updates'  => $subUpdates,
    ];
}

function app_api_settings_map(int $appId): array {
    $out = [];
    foreach (rows('SELECT cfg_key, cfg_val FROM app_settings_kv WHERE app_id=? ORDER BY cfg_key', [$appId]) as $r) {
        $v = (string)($r['cfg_val'] ?? '');
        $j = json_decode($v, true);
        $out[$r['cfg_key']] = (json_last_error() === JSON_ERROR_NONE) ? $j : $v;
    }
    return $out;
}

function app_api_require_session(int $appId, array $in, string $reqId, string $endpoint): array {
    $tok = app_api_extract_session_token($in);
    if ($tok === '') app_api_err(401, 'session_required', 'تسجيل الدخول إلزامي قبل فتح التطبيق أو المزامنة.', $reqId, $appId, $endpoint);
    $hash = hash('sha256', $tok);
    $sess = row(
        'SELECT s.*, u.email, u.display_name, u.status AS user_status, u.sync_data, u.sync_version, u.last_sync
           FROM app_sessions s
           JOIN app_users u ON u.id = s.user_id AND u.app_id = s.app_id
          WHERE s.app_id=? AND s.token_hash=? AND s.revoked=0 AND s.expires>?',
        [$appId, $hash, time()]
    );
    if (!$sess) app_api_err(401, 'invalid_session', 'انتهت الجلسة أو أنها غير صالحة لهذا التطبيق (app_id). يرجى تسجيل الدخول مجدداً.', $reqId, $appId, $endpoint);
    if (($sess['user_status'] ?? 'active') !== 'active') {
        app_api_err(403, 'account_disabled', 'هذا الحساب موقوف من اللوحة الأم.', $reqId, $appId, $endpoint);
    }
    upd('app_sessions', ['last_seen' => time()], 'id=? AND app_id=?', [$sess['id'], $appId]);
    return $sess;
}

function app_api_upsert_device(int $appId, ?int $userId, array $in): ?int {
    $uid = mb_substr(trim((string)($in['device_uid'] ?? $in['device_id'] ?? '')), 0, 120);
    if ($uid === '') return null;
    $now = time();
    $dev = row('SELECT id FROM devices WHERE app_id=? AND device_uid=?', [$appId, $uid]);
    $data = [
        'user_id'     => $userId,
        'device_name' => mb_substr(trim((string)($in['device_name'] ?? 'Android Device')), 0, 150),
        'platform'    => mb_substr(trim((string)($in['platform'] ?? 'android')), 0, 40),
        'os_version'  => mb_substr(trim((string)($in['os_version'] ?? '')), 0, 40),
        'app_version' => mb_substr(trim((string)($in['app_version'] ?? '')), 0, 40),
        'last_sync'   => $now,
    ];
    if ($dev) {
        upd('devices', $data, 'id=? AND app_id=?', [$dev['id'], $appId]);
        return (int)$dev['id'];
    }
    return ins('devices', $data + [
        'app_id'     => $appId,
        'device_uid' => $uid,
        'status'     => 'active',
        'created'    => $now,
    ]);
}

function app_api_dispatch(string $subPath): never {
    ob_start();
    $reqId = 'req_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(4));
    $endpoint = 'api/' . trim($subPath, '/');
    $currentAppId = 0;

    set_exception_handler(function (Throwable $e) use (&$reqId, &$currentAppId, &$endpoint): void {
        app_api_err(500, 'internal_error', cfg('debug') ? $e->getMessage() : 'حدث خطأ داخلي في بوابة اللوحة الأم.', $reqId, $currentAppId, $endpoint);
    });
    register_shutdown_function(function () use (&$reqId, &$currentAppId, &$endpoint): void {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            app_api_err(500, 'fatal_error', 'خطأ قاتل في الخادم.', $reqId, $currentAppId, $endpoint);
        }
    });

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        app_api_send(200, ['ok' => true, 'preflight' => true], $reqId, 0, $endpoint, 'CORS preflight');
    }

    if (!is_file(ROOT . '/config.php')) {
        app_api_err(503, 'not_installed', 'اللوحة الأم غير مثبّتة بعد.', $reqId, 0, $endpoint);
    }

    // Ensure unified schema exists before serving API requests
    if (!table_exists('app_users') || !table_exists('boards')) {
        migrate_all();
    }

    $clean = preg_replace('#^v1/?#', '', trim($subPath, '/'));
    if ($clean === '' || $clean === 'health') {
        app_api_send(200, [
            'ok' => true,
            'gateway' => 'mother-panel-api',
            'version' => '1.0',
            'platform' => 'pure-php-hostinger',
            'unified_db' => true,
            'scope_key' => 'app_id',
            'online_mandatory' => true,
        ], $reqId, 0, $endpoint, 'gateway health');
    }

    $in = app_api_input();
    $appToken = app_api_extract_app_token($in);
    if ($appToken === '') {
        app_api_err(401, 'missing_app_token', 'مفتاح ربط التطبيق (X-App-Token أو Bearer) مطلوب؛ اللوحة الأم وحدها تملك صلاحية التحقق.', $reqId, 0, $endpoint);
    }

    $tokHash = hash('sha256', $appToken);
    $board = row('SELECT * FROM boards WHERE api_token_hash=?', [$tokHash]);
    if (!$board) {
        app_api_err(401, 'invalid_app_token', 'مفتاح ربط التطبيق غير صالح أو تم إبطاله من اللوحة الأم.', $reqId, 0, $endpoint);
    }

    $appId = (int)$board['id'];
    $currentAppId = $appId;

    // Enforce strict app_id / app_slug match if client passed an explicit scope assertion
    if (isset($in['app_id']) && (string)$in['app_id'] !== '' && (int)$in['app_id'] !== $appId) {
        app_api_err(403, 'scope_mismatch', 'عدم تطابق نطاق app_id مع التوكن المرسل.', $reqId, $appId, $endpoint);
    }
    if (!empty($in['app_slug']) && (string)$in['app_slug'] !== (string)$board['slug']) {
        app_api_err(403, 'scope_mismatch', 'عدم تطابق معرّف التطبيق (slug) مع التوكن المرسل.', $reqId, $appId, $endpoint);
    }
    if (($board['status'] ?? '') === 'archived') {
        app_api_err(403, 'app_archived', 'هذا التطبيق مؤرشف حالياً من اللوحة الأم.', $reqId, $appId, $endpoint);
    }

    // Rate limit per IP + app_id on sensitive auth endpoints
    if (str_starts_with($clean, 'auth/') && !rate_hit('api_auth:' . $appId . ':' . client_ip(), 25, 300)) {
        app_api_err(429, 'rate_limited', 'محاولات كثيرة متتالية؛ يرجى الانتظار قليلاً.', $reqId, $appId, $endpoint);
    }

    switch ($clean) {
        case 'status': {
            $upd = app_api_compute_updates(
                $board,
                trim((string)($in['version'] ?? '')),
                (int)($in['version_code'] ?? 0),
                (int)($in['sub_version'] ?? 0)
            );
            $siblings = rows(
                "SELECT id AS app_id, name AS app_name, slug, app_type, description AS summary, app_version AS version, github_url AS repo_url
                   FROM boards WHERE id<>? AND status IN ('active','ready') ORDER BY id DESC LIMIT 20",
                [$appId]
            );
            $packs = rows(
                'SELECT pack_key, title, kind, version, sha256, file_url, updated FROM content_packs WHERE app_id=? AND is_active=1 ORDER BY pack_key',
                [$appId]
            );
            app_api_send(200, [
                'ok' => true,
                'app' => [
                    'app_id'          => $appId,
                    'app_name'        => (string)($board['name'] ?? ''),
                    'slug'            => (string)$board['slug'],
                    'app_type'        => (string)$board['app_type'],
                    'version'         => (string)($board['app_version'] ?? ''),
                    'status'          => (string)$board['status'],
                    'online_required' => (bool)($board['online_required'] ?? 1),
                    'sync_enabled'    => (bool)($board['sync_enabled'] ?? 1),
                    'developer_name'  => (string)($board['developer_name'] ?? ''),
                    'developer_url'   => (string)($board['github_url'] ?? ''),
                ],
                'settings'      => app_api_settings_map($appId),
                'updates'       => $upd,
                'content_packs' => $packs,
                'sibling_apps'  => $siblings,
            ], $reqId, $appId, $endpoint, 'status ok');
        }

        case 'auth/register': {
            $email = strtolower(trim((string)($in['email'] ?? '')));
            $pass  = (string)($in['password'] ?? '');
            $name  = mb_substr(trim((string)($in['display_name'] ?? '')), 0, 150);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                app_api_err(422, 'invalid_email', 'البريد الإلكتروني غير صالح.', $reqId, $appId, $endpoint);
            }
            if (strlen($pass) < 8) {
                app_api_err(422, 'weak_password', 'كلمة المرور يجب أن لا تقل عن 8 خانات.', $reqId, $appId, $endpoint);
            }
            // Scoped strictly to app_id: same email CAN register separate accounts across different apps
            if (row('SELECT id FROM app_users WHERE app_id=? AND LOWER(email)=LOWER(?)', [$appId, $email])) {
                app_api_err(409, 'email_exists_in_app', 'هذا البريد مسجّل مسبقاً في هذا التطبيق؛ يمكنك تسجيل الدخول مباشرة.', $reqId, $appId, $endpoint);
            }
            $now = time();
            $uid = ins('app_users', [
                'app_id'       => $appId,
                'email'        => $email,
                'pass_hash'    => password_hash($pass, PASSWORD_DEFAULT),
                'display_name' => $name !== '' ? $name : explode('@', $email)[0],
                'status'       => 'active',
                'sync_data'    => '{}',
                'sync_version' => 1,
                'last_sync'    => $now,
                'created'      => $now,
                'updated'      => $now,
            ]);
            $cnt = (int)val('SELECT COUNT(*) FROM app_users WHERE app_id=?', [$appId]);
            upd('boards', ['users_count' => $cnt, 'updated' => $now], 'id=?', [$appId]);

            $devId = app_api_upsert_device($appId, $uid, $in);
            $sessPlain = 'mps_' . $appId . '_' . bin2hex(random_bytes(24));
            ins('app_sessions', [
                'app_id'     => $appId,
                'user_id'    => $uid,
                'device_id'  => $devId,
                'token_hash' => hash('sha256', $sessPlain),
                'ip_hash'    => hmac(client_ip()),
                'created'    => $now,
                'last_seen'  => $now,
                'expires'    => $now + 86400 * 30,
                'revoked'    => 0,
            ]);
            app_api_send(201, [
                'ok'            => true,
                'app_id'        => $appId,
                'session_token' => $sessPlain,
                'expires_at'    => $now + 86400 * 30,
                'user'          => ['id' => $uid, 'email' => $email, 'display_name' => $name !== '' ? $name : explode('@', $email)[0]],
                'sync_version'  => 1,
                'sync_data'     => new stdClass(),
            ], $reqId, $appId, $endpoint, "registered user#$uid");
        }

        case 'auth/login': {
            $email = strtolower(trim((string)($in['email'] ?? '')));
            $pass  = (string)($in['password'] ?? '');
            $u = row('SELECT * FROM app_users WHERE app_id=? AND LOWER(email)=LOWER(?)', [$appId, $email]);
            if (!$u || !password_verify($pass, (string)$u['pass_hash'])) {
                app_api_err(401, 'invalid_credentials', 'البريد الإلكتروني أو كلمة المرور غير صحيحة لهذا التطبيق.', $reqId, $appId, $endpoint);
            }
            if (($u['status'] ?? 'active') !== 'active') {
                app_api_err(403, 'account_disabled', 'تم إيقاف هذا الحساب من لوحة التحكم الأم.', $reqId, $appId, $endpoint);
            }
            $now = time();
            $devId = app_api_upsert_device($appId, (int)$u['id'], $in);
            if ($devId) {
                $devRow = row('SELECT status FROM devices WHERE id=? AND app_id=?', [$devId, $appId]);
                if ($devRow && ($devRow['status'] ?? 'active') !== 'active') {
                    app_api_err(403, 'device_blocked', 'تم حظر هذا الجهاز من لوحة التحكم الأم.', $reqId, $appId, $endpoint);
                }
            }
            $sessPlain = 'mps_' . $appId . '_' . bin2hex(random_bytes(24));
            ins('app_sessions', [
                'app_id'     => $appId,
                'user_id'    => (int)$u['id'],
                'device_id'  => $devId,
                'token_hash' => hash('sha256', $sessPlain),
                'ip_hash'    => hmac(client_ip()),
                'created'    => $now,
                'last_seen'  => $now,
                'expires'    => $now + 86400 * 30,
                'revoked'    => 0,
            ]);
            $syncObj = json_decode((string)($u['sync_data'] ?? '{}'), true);
            app_api_send(200, [
                'ok'            => true,
                'app_id'        => $appId,
                'session_token' => $sessPlain,
                'expires_at'    => $now + 86400 * 30,
                'user'          => [
                    'id'           => (int)$u['id'],
                    'email'        => (string)$u['email'],
                    'display_name' => (string)($u['display_name'] ?? ''),
                ],
                'sync_version'  => (int)$u['sync_version'],
                'sync_data'     => is_array($syncObj) && $syncObj ? $syncObj : new stdClass(),
                'settings'      => app_api_settings_map($appId),
                'updates'       => app_api_compute_updates(
                    $board,
                    trim((string)($in['app_version'] ?? $in['version'] ?? '')),
                    (int)($in['version_code'] ?? 0),
                    (int)($in['sub_version'] ?? 0)
                ),
            ], $reqId, $appId, $endpoint, 'login user#' . (int)$u['id']);
        }

        case 'auth/session': {
            $sess = app_api_require_session($appId, $in, $reqId, $endpoint);
            app_api_send(200, [
                'ok'           => true,
                'app_id'       => $appId,
                'user'         => ['id' => (int)$sess['user_id'], 'email' => (string)$sess['email'], 'display_name' => (string)($sess['display_name'] ?? '')],
                'sync_version' => (int)$sess['sync_version'],
                'last_sync'    => (int)($sess['last_sync'] ?? 0),
            ], $reqId, $appId, $endpoint, 'session valid user#' . (int)$sess['user_id']);
        }

        case 'auth/logout': {
            $sess = app_api_require_session($appId, $in, $reqId, $endpoint);
            upd('app_sessions', ['revoked' => 1], 'id=? AND app_id=?', [$sess['id'], $appId]);
            app_api_send(200, ['ok' => true, 'logged_out' => true], $reqId, $appId, $endpoint, 'logout user#' . (int)$sess['user_id']);
        }

        case 'sync': {
            $sess = app_api_require_session($appId, $in, $reqId, $endpoint);
            $uid = (int)$sess['user_id'];
            $devId = app_api_upsert_device($appId, $uid, $in);
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $action = strtolower(trim((string)($in['action'] ?? ($method === 'POST' && isset($in['sync_data']) ? 'push' : 'pull'))));

            if ($action === 'push') {
                $incoming = $in['sync_data'] ?? null;
                if (is_string($incoming)) {
                    $decoded = json_decode($incoming, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        app_api_err(422, 'invalid_sync_json', 'بيانات المزامنة المرسلة ليست JSON صالحاً.', $reqId, $appId, $endpoint);
                    }
                    $incoming = $decoded;
                }
                if (!is_array($incoming)) {
                    app_api_err(422, 'invalid_sync_payload', 'حقل sync_data مطلوب ككائن JSON للمزامنة بين الأجهزة.', $reqId, $appId, $endpoint);
                }
                $curVer = (int)$sess['sync_version'];
                if (isset($in['base_version']) && (int)$in['base_version'] > 0 && (int)$in['base_version'] < $curVer && empty($in['force'])) {
                    $serverData = json_decode((string)($sess['sync_data'] ?? '{}'), true);
                    app_api_send(409, [
                        'ok'             => false,
                        'error'          => ['code' => 'sync_conflict', 'message' => 'توجد نسخة أحدث على السحابة من جهاز آخر.'],
                        'server_version' => $curVer,
                        'server_data'    => is_array($serverData) && $serverData ? $serverData : new stdClass(),
                    ], $reqId, $appId, $endpoint, "sync conflict user#$uid");
                }
                $newVer = $curVer + 1;
                $now = time();
                upd('app_users', [
                    'sync_data'    => json_encode($incoming, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'sync_version' => $newVer,
                    'last_sync'    => $now,
                    'updated'      => $now,
                ], 'id=? AND app_id=?', [$uid, $appId]);
                if ($devId) upd('devices', ['last_sync' => $now], 'id=? AND app_id=?', [$devId, $appId]);

                app_api_send(200, [
                    'ok'           => true,
                    'app_id'       => $appId,
                    'sync_version' => $newVer,
                    'synced_at'    => $now,
                ], $reqId, $appId, $endpoint, "sync push v$newVer user#$uid");
            }

            $devices = rows(
                'SELECT device_uid, device_name, platform, os_version, app_version, last_sync, status FROM devices WHERE app_id=? AND user_id=? ORDER BY last_sync DESC',
                [$appId, $uid]
            );
            $curObj = json_decode((string)($sess['sync_data'] ?? '{}'), true);
            app_api_send(200, [
                'ok'           => true,
                'app_id'       => $appId,
                'sync_version' => (int)$sess['sync_version'],
                'last_sync'    => (int)($sess['last_sync'] ?? 0),
                'sync_data'    => is_array($curObj) && $curObj ? $curObj : new stdClass(),
                'devices'      => $devices,
            ], $reqId, $appId, $endpoint, "sync pull user#$uid");
        }

        case 'releases/check': {
            $upd = app_api_compute_updates(
                $board,
                trim((string)($in['version'] ?? $in['app_version'] ?? '')),
                (int)($in['version_code'] ?? 0),
                (int)($in['sub_version'] ?? 0)
            );
            $sincePackVer = (int)($in['pack_version'] ?? 0);
            $packs = rows(
                'SELECT pack_key, title, kind, version, payload_json, file_url, sha256, updated
                   FROM content_packs WHERE app_id=? AND is_active=1 AND version>? ORDER BY pack_key',
                [$appId, $sincePackVer]
            );
            foreach ($packs as &$pk) {
                $pk['payload'] = json_decode((string)($pk['payload_json'] ?? ''), true);
                unset($pk['payload_json']);
            }
            unset($pk);
            app_api_send(200, ['ok' => true, 'app_id' => $appId] + $upd + ['updated_packs' => $packs], $reqId, $appId, $endpoint, 'releases check');
        }

        case 'content': {
            $packKey = trim((string)($in['pack_key'] ?? ''));
            if ($packKey !== '') {
                $pk = row('SELECT pack_key, title, kind, version, payload_json, file_url, sha256, updated FROM content_packs WHERE app_id=? AND pack_key=? AND is_active=1', [$appId, $packKey]);
                if (!$pk) app_api_err(404, 'pack_not_found', 'حزمة المحتوى المطلوبة غير موجودة لهذا التطبيق.', $reqId, $appId, $endpoint);
                $pk['payload'] = json_decode((string)($pk['payload_json'] ?? ''), true);
                unset($pk['payload_json']);
                app_api_send(200, ['ok' => true, 'app_id' => $appId, 'pack' => $pk], $reqId, $appId, $endpoint, "content pack $packKey");
            }
            $packs = rows('SELECT pack_key, title, kind, version, payload_json, file_url, sha256, updated FROM content_packs WHERE app_id=? AND is_active=1 ORDER BY pack_key', [$appId]);
            foreach ($packs as &$p) {
                $p['payload'] = json_decode((string)($p['payload_json'] ?? ''), true);
                unset($p['payload_json']);
            }
            unset($p);
            app_api_send(200, [
                'ok'            => true,
                'app_id'        => $appId,
                'settings'      => app_api_settings_map($appId),
                'content_packs' => $packs,
            ], $reqId, $appId, $endpoint, 'content list');
        }

        case 'licenses/verify':
        case 'licenses/activate': {
            $code = trim((string)($in['code'] ?? $in['license_key'] ?? ''));
            if ($code === '') app_api_err(422, 'missing_license_code', 'رمز الترخيص مطلوب.', $reqId, $appId, $endpoint);
            $lic = row('SELECT * FROM board_licenses WHERE board_id=? AND code=?', [$appId, $code]);
            if (!$lic) app_api_err(404, 'license_not_found', 'الترخيص غير صالح لهذا التطبيق.', $reqId, $appId, $endpoint);
            if (($lic['status'] ?? '') === 'revoked') app_api_err(403, 'license_revoked', 'تم إبطال هذا الترخيص من اللوحة الأم.', $reqId, $appId, $endpoint);
            if (!empty($lic['expires']) && (int)$lic['expires'] < time()) {
                app_api_err(403, 'license_expired', 'انتهت صلاحية هذا الترخيص.', $reqId, $appId, $endpoint);
            }
            if ($clean === 'licenses/activate' && ($lic['status'] ?? '') === 'unused') {
                $holderEmail = mb_substr(trim((string)($in['holder'] ?? $in['email'] ?? $lic['email'] ?? '')), 0, 190);
                upd('board_licenses', ['status' => 'active', 'email' => $holderEmail], 'id=? AND board_id=?', [$lic['id'], $appId]);
                $lic['status'] = 'active';
                $lic['email'] = $holderEmail;
            }
            app_api_send(200, [
                'ok'      => true,
                'app_id'  => $appId,
                'license' => [
                    'code'    => (string)$lic['code'],
                    'plan'    => (string)($lic['plan'] ?? ''),
                    'status'  => (string)$lic['status'],
                    'holder'  => (string)($lic['email'] ?? ''),
                    'email'   => (string)($lic['email'] ?? ''),
                    'expires' => $lic['expires'] ? (int)$lic['expires'] : null,
                ],
            ], $reqId, $appId, $endpoint, 'license ' . $lic['status']);
        }

        case 'telemetry': {
            $event = trim((string)($in['event'] ?? 'ping'));
            if ($event === 'download') {
                upd('boards', ['downloads_count' => (int)$board['downloads_count'] + 1, 'updated' => time()], 'id=?', [$appId]);
            } elseif ($event === 'review') {
                $body = trim((string)($in['body'] ?? ''));
                if ($body !== '') {
                    ins('board_comments', [
                        'board_id' => $appId,
                        'author'   => mb_substr(trim((string)($in['author'] ?? 'مستخدم التطبيق')), 0, 150),
                        'email'    => mb_substr(trim((string)($in['email'] ?? '')), 0, 190),
                        'rating'   => max(1, min(5, (int)($in['rating'] ?? 5))),
                        'body'     => mb_substr($body, 0, 2000),
                        'approved' => 0,
                        'created'  => time(),
                    ]);
                }
            } elseif ($event === 'metric') {
                $metric = mb_substr(preg_replace('/[^a-z0-9_.-]/i', '_', (string)($in['metric'] ?? 'active_open')), 0, 30);
                $valNum = (int)($in['val'] ?? 1);
                ins('board_stats', ['board_id' => $appId, 'kind' => $metric, 'value' => $valNum, 'period_start' => time(), 'period_end' => null, 'created' => time()]);
            }
            app_api_send(200, ['ok' => true, 'app_id' => $appId, 'recorded' => $event], $reqId, $appId, $endpoint, "telemetry:$event");
        }

        case 'control': {
            // Real management API for the app's panel (scoped strictly to $appId)
            $op = strtolower(trim((string)($in['op'] ?? 'overview')));
            if ($op === 'overview') {
                app_api_send(200, [
                    'ok'     => true,
                    'app_id' => $appId,
                    'counts' => [
                        'users'            => (int)val('SELECT COUNT(*) FROM app_users WHERE app_id=?', [$appId]),
                        'active_sessions'  => (int)val('SELECT COUNT(*) FROM app_sessions WHERE app_id=? AND revoked=0 AND expires>?', [$appId, time()]),
                        'devices'          => (int)val('SELECT COUNT(*) FROM devices WHERE app_id=?', [$appId]),
                        'releases_full'    => (int)val("SELECT COUNT(*) FROM releases WHERE app_id=? AND release_type='full'", [$appId]),
                        'releases_sub'     => (int)val("SELECT COUNT(*) FROM releases WHERE app_id=? AND release_type='sub'", [$appId]),
                        'content_packs'    => (int)val('SELECT COUNT(*) FROM content_packs WHERE app_id=?', [$appId]),
                        'licenses'         => (int)val('SELECT COUNT(*) FROM board_licenses WHERE board_id=?', [$appId]),
                        'missing_reqs'     => (int)val("SELECT COUNT(*) FROM app_requirements WHERE app_id=? AND status='missing'", [$appId]),
                        'downloads'        => (int)$board['downloads_count'],
                    ],
                    'handoff_status' => (string)($board['handoff_status'] ?? 'pending'),
                ], $reqId, $appId, $endpoint, 'control:overview');
            }
            if ($op === 'users') {
                $users = rows('SELECT id, email, display_name, status, sync_version, last_sync, created FROM app_users WHERE app_id=? ORDER BY id DESC LIMIT 200', [$appId]);
                app_api_send(200, ['ok' => true, 'app_id' => $appId, 'users' => $users], $reqId, $appId, $endpoint, 'control:users');
            }
            if ($op === 'user_status') {
                $uid = (int)($in['user_id'] ?? 0);
                $st  = ($in['status'] ?? '') === 'suspended' ? 'suspended' : 'active';
                upd('app_users', ['status' => $st, 'updated' => time()], 'id=? AND app_id=?', [$uid, $appId]);
                if ($st !== 'active') upd('app_sessions', ['revoked' => 1], 'user_id=? AND app_id=?', [$uid, $appId]);
                app_api_send(200, ['ok' => true, 'app_id' => $appId, 'user_id' => $uid, 'status' => $st], $reqId, $appId, $endpoint, "control:user_status #$uid->$st");
            }
            if ($op === 'devices') {
                $devs = rows('SELECT id, user_id, device_uid, device_name, platform, os_version, app_version, last_sync, status, created FROM devices WHERE app_id=? ORDER BY last_sync DESC LIMIT 200', [$appId]);
                app_api_send(200, ['ok' => true, 'app_id' => $appId, 'devices' => $devs], $reqId, $appId, $endpoint, 'control:devices');
            }
            if ($op === 'releases') {
                $rels = rows('SELECT id, version, version_code, min_version, release_type, channel, download_url, sha256, size_bytes, permissions_changed, permissions_note, changelog, is_active, created FROM releases WHERE app_id=? ORDER BY version_code DESC, id DESC', [$appId]);
                app_api_send(200, ['ok' => true, 'app_id' => $appId, 'releases' => $rels], $reqId, $appId, $endpoint, 'control:releases');
            }
            if ($op === 'save_settings' && !empty($in['settings']) && is_array($in['settings'])) {
                $now = time();
                foreach ($in['settings'] as $sk => $sv) {
                    if (!is_string($sk) || trim($sk) === '') continue;
                    $valStr = is_scalar($sv) ? (string)$sv : json_encode($sv, JSON_UNESCAPED_UNICODE);
                    $ex = row('SELECT id FROM app_settings_kv WHERE app_id=? AND cfg_key=?', [$appId, $sk]);
                    if ($ex) upd('app_settings_kv', ['cfg_val' => $valStr, 'updated' => $now], 'id=? AND app_id=?', [$ex['id'], $appId]);
                    else ins('app_settings_kv', ['app_id' => $appId, 'cfg_key' => mb_substr($sk, 0, 100), 'cfg_val' => $valStr, 'updated' => $now]);
                }
                app_api_send(200, ['ok' => true, 'app_id' => $appId, 'settings' => app_api_settings_map($appId)], $reqId, $appId, $endpoint, 'control:save_settings');
            }
            if ($op === 'requirements') {
                $reqs = rows('SELECT id, category, title, source_name, source_url, priority, status, notes, updated FROM app_requirements WHERE app_id=? ORDER BY ' . req_order_sql(), [$appId]);
                app_api_send(200, ['ok' => true, 'app_id' => $appId, 'requirements' => $reqs], $reqId, $appId, $endpoint, 'control:requirements');
            }
            app_api_err(400, 'unknown_control_op', 'عملية التحكم غير معروفة: ' . $op, $reqId, $appId, $endpoint);
        }

        default:
            app_api_err(404, 'endpoint_not_found', 'نقطة الـ API غير موجودة في اللوحة الأم: ' . $clean, $reqId, $appId, $endpoint);
    }
}
