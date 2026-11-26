<?php
declare(strict_types=1);
/**
 * Unified Mother Panel Schema & Seeds:
 * - Single shared database across all developer apps (PHP on Hostinger — NOT WordPress).
 * - Existing shared tables are NEVER duplicated per app; every app-owned row is strictly scoped by app_id (= boards.id).
 * - Same email is permitted to register separate, independent accounts per app via UNIQUE(app_id, email).
 * - Online-first: login is mandatory before launch, with cross-device sync and full/sub OTA release management.
 */

const ASSET_SOURCE_CATS = [
    'audio'  => ['label' => 'الصوتيات',  'icon' => '🔊'],
    'images' => ['label' => 'الصور',     'icon' => '🖼'],
    'icons'  => ['label' => 'الأيقونات', 'icon' => '✨'],
    'fonts'  => ['label' => 'الخطوط',    'icon' => '🔤'],
    'video'  => ['label' => 'الفيديو',   'icon' => '🎬'],
    'data'   => ['label' => 'البيانات',  'icon' => '📊'],
];

const REQ_CATS = [
    'images' => 'صور وواجهات',
    'audio'  => 'صوتيات ومؤثرات',
    'icons'  => 'أيقونات وشعارات',
    'fonts'  => 'خطوط',
    'video'  => 'فيديو وعروض',
    'data'   => 'بيانات وملفات إعداد',
    'info'   => 'معلومات ونصوص',
    'api'    => 'مفاتيح وصلاحيات وربط',
    'other'  => 'أخرى',
];

const REQ_PRIORITIES = [
    'critical' => ['label' => 'حرجة',   'rank' => 1, 'cls' => 'bad'],
    'high'     => ['label' => 'عالية',  'rank' => 2, 'cls' => 'warn'],
    'medium'   => ['label' => 'متوسطة', 'rank' => 3, 'cls' => ''],
    'low'      => ['label' => 'منخفضة', 'rank' => 4, 'cls' => 'ok'],
];

const REQ_STATUSES = [
    'missing'  => 'ناقص',
    'provided' => 'تم توفيره',
];

/** Core unified tables shared by all managed apps (strictly scoped by app_id = boards.id). */
function mother_panel_schema(array $T): array {
    return [
        "CREATE TABLE IF NOT EXISTS sync_log (id {$T['id']}, ts INT, ok INT, summary TEXT)" . $T['tail'],

        // Unified app users: same email allowed across different apps via UNIQUE(app_id, email)
        "CREATE TABLE IF NOT EXISTS app_users (
            id {$T['id']},
            app_id INT NOT NULL,
            email VARCHAR(190) NOT NULL,
            pass_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(150) NULL,
            is_admin INT DEFAULT 0,
            status VARCHAR(20) DEFAULT 'active',
            sync_data {$T['big']} NULL,
            sync_version INT DEFAULT 0,
            last_sync INT NULL,
            created INT,
            updated INT,
            UNIQUE(app_id, email)
        )" . $T['tail'],
        "CREATE INDEX idx_au_app ON app_users (app_id, status)",

        // Unified sessions per app + user + device
        "CREATE TABLE IF NOT EXISTS app_sessions (
            id {$T['id']},
            app_id INT NOT NULL,
            user_id INT NOT NULL,
            device_id INT NULL,
            token_hash VARCHAR(64) UNIQUE,
            ip_hash VARCHAR(40) NULL,
            created INT,
            last_seen INT,
            expires INT,
            revoked INT DEFAULT 0
        )" . $T['tail'],
        "CREATE INDEX idx_as_app_user ON app_sessions (app_id, user_id, revoked)",

        // Registered user devices for cross-device sync
        "CREATE TABLE IF NOT EXISTS devices (
            id {$T['id']},
            app_id INT NOT NULL,
            user_id INT NULL,
            device_uid VARCHAR(120) NOT NULL,
            device_name VARCHAR(150) NULL,
            platform VARCHAR(40) NULL,
            os_version VARCHAR(40) NULL,
            app_version VARCHAR(40) NULL,
            last_sync INT NULL,
            status VARCHAR(20) DEFAULT 'active',
            created INT,
            UNIQUE(app_id, device_uid)
        )" . $T['tail'],
        "CREATE INDEX idx_dev_app_user ON devices (app_id, user_id)",

        // Full APK/installer releases + lightweight startup data-only sub-updates
        "CREATE TABLE IF NOT EXISTS releases (
            id {$T['id']},
            app_id INT NOT NULL,
            version VARCHAR(60) NOT NULL,
            version_code INT DEFAULT 1,
            min_version VARCHAR(60) NULL,
            release_type VARCHAR(20) DEFAULT 'full',
            channel VARCHAR(20) DEFAULT 'stable',
            download_url VARCHAR(500) NULL,
            sha256 VARCHAR(128) NULL,
            size_bytes BIGINT DEFAULT 0,
            permissions_changed INT DEFAULT 0,
            permissions_note VARCHAR(500) NULL,
            changelog {$T['big']} NULL,
            payload_json {$T['big']} NULL,
            is_active INT DEFAULT 1,
            created INT
        )" . $T['tail'],
        "CREATE INDEX idx_rel_app ON releases (app_id, release_type, is_active)",

        // Remote content & configuration packs pulled on startup
        "CREATE TABLE IF NOT EXISTS content_packs (
            id {$T['id']},
            app_id INT NOT NULL,
            pack_key VARCHAR(100) NOT NULL,
            title VARCHAR(190),
            kind VARCHAR(40) DEFAULT 'data',
            version INT DEFAULT 1,
            payload_json {$T['big']} NULL,
            file_url VARCHAR(500) NULL,
            sha256 VARCHAR(128) NULL,
            is_active INT DEFAULT 1,
            created INT,
            updated INT,
            UNIQUE(app_id, pack_key)
        )" . $T['tail'],
        "CREATE INDEX idx_cp_app ON content_packs (app_id, is_active)",

        // Per-app remote settings controlled live from Mother Panel
        "CREATE TABLE IF NOT EXISTS app_settings_kv (
            id {$T['id']},
            app_id INT NOT NULL,
            cfg_key VARCHAR(100) NOT NULL,
            cfg_val {$T['big']} NULL,
            updated INT,
            UNIQUE(app_id, cfg_key)
        )" . $T['tail'],

        // Section 1 of App Requirements: categorized asset & data sources
        "CREATE TABLE IF NOT EXISTS asset_sources (
            id {$T['id']},
            category VARCHAR(30) NOT NULL,
            name VARCHAR(190) NOT NULL,
            url VARCHAR(500) NULL,
            license_note VARCHAR(190) NULL,
            notes TEXT NULL,
            sort_order INT DEFAULT 0,
            created INT
        )" . $T['tail'],
        "CREATE INDEX idx_src_cat ON asset_sources (category, sort_order)",

        // Section 2 of App Requirements: per-app requirements & missing items tracker
        "CREATE TABLE IF NOT EXISTS app_requirements (
            id {$T['id']},
            app_id INT NOT NULL,
            category VARCHAR(30) DEFAULT 'other',
            title VARCHAR(255) NOT NULL,
            details TEXT NULL,
            source_id INT NULL,
            source_name VARCHAR(255) NULL,
            source_url VARCHAR(500) NULL,
            priority VARCHAR(20) DEFAULT 'high',
            status VARCHAR(20) DEFAULT 'missing',
            notes TEXT NULL,
            from_handoff INT DEFAULT 0,
            provided_at INT NULL,
            created INT,
            updated INT
        )" . $T['tail'],
        "CREATE INDEX idx_req_app_status ON app_requirements (app_id, status, priority)",
    ];
}

/** Compare two semantic-like version strings safely (strips leading 'v'). */
function app_ver_cmp(string $a, string $b): int {
    $ca = ltrim(trim($a), 'vV');
    $cb = ltrim(trim($b), 'vV');
    if ($ca === '' && $cb === '') return 0;
    if ($ca === '') return -1;
    if ($cb === '') return 1;
    return version_compare($ca, $cb);
}

/** Priority rank SQL expression so 'missing' comes first, then critical → high → medium → low. */
function req_order_sql(string $alias = ''): string {
    $p = $alias !== '' ? $alias . '.' : '';
    return "CASE WHEN {$p}status='missing' THEN 0 ELSE 1 END ASC, "
         . "CASE {$p}priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END ASC, "
         . "{$p}id DESC";
}

/** Ensure Mother Panel columns exist on the existing boards table (SQLite + MySQL safe, idempotent). */
function mother_ensure_boards_columns(): void {
    if (!table_exists('boards')) return;
    $cols = [
        'min_app_version'    => 'VARCHAR(60) NULL',
        'online_required'    => 'INT DEFAULT 1',
        'sync_enabled'       => 'INT DEFAULT 1',
        'uses_github'        => 'INT DEFAULT 0',
        'handoff_status'     => "VARCHAR(20) DEFAULT 'pending'",
        'handoff_md'         => 'TEXT NULL',
        'handoff_report'     => 'TEXT NULL',
        'handoff_verified_at'=> 'INT NULL',
        'panel_tabs_json'    => 'TEXT NULL',
        'api_token_hash'     => 'VARCHAR(64) NULL',
        'api_token_hint'     => 'VARCHAR(40) NULL',
    ];
    foreach ($cols as $col => $def) {
        try {
            // Probe column existence via a zero-row select (works on both drivers)
            q("SELECT $col FROM boards LIMIT 0");
        } catch (Throwable $e) {
            try { db()->exec("ALTER TABLE boards ADD COLUMN $col $def"); } catch (Throwable $e2) {}
        }
    }
    try { db()->exec('CREATE INDEX IF NOT EXISTS idx_boards_token ON boards (api_token_hash)'); } catch (Throwable $e) {}
}

/** Generate a fresh API token for a board; stores ONLY its SHA-256 hash + masked hint in Mother Panel DB. */
function board_issue_api_token(int $boardId): string {
    $plain = 'mp_app_' . $boardId . '_' . bin2hex(random_bytes(20));
    $hash = hash('sha256', $plain);
    $hint = substr($plain, 0, 11) . '••••' . substr($plain, -4);
    upd('boards', ['api_token_hash' => $hash, 'api_token_hint' => $hint, 'updated' => time()], 'id=?', [$boardId]);
    return $plain;
}

/** Log every Mother Panel API / sync event with a unique req_id into BOTH audit_log and sync_log. */
function mother_log_call(string $reqId, int $appId, string $endpoint, bool $ok, string $detail = ''): void {
    $ts = time();
    $summary = "[$reqId] app#$appId $endpoint" . ($detail !== '' ? " — $detail" : '');
    try {
        ins('audit_log', [
            'ts' => $ts,
            'admin' => 'api:app#' . $appId,
            'action' => mb_substr($endpoint, 0, 100),
            'detail' => mb_substr($reqId . ($detail !== '' ? ' · ' . $detail : ''), 0, 255),
        ]);
    } catch (Throwable $e) {}
    try {
        if (table_exists('sync_log')) {
            ins('sync_log', ['ts' => $ts, 'ok' => $ok ? 1 : 0, 'summary' => mb_substr($summary, 0, 1000)]);
        }
    } catch (Throwable $e) {}
}

/** Render the universal smart floating return button (returns to Admin ONLY when locked/private, else to Main Site). */
function render_floating_return_btn(bool $lockedOrAdminOnly, ?string $customHref = null, ?string $customLabel = null): string {
    if ($customHref !== null && $customLabel !== null) {
        $href = $customHref;
        $label = $customLabel;
    } elseif ($lockedOrAdminOnly) {
        $href = url('admin/');
        $label = '🔒 العودة للوحة التحكم الأساسية';
    } else {
        $href = function_exists('lurl') ? lurl('') : url('');
        $label = '⌂ الموقع الأساسي';
    }
    return '<a class="mp-fab-return" target="_top" href="' . e($href) . '" style="position:fixed;bottom:20px;inset-inline-end:20px;z-index:99999;'
         . 'display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:999px;'
         . 'background:linear-gradient(120deg,#0d9488,#0284c7);color:#ffffff;font:700 13.5px/1.4 Cairo,system-ui,Tahoma,sans-serif;'
         . 'text-decoration:none;box-shadow:0 10px 28px rgba(2,132,199,.38);border:1px solid rgba(255,255,255,.22)">'
         . e($label) . '</a>';
}

/** Ensure app-scoped user admin flag exists on databases created before the team upgrade. */
function mother_ensure_app_users_columns(): void {
    if (!table_exists('app_users')) return;
    try { db()->exec('ALTER TABLE app_users ADD COLUMN is_admin INT DEFAULT 0'); } catch (Throwable $e) {}
}

/**
 * GitHub token of the Mother Panel (single source of truth for every app that talks to GitHub).
 * Resolution order: settings table → config.php fallback. Never ship this value inside an app build;
 * apps request it server-side through /api/v1/control?op=github_token for an app-admin session.
 */
function mother_github_token(): string {
    $t = trim((string)setting('github_token'));
    if ($t !== '') return $t;
    return trim((string)cfg('github_token', ''));
}

/** Masked, human-readable state of the GitHub token (safe to render in the panel). */
function mother_github_token_info(): array {
    $t = mother_github_token();
    $fromSettings = trim((string)setting('github_token')) !== '';
    return [
        'set'    => $t !== '',
        'masked' => $t === '' ? '' : (substr($t, 0, 4) . '••••••••••••' . substr($t, -4)),
        'hint'   => $t === '' ? '' : (substr($t, 0, 4) . '…' . substr($t, -4)),
        'source' => $t === '' ? 'none' : ($fromSettings ? 'settings' : 'config'),
        'length' => strlen($t),
    ];
}

/** Who may copy/reveal the raw GitHub token: the owner «امبراطور السحر يوسف» and managers only. */
function mother_github_token_can_view(): bool {
    if (!function_exists('team_is_owner')) return true;                 // CLI / installer context
    if (!function_exists('admin_current') || admin_current() === null) return true;
    return team_is_owner() || in_array(team_role(), team_managers(), true);
}

/** Copy-ready button markup for the GitHub token (owner/manager see copy+reveal; others get a masked hint). */
function mother_github_token_box(string $extraNote = ''): string {
    $info = mother_github_token_info();
    $token = mother_github_token();
    $html = '<div class="panel"><h2>🔑 توكن GitHub (اللوحة الأم)</h2>';
    if (!$info['set']) {
        $html .= '<p class="muted">لا يوجد توكن GitHub محفوظ بعد. أضفه من <a href="' . e(adm_url('settings')) . '">الإعدادات ← GitHub</a> ليستخدمه كل تطبيق يتعامل مع المستودعات.</p>';
        return $html . '</div>';
    }
    $html .= '<p class="muted">توكن واحد مركزي لكل التطبيقات التي تتعامل مع GitHub والمستودعات. يُرسل للتطبيق من الخادم فقط (<code dir="ltr">control?op=github_token</code> لحساب أدمن) ولا يُحفظ داخل الـ APK ولا يُعرض للزوار.</p>';
    $html .= '<div class="row" style="gap:8px;align-items:center;flex-wrap:wrap">'
        . '<code dir="ltr" data-gh-mask style="padding:8px 12px;border-radius:10px;background:#0b1622;border:1px solid #1e3346">' . e($info['hint']) . '</code>';
    if (mother_github_token_can_view()) {
        $html .= '<input type="hidden" data-gh-full value="' . e($token) . '">'
              . '<button type="button" class="btn sm ghost" data-gh-reveal>👁 إظهار</button>'
              . '<button type="button" class="btn sm" data-gh-copy>⧉ نسخ التوكن</button>';
    } else {
        $html .= '<span class="chip">🔒 النسخ والكشف لامبراطور السحر يوسف فقط</span>';
    }
    $html .= '<span class="muted" dir="ltr">' . e($info['source']) . ' · ' . (int)$info['length'] . ' chars</span>'
        . '</div>';
    if ($extraNote !== '') $html .= '<p class="muted">' . e($extraNote) . '</p>';
    return $html . '</div>';
}
