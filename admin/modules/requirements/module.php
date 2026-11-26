<?php
declare(strict_types=1);
/**
 * App Requirements (متطلبات التطبيقات) — Mother Panel tab with two sections:
 *  ① مصادر الأشياء (asset_sources): independent sub-sections for الصوتيات، الصور، الأيقونات، الخطوط، الفيديو، البيانات.
 *  ② متطلبات التطبيقات (app_requirements): per-app requirements with source, status (ناقص/تم توفيره),
 *     priority, notes, per-app missing counters, missing-first + priority sort, filter, 1-click mark-as-provided.
 * Tables are created by the Mother Panel core schema (core/mother_schema.php) — this module only manages rows.
 */

require_once __DIR__ . '/lib.php';

return [
    'title' => ['ar' => 'متطلبات التطبيقات', 'en' => 'App requirements'], 'icon' => '📋', 'group' => 'app', 'order' => 16, 'version' => 1,
    'widgets' => fn() => [
        ['label' => 'مصادر الأشياء', 'value' => table_exists('asset_sources') ? (int)val('SELECT COUNT(*) FROM asset_sources') : 0, 'link' => 'requirements'],
        ['label' => 'متطلبات ناقصة', 'value' => table_exists('app_requirements') ? (int)val("SELECT COUNT(*) FROM app_requirements WHERE status='missing'") : 0, 'link' => 'requirements'],
        ['label' => 'تم توفيرها', 'value' => table_exists('app_requirements') ? (int)val("SELECT COUNT(*) FROM app_requirements WHERE status='provided'") : 0, 'link' => 'requirements'],
    ],
    'badge' => fn() => table_exists('app_requirements') ? (int)val("SELECT COUNT(*) FROM app_requirements WHERE status='missing'") : 0,

    'actions' => [
        'save_source' => function (): void {
            $cat = P('category');
            if (!isset(ASSET_SOURCE_CATS[$cat])) { flash('فئة المصدر غير صالحة.', false); adm_back('requirements'); }
            $id = PN('id');
            $d = [
                'category'     => $cat,
                'name'         => mb_substr(P('name'), 0, 190),
                'url'          => mb_substr(P('url'), 0, 500),
                'license_note' => mb_substr(P('license_note'), 0, 190),
                'notes'        => P('notes'),
                'sort_order'   => PN('sort_order'),
            ];
            if ($d['name'] === '') { flash('اسم المصدر مطلوب.', false); adm_back('requirements'); }
            if ($id) { upd('asset_sources', $d, 'id=?', [$id]); flash('تم تحديث المصدر.'); }
            else { $d['created'] = time(); ins('asset_sources', $d); flash('أُضيف المصدر.'); }
            audit('req_source_save', "$cat:{$d['name']}");
            adm_back('requirements', ['cat' => $cat]);
        },
        'delete_source' => function (): void {
            $s = row('SELECT * FROM asset_sources WHERE id=?', [PN('id')]);
            if ($s) {
                q('UPDATE app_requirements SET source_id=NULL WHERE source_id=?', [$s['id']]);
                q('DELETE FROM asset_sources WHERE id=?', [$s['id']]);
                audit('req_source_delete', (string)$s['id']);
            }
            flash('حُذف المصدر.'); adm_back('requirements', ['cat' => $s['category'] ?? '']);
        },
        'save_requirement' => function (): void {
            $appId = PN('app_id');
            if (!$appId || !row('SELECT id FROM boards WHERE id=?', [$appId])) { flash('اختر التطبيق أولاً.', false); adm_back('requirements'); }
            $cat = P('category'); if (!isset(REQ_CATS[$cat])) $cat = 'other';
            $prio = P('priority'); if (!isset(REQ_PRIORITIES[$prio])) $prio = 'high';
            $status = P('status') === 'provided' ? 'provided' : 'missing';
            $srcId = PN('source_id') ?: null;
            $srcName = mb_substr(P('source_name'), 0, 255);
            $srcUrl = mb_substr(P('source_url'), 0, 500);
            if ($srcId && table_exists('asset_sources')) {
                $sr = row('SELECT name, url FROM asset_sources WHERE id=?', [$srcId]);
                if ($sr) { if ($srcName === '') $srcName = (string)$sr['name']; if ($srcUrl === '') $srcUrl = (string)$sr['url']; }
            }
            $id = PN('id');
            $now = time();
            $d = [
                'app_id'      => $appId,
                'category'    => $cat,
                'title'       => mb_substr(P('title'), 0, 255),
                'details'     => P('details'),
                'source_id'   => $srcId,
                'source_name' => $srcName,
                'source_url'  => $srcUrl,
                'priority'    => $prio,
                'status'      => $status,
                'notes'       => P('notes'),
                'provided_at' => $status === 'provided' ? $now : null,
                'updated'     => $now,
            ];
            if ($d['title'] === '') { flash('عنوان المتطلب مطلوب.', false); adm_back('requirements', ['app_id' => $appId]); }
            if ($id) { upd('app_requirements', $d, 'id=? AND app_id=?', [$id, $appId]); flash('تم تحديث المتطلب.'); }
            else { $d['created'] = $now; ins('app_requirements', $d); flash('أُضيف المتطلب.'); }
            audit('req_save', "$appId:{$d['title']}");
            adm_back('requirements', ['app_id' => $appId]);
        },
        'toggle_requirement' => function (): void {
            $r = row('SELECT * FROM app_requirements WHERE id=?', [PN('id')]);
            if ($r) {
                $st = ($r['status'] ?? 'missing') === 'missing' ? 'provided' : 'missing';
                upd('app_requirements', ['status' => $st, 'provided_at' => $st === 'provided' ? time() : null, 'updated' => time()], 'id=?', [$r['id']]);
                audit('req_toggle', "$r[id]:$st");
            }
            flash('تم تحديث حالة المتطلب.'); adm_back('requirements', ['app_id' => PN('app_id')]);
        },
        'delete_requirement' => function (): void {
            q('DELETE FROM app_requirements WHERE id=?', [PN('id')]);
            audit('req_delete', (string)PN('id')); flash('حُذف المتطلب.');
            adm_back('requirements', ['app_id' => PN('app_id')]);
        },
    ],

    'view' => function (array $mod): void {
        adm_h('متطلبات التطبيقات', '① مصادر الأشياء (صوتيات، صور، أيقونات، خطوط، فيديو، بيانات) · ② متطلبات كل تطبيق مع المصدر والحالة والأولوية والملاحظة.');
        $section = (string)($_GET['section'] ?? 'requirements');
        if ($section !== 'sources' && $section !== 'requirements') $section = 'requirements';
        $cat = (string)($_GET['cat'] ?? 'audio');
        if (!isset(ASSET_SOURCE_CATS[$cat])) $cat = 'audio';
        $appFilter = (int)($_GET['app_id'] ?? 0);
        $statusFilter = (string)($_GET['status'] ?? '');
        if (!in_array($statusFilter, ['', 'missing', 'provided'], true)) $statusFilter = '';
        $q = trim((string)($_GET['q'] ?? ''));

        // Section switcher
        echo '<div class="filter-bar" style="margin-bottom:14px">';
        echo '<a class="btn ' . ($section === 'requirements' ? '' : 'ghost') . ' sm" href="' . e(adm_url('requirements', ['section' => 'requirements'])) . '">② متطلبات التطبيقات</a>';
        echo '<a class="btn ' . ($section === 'sources' ? '' : 'ghost') . ' sm" href="' . e(adm_url('requirements', ['section' => 'sources', 'cat' => $cat])) . '">① مصادر الأشياء</a>';
        echo '</div>';

        if ($section === 'sources') {
            // ---- Section 1: asset sources with independent sub-sections per category ----
            echo '<div class="filter-bar" style="margin-bottom:14px">';
            foreach (ASSET_SOURCE_CATS as $ck => $ci) {
                $cnt = table_exists('asset_sources') ? (int)val('SELECT COUNT(*) FROM asset_sources WHERE category=?', [$ck]) : 0;
                echo '<a class="btn ' . ($ck === $cat ? '' : 'ghost') . ' sm" href="' . e(adm_url('requirements', ['section' => 'sources', 'cat' => $ck])) . '">' . e($ci['icon'] . ' ' . $ci['label'] . ' (' . $cnt . ')') . '</a>';
            }
            echo '</div>';

            $sources = table_exists('asset_sources') ? rows('SELECT * FROM asset_sources WHERE category=? ORDER BY sort_order, id', [$cat]) : [];
            $ci = ASSET_SOURCE_CATS[$cat];
            echo '<div class="panel"><h2>' . e($ci['icon'] . ' مصادر ' . $ci['label']) . ' (' . count($sources) . ')</h2>';
            if ($sources) {
                echo '<div class="tw"><table><thead><tr><th>الاسم</th><th>الرابط</th><th>الترخيص</th><th>ملاحظات</th><th>الترتيب</th><th></th></tr></thead><tbody>';
                foreach ($sources as $s) {
                    echo '<tr><td><b>' . e((string)$s['name']) . '</b></td><td dir="ltr">' . (!empty($s['url']) ? '<a href="' . e((string)$s['url']) . '" target="_blank" rel="noopener">' . e(mb_substr((string)$s['url'], 0, 60)) . ' ↗</a>' : '—') . '</td><td>' . e((string)($s['license_note'] ?? '')) . '</td><td>' . e(mb_substr((string)($s['notes'] ?? ''), 0, 160)) . '</td><td dir="ltr">' . (int)$s['sort_order'] . '</td><td class="acts">';
                    btn_post('requirements', 'delete_source', ['id' => $s['id']], 'حذف', 'btn danger sm', 'حذف هذا المصدر؟');
                    echo '</td></tr>';
                }
                echo '</tbody></table></div>';
            } else echo '<p class="muted">لا مصادر في هذه الفئة بعد — أضف أول مصدر من النموذج بالأسفل.</p>';
            echo '</div>';

            echo '<div class="panel"><h2>إضافة مصدر جديد — ' . e($ci['label']) . '</h2>';
            f_open('requirements', 'save_source', [], false, 'form');
            f_sel('category', 'الفئة', array_map(fn($c) => $c['icon'] . ' ' . $c['label'], ASSET_SOURCE_CATS), $cat);
            f_in('name', 'اسم المصدر *', '', 'text', 'required maxlength="190"');
            f_in('url', 'الرابط', '', 'text', 'dir="ltr" placeholder="https://..."');
            f_in('license_note', 'ملاحظة الترخيص', '', 'text');
            f_ta('notes', 'ملاحظات الاستخدام', '', 2);
            f_in('sort_order', 'الترتيب', '0', 'number', 'dir="ltr" min="0"');
            echo '<button class="btn" type="submit">＋ إضافة المصدر</button></form></div>';

            // All categories overview
            echo '<div class="panel"><h2>نظرة شاملة على كل الفئات</h2><div class="kpis">';
            foreach (ASSET_SOURCE_CATS as $ck => $ci2) {
                $cnt = table_exists('asset_sources') ? (int)val('SELECT COUNT(*) FROM asset_sources WHERE category=?', [$ck]) : 0;
                echo '<a class="kpi" href="' . e(adm_url('requirements', ['section' => 'sources', 'cat' => $ck])) . '"><b>' . $cnt . '</b><span>' . e($ci2['icon'] . ' ' . $ci2['label']) . '</span></a>';
            }
            echo '</div></div>';
        } else {
            // ---- Section 2: per-app requirements ----
            $boards = table_exists('boards') ? rows('SELECT id, name, app_type FROM boards ORDER BY name') : [];
            $missingByApp = req_missing_counts();

            // Per-app missing counters strip
            echo '<div class="filter-bar" style="margin-bottom:14px">';
            echo '<a class="btn ' . ($appFilter === 0 ? '' : 'ghost') . ' sm" href="' . e(adm_url('requirements', ['section' => 'requirements'])) . '">كل التطبيقات</a>';
            foreach ($boards as $b) {
                $mc = $missingByApp[(int)$b['id']] ?? 0;
                echo '<a class="btn ' . ($appFilter === (int)$b['id'] ? '' : 'ghost') . ' sm" href="' . e(adm_url('requirements', ['section' => 'requirements', 'app_id' => (int)$b['id']])) . '">' . e((string)$b['name']) . ($mc > 0 ? ' (' . $mc . ' ناقص)' : ' ✓') . '</a>';
            }
            echo '</div>';

            // Filters
            echo '<form method="get" class="filter-bar"><input type="hidden" name="m" value="requirements"><input type="hidden" name="section" value="requirements">';
            if ($appFilter) echo '<input type="hidden" name="app_id" value="' . $appFilter . '">';
            echo '<input type="search" name="q" value="' . e($q) . '" placeholder="🔎 بحث في المتطلبات…">';
            echo '<select name="status" onchange="this.form.submit()"><option value="">كل الحالات</option>';
            foreach (REQ_STATUSES as $sk => $sl) echo '<option value="' . $sk . '"' . ($statusFilter === $sk ? ' selected' : '') . '>' . e($sl) . '</option>';
            echo '</select><button class="btn ghost sm" type="submit">تصفية</button>';
            if ($q !== '' || $statusFilter !== '') echo '<a class="btn ghost sm" href="' . e(adm_url('requirements', ['section' => 'requirements', 'app_id' => $appFilter])) . '">إعادة تعيين</a>';
            echo '</form>';

            $where = '1=1'; $p = [];
            if ($appFilter) { $where .= ' AND r.app_id=?'; $p[] = $appFilter; }
            if ($statusFilter !== '') { $where .= ' AND r.status=?'; $p[] = $statusFilter; }
            if ($q !== '') { $where .= ' AND (r.title LIKE ? OR r.notes LIKE ? OR r.source_name LIKE ?)'; $p = array_merge($p, ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%']); }
            $reqs = table_exists('app_requirements') ? rows("SELECT r.*, b.name AS board_name FROM app_requirements r LEFT JOIN boards b ON b.id=r.app_id WHERE $where ORDER BY " . req_order_sql('r'), $p) : [];
            $totalMissing = table_exists('app_requirements') ? (int)val("SELECT COUNT(*) FROM app_requirements WHERE status='missing'" . ($appFilter ? ' AND app_id=?' : ''), $appFilter ? [$appFilter] : []) : 0;

            echo '<div class="panel"><h2>المتطلبات (' . count($reqs) . ' · ناقص: ' . $totalMissing . ')</h2>';
            echo '<p class="muted">الترتيب: الناقص أولاً، ثم الأولوية (حرجة → عالية → متوسطة → منخفضة). اضغط «✓ تم توفيره» عند وصول العنصر.</p>';
            if ($reqs) {
                echo '<div class="tw"><table><thead><tr><th>المتطلب</th><th>التطبيق</th><th>الفئة</th><th>المصدر</th><th>الأولوية</th><th>الحالة</th><th></th></tr></thead><tbody>';
                foreach ($reqs as $r) {
                    $prio = REQ_PRIORITIES[$r['priority']] ?? REQ_PRIORITIES['high'];
                    $isMissing = ($r['status'] ?? 'missing') === 'missing';
                    echo '<tr><td><b>' . e((string)$r['title']) . '</b>' . (!empty($r['details']) ? '<br><span class="muted">' . e(mb_substr((string)$r['details'], 0, 140)) . '</span>' : '') . (!empty($r['notes']) ? '<br><span class="muted">📝 ' . e(mb_substr((string)$r['notes'], 0, 140)) . '</span>' : '') . (!empty($r['from_handoff']) ? ' <span class="chip">📦 من ملف التسليم</span>' : '') . '</td>';
                    echo '<td>' . e((string)($r['board_name'] ?? ('#' . $r['app_id']))) . '</td>';
                    echo '<td>' . e(REQ_CATS[$r['category']] ?? $r['category']) . '</td>';
                    echo '<td>' . e((string)($r['source_name'] ?? '')) . (!empty($r['source_url']) ? ' <a href="' . e((string)$r['source_url']) . '" target="_blank" rel="noopener">↗</a>' : '') . '</td>';
                    echo '<td><span class="chip ' . e($prio['cls']) . '">' . e($prio['label']) . '</span></td>';
                    echo '<td><span class="chip ' . ($isMissing ? 'bad' : 'ok') . '">' . e(REQ_STATUSES[$r['status']] ?? $r['status']) . '</span></td>';
                    echo '<td class="acts">';
                    btn_post('requirements', 'toggle_requirement', ['id' => $r['id'], 'app_id' => $r['app_id']], $isMissing ? '✓ تم توفيره' : '↩ إعادة لناقص');
                    btn_post('requirements', 'delete_requirement', ['id' => $r['id'], 'app_id' => $r['app_id']], 'حذف', 'btn danger sm', 'حذف هذا المتطلب؟');
                    echo '</td></tr>';
                }
                echo '</tbody></table></div>';
            } else echo '<p class="muted">لا متطلبات تطابق الفلتر الحالي — أضف متطلباً من النموذج بالأسفل أو انتظر تسجيله تلقائياً من MOTHER_PANEL_HANDOFF.md.</p>';
            echo '</div>';

            echo '<div class="panel"><h2>إضافة متطلب جديد</h2>';
            f_open('requirements', 'save_requirement', [], false, 'form');
            $boardOpts = req_boards_opts(); unset($boardOpts[0]);
            if (!$boardOpts) $boardOpts = [1 => 'لا توجد لوحات بعد'];
            f_sel('app_id', 'التطبيق *', $boardOpts, $appFilter);
            f_sel('category', 'الفئة', REQ_CATS, 'other');
            f_in('title', 'عنوان المتطلب *', '', 'text', 'required maxlength="255"');
            f_ta('details', 'تفاصيل إضافية', '', 2);
            f_sel('source_id', 'مصدر مرتبط (من مصادر الأشياء)', req_source_opts(), 0);
            f_in('source_name', 'اسم المصدر (يدوي)', '', 'text', 'maxlength="255"');
            f_in('source_url', 'رابط المصدر', '', 'text', 'dir="ltr" placeholder="https://..."');
            f_sel('priority', 'الأولوية', array_map(fn($p) => $p['label'], REQ_PRIORITIES), 'high');
            f_sel('status', 'الحالة', REQ_STATUSES, 'missing');
            f_ta('notes', 'ملاحظة', '', 2);
            echo '<button class="btn" type="submit">＋ إضافة المتطلب</button></form></div>';
        }
        req_fab();
    },
];
