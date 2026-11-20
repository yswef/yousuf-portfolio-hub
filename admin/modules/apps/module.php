<?php
declare(strict_types=1);

return [
    'title' => ['ar' => 'التطبيقات والتنزيلات', 'en' => 'Apps & downloads'], 'icon' => '⬇', 'group' => 'content', 'order' => 30, 'version' => 1,
    'schema' => fn(array $T) => [
        "CREATE TABLE IF NOT EXISTS apps (id {$T['id']}, project_id INT NULL UNIQUE, slug VARCHAR(190) UNIQUE, name VARCHAR(190), description TEXT NULL, platform VARCHAR(100) NULL,
          requirements TEXT NULL, install_notes TEXT NULL, price_type VARCHAR(20) DEFAULT 'free', price_note VARCHAR(190) NULL, version VARCHAR(60) NULL,
          release_notes {$T['big']} NULL, release_url VARCHAR(255) NULL, published_at VARCHAR(40) NULL, hidden INT DEFAULT 0, ignored INT DEFAULT 0, locked INT DEFAULT 0, created INT)" . $T['tail'],
        "CREATE TABLE IF NOT EXISTS app_files (id {$T['id']}, app_id INT, gh_asset_id BIGINT NULL UNIQUE, name VARCHAR(255), label VARCHAR(190) NULL, kind VARCHAR(20) DEFAULT 'other',
          size BIGINT DEFAULT 0, gh_count INT DEFAULT 0, digest VARCHAR(100) NULL, url TEXT, hidden INT DEFAULT 0)" . $T['tail'],
        "CREATE TABLE IF NOT EXISTS downloads (id {$T['id']}, file_id INT, app_id INT, ts INT, country VARCHAR(10) NULL, device VARCHAR(20) NULL, referrer VARCHAR(190) NULL)" . $T['tail'],
        "CREATE INDEX idx_files_app ON app_files (app_id)", "CREATE INDEX idx_dl_ts ON downloads (ts)",
    ],
    'widgets' => fn() => [
        ['label' => 'التطبيقات', 'value' => (int)val('SELECT COUNT(*) FROM apps WHERE ignored=0')],
        ['label' => 'نقرات تنزيل (7 أيام)', 'value' => (int)val('SELECT COUNT(*) FROM downloads WHERE ts>?', [time() - 7 * 86400])],
    ],
    'actions' => [
        'app_save' => function (): void {
            $id = PN('id');
            $pt = in_array(P('price_type'), ['free', 'paid', 'donation'], true) ? P('price_type') : 'free';
            $name = mb_substr(P('name'), 0, 190);
            if ($name === '') { flash('اسم التطبيق مطلوب.', false); adm_back('apps', ['edit' => $id]); }
            $d = ['name' => $name, 'description' => P('description'), 'platform' => mb_substr(P('platform'), 0, 100), 'requirements' => P('requirements'),
                  'install_notes' => P('install_notes'), 'price_type' => $pt, 'price_note' => mb_substr(P('price_note'), 0, 190),
                  'version' => mb_substr(P('version'), 0, 60), 'hidden' => PB('hidden'), 'locked' => 1];
            if ($id) { upd('apps', $d, 'id=?', [$id]); }
            else { $d['slug'] = unique_slug('apps', slugify($name)); $d['created'] = time(); $d['published_at'] = gmdate('c'); $id = ins('apps', $d); }
            audit('app_save', (string)$id); flash('تم حفظ التطبيق.'); adm_back('apps', ['edit' => $id]);
        },
        'file_save' => function (): void {
            $k = in_array(P('kind'), ['installer', 'portable', 'checksum', 'source', 'other'], true) ? P('kind') : 'other';
            $d = ['label' => mb_substr(P('label'), 0, 190), 'kind' => $k, 'hidden' => PB('hidden')];
            upd('app_files', $d, 'id=?', [PN('id')]); flash('تم حفظ الملف.'); adm_back('apps', ['edit' => PN('app_id')]);
        },
        'file_add' => function (): void {        // manual file entry (hosted anywhere over https)
            $aid = PN('app_id'); $u = P('url');
            if (!preg_match('#^https://#i', $u)) { flash('الرابط يجب أن يبدأ بـ https://', false); adm_back('apps', ['edit' => $aid]); }
            ins('app_files', ['app_id' => $aid, 'name' => mb_substr(P('name') ?: basename((string)parse_url($u, PHP_URL_PATH)), 0, 255), 'label' => mb_substr(P('label'), 0, 190),
                              'kind' => 'other', 'size' => PN('size'), 'url' => $u]);
            flash('تمت إضافة الملف.'); adm_back('apps', ['edit' => $aid]);
        },
        'file_delete' => function (): void {
            $f = row('SELECT * FROM app_files WHERE id=?', [PN('id')]);
            if ($f && $f['gh_asset_id'] === null) { q('DELETE FROM app_files WHERE id=?', [$f['id']]); flash('تم حذف الملف.'); }
            else flash('ملفات GitHub تُدار بالمزامنة — أخفِها بدل حذفها.', false);
            adm_back('apps', ['edit' => (int)($f['app_id'] ?? 0)]);
        },
        'app_ignore' => function (): void { upd('apps', ['ignored' => 1, 'hidden' => 1], 'id=?', [PN('id')]); audit('app_ignore', (string)PN('id')); flash('تم تجاهل التطبيق (لن تعيد المزامنة إنشاءه).'); },
        'app_restore' => function (): void { upd('apps', ['ignored' => 0, 'hidden' => 0], 'id=?', [PN('id')]); flash('تمت الاستعادة.'); },
        'app_delete' => function (): void {
            $a = row('SELECT * FROM apps WHERE id=?', [PN('id')]);
            if (!$a) return;
            if ($a['project_id']) { flash('تطبيق مرتبط بمشروع GitHub — استخدم «تجاهل».', false); return; }
            q('DELETE FROM app_files WHERE app_id=?', [$a['id']]); q('DELETE FROM apps WHERE id=?', [$a['id']]); flash('تم الحذف.');
        },
    ],
    'view' => function (array $mod): void {
        adm_h('التطبيقات والتنزيلات', 'تظهر في صفحة التطبيقات. التنزيلات تُحتسب عبر رابط /dl/ داخل موقعك ثم يُحوَّل الزائر إلى الملف.');
        if (isset($_GET['edit'])) { apps_form((int)$_GET['edit']); return; }
        echo '<div class="row" style="margin-bottom:14px"><a class="btn" href="' . adm_url('apps', ['edit' => 0]) . '">+ تطبيق يدوي</a></div>';
        $apps = rows('SELECT a.*, (SELECT COALESCE(SUM(gh_count),0) FROM app_files f WHERE f.app_id=a.id) AS gh,
                      (SELECT COUNT(*) FROM downloads d WHERE d.app_id=a.id) AS clicks FROM apps a ORDER BY a.ignored, a.id DESC');
        if (!$apps) { echo '<div class="panel"><p class="muted">لا توجد تطبيقات. تُنشأ تلقائياً عند مزامنة مشروع GitHub له إصدار (Release) فيه ملفات، أو أضف تطبيقاً يدوياً.</p></div>'; return; }
        echo '<div class="panel tw"><table><thead><tr><th>الاسم</th><th>الإصدار</th><th>المنصة</th><th>تنزيلات GitHub</th><th>نقرات الموقع</th><th></th></tr></thead><tbody>';
        foreach ($apps as $a) {
            echo '<tr><td><b>' . e($a['name']) . '</b>' . ($a['ignored'] ? ' <span class="chip">متجاهل</span>' : '') . ($a['hidden'] ? ' <span class="chip">مخفي</span>' : '') . '</td><td dir="ltr">' . e($a['version']) . '</td><td>' . e($a['platform']) . '</td><td>' . (int)$a['gh'] . '</td><td>' . (int)$a['clicks'] . '</td><td class="acts">';
            echo '<a class="btn ghost sm" href="' . adm_url('apps', ['edit' => $a['id']]) . '">تعديل</a>';
            if ($a['ignored']) btn_post('apps', 'app_restore', ['id' => $a['id']], 'استعادة');
            elseif ($a['project_id']) btn_post('apps', 'app_ignore', ['id' => $a['id']], 'تجاهل', 'btn danger sm', 'تجاهل هذا التطبيق؟ لن تعيد المزامنة إنشاءه.');
            else btn_post('apps', 'app_delete', ['id' => $a['id']], 'حذف', 'btn danger sm', 'حذف التطبيق وملفاته؟');
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    },
];

function apps_form(int $id): void {
    $a = $id ? row('SELECT * FROM apps WHERE id=?', [$id]) : null;
    if ($id && !$a) { echo '<div class="note bad">غير موجود.</div>'; return; }
    echo '<div class="panel"><h2>' . ($id ? 'تعديل التطبيق' : 'تطبيق جديد') . '</h2>';
    f_open('apps', 'app_save', ['id' => $id], false, 'form');
    f_in('name', 'الاسم *', $a['name'] ?? '', 'text', 'required maxlength="190"');
    f_ta('description', 'الوصف', $a['description'] ?? '', 3);
    echo '<div class="two">'; f_in('version', 'الإصدار', $a['version'] ?? '', 'text', 'dir="ltr"'); f_in('platform', 'المنصة (Windows / Android …)', $a['platform'] ?? ''); echo '</div>';
    echo '<div class="two">'; f_sel('price_type', 'التسعير', ['free' => 'مجاني', 'paid' => 'مدفوع', 'donation' => 'تبرّع'], $a['price_type'] ?? 'free'); f_in('price_note', 'ملاحظة السعر', $a['price_note'] ?? ''); echo '</div>';
    f_ta('requirements', 'المتطلبات (Markdown)', $a['requirements'] ?? '', 4, 'class="mono"');
    f_ta('install_notes', 'ملاحظات التثبيت (Markdown)', $a['install_notes'] ?? '', 4, 'class="mono"');
    f_chk('hidden', 'مخفي عن الزوار', !empty($a['hidden']));
    echo '<div class="row"><button class="btn" type="submit">حفظ</button><a class="btn ghost" href="' . adm_url('apps') . '">رجوع</a></div></form></div>';
    if (!$a) return;
    echo '<div class="panel"><h2>الملفات</h2>';
    $files = rows('SELECT * FROM app_files WHERE app_id=? ORDER BY id', [$id]);
    foreach ($files as $f) {
        echo '<div class="file-row">'; f_open('apps', 'file_save', ['id' => $f['id'], 'app_id' => $id], false, 'inline-form');
        echo '<span dir="ltr" class="fname">' . e($f['name']) . ' · ' . e(fmt_size($f['size'])) . ' · ' . (int)$f['gh_count'] . '↓' . ($f['gh_asset_id'] === null ? ' · يدوي' : '') . '</span>';
        f_in('label', 'التسمية', $f['label'], 'text', 'maxlength="190"');
        f_sel('kind', 'النوع', ['installer' => 'مثبّت', 'portable' => 'محمول', 'checksum' => 'بصمة', 'source' => 'مصدر', 'other' => 'أخرى'], $f['kind']);
        f_chk('hidden', 'مخفي', !empty($f['hidden']));
        echo '<button class="btn ghost sm">حفظ</button></form>';
        if ($f['gh_asset_id'] === null) btn_post('apps', 'file_delete', ['id' => $f['id']], 'حذف', 'btn danger sm', 'حذف الملف؟');
        echo '</div>';
    }
    if (!$files) echo '<p class="muted">لا ملفات بعد.</p>';
    echo '<h3>إضافة ملف يدوياً</h3>'; f_open('apps', 'file_add', ['app_id' => $id], false, 'form');
    f_in('url', 'رابط الملف (https)', '', 'text', 'dir="ltr" required placeholder="https://"');
    echo '<div class="two">'; f_in('name', 'اسم الملف (اختياري)', ''); f_in('label', 'التسمية (اختياري)', ''); echo '</div>';
    f_in('size', 'الحجم بالبايت (اختياري)', '', 'number', 'dir="ltr" min="0"');
    echo '<button class="btn" type="submit">إضافة</button></form></div>';
}
