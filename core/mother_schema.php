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

/** Seed default categorized asset sources + Y- Dimensions (mobile) board and explicit missing requirements. */
function mother_panel_seed(): void {
    $now = time();

    // 1. Seed categorized asset sources (audio, images, icons, fonts, video, data) if empty
    if (table_exists('asset_sources') && (int)val('SELECT COUNT(*) FROM asset_sources') === 0) {
        $sources = [
            // Audio
            ['audio',  'Pixabay Sound Effects', 'https://pixabay.com/sound-effects/', 'مجاني تجاري (بدون نسبة)', 'مؤثرات تنبيهات وانتقالات واجهة Y- Dimensions وتبديل المزاج', 1],
            ['audio',  'Mixkit Free Sound Effects', 'https://mixkit.co/free-sound-effects/', 'Mixkit Free License', 'نغمات خفيفة لتنبيهات المزامنة واكتمال عمليات Git', 2],
            ['audio',  'Freesound', 'https://freesound.org/', 'CC0 / CC-BY', 'مكتبة مؤثرات صوتية مفتوحة للواجهات والمشغلات', 3],
            // Images
            ['images', 'Unsplash', 'https://unsplash.com/', 'Unsplash License', 'خلفيات عالية الدقة لبطاقات الأمزجة والغلاف التعريفي', 1],
            ['images', 'Pexels', 'https://www.pexels.com/', 'Pexels Free', 'صور توضيحية لشاشات الترحيب ومعاينة المشغل المدمج', 2],
            // Icons
            ['icons',  'Lucide Icons', 'https://lucide.dev/icons/', 'ISC Open Source', 'أيقونات SVG موحّدة لمدير الملفات، Git، القبو، والمكتبة', 1],
            ['icons',  'Google Material Symbols', 'https://fonts.google.com/icons', 'Apache 2.0', 'أيقونات أندرويد القياسية للصلاحيات ومشغل الفيديو/الصور', 2],
            ['icons',  'Tabler Icons', 'https://tabler.io/icons', 'MIT', 'أيقونات تقنية لفروع Git والدمج والإصدارات', 3],
            // Fonts
            ['fonts',  'Google Fonts — Cairo & Readex Pro', 'https://fonts.google.com/?subset=arabic', 'OFL', 'خطوط عربية واضحة للواجهة وقارئ ملفات Markdown وPDF', 1],
            ['fonts',  'JetBrains Mono', 'https://www.jetbrains.com/lp/mono/', 'OFL', 'خط أحادي المسافة لعرض فروقات Git (Diff) وأوامر الطرفية', 2],
            // Video
            ['video',  'Coverr Free Stock Video', 'https://coverr.co/', 'Coverr Free', 'مقاطع فيديو قصيرة لاختبار المشغل المدمج والعروض التعريفية', 1],
            ['video',  'Pexels Videos', 'https://www.pexels.com/videos/', 'Pexels Free', 'مقاطع معاينة بدقات متعددة لاختبار قبو الوسائط', 2],
            // Data
            ['data',   'GitHub REST API v2022-11-28 Docs', 'https://docs.github.com/en/rest', 'Official Docs', 'مرجع نقاط الفروع، الالتزامات، المقارنة (Compare)، والدمج وإصدارات GitHub', 1],
            ['data',   'Android App Permissions & Package Visibility', 'https://developer.android.com/training/package-visibility', 'Android Docs', 'مرجع صلاحيات إخفاء/إدارة التطبيقات والوسائط وتحديثات APK الداخلية', 2],
        ];
        foreach ($sources as $s) {
            ins('asset_sources', [
                'category'     => $s[0],
                'name'         => $s[1],
                'url'          => $s[2],
                'license_note' => $s[3],
                'notes'        => $s[4],
                'sort_order'   => $s[5],
                'created'      => $now,
            ]);
        }
    }

    // 2. Ensure Y- Dimensions (mobile) exists with untouched name, mobile type, and developer Yusef Alhmzy
    if (!table_exists('boards')) return;
    mother_ensure_boards_columns();
    $yd = row("SELECT * FROM boards WHERE slug='y-dimensions' OR LOWER(name)='y- dimensions'");
    if (!$yd) {
        $devName = (string)cfg('name_ar', 'يوسف الحمزي');
        $ydId = ins('boards', [
            'name'            => 'Y- Dimensions',
            'slug'            => 'y-dimensions',
            'app_type'        => 'mobile',
            'description'     => 'تطبيق أندرويد متكامل لإدارة وإخفاء التطبيقات والكتب والأدوات حسب المزاج، مع مشغل وقبو فيديو/صور مدمج، ومدير مشاريع Git للمطور من الجوال، وماسح شامل لملفات PDF وMarkdown ومدير ملفات متكامل وتحديث ذكي.',
            'app_version'     => '1.0.0',
            'min_app_version' => '1.0.0',
            'online_required' => 1,
            'sync_enabled'    => 1,
            'handoff_status'  => 'pending',
            'developer_name'  => $devName !== '' ? $devName : 'يوسف الحمزي',
            'status'          => 'draft',
            'created'         => $now,
            'updated'         => $now,
        ]);
        board_issue_api_token($ydId);
    } else {
        $ydId = (int)$yd['id'];
        if (empty($yd['api_token_hash'])) board_issue_api_token($ydId);
    }

    // 3. Seed explicit missing requirements for Y- Dimensions (only if none registered yet for this app)
    if (table_exists('app_requirements') && (int)val('SELECT COUNT(*) FROM app_requirements WHERE app_id=?', [$ydId]) === 0) {
        $reqs = [
            [
                'category'    => 'api',
                'priority'    => 'critical',
                'title'       => 'تزويد التطبيق برابط اللوحة الأم النهائي ومفتاح ربط التطبيق (App API Token)',
                'source_name' => 'لوحة التحكم الأم ← لوحات الإدارة ← Y- Dimensions',
                'source_url'  => '',
                'notes'       => 'لا تُخترع التوكنات أو بيانات الاستضافة داخل الكود؛ يُولَّد التوكن من تبويب مفاتيح API في لوحة Y- Dimensions ويُضبط في إعدادات البناء الآمنة.',
            ],
            [
                'category'    => 'api',
                'priority'    => 'critical',
                'title'       => 'إعداد مصادقة GitHub (OAuth App Client ID أو آلية إدخال PAT مشفّر محلياً للمستخدم)',
                'source_name' => 'GitHub Developer Settings',
                'source_url'  => 'https://github.com/settings/developers',
                'notes'       => 'مطلوب لميزة إدارة مشاريع المطور من الجوال: مقارنة النسخة المحلية مع GitHub، git pull/push، عرض الفروع، الدمج، والإصدارات دون تخزين أي توكن خام في المستودع.',
            ],
            [
                'category'    => 'data',
                'priority'    => 'critical',
                'title'       => 'ملف الإصدار الكامل الأول (APK موقّع) وبصمة SHA-256 وقائمة الصلاحيات المطلوبة',
                'source_name' => 'بناء أندرويد الموقّع من المطوّر (Keystore خاص بالمطوّر)',
                'source_url'  => '',
                'notes'       => 'مطلوب لتفعيل التحديث الذكي داخل التطبيق (REQUEST_INSTALL_PACKAGES + MANAGE_EXTERNAL_STORAGE + QUERY_ALL_PACKAGES) وإعادة طلب الصلاحيات عند تغيّرها في التحديثات.',
            ],
            [
                'category'    => 'icons',
                'priority'    => 'high',
                'title'       => 'أيقونة Y- Dimensions التكيفية (Adaptive Icon 1024×1024 + Foreground/Background SVG)',
                'source_name' => 'تصميم المطوّر / Lucide Icons',
                'source_url'  => 'https://lucide.dev/icons/',
                'notes'       => 'تُستخدم لأيقونة المثبّت، شاشة تسجيل الدخول الإلزامية قبل الفتح، وبطاقة التطبيق في الموقع الرئيسي.',
            ],
            [
                'category'    => 'images',
                'priority'    => 'high',
                'title'       => 'لقطات شاشة رسمية وصورة غلاف لـ Y- Dimensions (وضع المزاج، قبو الفيديو/الصور، مدير Git، مكتبة PDF/MD)',
                'source_name' => 'التقاط مباشر من جهاز المطوّر أو Unsplash للخلفيات',
                'source_url'  => 'https://unsplash.com/',
                'notes'       => 'تُعرض في صفحة التطبيق وفي تبويب نظرة عامة داخل اللوحة الأم.',
            ],
            [
                'category'    => 'data',
                'priority'    => 'high',
                'title'       => 'حزمة البيانات الفرعية الافتراضية للأمزجة (Mood Presets JSON) وقواعد التصنيف المبدئية',
                'source_name' => 'حزم المحتوى (Content Packs) في اللوحة الأم',
                'source_url'  => '',
                'notes'       => 'تُسحب كتحديث فرعي خفيف عند تشغيل التطبيق لتحديد قوائم الأمزجة الافتراضية وترتيب إخفاء/إظهار التطبيقات والكتب والأدوات.',
            ],
            [
                'category'    => 'fonts',
                'priority'    => 'medium',
                'title'       => 'حزمة خطوط القراءة والكود (Cairo للنصوص العربية + JetBrains Mono لفروقات Git وMarkdown)',
                'source_name' => 'Google Fonts & JetBrains Mono',
                'source_url'  => 'https://fonts.google.com/?subset=arabic',
                'notes'       => 'لضمان وضوح عرض ملفات PDF/Markdown وفروقات الأكواد (Diff) على مختلف أجهزة أندرويد.',
            ],
            [
                'category'    => 'audio',
                'priority'    => 'low',
                'title'       => 'مؤثرات صوتية خفيفة لتبديل المزاج وقفل قبو الوسائط واكتمال المزامنة',
                'source_name' => 'Pixabay Sound Effects',
                'source_url'  => 'https://pixabay.com/sound-effects/',
                'notes'       => 'ملفات OGG/MP3 قصيرة (< 50KB) قابلة للتفعيل أو الكتم من إعدادات المستخدم المتزامنة.',
            ],
            [
                'category'    => 'info',
                'priority'    => 'medium',
                'title'       => 'نص سياسة الخصوصية وتوضيح الصلاحيات الحساسة (إخفاء الصور/التطبيقات والوصول للملفات)',
                'source_name' => 'إعدادات التطبيق السحابية في اللوحة الأم',
                'source_url'  => '',
                'notes'       => 'يُعرض للمستخدم قبل تسجيل الدخول وعند طلب صلاحيات الوصول الكامل للملفات والوسائط.',
            ],
        ];
        foreach ($reqs as $rq) {
            ins('app_requirements', [
                'app_id'       => $ydId,
                'category'     => $rq['category'],
                'title'        => $rq['title'],
                'details'      => '',
                'source_id'    => null,
                'source_name'  => $rq['source_name'],
                'source_url'   => $rq['source_url'],
                'priority'     => $rq['priority'],
                'status'       => 'missing',
                'notes'        => $rq['notes'],
                'from_handoff' => 0,
                'provided_at'  => null,
                'created'      => $now,
                'updated'      => $now,
            ]);
        }
    }

    // 4. Seed default cloud settings for Y- Dimensions if empty
    if (table_exists('app_settings_kv') && (int)val('SELECT COUNT(*) FROM app_settings_kv WHERE app_id=?', [$ydId]) === 0) {
        $defaults = [
            'auth_mandatory_before_open' => 'true',
            'cross_device_sync'          => 'true',
            'allow_same_email_per_app'   => 'true',
            'in_app_apk_update'          => 'true',
            'startup_sub_updates'        => 'true',
            'reprompt_permissions_on_upd'=> 'true',
            'mood_presets'               => json_encode(['تركيز وعمل', 'تطوير ومشاريع Git', 'قراءة وكتب PDF/MD', 'وسائط خاصة', 'وضع عام'], JSON_UNESCAPED_UNICODE),
        ];
        foreach ($defaults as $k => $v) {
            ins('app_settings_kv', ['app_id' => $ydId, 'cfg_key' => $k, 'cfg_val' => $v, 'updated' => $now]);
        }
    }
}

