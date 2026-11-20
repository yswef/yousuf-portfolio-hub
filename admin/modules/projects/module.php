<?php
declare(strict_types=1);
require_once __DIR__ . '/github.php';

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
        'title_i18n' => ['t' => 'i18n', 'label' => 'العنوان (ترجمات اختيارية)'],
        'slug' => ['t' => 'slug', 'label' => 'الرابط المختصر', 'from' => 'name'],
        'description' => ['t' => 'textarea', 'label' => 'الوصف الافتراضي (يُملأ من GitHub للمشاريع المزامنة)', 'rows' => 3, 'max' => 1000],
        'desc_i18n' => ['t' => 'i18n_area', 'label' => 'ترجمات الوصف', 'rows' => 3, 'max' => 1000],
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
        'sort_order' => ['t' => 'num', 'label' => 'الترتيب'],
        'created' => ['t' => 'ts_created'], 'updated' => ['t' => 'ts_updated'],
    ],
    'list' => [
        'order' => 'sort_order, id DESC',
        'columns' => [
            ['label' => '', 'fmt' => fn($r) => $r['image'] ? '<img class="thumb" src="' . e(upload_url($r['image'])) . '" alt="">' : ''],
            ['label' => 'الاسم', 'fmt' => fn($r) => '<b>' . e($r['name']) . '</b>'],
            ['label' => 'المصدر', 'fmt' => fn($r) => $r['gh_id'] ? 'GitHub' : 'يدوي'],
            ['label' => 'مميّز', 'fmt' => fn($r) => $r['featured'] ? '★' : ''],
            ['label' => 'مخفي', 'fmt' => fn($r) => $r['hidden'] ? '🚫' : ''],
            ['label' => 'نجوم', 'fmt' => fn($r) => (string)(int)$r['stars']],
        ],
    ],
    'list_top' => function (): void {
        $pending = (int)val('SELECT COUNT(*) FROM projects WHERE gh_id IS NOT NULL AND checked_at=0');
        $log = rows('SELECT * FROM sync_log ORDER BY id DESC LIMIT 5');
        echo '<div class="panel"><h2>المزامنة مع GitHub (يدوية)</h2><p class="muted">المستخدم: <b dir="ltr">' . e(gh_user() ?: '—') . '</b> · التوكن: '
           . (gh_token() !== '' ? 'مضبوط' : 'غير مضبوط (حد 60 طلب/ساعة)') . ($pending ? " · مشاريع لم تُفحص بعد: $pending (اضغط مزامنة مرة أخرى)" : '') . '</p>';
        f_open('projects', 'sync'); f_chk('force', 'مزامنة كاملة (إعادة جلب README والإصدارات)', false);
        echo '<button class="btn" type="submit">مزامنة الآن</button></form>';
        if ($log) { echo '<div class="tw" style="margin-top:12px"><table>'; foreach ($log as $l) echo '<tr><td>' . e(ts_fmt($l['ts'])) . '</td><td>' . ($l['ok'] ? '✓' : '⚠') . '</td><td dir="ltr">' . e($l['summary']) . '</td></tr>'; echo '</table></div>'; }
        echo '<p class="muted" style="margin-top:10px">مزامنة تلقائية اختيارية: أضف Cron Job في هوستنجر يشغّل <code dir="ltr">php ' . e(ROOT) . '/cron_sync.php</code> (راجع docs/DEPLOY.md).</p></div>';
    },
    'extra_actions' => [
        'sync' => function (): void {
            [$ok, $sum] = sync_and_log(PB('force') === 1);
            audit('sync', $sum); flash(($ok ? 'اكتملت المزامنة: ' : 'فشلت المزامنة: ') . $sum, $ok);
        },
    ],
]);
