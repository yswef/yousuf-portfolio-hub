<?php
declare(strict_types=1);
/**
 * Boards Mother Panel helpers (loaded via require_once from module.php to avoid redeclaration
 * when migrate_all() re-enters modules_load() during actions).
 * Embedded App Control Panel (?m=boards&panel=<id>&tab=...) + release/pack/settings helpers.
 */

const BOARDS_PANEL_TABS = [
    'overview'     => ['label' => 'نظرة عامة',      'icon' => '⌂'],
    'users'        => ['label' => 'المستخدمون والجلسات', 'icon' => '👥'],
    'devices'      => ['label' => 'الأجهزة',         'icon' => '📱'],
    'releases'     => ['label' => 'الإصدارات والتحديثات', 'icon' => '⬆'],
    'content'      => ['label' => 'المحتوى والإعدادات', 'icon' => '⚙'],
    'licenses'     => ['label' => 'التراخيص',       'icon' => '🔑'],
    'requirements' => ['label' => 'متطلبات التطبيق', 'icon' => '📋'],
    'handoff'      => ['label' => 'ملف التسليم',    'icon' => '📦'],
    'settings'     => ['label' => 'إعدادات اللوحة',  'icon' => '🛠'],
];

function boards_panel_url(int $id, string $tab = 'overview'): string {
    return adm_url('boards', ['panel' => $id, 'tab' => $tab]);
}

function boards_panel_counts(int $appId): array {
    $c = ['users' => 0, 'sessions' => 0, 'devices' => 0, 'releases_full' => 0, 'releases_sub' => 0, 'packs' => 0, 'licenses' => 0, 'missing_reqs' => 0, 'provided_reqs' => 0];
    try {
        if (table_exists('app_users')) $c['users'] = (int)val('SELECT COUNT(*) FROM app_users WHERE app_id=?', [$appId]);
        if (table_exists('app_sessions')) $c['sessions'] = (int)val('SELECT COUNT(*) FROM app_sessions WHERE app_id=? AND revoked=0 AND expires>?', [$appId, time()]);
        if (table_exists('devices')) $c['devices'] = (int)val('SELECT COUNT(*) FROM devices WHERE app_id=?', [$appId]);
        if (table_exists('releases')) {
            $c['releases_full'] = (int)val("SELECT COUNT(*) FROM releases WHERE app_id=? AND release_type='full'", [$appId]);
            $c['releases_sub'] = (int)val("SELECT COUNT(*) FROM releases WHERE app_id=? AND release_type='sub'", [$appId]);
        }
        if (table_exists('content_packs')) $c['packs'] = (int)val('SELECT COUNT(*) FROM content_packs WHERE app_id=?', [$appId]);
        if (table_exists('board_licenses')) $c['licenses'] = (int)val('SELECT COUNT(*) FROM board_licenses WHERE board_id=?', [$appId]);
        if (table_exists('app_requirements')) {
            $c['missing_reqs'] = (int)val("SELECT COUNT(*) FROM app_requirements WHERE app_id=? AND status='missing'", [$appId]);
            $c['provided_reqs'] = (int)val("SELECT COUNT(*) FROM app_requirements WHERE app_id=? AND status='provided'", [$appId]);
        }
    } catch (Throwable $e) {}
    return $c;
}

function boards_panel_tab_bar(int $id, string $cur): void {
    echo '<div class="filter-bar" style="margin-bottom:14px">';
    foreach (BOARDS_PANEL_TABS as $k => $t) {
        echo '<a class="btn ' . ($k === $cur ? '' : 'ghost') . ' sm" href="' . e(boards_panel_url($id, $k)) . '">' . e($t['icon'] . ' ' . $t['label']) . '</a>';
    }
    echo '</div>';
}

function boards_panel_fab(): void {
    // Floating return button preserving the admin session (inside Mother Panel only)
    echo '<a class="mp-fab-return" href="' . e(adm_url('boards')) . '" style="position:fixed;bottom:20px;inset-inline-end:20px;z-index:99999;display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:999px;background:linear-gradient(120deg,#0d9488,#0284c7);color:#fff;font:700 13.5px/1.4 Cairo,system-ui,Tahoma,sans-serif;text-decoration:none;box-shadow:0 10px 28px rgba(2,132,199,.38);border:1px solid rgba(255,255,255,.22)">↩ العودة للوحة التحكم الأم</a>';
}

function boards_panel_view(int $id, string $tab): void {
    $b = row('SELECT * FROM boards WHERE id=?', [$id]);
    if (!$b) { echo '<div class="note bad">اللوحة غير موجودة.</div>'; return; }
    if (!isset(BOARDS_PANEL_TABS[$tab])) $tab = 'overview';
    $counts = boards_panel_counts($id);
    $handoffStatus = (string)($b['handoff_status'] ?? 'pending');

    adm_h('لوحة تحكم التطبيق: ' . $b['name'], boards_type_label($b) . ' · ' . ($b['slug'] ?? '') . ' · الحالة: ' . (BOARDS_STATUS[$b['status']] ?? $b['status']));
    boards_panel_tab_bar($id, $tab);

    // Handoff gate banner: installation can NEVER be complete before MOTHER_PANEL_HANDOFF.md is read + executed
    if ($handoffStatus !== 'verified') {
        echo '<div class="note bad">⛔ بوابة التسليم: لم يتم قراءة وتنفيذ <code>MOTHER_PANEL_HANDOFF.md</code> بعد (الحالة: ' . e($handoffStatus) . '). لا يمكن إعلان اكتمال تثبيت هذا التطبيق قبل قراءة الملف وتنفيذ بنوده وتسجيل النتيجة في تبويب «ملف التسليم».</div>';
    } else {
        echo '<div class="note ok">✓ تمت قراءة وتنفيذ <code>MOTHER_PANEL_HANDOFF.md</code> وتسجيل النتيجة (' . e(fmt_date($b['handoff_verified_at'] ?? '')) . ').</div>';
    }

    if ($tab === 'overview') {
        echo '<div class="kpis">';
        echo '<div class="kpi"><b>' . $counts['users'] . '</b><span>مستخدمون (app_users)</span></div>';
        echo '<div class="kpi"><b>' . $counts['sessions'] . '</b><span>جلسات نشطة</span></div>';
        echo '<div class="kpi"><b>' . $counts['devices'] . '</b><span>أجهزة مسجّلة</span></div>';
        echo '<div class="kpi"><b>' . $counts['releases_full'] . ' / ' . $counts['releases_sub'] . '</b><span>إصدارات كاملة / فرعية</span></div>';
        echo '<div class="kpi"><b>' . $counts['packs'] . '</b><span>حزم محتوى</span></div>';
        echo '<div class="kpi"><b>' . $counts['licenses'] . '</b><span>تراخيص</span></div>';
        echo '<div class="kpi"><b>' . $counts['missing_reqs'] . '</b><span>متطلبات ناقصة</span></div>';
        echo '<div class="kpi"><b>' . e((string)($b['api_token_hint'] ?? '—')) . '</b><span>توكن API (مموّه)</span></div>';
        echo '</div>';
        echo '<div class="panel"><h2>معلومات التطبيق</h2>';
        echo '<p class="muted">الاسم: <b>' . e($b['name']) . '</b> · النوع: ' . e(boards_type_label($b)) . ' · الإصدار: <span dir="ltr">' . e((string)($b['app_version'] ?? '')) . '</span> · الحد الأدنى: <span dir="ltr">' . e((string)($b['min_app_version'] ?? '')) . '</span></p>';
        echo '<p class="muted">مطلوب تسجيل الدخول قبل الفتح: ' . (!empty($b['online_required']) ? '✓ نعم' : '✗ لا') . ' · المزامنة بين الأجهزة: ' . (!empty($b['sync_enabled']) ? '✓ مفعّلة' : '✗ معطّلة') . ' · GitHub: ' . (!empty($b['uses_github']) ? '✓ مربوط (توكن مركزي)' : '✗ غير مربوط') . '</p>';
        if (!empty($b['description'])) echo '<p>' . e($b['description']) . '</p>';
        echo '<div class="row"><a class="btn ghost sm" href="' . e(adm_url('boards', ['edit' => $id])) . '">✏ تعديل البيانات الأساسية</a>';
        echo '<a class="btn ghost sm" href="' . e(adm_url('requirements', ['app_id' => $id])) . '">📋 فتح متطلبات التطبيق</a></div></div>';
        echo '<div class="panel"><h2>بوابة الـ API الموحّدة (JSON فقط)</h2>';
        echo '<p class="muted">اللوحة الأم وحدها تملك الكود والتوكنات؛ اللوحات الفرعية بلا كود مستقل. كل استدعاء يُسجَّل في <code>audit_log</code> و<code>sync_log</code> مع <code>req_id</code> فريد.</p>';
        echo '<pre dir="ltr" style="text-align:left">Base: ' . e(site_origin() . '/api/v1/') . "\nHeader: X-App-Token: mp_app_...  (or Authorization: Bearer ...)\nEndpoints: status · auth/register · auth/login · auth/session · auth/logout · sync · releases/check · content · licenses/verify · licenses/activate · telemetry · control</pre>";
        echo '</div>';
    }

    if ($tab === 'users') {
        $users = table_exists('app_users') ? rows('SELECT * FROM app_users WHERE app_id=? ORDER BY id DESC LIMIT 200', [$id]) : [];
        echo '<div class="panel"><h2>مستخدمو التطبيق (' . count($users) . ') — نطاق app_id=' . $id . '</h2>';
        echo '<p class="muted">نفس البريد مسموح له بحساب منفصل في كل تطبيق (UNIQUE(app_id, email)). تسجيل الدخول إلزامي قبل فتح التطبيق.</p>';
        if (!empty($b['uses_github'])) echo '<p class="muted">🐙 التطبيق مربوط بـ GitHub: أي حساب «أدمن» هنا يستطيع طلب توكن GitHub المركزي من الخادم (<code dir="ltr">control?op=github_token</code>)، والحسابات العادية لا تصل إليه.</p>';
        if ($users) {
            echo '<div class="tw"><table><thead><tr><th>#</th><th>البريد</th><th>الاسم</th><th>أدمن GitHub</th><th>الحالة</th><th>إصدار المزامنة</th><th>آخر مزامنة</th><th>الإنشاء</th><th></th></tr></thead><tbody>';
            foreach ($users as $u) {
                $isAdm = (int)($u['is_admin'] ?? 0) === 1;
                echo '<tr><td>' . (int)$u['id'] . '</td><td dir="ltr">' . e((string)$u['email']) . '</td><td>' . e((string)($u['display_name'] ?? '')) . '</td>';
                echo '<td><span class="chip ' . ($isAdm ? 'ok' : '') . '">' . ($isAdm ? '👑 أدمن' : '—') . '</span></td>';
                echo '<td>' . e((string)$u['status']) . '</td><td dir="ltr">' . (int)$u['sync_version'] . '</td><td>' . e($u['last_sync'] ? fmt_date($u['last_sync']) : '—') . '</td><td>' . e(fmt_date($u['created'])) . '</td><td class="acts">';
                btn_post('boards', 'toggle_user_status', ['id' => $u['id'], 'board_id' => $id], ($u['status'] ?? 'active') === 'active' ? 'إيقاف' : 'تفعيل');
                btn_post('boards', 'toggle_user_admin', ['id' => $u['id'], 'board_id' => $id], $isAdm ? 'إلغاء الأدمن' : 'ترقية لأدمن', 'btn ghost sm', 'تغيير صلاحية الأدمن لهذا الحساب؟');
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else echo '<p class="muted">لا مستخدمين بعد — سيظهرون هنا فور تسجيلهم عبر /api/v1/auth/register.</p>';
        echo '</div>';
        $sess = table_exists('app_sessions') ? rows('SELECT s.*, u.email FROM app_sessions s LEFT JOIN app_users u ON u.id=s.user_id AND u.app_id=s.app_id WHERE s.app_id=? ORDER BY s.id DESC LIMIT 100', [$id]) : [];
        echo '<div class="panel"><h2>الجلسات (' . count($sess) . ')</h2>';
        if ($sess) {
            echo '<div class="tw"><table><thead><tr><th>#</th><th>المستخدم</th><th>الجهاز</th><th>آخر ظهور</th><th>الانتهاء</th><th>ملغاة</th><th></th></tr></thead><tbody>';
            foreach ($sess as $s) {
                echo '<tr><td>' . (int)$s['id'] . '</td><td dir="ltr">' . e((string)($s['email'] ?? $s['user_id'])) . '</td><td>' . (int)($s['device_id'] ?? 0) . '</td><td>' . e(fmt_date($s['last_seen'])) . '</td><td>' . e(fmt_date($s['expires'])) . '</td><td>' . ((int)$s['revoked'] ? '✓ ملغاة' : 'نشطة') . '</td><td class="acts">';
                if (!(int)$s['revoked']) btn_post('boards', 'revoke_session', ['id' => $s['id'], 'board_id' => $id], 'إلغاء الجلسة', 'btn danger sm', 'إلغاء هذه الجلسة؟');
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else echo '<p class="muted">لا جلسات.</p>';
        echo '</div>';
    }

    if ($tab === 'devices') {
        $devs = table_exists('devices') ? rows('SELECT * FROM devices WHERE app_id=? ORDER BY last_sync DESC LIMIT 200', [$id]) : [];
        echo '<div class="panel"><h2>أجهزة المستخدمين (' . count($devs) . ')</h2>';
        if ($devs) {
            echo '<div class="tw"><table><thead><tr><th>UID</th><th>الاسم</th><th>المنصة</th><th>إصدار التطبيق</th><th>آخر مزامنة</th><th>الحالة</th><th></th></tr></thead><tbody>';
            foreach ($devs as $d) {
                echo '<tr><td dir="ltr"><code>' . e((string)$d['device_uid']) . '</code></td><td>' . e((string)($d['device_name'] ?? '')) . '</td><td>' . e((string)($d['platform'] ?? '')) . '</td><td dir="ltr">' . e((string)($d['app_version'] ?? '')) . '</td><td>' . e($d['last_sync'] ? fmt_date($d['last_sync']) : '—') . '</td><td>' . e((string)$d['status']) . '</td><td class="acts">';
                btn_post('boards', 'toggle_device_status', ['id' => $d['id'], 'board_id' => $id], ($d['status'] ?? 'active') === 'active' ? 'حظر' : 'تفعيل');
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else echo '<p class="muted">لا أجهزة مسجّلة بعد — تُسجَّل تلقائياً عند تسجيل الدخول مع device_uid.</p>';
        echo '</div>';
    }

    if ($tab === 'releases') {
        $rels = table_exists('releases') ? rows('SELECT * FROM releases WHERE app_id=? ORDER BY version_code DESC, id DESC', [$id]) : [];
        echo '<div class="panel"><h2>إصدار كامل (APK) أو تحديث فرعي خفيف (بيانات فقط)</h2>';
        echo '<p class="muted">الكامل يُنزَّل داخل التطبيق مع دعم <code>permissions_changed</code> وإعادة طلب الصلاحيات. الفرعي يُسحب عند تشغيل التطبيق (<code>sub</code>) كحزمة بيانات خفيفة. <code>min_version</code> يفرض تحديثاً إجبارياً.</p>';
        if ($rels) {
            echo '<div class="tw"><table><thead><tr><th>الإصدار</th><th>النوع</th><th>القناة</th><th>الحد الأدنى</th><th>الحجم</th><th>الصلاحيات تغيّرت</th><th>نشط</th><th>التاريخ</th><th></th></tr></thead><tbody>';
            foreach ($rels as $r) {
                echo '<tr><td dir="ltr"><b>' . e((string)$r['version']) . '</b> (code ' . (int)$r['version_code'] . ')</td><td>' . e((string)$r['release_type']) . '</td><td>' . e((string)$r['channel']) . '</td><td dir="ltr">' . e((string)($r['min_version'] ?? '')) . '</td><td>' . e(fmt_size((int)$r['size_bytes'])) . '</td><td>' . ((int)$r['permissions_changed'] ? '✓ نعم' : '—') . '</td><td>' . ((int)$r['is_active'] ? '✓' : '✗') . '</td><td>' . e(fmt_date($r['created'])) . '</td><td class="acts">';
                btn_post('boards', 'toggle_release', ['id' => $r['id'], 'board_id' => $id], (int)$r['is_active'] ? 'إيقاف' : 'تفعيل');
                btn_post('boards', 'delete_release', ['id' => $r['id'], 'board_id' => $id], 'حذف', 'btn danger sm', 'حذف هذا الإصدار؟');
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else echo '<p class="muted">لا إصدارات منشورة بعد.</p>';
        echo '</div>';
        echo '<div class="panel"><h2>نشر إصدار جديد</h2>';
        f_open('boards', 'save_release', ['board_id' => $id], false, 'form');
        echo '<div class="two">';
        f_in('version', 'رقم الإصدار (مثال: 1.2.0)', '', 'text', 'dir="ltr" required placeholder="1.2.0"');
        f_in('version_code', 'رمز الإصدار الرقمي (مثال: 12)', '1', 'number', 'dir="ltr" min="1" required');
        echo '</div>';
        f_sel('release_type', 'نوع الإصدار', ['full' => 'كامل (APK يُنزَّل داخل التطبيق)', 'sub' => 'فرعي خفيف (بيانات فقط عند التشغيل)'], 'full');
        f_sel('channel', 'القناة', ['stable' => 'مستقر', 'beta' => 'تجريبي'], 'stable');
        f_in('min_version', 'الحد الأدنى المطلوب (اختياري — يفرض تحديثاً إجبارياً)', '', 'text', 'dir="ltr" placeholder="1.0.0"');
        f_in('download_url', 'رابط التنزيل المباشر (APK أو حزمة البيانات)', '', 'text', 'dir="ltr" placeholder="https://..."');
        f_in('sha256', 'بصمة SHA-256 (اختياري)', '', 'text', 'dir="ltr"');
        f_in('size_bytes', 'الحجم بالبايت (اختياري)', '0', 'number', 'dir="ltr" min="0"');
        f_chk('permissions_changed', 'تغيّرت الصلاحيات في هذا التحديث (سيُعاد طلبها داخل التطبيق)', false);
        f_in('permissions_note', 'ملاحظة الصلاحيات الجديدة', '', 'text');
        f_ta('changelog', 'سجل التغييرات', '', 3);
        f_ta('payload_json', 'حمولة JSON للتحديث الفرعي (sub فقط — اختياري)', '', 4, 'class="mono" dir="ltr"');
        f_chk('is_active', 'نشط (متاح للتطبيقات فوراً)', true);
        echo '<button class="btn" type="submit">⬆ نشر الإصدار</button></form></div>';
    }

    if ($tab === 'content') {
        $packs = table_exists('content_packs') ? rows('SELECT * FROM content_packs WHERE app_id=? ORDER BY pack_key', [$id]) : [];
        $settings = table_exists('app_settings_kv') ? rows('SELECT * FROM app_settings_kv WHERE app_id=? ORDER BY cfg_key', [$id]) : [];
        echo '<div class="panel"><h2>حزم المحتوى (' . count($packs) . ')</h2>';
        if ($packs) {
            echo '<div class="tw"><table><thead><tr><th>المفتاح</th><th>العنوان</th><th>النوع</th><th>الإصدار</th><th>نشطة</th><th></th></tr></thead><tbody>';
            foreach ($packs as $p) {
                echo '<tr><td dir="ltr"><code>' . e((string)$p['pack_key']) . '</code></td><td>' . e((string)($p['title'] ?? '')) . '</td><td>' . e((string)$p['kind']) . '</td><td dir="ltr">v' . (int)$p['version'] . '</td><td>' . ((int)$p['is_active'] ? '✓' : '✗') . '</td><td class="acts">';
                btn_post('boards', 'delete_pack', ['id' => $p['id'], 'board_id' => $id], 'حذف', 'btn danger sm', 'حذف هذه الحزمة؟');
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else echo '<p class="muted">لا حزم محتوى بعد.</p>';
        echo '</div>';
        echo '<div class="panel"><h2>إضافة / تحديث حزمة محتوى</h2>';
        f_open('boards', 'save_pack', ['board_id' => $id], false, 'form');
        f_in('pack_key', 'مفتاح الحزمة (مثال: mood_presets)', '', 'text', 'dir="ltr" required placeholder="mood_presets"');
        f_in('title', 'العنوان', '', 'text');
        f_sel('kind', 'النوع', ['data' => 'بيانات', 'config' => 'إعدادات', 'media' => 'وسائط', 'other' => 'أخرى'], 'data');
        f_in('version', 'الإصدار الرقمي', '1', 'number', 'dir="ltr" min="1"');
        f_in('file_url', 'رابط ملف الحزمة (اختياري)', '', 'text', 'dir="ltr" placeholder="https://..."');
        f_ta('payload_json', 'حمولة JSON', '{}', 5, 'class="mono" dir="ltr"');
        f_chk('is_active', 'نشطة', true);
        echo '<button class="btn" type="submit">حفظ الحزمة</button></form></div>';
        echo '<div class="panel"><h2>الإعدادات السحابية (' . count($settings) . ')</h2>';
        if ($settings) {
            echo '<div class="tw"><table><thead><tr><th>المفتاح</th><th>القيمة</th><th>التحديث</th><th></th></tr></thead><tbody>';
            foreach ($settings as $s) {
                echo '<tr><td dir="ltr"><code>' . e((string)$s['cfg_key']) . '</code></td><td dir="ltr">' . e(mb_substr((string)$s['cfg_val'], 0, 120)) . '</td><td>' . e(fmt_date($s['updated'])) . '</td><td class="acts">';
                btn_post('boards', 'delete_setting', ['id' => $s['id'], 'board_id' => $id], 'حذف', 'btn danger sm', 'حذف هذا الإعداد؟');
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else echo '<p class="muted">لا إعدادات سحابية بعد.</p>';
        echo '</div>';
        echo '<div class="panel"><h2>إضافة / تحديث إعداد سحابي</h2>';
        f_open('boards', 'save_setting', ['board_id' => $id], false, 'form');
        f_in('cfg_key', 'مفتاح الإعداد', '', 'text', 'dir="ltr" required placeholder="auth_mandatory_before_open"');
        f_ta('cfg_val', 'القيمة (نص أو JSON)', '', 3, 'class="mono" dir="ltr"');
        echo '<button class="btn" type="submit">حفظ الإعداد</button></form></div>';
    }

    if ($tab === 'licenses') {
        $lics = table_exists('board_licenses') ? rows('SELECT * FROM board_licenses WHERE board_id=? ORDER BY id DESC', [$id]) : [];
        echo '<div class="panel"><h2>تراخيص التطبيق (' . count($lics) . ')</h2>';
        if ($lics) {
            echo '<div class="tw"><table><thead><tr><th>الكود</th><th>الخطة</th><th>البريد</th><th>الحالة</th><th>الإنشاء</th><th>الانتهاء</th><th></th></tr></thead><tbody>';
            foreach ($lics as $l) {
                echo '<tr><td dir="ltr"><code>' . e((string)$l['code']) . '</code></td><td>' . e((string)($l['plan'] ?? '')) . '</td><td dir="ltr">' . e((string)($l['email'] ?? '')) . '</td><td>' . e((string)$l['status']) . '</td><td>' . e(fmt_date($l['created'])) . '</td><td>' . e($l['expires'] ? fmt_date($l['expires']) : '—') . '</td><td class="acts">';
                btn_post('boards', 'license_delete', ['id' => $l['id'], 'board_id' => $id], 'حذف', 'btn danger sm', 'حذف هذا الترخيص؟');
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else echo '<p class="muted">لا تراخيص بعد.</p>';
        echo '</div>';
        echo '<div class="panel"><h2>إنشاء ترخيص</h2>';
        f_open('boards', 'license_add', ['board_id' => $id], false, 'inline-form');
        f_in('code', 'الكود (فارغ = توليد)', '', 'text', 'dir="ltr"');
        f_in('email', 'بريد العميل', '', 'text', 'dir="ltr"');
        f_in('plan', 'الخطة', '', 'text');
        f_in('expires', 'الانتهاء (YYYY-MM-DD)', '', 'text', 'dir="ltr" placeholder="YYYY-MM-DD"');
        echo '<button class="btn sm" type="submit">إنشاء ترخيص</button></form></div>';
    }

    if ($tab === 'requirements') {
        $reqs = table_exists('app_requirements') ? rows('SELECT * FROM app_requirements WHERE app_id=? ORDER BY ' . req_order_sql(), [$id]) : [];
        echo '<div class="panel"><h2>متطلبات هذا التطبيق (' . count($reqs) . ' · ناقص: ' . $counts['missing_reqs'] . ')</h2>';
        echo '<p class="muted">مرتّبة: الناقص أولاً ثم حسب الأولوية (حرجة → عالية → متوسطة → منخفضة). اضغط «تم توفيره» عند وصول العنصر.</p>';
        if ($reqs) {
            echo '<div class="tw"><table><thead><tr><th>العنوان</th><th>الفئة</th><th>المصدر</th><th>الأولوية</th><th>الحالة</th><th></th></tr></thead><tbody>';
            foreach ($reqs as $r) {
                $prio = REQ_PRIORITIES[$r['priority']] ?? REQ_PRIORITIES['high'];
                echo '<tr><td><b>' . e((string)$r['title']) . '</b>' . (!empty($r['notes']) ? '<br><span class="muted">' . e(mb_substr((string)$r['notes'], 0, 160)) . '</span>' : '') . '</td><td>' . e(REQ_CATS[$r['category']] ?? $r['category']) . '</td><td>' . e((string)($r['source_name'] ?? '')) . '</td><td><span class="chip ' . e($prio['cls']) . '">' . e($prio['label']) . '</span></td><td><span class="chip ' . (($r['status'] ?? 'missing') === 'missing' ? 'bad' : 'ok') . '">' . e(REQ_STATUSES[$r['status']] ?? $r['status']) . '</span></td><td class="acts">';
                btn_post('boards', 'toggle_requirement', ['id' => $r['id'], 'board_id' => $id], ($r['status'] ?? 'missing') === 'missing' ? '✓ تم توفيره' : '↩ إعادة لناقص');
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else echo '<p class="muted">لا متطلبات مسجّلة بعد — تُسجَّل تلقائياً من MOTHER_PANEL_HANDOFF.md أو يدوياً من تبويب «متطلبات التطبيقات».</p>';
        echo '<div class="row"><a class="btn ghost sm" href="' . e(adm_url('requirements', ['app_id' => $id])) . '">📋 إدارة كاملة في تبويب متطلبات التطبيقات</a></div></div>';
    }

    if ($tab === 'handoff') {
        echo '<div class="panel"><h2>ملف التسليم الإلزامي: MOTHER_PANEL_HANDOFF.md</h2>';
        echo '<p class="muted">لا يُعلَن اكتمال تثبيت أي تطبيق قبل قراءة هذا الملف وتنفيذ بنوده وتسجيل النتيجة. يُستخرج تلقائياً من zip اللوحة إن وُجد، أو الصقه هنا يدوياً.</p>';
        echo '<p class="muted">الحالة الحالية: <b>' . e($handoffStatus) . '</b>' . (!empty($b['handoff_verified_at']) ? ' · آخر تحقق: ' . e(fmt_date($b['handoff_verified_at'])) : '') . '</p>';
        if (!empty($b['handoff_report'])) {
            echo '<h3>آخر تقرير تحقق</h3><pre>' . e((string)$b['handoff_report']) . '</pre>';
        }
        echo '</div>';
        echo '<div class="panel"><h2>لصق MOTHER_PANEL_HANDOFF.md</h2>';
        f_open('boards', 'save_handoff', ['board_id' => $id], false, 'form');
        f_ta('handoff_md', 'محتوى MOTHER_PANEL_HANDOFF.md (Markdown مع كتل JSON للمتطلبات والإعدادات)', (string)($b['handoff_md'] ?? ''), 12, 'class="mono" dir="ltr"');
        echo '<button class="btn" type="submit">حفظ الملف</button></form></div>';
        echo '<div class="panel"><h2>قراءة وتنفيذ وتسجيل النتيجة</h2>';
        echo '<p class="muted">سيُحلَّل الملف، تُسجَّل متطلباته في «متطلبات التطبيقات»، تُضاف إعداداته الافتراضية، ويُتحقق من الأقسام الخمسة (API، الجداول ونطاق app_id، الصلاحيات، المتطلبات، خطوات التحقق).</p>';
        echo '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="m" value="boards"><input type="hidden" name="do" value="verify_handoff"><input type="hidden" name="board_id" value="' . $id . '"><button class="btn" type="submit">▶ قراءة وتنفيذ والتحقق الآن</button></form>';
        echo '</div>';
        if (!empty($b['panel_tabs_json'])) {
            $tabs = json_decode((string)$b['panel_tabs_json'], true);
            if (is_array($tabs)) {
                echo '<div class="panel"><h2>تبويبات اللوحة المقترحة من الموديل</h2><p dir="ltr" style="text-align:left">' . e(implode(' · ', array_map(fn($t) => is_string($t) ? $t : json_encode($t, JSON_UNESCAPED_UNICODE), $tabs))) . '</p></div>';
            }
        }
    }

    if ($tab === 'settings') {
        echo '<div class="panel"><h2>إعدادات اللوحة والتوكن</h2>';
        echo '<p class="muted">التوكن الحالي (مموّه): <code dir="ltr">' . e((string)($b['api_token_hint'] ?? '—')) . '</code> — يُعرض التوكن الكامل مرة واحدة فقط عند توليده.</p>';
        echo '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="m" value="boards"><input type="hidden" name="do" value="issue_api_token"><input type="hidden" name="board_id" value="' . $id . '"><button class="btn" type="submit">🔑 توليد توكن API جديد (يُبطل القديم)</button></form>';
        if (!empty($_SESSION['new_api_token'])) {
            $tok = (string)$_SESSION['new_api_token']; unset($_SESSION['new_api_token']);
            echo '<div class="note ok">التوكن الجديد (انسخه الآن — لن يظهر مرة أخرى):<br><code dir="ltr" style="word-break:break-all">' . e($tok) . '</code></div>';
            echo '<button type="button" class="btn ghost sm" data-copy="' . e($tok) . '">📋 نسخ التوكن</button>';
        }
        echo '</div>';
        echo '<div class="panel"><h2>سياسات التطبيق</h2>';
        f_open('boards', 'save_board_extra', ['board_id' => $id], false, 'form');
        f_in('min_app_version', 'الحد الأدنى لإصدار التطبيق (min_version)', (string)($b['min_app_version'] ?? ''), 'text', 'dir="ltr" placeholder="1.0.0"');
        f_chk('online_required', 'تسجيل الدخول إلزامي قبل فتح التطبيق (أونلاين دائماً)', !empty($b['online_required']));
        f_chk('sync_enabled', 'المزامنة بين الأجهزة مفعّلة', !empty($b['sync_enabled']));
        f_chk('uses_github', 'هذا التطبيق يتعامل مع GitHub والمستودعات (يفعّل توكن GitHub المركزي لحسابات الأدمن)', !empty($b['uses_github']));
        echo '<button class="btn" type="submit">حفظ السياسات</button></form></div>';
        // Central GitHub token for apps that manage repositories: copyable any time, server-side only.
        if (function_exists('mother_github_token_box')) {
            echo mother_github_token_box(!empty($b['uses_github'])
                ? 'مفعّل لهذا التطبيق: حسابات الأدمن داخل التطبيق تستطيع طلب التوكن من الخادم، وكل حساب عادي يُرفض.'
                : 'غير مفعّل لهذا التطبيق بعد — فعّل الخيار أعلاه ثم احفظ السياسات لتتمكن حسابات الأدمن من استخدامه.');
        }
        echo '<div class="panel"><h2>روابط سريعة</h2><div class="row">';
        echo '<a class="btn ghost sm" href="' . e(adm_url('boards', ['edit' => $id])) . '">✏ البيانات الأساسية</a>';
        echo '<a class="btn ghost sm" href="' . e(adm_url('requirements', ['app_id' => $id])) . '">📋 متطلبات التطبيقات</a>';
        echo '<a class="btn ghost sm" href="' . e(adm_url('appkeys', ['board_id' => $id])) . '">🔑 مفاتيح التراخيص</a>';
        echo '</div></div>';
    }

    boards_panel_fab();
}
