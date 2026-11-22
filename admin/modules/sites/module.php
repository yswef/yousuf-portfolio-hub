<?php
declare(strict_types=1);

/** Identity colors copied straight from the live stylesheet (so the button never goes stale). */
function sites_brand_css(): string {
    $css = (string)@file_get_contents(ROOT . '/assets/css/style.css');
    $light = preg_match('/:root\s*\{(.*?)\}/s', $css, $a) ? trim($a[1]) : '--bg:#f4f1e8;--text:#1c1c24;--gold:#e8b923;--purple:#7c4dff';
    $dark = preg_match('/html\.dark\s*\{(.*?)\}/s', $css, $b) ? trim($b[1]) : '--bg:#0a0a10;--text:#f2efe4';
    return "/* ألوان هوية الموقع — الوضع الفاتح */\n:root{" . $light . "}\n/* الوضع الداكن (أضف class=\"dark\" على html) */\nhtml.dark{" . $dark . "}\n/* الخط: Cairo */";
}
function sites_ascii_slug(string $t): string {
    $s = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($t)), '-');
    return $s !== '' ? substr($s, 0, 60) : 'site-' . gmdate('ymd-His');
}
function sites_copy_btn(string $text, string $label): void {
    echo '<button type="button" class="btn ghost sm" data-copy="' . e($text) . '">' . e($label) . '</button>';
}
/** Stores the uploaded zip/html into data/sites/<slug>/ (wiping old content when replacing). Returns a status message. */
function sites_store_upload(array $f, string $slug, string $pageTitle): string {
    $name = (string)($f['name'] ?? ''); $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['zip', 'html', 'htm'], true)) throw new RuntimeException('الصيغ المدعومة: zip أو html.');
    $dir = site_dir($slug); rm_tree($dir); mkdir($dir, 0755, true);
    if ($ext === 'zip') { [$n, $skip] = site_extract_zip($f['tmp_name'], $dir); return "تم فك $n ملف" . ($skip ? ' · تم تجاهل ' . count($skip) . ' ملف غير مسموح (php/تنفيذية/مخفية…)' : ''); }
    if ($f['size'] > 20 * 1048576) throw new RuntimeException('ملف html كبير جداً (الحد 20MB) — اضغطه zip.');
    move_uploaded_file($f['tmp_name'], $dir . '/index.html');
    return 'تم حفظ الصفحة';
}

return [
    'title' => ['ar' => 'المواقع المنشورة', 'en' => 'Hosted sites'], 'icon' => '🌐', 'group' => 'content', 'order' => 35, 'version' => 1,
    'schema' => fn(array $T) => [
        "CREATE TABLE IF NOT EXISTS sites (id {$T['id']}, slug VARCHAR(80) UNIQUE, title VARCHAR(190), is_public INT DEFAULT 0, colors TEXT NULL, created INT)" . $T['tail'],
        "CREATE TABLE IF NOT EXISTS site_pages (id {$T['id']}, site_id INT, file VARCHAR(255), title VARCHAR(190), locked INT DEFAULT 0, sort INT DEFAULT 0)" . $T['tail'],
        "CREATE INDEX idx_sp_site ON site_pages (site_id)",
    ],
    'widgets' => fn() => [['label' => 'المواقع المنشورة', 'value' => (int)val('SELECT COUNT(*) FROM sites')]],
    'actions' => [
        'site_upload' => function (): void {
            $id = PN('id'); $f = $_FILES['site_file'] ?? null;
            if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK) { flash('لم يُرفع الملف (تحقق من حدّ الرفع في الاستضافة: ' . ini_get('upload_max_filesize') . ').', false); adm_back('sites', $id ? ['edit' => $id] : []); }
            $site = $id ? row('SELECT * FROM sites WHERE id=?', [$id]) : null;
            $title = mb_substr(P('title'), 0, 190);
            if (!$site && $title === '') { flash('اسم الموقع مطلوب.', false); adm_back('sites'); }
            $slug = $site['slug'] ?? unique_slug('sites', sites_ascii_slug(P('slug') ?: $title));
            try { $msg = sites_store_upload($f, $slug, P('page_title')); }
            catch (Throwable $e) { rm_tree(site_dir($slug)); flash('فشل الرفع: ' . $e->getMessage(), false); adm_back('sites', $id ? ['edit' => $id] : []); }
            if (!$site) $id = ins('sites', ['slug' => $slug, 'title' => $title, 'is_public' => PB('is_public'), 'created' => time()]);
            site_sync_pages($id, $slug);
            if (P('page_title') !== '' && strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'zip') upd('site_pages', ['title' => mb_substr(P('page_title'), 0, 190)], 'site_id=? AND file=?', [$id, 'index.html']);
            audit('site_upload', $slug); flash($msg . '.'); adm_back('sites', ['edit' => $id]);
        },
        'site_save' => function (): void {
            $id = PN('id');
            if (!row('SELECT id FROM sites WHERE id=?', [$id])) return;
            upd('sites', ['title' => mb_substr(P('title'), 0, 190) ?: 'site', 'is_public' => PB('is_public'), 'colors' => mb_substr(P('colors'), 0, 4000)], 'id=?', [$id]);
            foreach ((array)($_POST['pt'] ?? []) as $pid => $t) upd('site_pages', ['title' => mb_substr(trim((string)$t), 0, 190), 'locked' => isset($_POST['pl'][$pid]) ? 1 : 0, 'sort' => (int)($_POST['ps'][$pid] ?? 0)], 'id=? AND site_id=?', [(int)$pid, $id]);
            audit('site_save', (string)$id); flash('تم الحفظ.'); adm_back('sites', ['edit' => $id]);
        },
        'site_delete' => function (): void {
            $s = row('SELECT * FROM sites WHERE id=?', [PN('id')]);
            if (!$s) return;
            rm_tree(site_dir($s['slug'])); q('DELETE FROM site_pages WHERE site_id=?', [$s['id']]); q('DELETE FROM sites WHERE id=?', [$s['id']]);
            audit('site_delete', $s['slug']); flash('تم حذف الموقع وملفاته.');
        },
    ],
    'view' => function (array $mod): void {
        adm_h('المواقع المنشورة', 'مواقع توثيق وشروحات (html/zip) تُخزَّن في مجلد خاص لكل موقع وتظهر في صفحة «التوثيق». ملفات php لا تُنفَّذ ولا تُخزَّن.');
        $lim = 'حدّ الرفع في استضافتك: ' . ini_get('upload_max_filesize') . ' (post: ' . ini_get('post_max_size') . ')';
        if (isset($_GET['edit'])) { sites_edit((int)$_GET['edit'], $lim); return; }
        echo '<div class="panel"><h2>نشر موقع جديد</h2>'; f_open('sites', 'site_upload', [], true, 'form');
        f_in('title', 'اسم الموقع *', '', 'text', 'required maxlength="190"');
        f_in('site_file', 'الملف: zip (موقع كامل) أو html (صفحة واحدة)', '', 'file', 'required accept=".zip,.html,.htm"');
        f_in('page_title', 'اسم الصفحة الظاهر (لملف html الواحد)', '', 'text', 'maxlength="190"');
        f_chk('is_public', 'قابل للنشر للجميع (وإلا يظهر مقفلاً ولا يفتحه إلا المدير)', false);
        echo '<p class="muted hint">' . e($lim) . '</p><div class="row"><button class="btn" type="submit">رفع ونشر</button>'; sites_copy_btn(sites_brand_css(), '🎨 نسخ ألوان الهوية'); echo '</div></form></div>';
        $sites = rows('SELECT s.*, (SELECT COUNT(*) FROM site_pages p WHERE p.site_id=s.id) AS pc FROM sites s ORDER BY s.id DESC');
        if (!$sites) { echo '<div class="panel"><p class="muted">لا مواقع بعد.</p></div>'; return; }
        echo '<div class="panel tw"><table><thead><tr><th>الموقع</th><th>الحالة</th><th>الصفحات</th><th></th></tr></thead><tbody>';
        foreach ($sites as $s) {
            $u = site_origin() . '/s/' . $s['slug'] . '/';
            echo '<tr><td><b>' . e($s['title']) . '</b><br><a dir="ltr" href="' . e($u) . '" target="_blank" rel="noopener">/s/' . e($s['slug']) . '/</a></td><td>' . ($s['is_public'] ? '🌐 عام' : '🔒 خاص') . '</td><td>' . (int)$s['pc'] . '</td><td class="acts">';
            echo '<a class="btn ghost sm" href="' . adm_url('sites', ['edit' => $s['id']]) . '">تعديل</a>'; sites_copy_btn($u, 'نسخ الرابط');
            btn_post('sites', 'site_delete', ['id' => $s['id']], 'حذف', 'btn danger sm', 'حذف الموقع وكل ملفاته نهائياً؟'); echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    },
];

function sites_edit(int $id, string $lim): void {
    $s = row('SELECT * FROM sites WHERE id=?', [$id]);
    if (!$s) { echo '<div class="note bad">غير موجود.</div>'; return; }
    echo '<div class="panel"><h2>' . e($s['title']) . '</h2>'; f_open('sites', 'site_save', ['id' => $id], false, 'form');
    f_in('title', 'اسم الموقع', $s['title'], 'text', 'required maxlength="190"');
    f_chk('is_public', 'قابل للنشر للجميع', !empty($s['is_public']));
    f_ta('colors', 'ألوان/هوية خاصة بهذا الموقع (اختياري — مثلاً لتوثيق تطبيق له ألوانه)', $s['colors'] ?? '', 4, 'class="mono" dir="ltr"');
    echo '<h3>الصفحات</h3><p class="muted hint">الاسم هو ما يظهر في الواجهة. «مقفلة» تخفي الصفحة عن الزوار حتى لو الموقع عام.</p><div class="tw"><table><thead><tr><th>الملف</th><th>الاسم الظاهر</th><th>ترتيب</th><th>مقفلة</th></tr></thead><tbody>';
    foreach (rows('SELECT * FROM site_pages WHERE site_id=? ORDER BY sort, id', [$id]) as $p)
        echo '<tr><td dir="ltr"><a href="' . e(url('s/' . $s['slug'] . '/' . $p['file'])) . '" target="_blank" rel="noopener">' . e($p['file']) . '</a></td><td><input name="pt[' . (int)$p['id'] . ']" value="' . e($p['title']) . '" maxlength="190"></td>'
           . '<td><input type="number" name="ps[' . (int)$p['id'] . ']" value="' . (int)$p['sort'] . '" style="width:70px"></td><td><input type="checkbox" name="pl[' . (int)$p['id'] . ']" value="1"' . ($p['locked'] ? ' checked' : '') . '></td></tr>';
    echo '</tbody></table></div><div class="row"><button class="btn" type="submit">حفظ</button><a class="btn ghost" href="' . adm_url('sites') . '">رجوع</a>';
    sites_copy_btn(sites_brand_css(), '🎨 نسخ ألوان الهوية'); if (!empty($s['colors'])) sites_copy_btn((string)$s['colors'], '🎨 نسخ ألوان هذا الموقع'); echo '</div></form></div>';
    echo '<div class="panel"><h2>استبدال المحتوى</h2><p class="muted">يمسح ملفات الموقع ويرفع الجديدة؛ أسماء الصفحات المعدّلة تبقى لنفس أسماء الملفات.</p>'; f_open('sites', 'site_upload', ['id' => $id], true, 'form');
    f_in('site_file', 'zip أو html', '', 'file', 'required accept=".zip,.html,.htm"'); echo '<p class="muted hint">' . e($lim) . '</p><button class="btn" type="submit">استبدال</button></form></div>';
}
