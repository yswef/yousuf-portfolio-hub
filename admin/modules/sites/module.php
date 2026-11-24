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
    return $s !== '' ? substr($s, 0, 60) : 'site-' . bin2hex(random_bytes(3));
}
function sites_copy_btn(string $text, string $label): void {
    echo '<button type="button" class="btn ghost sm" data-copy="' . e($text) . '">' . e($label) . '</button>';
}
/** $_FILES['x'] with or without multiple="multiple" → list of single-file arrays (empty slots dropped). */
function sites_files(?array $f): array {
    if (!$f || !isset($f['name'])) return [];
    if (!is_array($f['name'])) return [$f];
    $out = [];
    foreach ($f['name'] as $i => $n) $out[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'size' => $f['size'][$i], 'error' => $f['error'][$i]];
    return array_values(array_filter($out, fn($x) => (int)$x['error'] !== UPLOAD_ERR_NO_FILE));
}
function sites_title_from_name(string $name): string {
    $t = trim((string)preg_replace('/[\s_-]+/u', ' ', pathinfo($name, PATHINFO_FILENAME)));
    return mb_substr($t !== '' ? $t : 'site', 0, 190);
}
/** Extracts a zip (or stores a single html) into a staging folder, then swaps it in, so a failed upload never wipes an existing site. Returns [kind, files_written, skipped[]]. */
function sites_store_upload(array $f, string $slug): array {
    $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['zip', 'html', 'htm'], true)) throw new RuntimeException('Unsupported file type (zip, html or htm only).');
    $dir = site_dir($slug); $tmp = $dir . '.new';
    rm_tree($tmp); mkdir($tmp, 0755, true);
    try {
        $skipped = [];
        if ($ext === 'zip') [$n, $skipped] = site_extract_zip($f['tmp_name'], $tmp);
        else {
            if ($f['size'] > 20 * 1048576) throw new RuntimeException('HTML file is too large (limit 20MB), upload it as zip.');
            if (!move_uploaded_file($f['tmp_name'], $tmp . '/index.html')) throw new RuntimeException('Could not store the uploaded file.');
            $n = 1;
        }
        if (!site_scan_pages($tmp)) throw new RuntimeException('No html pages found in the upload.');
    } catch (Throwable $e) { rm_tree($tmp); throw $e; }
    rm_tree($dir); rename($tmp, $dir);
    return [$ext === 'zip' ? 'site' : 'page', $n, $skipped];
}
function sites_report(string $title, int $n, array $skipped): string {
    $php = count(array_filter($skipped, fn($x) => preg_match('/\.(php\d?|phtml)$/i', $x)));
    return $title . ': ' . $n . ' ملف' . ($skipped ? ' · تم تجاهل ' . count($skipped) . ' ملف غير مسموح' : '') . ($php ? " ($php منها php — لا يُنفَّذ على الموقع)" : '');
}

return [
    'title' => ['ar' => 'المواقع المنشورة', 'en' => 'Hosted sites'], 'icon' => '🌐', 'group' => 'content', 'order' => 35, 'version' => 2,
    'schema' => fn(array $T) => [
        "CREATE TABLE IF NOT EXISTS sites (id {$T['id']}, slug VARCHAR(80) UNIQUE, title VARCHAR(190), is_public INT DEFAULT 0, colors TEXT NULL, created INT, description TEXT NULL, kind VARCHAR(10) DEFAULT 'site', entry VARCHAR(255) NULL, sort INT DEFAULT 0)" . $T['tail'],
        "ALTER TABLE sites ADD COLUMN description TEXT NULL",
        "ALTER TABLE sites ADD COLUMN kind VARCHAR(10) DEFAULT 'site'",
        "ALTER TABLE sites ADD COLUMN entry VARCHAR(255) NULL",
        "ALTER TABLE sites ADD COLUMN sort INT DEFAULT 0",
        "CREATE TABLE IF NOT EXISTS site_pages (id {$T['id']}, site_id INT, file VARCHAR(255), title VARCHAR(190), locked INT DEFAULT 0, sort INT DEFAULT 0)" . $T['tail'],
        "CREATE INDEX idx_sp_site ON site_pages (site_id)",
    ],
    'widgets' => fn() => [['label' => 'المواقع المنشورة', 'value' => (int)val('SELECT COUNT(*) FROM sites')]],
    'actions' => [
        'site_upload' => function (): void {
            $id = PN('id'); $site = $id ? row('SELECT * FROM sites WHERE id=?', [$id]) : null;
            $files = sites_files($_FILES['site_file'] ?? null);
            $back = fn() => adm_back('sites', $site ? ['edit' => $id] : []);
            if (!$files) { flash('لم يُرفع أي ملف (تحقق من حدّ الرفع في الاستضافة: ' . ini_get('upload_max_filesize') . ').', false); $back(); }
            if ($site) $files = [$files[0]];                                   // replacing content: one file only
            $single = count($files) === 1; $done = 0; $msgs = []; $lastId = $id;
            foreach ($files as $f) {
                $name = (string)$f['name'];
                if ((int)$f['error'] !== UPLOAD_ERR_OK) { $msgs[] = $name . ': upload error ' . (int)$f['error']; continue; }
                $title = $single && P('title') !== '' ? mb_substr(P('title'), 0, 190) : sites_title_from_name($name);
                $slug = $site['slug'] ?? unique_slug('sites', sites_ascii_slug(($single ? P('slug') : '') ?: pathinfo($name, PATHINFO_FILENAME)));
                try { [$kind, $n, $skip] = sites_store_upload($f, $slug); }
                catch (Throwable $e) { if (!$site) rm_tree(site_dir($slug)); $msgs[] = $name . ': ' . $e->getMessage(); continue; }
                if (!$site) {
                    $lastId = ins('sites', ['slug' => $slug, 'title' => $title, 'kind' => $kind, 'description' => $single ? mb_substr(P('description'), 0, 1000) : '',
                        'is_public' => PB('is_public'), 'sort' => 0, 'created' => time()]);
                } else upd('sites', ['kind' => $kind], 'id=?', [$id]);
                site_sync_pages($lastId, $slug);
                if ($kind === 'page' && $single && P('page_title') !== '') upd('site_pages', ['title' => mb_substr(P('page_title'), 0, 190)], 'site_id=? AND file=?', [$lastId, 'index.html']);
                audit('site_upload', $slug); $done++; $msgs[] = sites_report($title, $n, $skip);
            }
            flash(($done ? 'تم نشر ' . $done . ' من ' . count($files) . ' — ' : 'فشل الرفع — ') . implode(' | ', $msgs), $done > 0);
            adm_back('sites', $site || ($single && $done) ? ['edit' => $lastId] : []);
        },
        'site_save' => function (): void {
            $id = PN('id');
            if (!row('SELECT id FROM sites WHERE id=?', [$id])) return;
            $files = array_column(rows('SELECT file FROM site_pages WHERE site_id=?', [$id]), 'file');
            $entry = in_array(P('entry'), $files, true) ? P('entry') : (string)val('SELECT entry FROM sites WHERE id=?', [$id]);
            upd('sites', ['title' => mb_substr(P('title'), 0, 190) ?: 'site', 'description' => mb_substr(P('description'), 0, 1000), 'entry' => $entry, 'sort' => PN('sort'),
                'is_public' => PB('is_public'), 'colors' => mb_substr(P('colors'), 0, 4000)], 'id=?', [$id]);
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
        adm_h('المواقع المنشورة', 'مواقع تعليمية وخطط وكورسات (html/zip) تُخزَّن في مجلد خاص لكل موقع وتظهر كبطاقات في صفحة «المكتبة». ملفات php لا تُنفَّذ ولا تُخزَّن.');
        $lim = 'حدّ الرفع في استضافتك: ' . ini_get('upload_max_filesize') . ' (post: ' . ini_get('post_max_size') . ')';
        if (isset($_GET['edit'])) { sites_edit((int)$_GET['edit'], $lim); return; }
        echo '<div class="panel"><h2>نشر مواقع / صفحات</h2><p class="muted">كل ملف zip = موقع كامل بطاقته تفتح ملف index. كل ملف html = صفحة منفردة. يمكنك اختيار عدة ملفات دفعة واحدة وكل ملف يصير بطاقة مستقلة.</p>'; f_open('sites', 'site_upload', [], true, 'form');
        f_in('site_file[]', 'الملفات: zip (موقع كامل) و/أو html (صفحة واحدة)', '', 'file', 'required multiple accept=".zip,.html,.htm"');
        f_in('title', 'اسم البطاقة (عند رفع ملف واحد فقط — وإلا يُؤخذ من اسم الملف)', '', 'text', 'maxlength="190"');
        f_ta('description', 'وصف قصير يظهر في البطاقة (عند رفع ملف واحد)', '', 2, 'maxlength="1000"');
        f_chk('is_public', 'قابل للنشر للجميع (وإلا يظهر مقفلاً ولا يفتحه إلا المدير)', false);
        echo '<p class="muted hint">' . e($lim) . '</p><div class="row"><button class="btn" type="submit">رفع ونشر</button>'; sites_copy_btn(sites_brand_css(), '🎨 نسخ ألوان الهوية'); echo '</div></form></div>';
        $sites = rows('SELECT s.*, (SELECT COUNT(*) FROM site_pages p WHERE p.site_id=s.id) AS pc FROM sites s ORDER BY s.sort, s.id DESC');
        if (!$sites) { echo '<div class="panel"><p class="muted">لا مواقع بعد.</p></div>'; return; }
        echo '<div class="panel tw"><table><thead><tr><th>الموقع</th><th>الحالة</th><th>الصفحات</th><th></th></tr></thead><tbody>';
        foreach ($sites as $s) {
            $u = site_origin() . '/s/' . $s['slug'] . '/';
            echo '<tr><td>' . (($s['kind'] ?? 'site') === 'page' ? '📄 ' : '📘 ') . '<b>' . e($s['title']) . '</b><br><a dir="ltr" href="' . e($u) . '" target="_blank" rel="noopener">/s/' . e($s['slug']) . '/</a></td><td>' . ($s['is_public'] ? '🌐 عام' : '🔒 خاص') . '</td><td>' . (int)$s['pc'] . '</td><td class="acts">';
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
    f_ta('description', 'وصف قصير يظهر في البطاقة', (string)($s['description'] ?? ''), 2, 'maxlength="1000"');
    $pg = rows('SELECT file, title FROM site_pages WHERE site_id=? ORDER BY sort, id', [$id]);
    f_sel('entry', 'صفحة الدخول (التي تفتحها البطاقة)', array_column($pg, 'file', 'file') ?: ['' => '—'], (string)($s['entry'] ?? ''));
    f_in('sort', 'ترتيب البطاقة (الأصغر أولاً)', (string)(int)($s['sort'] ?? 0), 'number', 'style="max-width:120px"');
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
