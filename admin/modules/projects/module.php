<?php
declare(strict_types=1);
require_once __DIR__ . '/github.php';

const PROJ_PUBLIC_SORTS = [
    'custom' => 'المميّز والترتيب المخصّص أولاً',
    'stars' => 'الأكثر نجوماً على GitHub ★',
    'updated' => 'الأحدث تحديثاً (آخر دفع على GitHub)',
    'newest' => 'الأحدث إضافةً للموقع',
    'oldest' => 'الأقدم إضافةً',
    'name' => 'أبجدياً حسب الاسم',
];

return crud_module([
    'title' => ['ar' => 'المشاريع', 'en' => 'Projects'], 'icon' => '▣', 'group' => 'content', 'order' => 20, 'version' => 1,
    'table' => 'projects', 'one' => 'مشروع', 'new_label' => 'مشروع جديد (يدوي)',
    'schema' => fn(array $T) => [
        "CREATE TABLE IF NOT EXISTS projects (id {$T['id']}, gh_id BIGINT NULL UNIQUE, slug VARCHAR(190) UNIQUE, name VARCHAR(190), title_i18n TEXT NULL,
          description TEXT NULL, desc_i18n TEXT NULL, topics TEXT NULL, language VARCHAR(60) NULL, stars INT DEFAULT 0, forks INT DEFAULT 0, license VARCHAR(100) NULL,
          pushed_at VARCHAR(40) NULL, synced_pushed VARCHAR(40) NULL, html_url VARCHAR(255) NULL, homepage VARCHAR(255) NULL, download_url VARCHAR(255) NULL,
          image VARCHAR(255) NULL, readme {$T['big']} NULL, category VARCHAR(100) NULL, featured INT DEFAULT 0, hidden INT DEFAULT 0, locked INT DEFAULT 0,
          sort_order INT DEFAULT 0, checked_at INT DEFAULT 0, created INT, updated INT)" . $T['tail'],
        "CREATE TABLE IF NOT EXISTS sync_log (id {$T['id']}, ts INT, ok INT, summary TEXT)" . $T['tail'],
    ],
    'seed' => function (): void {
        $imgs = ['p1.jpg' => 'seed-p1.jpg', 'p2.jpg' => 'seed-p2.jpg', 'p3.jpg' => 'seed-p3.jpg'];
        foreach ($imgs as $s => $d) if (is_file(ROOT . "/assets/img/$s") && !is_file(upload_dir() . $d)) @copy(ROOT . "/assets/img/$s", upload_dir() . $d);
        $seed = json_decode((string)file_get_contents(__DIR__ . '/seed.json'), true) ?: [];
        $now = time(); $i = 0;
        foreach ($seed as $p) {
            ins('projects', [
                'slug' => unique_slug('projects', slugify($p['name'])), 'name' => $p['name'],
                'title_i18n' => json_encode($p['title'], JSON_UNESCAPED_UNICODE), 'description' => $p['desc']['en'],
                'desc_i18n' => json_encode($p['desc'], JSON_UNESCAPED_UNICODE), 'language' => $p['language'], 'image' => $p['image'],
                'html_url' => 'https://github.com/yswef', 'featured' => 1, 'locked' => 1, 'sort_order' => ++$i, 'created' => $now, 'updated' => $now,
            ]);
        }
    },
    'badge' => null,
    'widgets' => fn() => [
        ['label' => 'المشاريع', 'value' => (int)val('SELECT COUNT(*) FROM projects')],
        ['label' => 'ظاهرة للزوار', 'value' => (int)val('SELECT COUNT(*) FROM projects WHERE hidden=0')],
    ],
    'fields' => [
        'name' => ['t' => 'text', 'label' => 'الاسم (يُستخدم للرابط)', 'req' => true],
        'title_i18n' => ['t' => 'i18n', 'label' => 'العنوان'],
        'slug' => ['t' => 'slug', 'label' => 'الرابط المختصر', 'from' => 'name'],
        'description' => ['t' => 'textarea', 'label' => 'الوصف الافتراضي (يُملأ من GitHub للمشاريع المزامنة)', 'rows' => 3, 'max' => 1000],
        'desc_i18n' => ['t' => 'i18n_area', 'label' => 'وصف المشروع المترجم', 'rows' => 3, 'max' => 1000],
        'image' => ['t' => 'image', 'label' => 'صورة المشروع'],
        'language' => ['t' => 'text', 'label' => 'اللغة / التقنية', 'max' => 60],
        'topics' => ['t' => 'text', 'label' => 'الوسوم (مفصولة بفاصلة)'],
        'category' => ['t' => 'text', 'label' => 'التصنيف', 'max' => 100],
        'html_url' => ['t' => 'url', 'label' => 'رابط GitHub'],
        'homepage' => ['t' => 'url', 'label' => 'رابط الموقع / العرض'],
        'download_url' => ['t' => 'url', 'label' => 'رابط التنزيل المباشر'],
        'readme' => ['t' => 'md', 'label' => 'التوثيق / README', 'rows' => 10],
        'featured' => ['t' => 'check', 'label' => 'مميّز (يظهر في الرئيسية)'],
        'hidden' => ['t' => 'check', 'label' => 'مخفي عن الزوار'],
        'locked' => ['t' => 'check', 'label' => 'مقفل (لا تعدّله المزامنة)'],
        'sort_order' => ['t' => 'num', 'label' => 'رقم الترتيب اليدوي'],
        'created' => ['t' => 'ts_created'], 'updated' => ['t' => 'ts_updated'],
    ],
    'before_save' => function (array &$d, ?array $old): void {
        if (trim((string)($d['desc_i18n'] ?? '')) === '' && preg_match('/\p{Arabic}/u', (string)($d['description'] ?? ''))) {
            $d['desc_i18n'] = json_encode(i18n_auto_fill(['ar' => (string)$d['description']]), JSON_UNESCAPED_UNICODE);
        }
    },
    'list' => [
        'order' => 'sort_order, id DESC',
        'sorts' => [
            'default' => ['الترتيب المخصّص والمميّز', 'featured DESC, sort_order ASC, id DESC'],
            'stars' => ['الأكثر نجوماً ★', 'stars DESC, id DESC'],
            'forks' => ['الأكثر تفرّعاً (Forks)', 'forks DESC, id DESC'],
            'pushed' => ['الأحدث دفعاً على GitHub', 'pushed_at DESC, id DESC'],
            'newest' => ['الأحدث إضافةً', 'id DESC'],
            'oldest' => ['الأقدم إضافةً', 'id ASC'],
            'alpha' => ['أبجدياً (A–Z)', 'name ASC'],
        ],
        'columns' => [
            ['label' => '', 'fmt' => fn($r) => $r['image'] ? '<img class="thumb" src="' . e(upload_url($r['image'])) . '" alt="">' : ''],
            ['label' => 'الاسم', 'fmt' => fn($r) => '<b>' . e($r['name']) . '</b>' . ($r['language'] ? '<br><span class="chip">' . e($r['language']) . '</span>' : '')],
            ['label' => 'المصدر', 'fmt' => fn($r) => $r['gh_id'] ? '<span class="chip ok">GitHub</span>' : '<span class="chip">يدوي</span>'],
            ['label' => 'تطبيق', 'fmt' => function ($r): string {
                if (!table_exists('apps')) return '';
                $a = row('SELECT id, ignored FROM apps WHERE project_id=?', [$r['id']]);
                if ($a && !$a['ignored']) return '<a class="chip ok" href="' . e(adm_url('apps', ['edit' => $a['id']])) . '">⬇ تطبيق</a>';
                return '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="m" value="projects"><input type="hidden" name="do" value="make_app"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn ghost sm" type="submit">＋ لتطبيق</button></form>';
            }],
            ['label' => 'مميّز', 'fmt' => fn($r) => $r['featured'] ? '★' : ''],
            ['label' => 'مخفي', 'fmt' => fn($r) => $r['hidden'] ? '🚫' : ''],
            ['label' => 'نجوم', 'fmt' => fn($r) => '★ ' . (int)$r['stars']],
            ['label' => 'ترتيب', 'fmt' => fn($r) => (string)(int)$r['sort_order']],
        ],
    ],
    'list_top' => function (): void {
        $pending = (int)val('SELECT COUNT(*) FROM projects WHERE gh_id IS NOT NULL AND checked_at=0');
        $log = rows('SELECT * FROM sync_log ORDER BY id DESC LIMIT 5');
        $curPubSort = setting('projects_sort', 'custom');
        echo '<div class="cols" style="margin-bottom:18px">';
        echo '<div class="panel" style="margin-bottom:0"><h2>المزامنة مع GitHub</h2><p class="muted">الحساب: <b dir="ltr">' . e(gh_user() ?: '—') . '</b> · التوكن: '
           . (gh_token() !== '' ? 'مضبوط ✓' : 'غير مضبوط (60 طلب/ساعة)') . ($pending ? " · بانتظار الفحص: $pending" : '') . '</p>';
        f_open('projects', 'sync'); f_chk('force', 'إعادة فحص شاملة (README والإصدارات)', false);
        echo '<button class="btn" type="submit">🔄 مزامنة الآن</button></form>';
        if ($log) { echo '<div class="tw" style="margin-top:12px"><table>'; foreach ($log as $l) echo '<tr><td>' . e(ts_fmt($l['ts'])) . '</td><td>' . ($l['ok'] ? '✓' : '⚠') . '</td><td dir="ltr">' . e($l['summary']) . '</td></tr>'; echo '</table></div>'; }
        echo '</div>';

        echo '<div class="panel" style="margin-bottom:0"><h2>ترتيب العرض في الموقع</h2>';
        f_open('projects', 'save_display_sort');
        f_sel('projects_sort', 'الترتيب الافتراضي للزوار', PROJ_PUBLIC_SORTS, $curPubSort);
        echo '<button class="btn ghost" type="submit">حفظ</button></form></div>';
        echo '</div>';
    },
    'extra_actions' => [
        'sync' => function (): void {
            [$ok, $sum] = sync_and_log(PB('force') === 1);
            audit('sync', $sum); flash(($ok ? 'اكتملت المزامنة: ' : 'فشلت المزامنة: ') . $sum, $ok);
        },
        'make_app' => function (): void {
            $p = row('SELECT * FROM projects WHERE id=?', [PN('id')]);
            if (!$p || !table_exists('apps')) return;
            $ex = row('SELECT id FROM apps WHERE project_id=?', [$p['id']]);
            if ($ex) { upd('apps', ['ignored' => 0, 'hidden' => 0], 'id=?', [$ex['id']]); $aid = (int)$ex['id']; }
            else {
                $aid = ins('apps', [
                    'project_id' => $p['id'], 'slug' => unique_slug('apps', slugify($p['name'])),
                    'name' => $p['name'], 'description' => (string)$p['description'],
                    'platform' => detect_app_platform([], ['name' => $p['name'], 'description' => $p['description'], 'language' => $p['language'], 'topics' => explode(',', (string)$p['topics'])]),
                    'version' => extract_version_from_readme((string)$p['readme']) ?: 'latest',
                    'release_url' => (string)$p['html_url'], 'published_at' => (string)($p['pushed_at'] ?: gmdate('c')), 'created' => time(),
                ]);
                if (!empty($p['download_url']) && preg_match('#^https://#i', (string)$p['download_url'])) {
                    ins('app_files', ['app_id' => $aid, 'name' => basename((string)parse_url((string)$p['download_url'], PHP_URL_PATH)) ?: ($p['slug'] . '.zip'), 'label' => 'تنزيل مباشر', 'kind' => 'portable', 'size' => 0, 'url' => (string)$p['download_url']]);
                } elseif (!empty($p['html_url']) && preg_match('#^https://github\.com/([^/]+)/([^/]+)#i', (string)$p['html_url'], $m)) {
                    ins('app_files', ['app_id' => $aid, 'gh_asset_id' => -(int)$p['id'], 'name' => $m[2] . '-main.zip', 'label' => 'حزمة التطبيق (ZIP)', 'kind' => 'portable', 'size' => 0, 'url' => "https://github.com/{$m[1]}/{$m[2]}/archive/refs/heads/main.zip"]);
                }
            }
            audit('project_make_app', (string)$p['id']);
            flash('تم تفعيل المشروع كتطبيق قابل للتنزيل.');
            adm_back('apps', ['edit' => $aid]);
        },
        'save_display_sort' => function (): void {
            $s = array_key_exists(P('projects_sort'), PROJ_PUBLIC_SORTS) ? P('projects_sort') : 'custom';
            set_setting('projects_sort', $s);
            audit('projects_sort', $s); flash('تم تحديث طريقة ترتيب عرض المستودعات.');
        },
    ],
]);
