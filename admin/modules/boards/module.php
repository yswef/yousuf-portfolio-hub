<?php
declare(strict_types=1);
/**
 * Boards (لوحات الإدارة) — mother-panel registry for every app's admin panel.
 *
 * Flow: "لوحة جديدة" → popup (name + type) → prompt is generated & copied →
 * an empty card appears with a zip dropzone → drop the panel zip → "إضافة"
 * opens the full data page (GitHub sync, icon, docs site, tags, stats,
 * licenses, unified comments, developer data, install map).
 * Each card has a ⋮ menu: export panel (zip) / update panel / delete forever.
 * Top bar: [لوحة جديدة] [الترتيب] [استخراج تقرير] (popup: apps + period).
 */

require_once __DIR__ . '/lib.php';

const BOARDS_TYPES = [
    'mobile' => 'موبايل', 'desktop' => 'سطح مكتب', 'both' => 'موبايل وسطح مكتب',
    'whatsapp_bot' => 'بوت واتساب', 'browser_extension' => 'إضافة متصفح', 'other' => 'أخرى',
];
const BOARDS_SORTS = [
    'newest' => 'الأحدث إضافة', 'oldest' => 'الأقدم إضافة', 'users' => 'الأكثر مستخدمين',
    'downloads' => 'الأكثر تنزيلاً', 'licenses' => 'الأكثر تراخيص', 'name' => 'الاسم أ-ي', 'updated' => 'آخر تحديث',
];
const BOARDS_STATUS = ['draft' => 'مسودة', 'ready' => 'جاهزة', 'active' => 'نشطة'];

function boards_dir(): string { return ROOT . '/data/boards'; }
function boards_board_dir(string $slug): string { return boards_dir() . '/' . $slug; }
function boards_type_label(array $b): string {
    $t = BOARDS_TYPES[$b['app_type'] ?? 'other'] ?? 'أخرى';
    if (($b['app_type'] ?? '') === 'other' && trim((string)($b['app_type_custom'] ?? '')) !== '') $t = (string)$b['app_type_custom'];
    return $t;
}
function boards_all_tables(): array {
    try {
        if (db_driver() === 'mysql') return array_map(fn($r) => (string)array_values($r)[0], rows('SHOW TABLES'));
        return array_map(fn($r) => (string)$r['name'], rows("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"));
    } catch (Throwable $e) { return []; }
}
/** The strict prompt handed to the AI/model that builds (or rebuilds) one app's admin panel. */
function boards_prompt(array $b): string {
    $name = (string)($b['name'] ?? 'التطبيق');
    $type = boards_type_label($b);
    $tables = boards_all_tables();
    $tablesTxt = $tables ? implode(', ', $tables) : '(جداول قاعدة البيانات الموحّدة — راجع ملف الخريطة المرفق)';
    $dev = trim((string)($b['developer_name'] ?? '')) !== '' ? (string)$b['developer_name'] : 'يوسف الحمزي';
    $devMail = trim((string)($b['developer_email'] ?? '')) !== '' ? (string)$b['developer_email'] : '(بريد المطوّر)';
    $appId = (int)($b['id'] ?? 0);
    $lines = [
        'أنت موديل يعمل على مشروع/تطبيق واحد محدد. مهمتك: بناء لوحة تحكم (Admin Panel) لهذا التطبيق — أو إعادة بنائها بالكامل — بما يتوافق مع «لوحة الأم» (Mother Panel) الخاصة بالمطوّر، وباتباع التعليمات الصارمة التالية دون أي اجتهاد خارجها.',
        '',
        '■ بيانات التطبيق:',
        '• اسم التطبيق: ' . $name,
        '• نوع التطبيق: ' . $type . ' (موبايل / سطح مكتب / الاثنين / بوت واتساب / إضافة متصفح / أخرى — التزم بهذا النوع فقط)',
        '• المطوّر: ' . $dev . ' — ' . $devMail,
        '• معرّف التطبيق في اللوحة الأم (app_id = boards.id): ' . ($appId > 0 ? (string)$appId : '(يُحدَّد عند الإنشاء)'),
        '',
        '■ البيئة التقنية (إلزامي):',
        '• اللوحة الأم PHP خالص على Hostinger — ليست WordPress. لا تستخدم أي إضافات/قوالب ووردبريس، ولا كوداً يتطلب ووردبريس.',
        '• اللوحة الأم وحدها تملك الكود/الرمز والتوكنات (بصمات SHA-256 فقط في قاعدة البيانات). لوحتك الفرعية بلا كود PHP مستقل ولا تملك توكنات خام — كل التحكم يتم عبر بوابة الـ API في اللوحة الأم.',
        '',
        '■ القواعد الصارمة:',
        '1) عدد الملفات والمجلدات في اللوحة لا يهمّ إطلاقاً — اختر البنية الأنسب، المهم الالتزام ببقية القواعد.',
        '2) أرفق «خريطة تثبيت» (ملف MAP / JSON) يساعد لوحة الأم على التثبيت وحفظ مسارات الملفات بالترتيب الصحيح، مع خطوة تحقّق (Verify) تؤكد نجاح التثبيت وترتيب الملفات قبل الإعلان عن الاكتمال. لا يحصل أي خطأ في ترتيب الملفات.',
        '3) المزامنة مع لوحة الأم (إلزامية — أونلاين دائماً):',
        '   • أُلغي مبدأ العمل دون اتصال: تسجيل الدخول إلزامي قبل فتح التطبيق، والمزامنة بين الأجهزة إلزامية (نفس الحساب يفتح على أي جهاز ويُزامَن).',
        '   • الإحصائيات: اعرض عدد المستخدمين وكل إحصائيات التطبيق (تنزيلات، تراخيص، تقييمات…) في الرئيسية، ووفّر واجهة صغيرة في قسم «لوحات الإدارة» لكل لوحة بطاقة تعرض إحصائياتها منفصلة — كل تطبيق منفصل عن الآخر.',
        '   • قاعدة البيانات واحدة وموحّدة بلا تعارضات ولا تضاربات. الجداول المشتركة الموجودة لا تُكرَّر أبداً لكل تطبيق (ممنوع إنشاء users أو أي جدول مكرّر لكل تطبيق). الجداول المشتركة المسموح بها فقط — كلها بنطاق app_id صارم: app_users (UNIQUE(app_id,email))، app_sessions، devices (UNIQUE(app_id,device_uid))، releases (full/sub)، content_packs، app_settings_kv، asset_sources، app_requirements، sync_log. أي جدول جديد يجب أن يكون غير مكرّر ومشتركاً وبنطاق app_id. الجداول الحالية: ' . $tablesTxt . '.',
        '   • الحسابات منفصلة لكل تطبيق: من يسجّل في تطبيق ليس له حساب في التطبيقات الأخرى تلقائياً؛ ينشئ حساباً جديداً في كل تطبيق، لكن بنفس البريد الإلكتروني المسموح به.',
        '   • وحّد مدونات التطبيقات مع مدونة التعليقات في اللوحة: مدونة واحدة موحّدة هي آراء وتقييمات العملاء عن التطبيقات.',
        '4) استخدم بوابة الـ API الحقيقية في اللوحة الأم (JSON فقط — لا HTML أبداً):',
        '   • الأساس: /api/v1/ مع ترويسة X-App-Token (أو Authorization: Bearer) من اللوحة الأم، ونطاق app_id صارم في كل استعلام.',
        '   • النقاط المطلوبة: status (حالة التطبيق + الإعدادات + التحديثات + التطبيقات الشقيقة) · auth/register · auth/login · auth/session · auth/logout · sync (دفع/سحب مع إصدار المزامنة وكشف التعارض) · releases/check (تحديث APK كامل + تحديثات فرعية عند التشغيل + min_version) · content (حزم المحتوى) · licenses/verify · licenses/activate · telemetry (تنزيل/تقييم/مقاييس) · control (نظرة عامة/مستخدمون/أجهزة/إصدارات/إعدادات/متطلبات).',
        '   • كل استدعاء API يُسجَّل في audit_log و sync_log مع req_id فريد — لا تستدعِ قاعدة البيانات مباشرة من التطبيق.',
        '   • إدارة الإصدارات: انشر APK كاملاً (full) يُنزَّل داخل التطبيق مع دعم permissions_changed وإعادة طلب الصلاحيات، وحدّد min_version للتحديث الإجباري، وانشر تحديثات فرعية خفيفة (sub) بيانات فقط تُسحب عند تشغيل التطبيق.',
        '5) أضف في التطبيق قسماً يعرض «التطبيقات الأخرى» الموجودة عند المطوّر (روابط/بطاقات للتطبيقات الشقيقة).',
        '6) هذا البرومت مولّد حسب اسم التطبيق ونوعه (' . $name . ' — ' . $type . ')؛ لا تعمّم على تطبيقات أخرى.',
        '7) ضع أيقونة/صورة التطبيق لتظهر في بطاقة عرض اللوحة في لوحة الأم.',
        '8) زامن لوحة إنشاء التراخيص للتطبيقات المدفوعة مع لوحة الأم (إنشاء/إلغاء/تفعيل ترخيص من مكان واحد).',
        '9) سجّل بيانات المطوّر (' . $dev . ') في التطبيق، وفي الكود: في بداية كل ملف ونهايته (تعليق توقيع المطوّر).',
        '10) اطلب صراحةً كل ما ينقصك: في ملف التسليم اذكر كل متطلب ناقص (صور، صوتيات، أيقونات، خطوط، فيديو، بيانات، مفاتيح/صلاحيات) مع المصدر والأولوية (critical/high/medium/low) والملاحظة — ستُسجَّل تلقائياً في تبويب «متطلبات التطبيقات» مرتبةً (الناقص أولاً ثم الأولوية) مع عدّاد نواقص لكل تطبيق.',
        '',
        '■ ملف التسليم الإلزامي (بوابة الاكتمال):',
        '• يجب أن تُنتج ملفاً باسم MOTHER_PANEL_HANDOFF.md موجّهاً إلى موديل اللوحة الأم، داخل الـ zip، يتضمن:',
        '   - التعديلات التي أجريتها (ملفاً بملف).',
        '   - نقاط الـ API المستخدمة (المسارات والحقول).',
        '   - الجداول المستخدمة ونطاق app_id (أي جدول جديد يجب أن يكون مشتركاً غير مكرّر).',
        '   - الصلاحيات المطلوبة (permissions) وأي تغيير فيها.',
        '   - المتطلبات الناقصة صراحةً (بكتلة JSON واحدة على الأقل: {"requirements":[{"category":"audio|images|icons|fonts|video|data|info|api|other","title":"...","source":"...","source_url":"...","priority":"critical|high|medium|low","status":"missing|provided","notes":"..."}]}) مع إعدادات افتراضية ({"settings":{...}}) و min_version إن وُجد.',
        '   - خطوات التحقق (Verify) التي نفّذتها بنفسك.',
        '• لا يُعلَن اكتمال تثبيت التطبيق أبداً قبل أن تقرأ اللوحة الأم هذا الملف وتنفّذ بنوده وتسجّل النتيجة. أي توكن/كلمة مرور/بيانات حساسة لا تُضمَّن نصاً — تُطلَب من المطوّر.',
        '',
        '■ المخرجات المطلوبة:',
        '• ملفات اللوحة كاملة + ملف الخريطة (MAP) + ملف بيانات التطبيق (اسم، وصف، تاجات، علامات بحث، إصدار، أيقونة، توثيق) يعبّئه الموديل، ويراجعه المطوّر فقط + ملف MOTHER_PANEL_HANDOFF.md.',
        '• تأكيد كتابي باكتمال التثبيت بعد التحقق من الخريطة وقراءة ملف التسليم وتنفيذ بنوده.',
        '• لا تغيّر أي ملف خارج نطاق هذه اللوحة، ولا تنشئ جداول مكرّرة، ولا تخترع توكنات أو بيانات استضافة.',
    ];
    return implode("\n", $lines);
}
/** Validates + stores an uploaded panel zip under data/boards/<slug>/panel.zip. Returns [files_count, size]. */
function boards_store_panel_zip(array $f, string $slug): array {
    if (!isset($f['error']) || (int)$f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('لم يُرفع أي ملف (تحقق من حدّ الرفع: ' . ini_get('upload_max_filesize') . ').');
    if (strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION)) !== 'zip') throw new RuntimeException('الملف يجب أن يكون zip.');
    if (!class_exists('ZipArchive')) throw new RuntimeException('امتداد zip غير مفعّل في PHP.');
    $z = new ZipArchive();
    if ($z->open((string)$f['tmp_name']) !== true) throw new RuntimeException('الملف ليس zip صالحاً.');
    $n = $z->numFiles; $z->close();
    if ($n === 0) throw new RuntimeException('الملف المضغوط فارغ.');
    $dir = boards_board_dir($slug);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $dest = $dir . '/panel.zip';
    if (!move_uploaded_file((string)$f['tmp_name'], $dest)) throw new RuntimeException('تعذّر حفظ الملف.');
    return [$n, (int)filesize($dest)];
}
function boards_panel_path(string $slug): string { return boards_board_dir($slug) . '/panel.zip'; }
function boards_sorted(array $boards, string $sort): array {
    $key = ['newest' => ['created', -1], 'oldest' => ['created', 1], 'users' => ['users_count', -1], 'downloads' => ['downloads_count', -1], 'licenses' => ['licenses_count', -1], 'name' => ['name', 1], 'updated' => ['updated', -1]][$sort] ?? ['created', -1];
    usort($boards, function ($a, $b) use ($key) {
        [$k, $dir] = $key;
        $cmp = is_string($a[$k] ?? '') ? strnatcasecmp((string)($a[$k] ?? ''), (string)($b[$k] ?? '')) : ((int)($a[$k] ?? 0) <=> (int)($b[$k] ?? 0));
        return $cmp * $dir;
    });
    return $boards;
}
function boards_copy_btn(string $text, string $label): void {
    echo '<button type="button" class="btn ghost sm" data-copy="' . e($text) . '">' . e($label) . '</button>';
}

return [
    'title' => ['ar' => 'لوحات الإدارة', 'en' => 'Admin boards'], 'icon' => '▦', 'group' => 'app', 'order' => 15, 'version' => 2,
    'schema' => fn(array $T) => [
        "CREATE TABLE IF NOT EXISTS boards (id {$T['id']}, slug VARCHAR(190) UNIQUE, name VARCHAR(190), app_type VARCHAR(30) DEFAULT 'other', app_type_custom VARCHAR(100) NULL,
          description TEXT NULL, tags VARCHAR(500) NULL, search_tags VARCHAR(500) NULL, icon VARCHAR(255) NULL,
          github_url VARCHAR(255) NULL, github_synced INT DEFAULT 0, app_version VARCHAR(60) NULL, version_uploaded INT DEFAULT 0,
          docs_has INT DEFAULT 0, docs_site_slug VARCHAR(80) NULL, docs_site_url VARCHAR(255) NULL,
          status VARCHAR(20) DEFAULT 'draft', users_count INT DEFAULT 0, downloads_count INT DEFAULT 0, licenses_count INT DEFAULT 0,
          revenue_num INT DEFAULT 0, rating VARCHAR(10) NULL, panel_zip_name VARCHAR(255) NULL, panel_zip_size BIGINT DEFAULT 0,
          panel_files_count INT DEFAULT 0, panel_map {$T['big']} NULL, model_data {$T['big']} NULL,
          developer_name VARCHAR(190) NULL, developer_email VARCHAR(190) NULL, developer_notes TEXT NULL, created INT, updated INT)" . $T['tail'],
        "CREATE TABLE IF NOT EXISTS board_files (id {$T['id']}, board_id INT, kind VARCHAR(20) DEFAULT 'attachment', name VARCHAR(255), size BIGINT DEFAULT 0, path VARCHAR(255) NULL, created INT)" . $T['tail'],
        "CREATE TABLE IF NOT EXISTS board_comments (id {$T['id']}, board_id INT NULL, author VARCHAR(150), email VARCHAR(190) NULL, rating INT DEFAULT 0, body TEXT NULL, approved INT DEFAULT 0, created INT)" . $T['tail'],
        "CREATE TABLE IF NOT EXISTS board_licenses (id {$T['id']}, board_id INT, code VARCHAR(100) UNIQUE, email VARCHAR(190) NULL, plan VARCHAR(100) NULL, status VARCHAR(20) DEFAULT 'active', created INT, expires INT NULL)" . $T['tail'],
        "CREATE TABLE IF NOT EXISTS board_stats (id {$T['id']}, board_id INT, kind VARCHAR(30), value INT DEFAULT 0, period_start INT NULL, period_end INT NULL, created INT)" . $T['tail'],
        "CREATE INDEX idx_bf_board ON board_files (board_id)", "CREATE INDEX idx_bc_board ON board_comments (board_id)",
        "CREATE INDEX idx_bl_board ON board_licenses (board_id)", "CREATE INDEX idx_bs_board ON board_stats (board_id)",
    ],
    'widgets' => fn() => [
        ['label' => 'لوحات الإدارة', 'value' => (int)val('SELECT COUNT(*) FROM boards'), 'link' => 'boards'],
        ['label' => 'لوحات مسودة', 'value' => (int)val("SELECT COUNT(*) FROM boards WHERE status='draft'"), 'link' => 'boards'],
        ['label' => 'مجموع المستخدمين', 'value' => (int)val('SELECT COALESCE(SUM(users_count),0) FROM boards'), 'link' => 'boards'],
        ['label' => 'التراخيص', 'value' => (int)val('SELECT COUNT(*) FROM board_licenses'), 'link' => 'boards'],
    ],
    'badge' => fn() => (int)val("SELECT COUNT(*) FROM boards WHERE status='draft'"),

    'actions' => [
        'create_board' => function (): void {
            $name = mb_substr(P('name'), 0, 190);
            if ($name === '') { flash('اسم المشروع مطلوب.', false); adm_back('boards'); }
            $type = array_key_exists(P('app_type'), BOARDS_TYPES) ? P('app_type') : 'other';
            $id = ins('boards', ['slug' => unique_slug('boards', slugify($name)), 'name' => $name, 'app_type' => $type,
                'app_type_custom' => mb_substr(P('app_type_custom'), 0, 100), 'status' => 'draft', 'created' => time(), 'updated' => time()]);
            audit('board_create', (string)$id);
            flash('أُنشئت بطاقة اللوحة. انسخ البرومت وأرسله للموديل، ثم أسقط ملف اللوحة المضغوط.');
            adm_back('boards', ['prompt' => $id]);
        },
        'save_board' => function (): void {
            $id = PN('id'); $b = row('SELECT * FROM boards WHERE id=?', [$id]);
            if (!$b) { flash('اللوحة غير موجودة.', false); adm_back('boards'); }
            $type = array_key_exists(P('app_type'), BOARDS_TYPES) ? P('app_type') : 'other';
            $status = array_key_exists(P('status'), BOARDS_STATUS) ? P('status') : 'draft';
            $d = ['name' => mb_substr(P('name') ?: $b['name'], 0, 190), 'app_type' => $type, 'app_type_custom' => mb_substr(P('app_type_custom'), 0, 100),
                'description' => P('description'), 'tags' => mb_substr(P('tags'), 0, 500), 'search_tags' => mb_substr(P('search_tags'), 0, 500),
                'app_version' => mb_substr(P('app_version'), 0, 60), 'github_url' => mb_substr(P('github_url'), 0, 255),
                'docs_has' => PB('docs_has'), 'docs_site_slug' => mb_substr(P('docs_site_slug'), 0, 80), 'docs_site_url' => mb_substr(P('docs_site_url'), 0, 255),
                'status' => $status, 'users_count' => PN('users_count'), 'downloads_count' => PN('downloads_count'),
                'licenses_count' => PN('licenses_count'), 'revenue_num' => PN('revenue_num'), 'rating' => mb_substr(P('rating'), 0, 10),
                'panel_map' => P('panel_map'), 'model_data' => P('model_data'),
                'developer_name' => mb_substr(P('developer_name'), 0, 190), 'developer_email' => mb_substr(P('developer_email'), 0, 190),
                'developer_notes' => P('developer_notes'), 'updated' => time()];
            if (!empty($_FILES['icon']['name'])) {
                $n = handle_image_upload($_FILES['icon'], 512);
                if ($n) { delete_upload($b['icon'] ?? null); $d['icon'] = $n; } else flash('تعذّر رفع الأيقونة (JPG/PNG/WebP حتى 5MB).', false);
            } elseif (PB('icon_remove')) { delete_upload($b['icon'] ?? null); $d['icon'] = null; }
            upd('boards', $d, 'id=?', [$id]);
            audit('board_save', (string)$id); flash('تم حفظ بيانات اللوحة.');
            adm_back('boards', ['edit' => $id]);
        },
        'apply_model_data' => function (): void {
            $id = PN('id'); $b = row('SELECT * FROM boards WHERE id=?', [$id]);
            if (!$b) adm_back('boards');
            $raw = P('model_data');
            $j = json_decode($raw, true);
            if (!is_array($j)) { flash('بيانات الموديل ليست JSON صالحاً — الصق JSON فقط.', false); adm_back('boards', ['edit' => $id]); }
            $map = ['name' => 'name', 'description' => 'description', 'tags' => 'tags', 'search_tags' => 'search_tags', 'app_version' => 'app_version',
                'github_url' => 'github_url', 'users_count' => 'users_count', 'downloads_count' => 'downloads_count', 'licenses_count' => 'licenses_count',
                'revenue_num' => 'revenue_num', 'rating' => 'rating', 'developer_name' => 'developer_name', 'developer_email' => 'developer_email'];
            $d = ['model_data' => $raw, 'updated' => time()];
            foreach ($map as $jk => $col) if (isset($j[$jk]) && is_scalar($j[$jk])) $d[$col] = in_array($col, ['users_count', 'downloads_count', 'licenses_count', 'revenue_num'], true) ? (int)$j[$jk] : mb_substr((string)$j[$jk], 0, 500);
            upd('boards', $d, 'id=?', [$id]);
            audit('board_model_apply', (string)$id); flash('طُبّقت بيانات الموديل على الحقول — راجعها ثم احفظ.');
            adm_back('boards', ['edit' => $id]);
        },
        'upload_panel' => function (): void {
            $id = PN('id'); $b = row('SELECT * FROM boards WHERE id=?', [$id]);
            if (!$b) { flash('اللوحة غير موجودة.', false); adm_back('boards'); }
            try {
                [$n, $size] = boards_store_panel_zip($_FILES['panel_file'] ?? [], (string)$b['slug']);
                upd('boards', ['panel_zip_name' => mb_substr((string)($_FILES['panel_file']['name'] ?? 'panel.zip'), 0, 255), 'panel_zip_size' => $size, 'panel_files_count' => $n, 'updated' => time()], 'id=?', [$id]);
                ins('board_files', ['board_id' => $id, 'kind' => 'panel_zip', 'name' => mb_substr((string)($_FILES['panel_file']['name'] ?? 'panel.zip'), 0, 255), 'size' => $size, 'path' => 'panel.zip', 'created' => time()]);
                audit('board_panel_upload', (string)$id);
                flash('رُفعت اللوحة (' . $n . ' ملفاً، ' . fmt_size($size) . '). أكمل بيانات اللوحة الآن.');
            } catch (Throwable $e) { flash('فشل رفع اللوحة: ' . $e->getMessage(), false); }
            adm_back('boards', ['edit' => $id]);
        },
        'verify_install' => function (): void {
            $id = PN('id'); $b = row('SELECT * FROM boards WHERE id=?', [$id]);
            if (!$b) adm_back('boards');
            $p = boards_panel_path((string)$b['slug']);
            $ok = is_file($p);
            $mapOk = trim((string)($b['panel_map'] ?? '')) === '' ? null : (json_decode((string)$b['panel_map'], true) !== null);
            $msg = 'التحقق: ملف اللوحة ' . ($ok ? 'موجود (' . (int)($b['panel_files_count'] ?? 0) . ' ملفاً)' : 'غير مرفوع') . ' · الخريطة ' . ($mapOk === null ? 'غير مضافة' : ($mapOk ? 'JSON صالح' : 'JSON غير صالح'));
            audit('board_verify', $msg); flash($msg, $ok && $mapOk !== false);
            adm_back('boards', ['edit' => $id]);
        },
        'import_from_apps' => function (): void {
            if (!table_exists('apps')) { flash('وحدة التطبيقات غير مفعّلة.', false); adm_back('boards'); }
            $added = 0; $now = time();
            foreach (rows('SELECT a.*, p.html_url, p.topics FROM apps a LEFT JOIN projects p ON p.id=a.project_id WHERE a.ignored=0') as $a) {
                if (row('SELECT id FROM boards WHERE LOWER(name)=LOWER(?)', [$a['name']])) continue;
                $plat = strtolower((string)$a['platform']);
                $type = str_contains($plat, 'android') || str_contains($plat, 'ios')
                    ? (str_contains($plat, 'windows') ? 'both' : 'mobile')
                    : (str_contains($plat, 'extension') ? 'browser_extension' : (str_contains($plat, 'bot') ? 'whatsapp_bot' : (str_contains($plat, 'windows') || str_contains($plat, 'desktop') ? 'desktop' : 'other')));
                $dlTotal = (int)val('SELECT COALESCE(SUM(gh_count),0) FROM app_files WHERE app_id=?', [$a['id']]) + (table_exists('downloads') ? (int)val('SELECT COUNT(*) FROM downloads WHERE app_id=?', [$a['id']]) : 0);
                ins('boards', [
                    'slug' => unique_slug('boards', slugify($a['name'])), 'name' => $a['name'], 'app_type' => $type,
                    'description' => (string)$a['description'], 'tags' => (string)($a['topics'] ?? ''),
                    'github_url' => (string)($a['html_url'] ?? $a['release_url'] ?? ''), 'github_synced' => !empty($a['html_url']) ? 1 : 0,
                    'app_version' => (string)$a['version'], 'version_uploaded' => $a['version'] !== '' && $a['version'] !== 'latest' ? 1 : 0,
                    'status' => 'active', 'downloads_count' => $dlTotal, 'created' => $now, 'updated' => $now,
                ]);
                $added++;
            }
            audit('boards_import_apps', (string)$added);
            flash($added > 0 ? "تم استيراد $added تطبيق إلى لوحات الإدارة." : 'جميع التطبيقات الحالية موجودة بالفعل كلوحات.');
            adm_back('boards');
        },
        'check_github' => function (): void {
            $id = PN('id'); $b = row('SELECT * FROM boards WHERE id=?', [$id]);
            if (!$b) adm_back('boards');
            $url = trim((string)($b['github_url'] ?? ''));
            if (!preg_match('#github\.com/([^/\s]+)/([^/\s]+)#i', $url, $m)) { flash('أدخل رابط مستودع GitHub صحيحاً أولاً واحفظه.', false); adm_back('boards', ['edit' => $id]); }
            $owner = $m[1]; $repoName = (string)preg_replace('/\.git$/i', '', $m[2]);
            if (!function_exists('gh_get')) require_once modules_dir() . '/projects/github.php';
            try {
                [$code, $body] = gh_get('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repoName));
                if ($code !== 200) { upd('boards', ['github_synced' => 2, 'updated' => time()], 'id=?', [$id]); flash('المستودع غير موجود أو غير قابل للوصول (HTTP ' . $code . ').', false); adm_back('boards', ['edit' => $id]); }
                $repo = json_decode($body, true) ?: [];
                [$rc, $rbody] = gh_get('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repoName) . '/releases?per_page=10');
                $rels = $rc === 200 ? (json_decode($rbody, true) ?: []) : [];
                $rel = function_exists('pick_best_release') ? pick_best_release($rels) : ($rels[0] ?? null);
                $ver = (string)($rel['tag_name'] ?? '');
                $uploaded = !empty($rel['assets']) ? 1 : ($ver !== '' ? 1 : 0);
                if ($ver === '') {
                    [$tc, $tbody] = gh_get('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repoName) . '/tags?per_page=3');
                    $tags = $tc === 200 ? (json_decode($tbody, true) ?: []) : [];
                    if (!empty($tags[0]['name'])) $ver = (string)$tags[0]['name'];
                }
                $ghDl = 0;
                foreach ($rels as $rx) foreach ((array)($rx['assets'] ?? []) as $ax) $ghDl += (int)($ax['download_count'] ?? 0);
                $d = ['github_synced' => 1, 'version_uploaded' => $uploaded, 'downloads_count' => max((int)$b['downloads_count'], $ghDl), 'updated' => time()];
                if ($ver !== '') $d['app_version'] = $ver;
                upd('boards', $d, 'id=?', [$id]);
                audit('board_github_check', (string)$id);
                flash('المستودع مزامن ✓ · آخر دفع: ' . fmt_date($repo['pushed_at'] ?? '') . ($ver !== '' ? ' · الإصدار: ' . $ver : ''));
            } catch (Throwable $e) { flash('فشل فحص GitHub: ' . $e->getMessage(), false); }
            adm_back('boards', ['edit' => $id]);
        },
        'upload_docs' => function (): void {
            $id = PN('id'); $b = row('SELECT * FROM boards WHERE id=?', [$id]);
            if (!$b) adm_back('boards');
            $f = $_FILES['docs_file'] ?? null;
            if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { flash('لم يُرفع أي ملف توثيق.', false); adm_back('boards', ['edit' => $id]); }
            if (!function_exists('sites_store_upload')) require_once modules_dir() . '/sites/module.php';
            $slug = unique_slug('sites', preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$b['slug'])) ?: 'docs');
            try {
                [$kind, $n, $skip] = sites_store_upload($f, $slug);
                $sid = ins('sites', ['slug' => $slug, 'title' => 'توثيق ' . $b['name'], 'kind' => $kind, 'description' => 'موقع توثيق تطبيق ' . $b['name'], 'is_public' => 0, 'sort' => 0, 'created' => time()]);
                site_sync_pages($sid, $slug);
                upd('boards', ['docs_has' => 1, 'docs_site_slug' => $slug, 'docs_site_url' => site_origin() . '/s/' . $slug . '/', 'updated' => time()], 'id=?', [$id]);
                audit('board_docs_upload', $slug);
                flash('رُفع موقع التوثيق (' . $n . ' ملفاً) ورُبط باللوحة: /s/' . $slug . '/');
            } catch (Throwable $e) { flash('فشل رفع التوثيق: ' . $e->getMessage(), false); }
            adm_back('boards', ['edit' => $id]);
        },
        'upload_attachment' => function (): void {
            $id = PN('id'); $b = row('SELECT * FROM boards WHERE id=?', [$id]);
            if (!$b) adm_back('boards');
            $f = $_FILES['attach_file'] ?? null;
            if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { flash('لم يُرفع أي ملف.', false); adm_back('boards', ['edit' => $id]); }
            $dir = boards_board_dir((string)$b['slug']) . '/files';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$f['name']);
            if (move_uploaded_file((string)$f['tmp_name'], $dir . '/' . $safe)) {
                ins('board_files', ['board_id' => $id, 'kind' => 'attachment', 'name' => mb_substr($safe, 0, 255), 'size' => (int)$f['size'], 'path' => 'files/' . $safe, 'created' => time()]);
                flash('رُفع الملف المرفق.');
            } else flash('تعذّر حفظ الملف.', false);
            adm_back('boards', ['edit' => $id]);
        },
        'license_add' => function (): void {
            $id = PN('board_id');
            $code = mb_substr(P('code') ?: ('LIC-' . strtoupper(bin2hex(random_bytes(4)))), 0, 100);
            if (row('SELECT id FROM board_licenses WHERE code=?', [$code])) { flash('هذا الكود مستخدم من قبل.', false); adm_back('boards', ['edit' => $id]); }
            $exp = P('expires'); $expTs = preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) ? strtotime($exp . ' UTC') : null;
            ins('board_licenses', ['board_id' => $id, 'code' => $code, 'email' => mb_substr(P('email'), 0, 190), 'plan' => mb_substr(P('plan'), 0, 100), 'status' => 'active', 'created' => time(), 'expires' => $expTs]);
            if ($id) upd('boards', ['licenses_count' => (int)val('SELECT COUNT(*) FROM board_licenses WHERE board_id=?', [$id]), 'updated' => time()], 'id=?', [$id]);
            audit('license_add', $code); flash('أُنشئ الترخيص: ' . $code);
            adm_back('boards', ['edit' => $id]);
        },
        'license_delete' => function (): void {
            $l = row('SELECT * FROM board_licenses WHERE id=?', [PN('id')]);
            if ($l) { q('DELETE FROM board_licenses WHERE id=?', [$l['id']]); upd('boards', ['licenses_count' => (int)val('SELECT COUNT(*) FROM board_licenses WHERE board_id=?', [$l['board_id']]), 'updated' => time()], 'id=?', [$l['board_id']]); audit('license_delete', (string)$l['id']); }
            flash('حُذف الترخيص.'); adm_back('boards', ['edit' => PN('board_id')]);
        },
        'comment_add' => function (): void {
            $id = PN('board_id') ?: null;
            ins('board_comments', ['board_id' => $id, 'author' => mb_substr(P('author'), 0, 150), 'email' => mb_substr(P('email'), 0, 190), 'rating' => max(0, min(5, PN('rating'))), 'body' => P('body'), 'approved' => PB('approved'), 'created' => time()]);
            audit('comment_add', (string)$id); flash('أُضيف التعليق.');
            adm_back('boards', ['edit' => $id ?: 0]);
        },
        'comment_approve' => function (): void {
            $c = row('SELECT * FROM board_comments WHERE id=?', [PN('id')]);
            if ($c) { upd('board_comments', ['approved' => $c['approved'] ? 0 : 1], 'id=?', [$c['id']]); }
            flash('تم تحديث حالة التعليق.'); adm_back('boards', ['edit' => PN('board_id')]);
        },
        'comment_delete' => function (): void {
            q('DELETE FROM board_comments WHERE id=?', [PN('id')]);
            audit('comment_delete', (string)PN('id')); flash('حُذف التعليق.');
            adm_back('boards', ['edit' => PN('board_id')]);
        },
        'issue_api_token' => function (): void {
            $id = PN('board_id');
            $b = row('SELECT * FROM boards WHERE id=?', [$id]);
            if (!$b) adm_back('boards');
            $plain = board_issue_api_token($id);
            $_SESSION['new_api_token'] = $plain;
            audit('board_api_token', (string)$id);
            flash('وُلّد توكن API جديد — انسخه الآن (لن يظهر مرة أخرى).');
            adm_back('boards', ['panel' => $id, 'tab' => 'settings']);
        },
        'save_board_extra' => function (): void {
            $id = PN('board_id');
            $b = row('SELECT * FROM boards WHERE id=?', [$id]);
            if (!$b) adm_back('boards');
            upd('boards', [
                'min_app_version' => mb_substr(P('min_app_version'), 0, 60),
                'online_required' => PB('online_required'),
                'sync_enabled'    => PB('sync_enabled'),
                'updated'         => time(),
            ], 'id=?', [$id]);
            audit('board_extra_save', (string)$id); flash('تم حفظ سياسات التطبيق.');
            adm_back('boards', ['panel' => $id, 'tab' => 'settings']);
        },
        'save_handoff' => function (): void {
            $id = PN('board_id');
            $b = row('SELECT * FROM boards WHERE id=?', [$id]);
            if (!$b) adm_back('boards');
            upd('boards', ['handoff_md' => P('handoff_md'), 'handoff_status' => 'pending', 'updated' => time()], 'id=?', [$id]);
            audit('board_handoff_save', (string)$id); flash('حُفظ ملف التسليم — اضغط «قراءة وتنفيذ والتحقق الآن».');
            adm_back('boards', ['panel' => $id, 'tab' => 'handoff']);
        },
        'verify_handoff' => function (): void {
            $id = PN('board_id');
            try {
                $r = handoff_execute_and_verify($id);
                flash($r['ok'] ? '✓ تمت قراءة وتنفيذ MOTHER_PANEL_HANDOFF.md وتسجيل النتيجة.' : '✗ فشل التحقق — راجع تقرير ملف التسليم.', $r['ok']);
            } catch (Throwable $e) { flash('خطأ في تنفيذ ملف التسليم: ' . $e->getMessage(), false); }
            adm_back('boards', ['panel' => $id, 'tab' => 'handoff']);
        },
        'save_release' => function (): void {
            $id = PN('board_id');
            $b = row('SELECT * FROM boards WHERE id=?', [$id]);
            if (!$b) adm_back('boards');
            $ver = mb_substr(P('version'), 0, 60);
            if ($ver === '') { flash('رقم الإصدار مطلوب.', false); adm_back('boards', ['panel' => $id, 'tab' => 'releases']); }
            $type = P('release_type') === 'sub' ? 'sub' : 'full';
            $payload = trim(P('payload_json'));
            if ($payload !== '' && json_decode($payload, true) === null && $payload !== 'null') { flash('حمولة JSON غير صالحة.', false); adm_back('boards', ['panel' => $id, 'tab' => 'releases']); }
            ins('releases', [
                'app_id' => $id, 'version' => $ver, 'version_code' => max(1, PN('version_code')),
                'min_version' => mb_substr(P('min_version'), 0, 60) ?: null,
                'release_type' => $type, 'channel' => P('channel') === 'beta' ? 'beta' : 'stable',
                'download_url' => mb_substr(P('download_url'), 0, 500) ?: null,
                'sha256' => mb_substr(P('sha256'), 0, 128) ?: null,
                'size_bytes' => max(0, PN('size_bytes')),
                'permissions_changed' => PB('permissions_changed'),
                'permissions_note' => mb_substr(P('permissions_note'), 0, 500) ?: null,
                'changelog' => P('changelog'), 'payload_json' => $payload !== '' ? $payload : null,
                'is_active' => PB('is_active'), 'created' => time(),
            ]);
            audit('release_save', "$id:$ver:$type"); flash('نُشر الإصدار ' . $ver . ' (' . $type . ').');
            adm_back('boards', ['panel' => $id, 'tab' => 'releases']);
        },
        'toggle_release' => function (): void {
            $r = row('SELECT * FROM releases WHERE id=?', [PN('id')]);
            if ($r) upd('releases', ['is_active' => (int)$r['is_active'] ? 0 : 1], 'id=? AND app_id=?', [$r['id'], PN('board_id')]);
            flash('تم تحديث حالة الإصدار.'); adm_back('boards', ['panel' => PN('board_id'), 'tab' => 'releases']);
        },
        'delete_release' => function (): void {
            q('DELETE FROM releases WHERE id=? AND app_id=?', [PN('id'), PN('board_id')]);
            audit('release_delete', (string)PN('id')); flash('حُذف الإصدار.'); adm_back('boards', ['panel' => PN('board_id'), 'tab' => 'releases']);
        },
        'save_pack' => function (): void {
            $id = PN('board_id');
            $key = mb_substr(P('pack_key'), 0, 100);
            if ($key === '') { flash('مفتاح الحزمة مطلوب.', false); adm_back('boards', ['panel' => $id, 'tab' => 'content']); }
            $payload = trim(P('payload_json'));
            if ($payload !== '' && json_decode($payload, true) === null && $payload !== 'null') { flash('حمولة JSON غير صالحة.', false); adm_back('boards', ['panel' => $id, 'tab' => 'content']); }
            $ex = row('SELECT id FROM content_packs WHERE app_id=? AND pack_key=?', [$id, $key]);
            $d = ['title' => mb_substr(P('title'), 0, 190), 'kind' => mb_substr(P('kind'), 0, 40), 'version' => max(1, PN('version')),
                'payload_json' => $payload !== '' ? $payload : null, 'file_url' => mb_substr(P('file_url'), 0, 500) ?: null,
                'is_active' => PB('is_active'), 'updated' => time()];
            if ($ex) upd('content_packs', $d, 'id=? AND app_id=?', [$ex['id'], $id]);
            else ins('content_packs', $d + ['app_id' => $id, 'pack_key' => $key, 'created' => time()]);
            audit('pack_save', "$id:$key"); flash('حُفظت حزمة المحتوى.');
            adm_back('boards', ['panel' => $id, 'tab' => 'content']);
        },
        'delete_pack' => function (): void {
            q('DELETE FROM content_packs WHERE id=? AND app_id=?', [PN('id'), PN('board_id')]);
            flash('حُذفت الحزمة.'); adm_back('boards', ['panel' => PN('board_id'), 'tab' => 'content']);
        },
        'save_setting' => function (): void {
            $id = PN('board_id');
            $key = mb_substr(P('cfg_key'), 0, 100);
            if ($key === '') { flash('مفتاح الإعداد مطلوب.', false); adm_back('boards', ['panel' => $id, 'tab' => 'content']); }
            $ex = row('SELECT id FROM app_settings_kv WHERE app_id=? AND cfg_key=?', [$id, $key]);
            if ($ex) upd('app_settings_kv', ['cfg_val' => P('cfg_val'), 'updated' => time()], 'id=? AND app_id=?', [$ex['id'], $id]);
            else ins('app_settings_kv', ['app_id' => $id, 'cfg_key' => $key, 'cfg_val' => P('cfg_val'), 'updated' => time()]);
            flash('حُفظ الإعداد السحابي.'); adm_back('boards', ['panel' => $id, 'tab' => 'content']);
        },
        'delete_setting' => function (): void {
            q('DELETE FROM app_settings_kv WHERE id=? AND app_id=?', [PN('id'), PN('board_id')]);
            flash('حُذف الإعداد.'); adm_back('boards', ['panel' => PN('board_id'), 'tab' => 'content']);
        },
        'toggle_user_status' => function (): void {
            $u = row('SELECT * FROM app_users WHERE id=? AND app_id=?', [PN('id'), PN('board_id')]);
            if ($u) {
                $st = ($u['status'] ?? 'active') === 'active' ? 'suspended' : 'active';
                upd('app_users', ['status' => $st, 'updated' => time()], 'id=? AND app_id=?', [$u['id'], PN('board_id')]);
                if ($st !== 'active') upd('app_sessions', ['revoked' => 1], 'user_id=? AND app_id=?', [$u['id'], PN('board_id')]);
            }
            flash('تم تحديث حالة المستخدم.'); adm_back('boards', ['panel' => PN('board_id'), 'tab' => 'users']);
        },
        'revoke_session' => function (): void {
            upd('app_sessions', ['revoked' => 1], 'id=? AND app_id=?', [PN('id'), PN('board_id')]);
            flash('أُلغيت الجلسة.'); adm_back('boards', ['panel' => PN('board_id'), 'tab' => 'users']);
        },
        'toggle_device_status' => function (): void {
            $d = row('SELECT * FROM devices WHERE id=? AND app_id=?', [PN('id'), PN('board_id')]);
            if ($d) upd('devices', ['status' => ($d['status'] ?? 'active') === 'active' ? 'blocked' : 'active'], 'id=? AND app_id=?', [$d['id'], PN('board_id')]);
            flash('تم تحديث حالة الجهاز.'); adm_back('boards', ['panel' => PN('board_id'), 'tab' => 'devices']);
        },
        'toggle_requirement' => function (): void {
            $r = row('SELECT * FROM app_requirements WHERE id=? AND app_id=?', [PN('id'), PN('board_id')]);
            if ($r) {
                $st = ($r['status'] ?? 'missing') === 'missing' ? 'provided' : 'missing';
                upd('app_requirements', ['status' => $st, 'provided_at' => $st === 'provided' ? time() : null, 'updated' => time()], 'id=? AND app_id=?', [$r['id'], PN('board_id')]);
            }
            flash('تم تحديث حالة المتطلب.'); adm_back('boards', ['panel' => PN('board_id'), 'tab' => 'requirements']);
        },
        'delete_board' => function (): void {
            $b = row('SELECT * FROM boards WHERE id=?', [PN('id')]);
            if (!$b) adm_back('boards');
            if (!empty($b['icon'])) delete_upload((string)$b['icon']);
            rm_tree(boards_board_dir((string)$b['slug']));
            q('DELETE FROM board_files WHERE board_id=?', [$b['id']]); q('DELETE FROM board_comments WHERE board_id=?', [$b['id']]);
            q('DELETE FROM board_licenses WHERE board_id=?', [$b['id']]); q('DELETE FROM board_stats WHERE board_id=?', [$b['id']]);
            if (table_exists('app_users')) q('DELETE FROM app_users WHERE app_id=?', [$b['id']]);
            if (table_exists('app_sessions')) q('DELETE FROM app_sessions WHERE app_id=?', [$b['id']]);
            if (table_exists('devices')) q('DELETE FROM devices WHERE app_id=?', [$b['id']]);
            if (table_exists('releases')) q('DELETE FROM releases WHERE app_id=?', [$b['id']]);
            if (table_exists('content_packs')) q('DELETE FROM content_packs WHERE app_id=?', [$b['id']]);
            if (table_exists('app_settings_kv')) q('DELETE FROM app_settings_kv WHERE app_id=?', [$b['id']]);
            if (table_exists('app_requirements')) q('DELETE FROM app_requirements WHERE app_id=?', [$b['id']]);
            q('DELETE FROM boards WHERE id=?', [$b['id']]);
            audit('board_delete', (string)$b['slug']); flash('حُذفت اللوحة نهائياً مع كل بياناتها وملفاتها.');
            adm_back('boards');
        },
    ],

    'download' => function (string $what): void {
        if ($what === 'panel') {
            $b = row('SELECT * FROM boards WHERE id=?', [(int)($_GET['id'] ?? 0)]);
            if (!$b) { http_response_code(404); echo 'Not found'; return; }
            if (!class_exists('ZipArchive')) { http_response_code(500); echo 'zip extension missing'; return; }
            $dir = boards_board_dir((string)$b['slug']); if (!is_dir($dir)) mkdir($dir, 0755, true);
            $tmp = $dir . '/export-' . bin2hex(random_bytes(4)) . '.zip';
            $z = new ZipArchive(); $z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $src = boards_panel_path((string)$b['slug']);
            if (is_file($src)) {
                $zs = new ZipArchive();
                if ($zs->open($src) === true) { for ($i = 0; $i < $zs->numFiles; $i++) { $st = $zs->statIndex($i); $z->addFromString((string)$st['name'], (string)$zs->getFromIndex($i)); } $zs->close(); }
            }
            $z->addFromString('board.json', json_encode($b, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $z->addFromString('map.json', (string)($b['panel_map'] ?? '{}'));
            $z->addFromString('prompt.txt', boards_prompt($b));
            $csv = "kind,value\nusers," . (int)$b['users_count'] . "\ndownloads," . (int)$b['downloads_count'] . "\nlicenses," . (int)$b['licenses_count'] . "\nrevenue," . (int)$b['revenue_num'] . "\nrating," . (string)$b['rating'] . "\n";
            $z->addFromString('stats.csv', $csv);
            $z->addFromString('README.txt', "لوحة إدارة تطبيق: " . $b['name'] . " (" . boards_type_label($b) . ")\nالمطوّر: " . $b['developer_name'] . " <" . $b['developer_email'] . ">\nالإصدار: " . $b['app_version'] . "\nGitHub: " . $b['github_url'] . "\n");
            $z->close();
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="board-' . $b['slug'] . '.zip"'); header('Content-Length: ' . filesize($tmp));
            readfile($tmp); @unlink($tmp); return;
        }
        if ($what === 'report') {
            $all = !empty($_GET['all']);
            $ids = array_map('intval', (array)($_GET['boards'] ?? []));
            $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? strtotime($_GET['from'] . ' UTC') : 0;
            $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? strtotime($_GET['to'] . ' 23:59:59 UTC') : time();
            $boards = rows('SELECT * FROM boards ORDER BY name');
            if (!$all && $ids) $boards = array_values(array_filter($boards, fn($b) => in_array((int)$b['id'], $ids, true)));
            header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="boards-report-' . gmdate('Ymd') . '.csv"');
            echo "\xEF\xBB\xBF"; // BOM for Excel Arabic
            echo "التطبيق,النوع,الحالة,الإصدار,المستخدمون,التنزيلات,التراخيص,الإيرادات,التقييم,تعليقات الفترة,تراخيص الفترة,آخر تحديث\n";
            foreach ($boards as $b) {
                $cc = (int)val('SELECT COUNT(*) FROM board_comments WHERE board_id=? AND created>=? AND created<=?', [$b['id'], $from, $to]);
                $lc = (int)val('SELECT COUNT(*) FROM board_licenses WHERE board_id=? AND created>=? AND created<=?', [$b['id'], $from, $to]);
                $row = [$b['name'], boards_type_label($b), BOARDS_STATUS[$b['status']] ?? $b['status'], (string)$b['app_version'], (int)$b['users_count'], (int)$b['downloads_count'], (int)$b['licenses_count'], (int)$b['revenue_num'], (string)$b['rating'], $cc, $lc, gmdate('Y-m-d', (int)$b['updated'])];
                echo implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $row)) . "\n";
            }
            audit('board_report', count($boards) . ' boards'); return;
        }
        http_response_code(404); echo 'Not found';
    },

    'view' => function (array $mod): void {
        if (isset($_GET['panel'])) { boards_panel_view((int)$_GET['panel'], (string)($_GET['tab'] ?? 'overview')); return; }
        if (isset($_GET['edit'])) { boards_edit((int)$_GET['edit']); return; }
        boards_list();
    },
];

function boards_list(): void {
    adm_h('لوحات الإدارة');
    $sort = array_key_exists((string)($_GET['sort'] ?? ''), BOARDS_SORTS) ? (string)$_GET['sort'] : 'newest';
    $q = trim((string)($_GET['q'] ?? ''));
    $fType = array_key_exists((string)($_GET['type'] ?? ''), BOARDS_TYPES) ? (string)$_GET['type'] : '';
    $fStatus = array_key_exists((string)($_GET['status'] ?? ''), BOARDS_STATUS) ? (string)$_GET['status'] : '';

    $allBoards = rows('SELECT * FROM boards');
    $where = '1=1'; $p = [];
    if ($q !== '') {
        $where .= ' AND (name LIKE ? OR description LIKE ? OR tags LIKE ? OR search_tags LIKE ? OR github_url LIKE ?)';
        $p = array_merge($p, ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%']);
    }
    if ($fType !== '') { $where .= ' AND app_type=?'; $p[] = $fType; }
    if ($fStatus !== '') { $where .= ' AND status=?'; $p[] = $fStatus; }
    $boards = boards_sorted(rows("SELECT * FROM boards WHERE $where", $p), $sort);

    echo '<div class="boards-bar">';
    echo '<button type="button" class="btn" data-modal-open="newBoard">＋ لوحة جديدة</button>';
    btn_post('boards', 'import_from_apps', [], '⚡ استيراد من التطبيقات', 'btn ghost');
    echo '<form method="get" class="inline" style="display:flex;gap:8px;align-items:center"><input type="hidden" name="m" value="boards">'
       . ($q !== '' ? '<input type="hidden" name="q" value="' . e($q) . '">' : '')
       . ($fType !== '' ? '<input type="hidden" name="type" value="' . e($fType) . '">' : '')
       . ($fStatus !== '' ? '<input type="hidden" name="status" value="' . e($fStatus) . '">' : '')
       . '<select name="sort" class="sort-sel" onchange="this.form.submit()" aria-label="الترتيب">';
    foreach (BOARDS_SORTS as $k => $n) echo '<option value="' . $k . '"' . ($k === $sort ? ' selected' : '') . '>الترتيب: ' . e($n) . '</option>';
    echo '</select></form>';
    echo '<span class="spacer"></span>';
    echo '<button type="button" class="btn ghost" data-modal-open="reportModal">⬇ استخراج تقرير</button>';
    echo '</div>';

    echo '<form method="get" class="filter-bar"><input type="hidden" name="m" value="boards"><input type="hidden" name="sort" value="' . e($sort) . '">'
       . '<input type="search" name="q" value="' . e($q) . '" placeholder="🔎 بحث بالاسم أو الوصف أو الوسوم أو المستودع…">'
       . '<select name="type" onchange="this.form.submit()"><option value="">كل الأنواع</option>';
    foreach (BOARDS_TYPES as $tk => $tl) echo '<option value="' . $tk . '"' . ($fType === $tk ? ' selected' : '') . '>' . e($tl) . '</option>';
    echo '</select><select name="status" onchange="this.form.submit()"><option value="">كل الحالات</option>';
    foreach (BOARDS_STATUS as $sk => $sl) echo '<option value="' . $sk . '"' . ($fStatus === $sk ? ' selected' : '') . '>' . e($sl) . '</option>';
    echo '</select><button class="btn ghost sm" type="submit">تصفية</button>';
    if ($q !== '' || $fType !== '' || $fStatus !== '') echo '<a class="btn ghost sm" href="' . e(adm_url('boards', ['sort' => $sort])) . '">إعادة تعيين</a>';
    echo '<span class="chip">' . count($boards) . ' لوحة</span></form>';

    if (!$boards) {
        echo '<div class="board-empty"><p style="font-size:2rem">▦</p><p>' . ($allBoards ? 'لا توجد لوحات تطابق الفلتر الحالي.' : 'لا توجد لوحات بعد. اضغط «لوحة جديدة» لبدء أول لوحة.') . '</p></div>';
    } else {
        echo '<div class="boards-grid">';
        foreach ($boards as $b) boards_card($b);
        echo '</div>';
    }

    // ---- modal: new board ----
    echo '<div class="modal" id="newBoard"><div class="modal-box"><button type="button" class="modal-x" data-modal-close>×</button><h2>لوحة جديدة</h2><p class="muted">أدخل اسم المشروع ونوع التطبيق — سيُولَّد البرومت ويُنسخ، وتظهر بطاقة فارغة لإسقاط ملف اللوحة.</p>';
    f_open('boards', 'create_board', [], false, 'form');
    f_in('name', 'اسم المشروع *', '', 'text', 'required maxlength="190" placeholder="مثال: تطبيق إدارة المخزون"');
    f_sel('app_type', 'نوع التطبيق', BOARDS_TYPES, 'other');
    f_in('app_type_custom', 'نوع آخر (إن اخترت «أخرى» — مثال: بوت واتساب، إضافة متصفح)', '', 'text', 'maxlength="100"');
    echo '<div class="row"><button class="btn" type="submit">توليد البرومت وإنشاء البطاقة</button></div></form></div></div>';

    // ---- modal: report ----
    echo '<div class="modal" id="reportModal"><div class="modal-box"><button type="button" class="modal-x" data-modal-close>×</button><h2>استخراج تقرير</h2><p class="muted">حدّد التطبيقات المستهدفة والفترة، أو اختر «كل التطبيقات».</p>';
    echo '<form method="get"><input type="hidden" name="m" value="boards"><input type="hidden" name="dl" value="report">';
    echo '<label class="chk"><input type="checkbox" name="all" value="1" checked> كل التطبيقات</label>';
    echo '<div class="two"><label>من تاريخ<input type="date" name="from" dir="ltr"></label><label>إلى تاريخ<input type="date" name="to" dir="ltr"></label></div>';
    echo '<label>التطبيقات المستهدفة (اتركها فارغة مع «كل التطبيقات»)</label><div class="check-row">';
    foreach ($allBoards as $b) echo '<label class="check-item"><input type="checkbox" name="boards[]" value="' . (int)$b['id'] . '"> ' . e($b['name']) . '</label>';
    echo '</div><div class="row" style="margin-top:14px"><button class="btn" type="submit">⬇ تنزيل التقرير (CSV)</button></div></form></div></div>';

    // ---- modal: update panel (one, id set by JS) ----
    echo '<div class="modal" id="updateModal"><div class="modal-box"><button type="button" class="modal-x" data-modal-close>×</button><h2>تحديث اللوحة</h2><p class="muted">ارفع ملف اللوحة الجديد (zip) — سيستبدل الملف الحالي.</p>';
    f_open('boards', 'upload_panel', ['id' => 0], true, 'form');
    echo '<input type="hidden" name="id" id="updateBoardId" value="0">';
    f_in('panel_file', 'ملف اللوحة (zip)', '', 'file', 'required accept=".zip"');
    echo '<div class="row"><button class="btn" type="submit">رفع وتحديث</button></div></form></div></div>';

    // ---- modal: prompt (after create) ----
    if (isset($_GET['prompt'])) {
        $b = row('SELECT * FROM boards WHERE id=?', [(int)$_GET['prompt']]);
        if ($b) {
            $prompt = boards_prompt($b);
            echo '<div class="modal open" id="promptModal"><div class="modal-box wide"><button type="button" class="modal-x" data-modal-close>×</button><h2>البرومت — ' . e($b['name']) . ' (' . e(boards_type_label($b)) . ')</h2><p class="muted">انسخ هذا البرومت وأرسله للموديل الذي يعمل على التطبيق. ثم أسقط ملف اللوحة المضغوط في البطاقة.</p>';
            echo '<textarea class="prompt-box" readonly>' . e($prompt) . '</textarea>';
            echo '<div class="row" style="margin-top:12px">' . boards_copy_btn($prompt, '📋 نسخ البرومت') . '<button type="button" class="btn ghost" data-modal-close>إغلاق</button></div></div></div>';
        }
    }
}

function boards_card(array $b): void {
    $draft = ($b['status'] ?? 'draft') === 'draft';
    $hasZip = !empty($b['panel_zip_name']);
    $panelUrl = boards_panel_url((int)$b['id'], 'overview');
    $missingReqs = 0;
    try { if (table_exists('app_requirements')) $missingReqs = (int)val("SELECT COUNT(*) FROM app_requirements WHERE app_id=? AND status='missing'", [$b['id']]); } catch (Throwable $e) {}
    echo '<article class="board-card' . ($draft ? ' draft' : '') . '">';
    echo '<div class="board-head"><a class="board-icon" href="' . e($panelUrl) . '" title="فتح لوحة تحكم التطبيق">';
    if (!empty($b['icon'])) echo '<img src="' . e(upload_url((string)$b['icon'])) . '" alt="">';
    else echo '▦';
    echo '</a><div style="flex:1;min-width:0"><a class="board-title" href="' . e($panelUrl) . '" style="color:inherit">' . e($b['name']) . '</a><div class="board-meta"><span class="chip">' . e(boards_type_label($b)) . '</span><span class="chip">' . e(BOARDS_STATUS[$b['status']] ?? $b['status']) . '</span>' . (!empty($b['app_version']) ? '<span class="chip" dir="ltr">v' . e($b['app_version']) . '</span>' : '') . '</div></div>';
    // 3-dot menu
    echo '<div class="dots"><button type="button" class="dots-btn" data-dots aria-label="خيارات">⋮</button><div class="dots-menu">';
    echo '<button type="button" data-copy="' . e(boards_prompt($b)) . '">📋 نسخ البرومت</button>';
    echo '<a href="' . e(adm_url('boards', ['dl' => 'panel', 'id' => $b['id']])) . '">⬇ استخراج اللوحة (zip)</a>';
    echo '<button type="button" data-update-board="' . (int)$b['id'] . '">⇪ تحديث اللوحة</button>';
    echo '<form method="post" data-confirm="حذف اللوحة نهائياً مع كل بياناتها وملفاتها؟">' . csrf_field() . '<input type="hidden" name="m" value="boards"><input type="hidden" name="do" value="delete_board"><input type="hidden" name="id" value="' . (int)$b['id'] . '"><button type="submit" class="danger-t">🗑 حذف اللوحة نهائياً</button></form>';
    echo '</div></div></div>';
    if (!empty($b['description'])) echo '<p class="board-desc">' . e(mb_substr((string)$b['description'], 0, 160)) . '</p>';
    echo '<div class="board-stats">'
       . '<div class="board-stat"><b>' . (int)$b['users_count'] . '</b><span>مستخدمون</span></div>'
       . '<div class="board-stat"><b>' . (int)$b['downloads_count'] . '</b><span>تنزيلات</span></div>'
       . '<div class="board-stat"><b>' . (int)$b['licenses_count'] . '</b><span>تراخيص</span></div>'
       . '<div class="board-stat"><b>' . e((string)($b['rating'] ?: '—')) . '</b><span>تقييم</span></div></div>';
    echo '<div class="board-badges">';
    if (!empty($b['github_url'])) echo '<span class="chip ' . ((int)$b['github_synced'] === 1 ? 'ok' : ((int)$b['github_synced'] === 2 ? 'bad' : 'warn')) . '">GitHub: ' . ((int)$b['github_synced'] === 1 ? 'مزامن ✓' : ((int)$b['github_synced'] === 2 ? 'غير مزامن' : 'بانتظار الفحص')) . '</span>';
    if (!empty($b['app_version'])) echo '<span class="chip ' . ((int)$b['version_uploaded'] ? 'ok' : 'warn') . '">الإصدار ' . ((int)$b['version_uploaded'] ? 'مرفوع ✓' : 'غير مرفوع') . '</span>';
    if ((int)($b['docs_has'] ?? 0)) echo '<span class="chip ok">📘 توثيق' . (!empty($b['docs_site_slug']) ? ': /s/' . e((string)$b['docs_site_slug']) . '/' : '') . '</span>';
    if ($hasZip) echo '<span class="chip">' . (int)$b['panel_files_count'] . ' ملفاً في اللوحة</span>';
    $hs = (string)($b['handoff_status'] ?? 'pending');
    echo '<span class="chip ' . ($hs === 'verified' ? 'ok' : 'bad') . '">📦 التسليم: ' . ($hs === 'verified' ? 'مُتحقق ✓' : 'بانتظار MOTHER_PANEL_HANDOFF.md') . '</span>';
    if ($missingReqs > 0) echo '<span class="chip bad">📋 ' . $missingReqs . ' متطلب ناقص</span>';
    echo '</div>';
    if (!$hasZip) {
        echo '<form method="post" enctype="multipart/form-data" class="dropzone-form">' . csrf_field() . '<input type="hidden" name="m" value="boards"><input type="hidden" name="do" value="upload_panel"><input type="hidden" name="id" value="' . (int)$b['id'] . '">';
        echo '<label class="dropzone" data-dropzone><span>⬇ اسحب ملف اللوحة المضغوط هنا أو اضغط للاختيار (zip)</span><input type="file" name="panel_file" accept=".zip" required><span class="dz-file" data-dz-file></span><span class="dz-add" data-dz-add style="display:none"><button type="submit" class="btn sm">＋ إضافة</button></span></label></form>';
    }
    echo '<div class="board-actions"><a class="btn sm grow" href="' . e($panelUrl) . '">🎛 فتح لوحة تحكم التطبيق</a><a class="btn ghost sm" href="' . e(adm_url('boards', ['edit' => $b['id']])) . '">' . ($hasZip ? 'البيانات' : 'إضافة بيانات') . '</a></div>';
    echo '</article>';
}

function boards_edit(int $id): void {
    $b = row('SELECT * FROM boards WHERE id=?', [$id]);
    if (!$b) { echo '<div class="note bad">اللوحة غير موجودة.</div>'; return; }
    adm_h('لوحة: ' . $b['name'], 'راجع البيانات وارفع ملفات التوثيق إن وُجدت — أغلب البيانات يعبّئها الموديل في ملف البيانات.');
    $sites = table_exists('sites') ? rows('SELECT slug, title FROM sites ORDER BY title') : [];
    $siteOpts = ['' => '— بدون ربط بموقع مرفوع —'];
    foreach ($sites as $s) $siteOpts[(string)$s['slug']] = (string)$s['title'] . ' (/s/' . $s['slug'] . '/)';

    echo '<div class="detail-grid">';
    // ---- main data ----
    echo '<div class="panel"><h2>بيانات التطبيق</h2>';
    f_open('boards', 'save_board', ['id' => $id], true, 'form');
    f_in('name', 'اسم التطبيق *', $b['name'], 'text', 'required maxlength="190"');
    f_sel('app_type', 'نوع التطبيق', BOARDS_TYPES, $b['app_type']);
    f_in('app_type_custom', 'نوع آخر (إن وُجد)', $b['app_type_custom'] ?? '', 'text', 'maxlength="100"');
    f_sel('status', 'الحالة', BOARDS_STATUS, $b['status']);
    f_ta('description', 'الوصف', $b['description'] ?? '', 3);
    f_in('tags', 'التاجات (مفصولة بفاصلة)', $b['tags'] ?? '', 'text', 'maxlength="500"');
    f_in('search_tags', 'علامات البحث', $b['search_tags'] ?? '', 'text', 'maxlength="500"');
    echo '<div class="two">'; f_in('app_version', 'إصدار التطبيق', $b['app_version'] ?? '', 'text', 'dir="ltr"'); f_in('github_url', 'مستودع GitHub', $b['github_url'] ?? '', 'text', 'dir="ltr" placeholder="https://github.com/user/repo"'); echo '</div>';
    echo '<h3>الأيقونة</h3>';
    echo '<div class="fimg"><label>أيقونة التطبيق (تظهر في بطاقة اللوحة)<input type="file" name="icon" accept="image/jpeg,image/png,image/webp"></label>';
    if (!empty($b['icon'])) echo '<div class="fimg-cur"><img src="' . e(upload_url((string)$b['icon'])) . '" alt=""><label class="chk"><input type="checkbox" name="icon_remove" value="1">حذف الأيقونة</label></div>';
    echo '</div>';
    echo '<h3>التوثيق</h3>';
    f_chk('docs_has', 'للتطبيق موقع توثيق', !empty($b['docs_has']));
    f_sel('docs_site_slug', 'موقع التوثيق من المواقع المرفوعة', $siteOpts, (string)($b['docs_site_slug'] ?? ''));
    f_in('docs_site_url', 'رابط موقع التوثيق الخارجي (إن وُجد)', $b['docs_site_url'] ?? '', 'text', 'dir="ltr" placeholder="https://"');
    echo '<h3>الإحصائيات</h3><div class="two">';
    f_in('users_count', 'عدد المستخدمين', (string)(int)$b['users_count'], 'number', 'dir="ltr" min="0"');
    f_in('downloads_count', 'عدد التنزيلات', (string)(int)$b['downloads_count'], 'number', 'dir="ltr" min="0"');
    f_in('licenses_count', 'عدد التراخيص', (string)(int)$b['licenses_count'], 'number', 'dir="ltr" min="0"');
    f_in('revenue_num', 'الإيرادات', (string)(int)$b['revenue_num'], 'number', 'dir="ltr" min="0"');
    echo '</div>'; f_in('rating', 'التقييم (مثال: 4.8)', $b['rating'] ?? '', 'text', 'dir="ltr"');
    echo '<h3>بيانات المطوّر</h3>';
    f_in('developer_name', 'اسم المطوّر', $b['developer_name'] ?? '', 'text', 'maxlength="190"');
    f_in('developer_email', 'بريد المطوّر', $b['developer_email'] ?? '', 'text', 'dir="ltr"');
    f_ta('developer_notes', 'ملاحظات المطوّر', $b['developer_notes'] ?? '', 2);
    echo '<h3>خريطة التثبيت (MAP)</h3><p class="muted hint">JSON يساعد لوحة الأم على تثبيت الملفات بالترتيب الصحيح.</p>';
    f_ta('panel_map', 'خريطة الملفات (JSON)', $b['panel_map'] ?? '', 6, 'class="mono" dir="ltr"');
    echo '<h3>بيانات الموديل</h3><p class="muted hint">الصق JSON الذي يعبّئه الموديل ثم اضغط «تطبيق البيانات» لملء الحقول، وراجعها قبل الحفظ.</p>';
    f_ta('model_data', 'ملف بيانات الموديل (JSON)', $b['model_data'] ?? '', 6, 'class="mono" dir="ltr"');
    echo '<div class="row"><button class="btn" type="submit">حفظ البيانات</button>';
    echo '<button class="btn ghost" type="submit" data-apply-model>تطبيق بيانات الموديل (JSON أعلاه)</button></form></div>';
    echo '</div>';

    // ---- side column ----
    echo '<div>';
    echo '<div class="panel"><h2>GitHub والمزامنة</h2><p class="muted">الحالة: ' . ((int)$b['github_synced'] === 1 ? '✓ مزامن' : ((int)$b['github_synced'] === 2 ? '⚠ غير مزامن' : 'لم يُفحص بعد')) . ' · الإصدار: ' . ((int)$b['version_uploaded'] ? 'مرفوع ✓' : 'غير مرفوع') . '</p>';
    echo '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="m" value="boards"><input type="hidden" name="do" value="check_github"><input type="hidden" name="id" value="' . $id . '"><button class="btn" type="submit">فحص المزامنة والإصدار</button></form>';
    if (!empty($b['github_url'])) echo ' <a class="btn ghost" href="' . e((string)$b['github_url']) . '" target="_blank" rel="noopener">فتح المستودع ↗</a>';
    echo '</div>';

    echo '<div class="panel"><h2>ملفات اللوحة</h2>';
    if (!empty($b['panel_zip_name'])) echo '<p class="muted">' . e((string)$b['panel_zip_name']) . ' · ' . e(fmt_size((int)$b['panel_zip_size'])) . ' · ' . (int)$b['panel_files_count'] . ' ملفاً</p>';
    else echo '<p class="muted">لم يُرفع ملف اللوحة بعد.</p>';
    echo '<div class="row"><a class="btn ghost sm" href="' . e(adm_url('boards', ['dl' => 'panel', 'id' => $id])) . '">⬇ استخراج اللوحة (zip)</a>';
    echo '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="m" value="boards"><input type="hidden" name="do" value="verify_install"><input type="hidden" name="id" value="' . $id . '"><button class="btn ghost sm" type="submit">التحقق من التثبيت</button></form></div>';
    f_open('boards', 'upload_panel', ['id' => $id], true, 'form');
    f_in('panel_file', 'تحديث ملف اللوحة (zip)', '', 'file', 'required accept=".zip"');
    echo '<button class="btn sm" type="submit">رفع وتحديث</button></form></div>';

    echo '<div class="panel"><h2>التوثيق والملفات المرفقة</h2>';
    if (!empty($b['docs_site_slug'])) echo '<p class="muted">موقع التوثيق المرفوع: <a dir="ltr" href="' . e(site_origin() . '/s/' . $b['docs_site_slug'] . '/') . '" target="_blank" rel="noopener">/s/' . e((string)$b['docs_site_slug']) . '/</a></p>';
    f_open('boards', 'upload_docs', ['id' => $id], true, 'form');
    f_in('docs_file', 'رفع موقع توثيق جديد (zip أو html)', '', 'file', 'required accept=".zip,.html,.htm"');
    echo '<button class="btn sm" type="submit">رفع وربط التوثيق</button></form>';
    $files = rows('SELECT * FROM board_files WHERE board_id=? ORDER BY id DESC', [$id]);
    if ($files) { echo '<h3>الملفات المرفقة</h3>'; foreach ($files as $f) echo '<p class="muted" dir="ltr">' . e($f['kind']) . ' · ' . e($f['name']) . ' · ' . e(fmt_size((int)$f['size'])) . '</p>'; }
    f_open('boards', 'upload_attachment', ['id' => $id], true, 'form');
    f_in('attach_file', 'ملف مرفق إضافي', '', 'file', 'required');
    echo '<button class="btn ghost sm" type="submit">رفع المرفق</button></form></div>';

    echo '<div class="panel"><h2>البرومت</h2><p class="muted">البرومت المولّد لهذه اللوحة (' . e(boards_type_label($b)) . '):</p>';
    echo '<textarea class="prompt-box" readonly style="min-height:160px">' . e(boards_prompt($b)) . '</textarea><div class="row" style="margin-top:10px">' . boards_copy_btn(boards_prompt($b), '📋 نسخ البرومت') . '</div></div>';
    echo '</div></div>';

    // ---- licenses ----
    echo '<div class="panel"><div class="row" style="justify-content:space-between;margin-bottom:8px"><h2 style="margin:0">التراخيص والمفاتيح المرتبطة</h2><a class="btn ghost sm" href="' . e(adm_url('appkeys', ['board_id' => $id])) . '">🔑 توليد دفعة مفاتيح لهذا التطبيق</a></div>';
    $lics = rows('SELECT * FROM board_licenses WHERE board_id=? ORDER BY id DESC', [$id]);
    $bulkKeys = table_exists('app_keys') ? rows('SELECT * FROM app_keys WHERE board_id=? ORDER BY id DESC LIMIT 50', [$id]) : [];
    if ($lics || $bulkKeys) {
        echo '<div class="tw"><table><thead><tr><th>الكود</th><th>النوع / الخطة</th><th>البريد</th><th>الحالة</th><th>الإنشاء</th><th>الانتهاء</th><th></th></tr></thead><tbody>';
        foreach ($lics as $l) {
            echo '<tr><td dir="ltr"><code>' . e($l['code']) . '</code></td><td>' . e((string)($l['plan'] ?: 'ترخيص')) . '</td><td dir="ltr">' . e((string)$l['email']) . '</td><td>' . e((string)$l['status']) . '</td><td>' . e(fmt_date($l['created'])) . '</td><td>' . e($l['expires'] ? fmt_date($l['expires']) : '—') . '</td><td class="acts">';
            btn_post('boards', 'license_delete', ['id' => $l['id'], 'board_id' => $id], 'حذف', 'btn danger sm', 'حذف هذا الترخيص؟');
            echo '</td></tr>';
        }
        foreach ($bulkKeys as $bk) {
            echo '<tr><td dir="ltr"><code>' . e($bk['key_code']) . '</code></td><td>' . e(($bk['key_type'] ?? 'key') . ($bk['plan'] ? ' · ' . $bk['plan'] : '')) . '</td><td dir="ltr">' . e((string)$bk['email']) . '</td><td>' . e((string)$bk['status']) . '</td><td>' . e(fmt_date($bk['created'])) . '</td><td>' . e($bk['expires'] ? fmt_date($bk['expires']) : '—') . '</td><td class="acts"><a class="btn ghost sm" href="' . e(adm_url('appkeys', ['board_id' => $id])) . '">إدارة</a></td></tr>';
        }
        echo '</tbody></table></div>';
    } else echo '<p class="muted">لا تراخيص أو مفاتيح مرتبطة بعد.</p>';
    f_open('boards', 'license_add', ['board_id' => $id], false, 'inline-form');
    f_in('code', 'الكود (اتركه فارغاً للتوليد)', '', 'text', 'dir="ltr"');
    f_in('email', 'بريد العميل', '', 'text', 'dir="ltr"');
    f_in('plan', 'الخطة', '', 'text');
    f_in('expires', 'الانتهاء (YYYY-MM-DD)', '', 'text', 'dir="ltr" placeholder="YYYY-MM-DD"');
    echo '<button class="btn sm" type="submit">إنشاء ترخيص</button></form></div>';

    // ---- unified comments / reviews ----
    echo '<div class="panel"><h2>التعليقات والتقييمات (مدونة موحّدة)</h2>';
    $coms = rows('SELECT * FROM board_comments WHERE board_id=? ORDER BY id DESC', [$id]);
    if ($coms) { echo '<div class="tw"><table><thead><tr><th>الكاتب</th><th>التقييم</th><th>التعليق</th><th>الحالة</th><th>التاريخ</th><th></th></tr></thead><tbody>';
        foreach ($coms as $c) { echo '<tr><td><b>' . e($c['author']) . '</b><br><span class="muted" dir="ltr">' . e((string)$c['email']) . '</span></td><td>' . str_repeat('★', (int)$c['rating']) . '</td><td>' . e(mb_substr((string)$c['body'], 0, 200)) . '</td><td>' . ($c['approved'] ? '✓ معتمد' : 'بانتظار') . '</td><td>' . e(fmt_date($c['created'])) . '</td><td class="acts">';
            btn_post('boards', 'comment_approve', ['id' => $c['id'], 'board_id' => $id], $c['approved'] ? 'إلغاء' : 'اعتماد');
            btn_post('boards', 'comment_delete', ['id' => $c['id'], 'board_id' => $id], 'حذف', 'btn danger sm', 'حذف هذا التعليق؟'); echo '</td></tr>'; }
        echo '</tbody></table></div>'; }
    else echo '<p class="muted">لا تعليقات بعد — هذه المدونة الموحّدة تعرض آراء وتقييمات العملاء عن التطبيقات.</p>';
    f_open('boards', 'comment_add', ['board_id' => $id], false, 'form');
    echo '<div class="two">'; f_in('author', 'اسم الكاتب', '', 'text', 'required'); f_in('email', 'البريد', '', 'text', 'dir="ltr"'); echo '</div>';
    f_in('rating', 'التقييم (0-5)', '5', 'number', 'dir="ltr" min="0" max="5"');
    f_ta('body', 'التعليق', '', 3);
    f_chk('approved', 'معتمد (ظاهر)', false);
    echo '<button class="btn sm" type="submit">إضافة تعليق</button></form></div>';
    echo '<p><a class="btn ghost" href="' . e(adm_url('boards')) . '">← رجوع إلى لوحات الإدارة</a> <a class="btn" href="' . e(boards_panel_url($id, 'overview')) . '">🎛 فتح لوحة تحكم التطبيق</a></p>';
    boards_panel_fab();
}
