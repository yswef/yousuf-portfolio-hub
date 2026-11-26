<?php
declare(strict_types=1);
/**
 * CV generator (مولّد السيرة الذاتية): fill the form (or one-click import from portfolio data)
 * → live printable preview → save / print to PDF / download HTML.
 */
const CV_TEMPLATES = ['modern' => 'عصري (أورورا فيروزي)', 'executive' => 'تنفيذي (كحلي داكن)', 'classic' => 'كلاسيكي (رمادي رسمي)', 'minimal' => 'بسيط (أحادية اللون)'];

function cv_lines(string $t): array {
    $norm = str_replace(["\r\n", "\r"], "\n", $t);
    return array_values(array_filter(array_map('trim', explode("\n", $norm)), fn($x) => $x !== ''));
}

/** Build pre-filled CV fields from existing portfolio tables (settings, items, projects). */
function cv_from_portfolio(string $lang = 'ar'): array {
    $pick = function (?string $json, string $fallback = '') use ($lang): string {
        $a = i18n_decode($json);
        return trim((string)($a[$lang] ?? $a['ar'] ?? $a['en'] ?? $fallback));
    };
    $setL = fn(string $k): string => setting($k . '_' . $lang) ?: setting($k . '_ar') ?: setting($k . '_en');
    $expLines = [];
    if (table_exists('items')) {
        foreach (rows("SELECT * FROM items WHERE type='experience' AND hidden=0 ORDER BY sort_order, id") as $x) {
            $per = trim($x['date_from'] . ($x['date_from'] || $x['date_to'] || $x['is_current'] ? ' — ' : '') . ($x['is_current'] ? ($lang === 'en' ? 'Present' : 'حتى الآن') : $x['date_to']), ' —');
            $expLines[] = implode(' | ', array_filter([$pick($x['title_i18n']), $pick($x['sub_i18n']), $per, $pick($x['text_i18n'])]));
        }
    }
    $eduLines = [];
    if (table_exists('items')) {
        foreach (rows("SELECT * FROM items WHERE type='education' AND hidden=0 ORDER BY sort_order, id") as $x) {
            $eduLines[] = implode(' | ', array_filter([$pick($x['title_i18n']), $pick($x['sub_i18n']), (string)$x['date_to']]));
        }
    }
    $skills = [];
    if (table_exists('items')) {
        foreach (rows("SELECT * FROM items WHERE type='skill' AND hidden=0 ORDER BY sort_order, id") as $s) {
            $t = $pick($s['title_i18n']); if ($t !== '') $skills[] = $t;
        }
    }
    $projs = [];
    if (table_exists('projects')) {
        foreach (rows('SELECT * FROM projects WHERE hidden=0 ORDER BY featured DESC, stars DESC, id DESC LIMIT 8') as $p) {
            $pt = $pick($p['title_i18n'], (string)$p['name']);
            $pd = $pick($p['desc_i18n'], (string)$p['description']);
            $projs[] = $pt . ($pd !== '' ? ' — ' . mb_substr($pd, 0, 120) : '');
        }
    }
    $email = ''; $phone = ''; $siteUrl = site_origin();
    if (table_exists('items')) {
        foreach (rows("SELECT extra FROM items WHERE type='link' AND hidden=0 ORDER BY sort_order, id") as $lk) {
            $u = trim((string)$lk['extra']);
            if ($email === '' && str_starts_with($u, 'mailto:')) $email = substr($u, 7);
            elseif ($phone === '' && preg_match('/wa\.me\/(\+?\d+)/', $u, $m)) $phone = $m[1];
            elseif ($phone === '' && str_starts_with($u, 'tel:')) $phone = substr($u, 4);
        }
    }
    return [
        'name' => $setL('name'), 'job' => $setL('title'), 'email' => $email, 'phone' => $phone,
        'location' => '', 'website' => $siteUrl,
        'summary' => $setL('bio'), 'experience' => implode("\n", $expLines),
        'education' => implode("\n", $eduLines), 'skills' => implode('، ', $skills),
        'projects' => implode("\n", $projs), 'languages' => $setL('spoken'), 'template' => 'modern',
    ];
}

function cv_html(array $d): string {
    $h = fn($v) => e((string)($v ?? ''));
    $tpl = $d['template'] ?? 'modern';
    $palette = [
        'modern'    => ['#0f766e', '#ccfbf1', '#f0fdfa'],
        'executive' => ['#0f2942', '#cbd5e1', '#f1f5f9'],
        'classic'   => ['#334155', '#e2e8f0', '#f8fafc'],
        'minimal'   => ['#111111', '#111111', '#f4f4f5'],
    ][$tpl] ?? ['#0f766e', '#ccfbf1', '#f0fdfa'];
    [$accent, $line, $chipBg] = $palette;

    $out = '<div class="cv-preview" dir="auto" style="--cv-accent:' . $accent . ';--cv-line:' . $line . '">';
    $out .= '<div style="border-bottom:3px solid ' . $accent . ';padding-bottom:14px;margin-bottom:14px">';
    $out .= '<h1 style="color:' . $accent . ';margin-bottom:4px">' . $h($d['name'] ?? '') . '</h1>';
    if (!empty($d['job'])) $out .= '<p style="font-weight:700;color:#334155;font-size:1.05rem">' . $h($d['job']) . '</p>';
    $contact = array_filter([$d['email'] ?? '', $d['phone'] ?? '', $d['location'] ?? '', $d['website'] ?? '']);
    if ($contact) $out .= '<p class="cv-contact" dir="ltr" style="margin-top:6px">' . implode(' · ', array_map($h, $contact)) . '</p>';
    $out .= '</div>';

    $sec = function (string $title, string $body) use (&$out, $accent, $line) {
        if (trim($body) !== '') $out .= '<h2 style="color:' . $accent . ';border-bottom:2px solid ' . $line . '">' . e($title) . '</h2>' . $body;
    };
    if (!empty($d['summary'])) $sec('نبذة مهنية', '<p style="white-space:pre-line">' . $h($d['summary']) . '</p>');
    if (!empty($d['experience'])) {
        $items = '';
        foreach (cv_lines((string)$d['experience']) as $l) {
            $p = array_map('trim', explode('|', $l));
            $items .= '<li style="margin-bottom:8px"><b>' . $h($p[0] ?? '') . '</b>' . (!empty($p[1]) ? ' — <span style="color:' . $accent . ';font-weight:600">' . $h($p[1]) . '</span>' : '') . (!empty($p[2]) ? ' <span style="color:#64748b;font-size:.86rem">(' . $h($p[2]) . ')</span>' : '') . (!empty($p[3]) ? '<div style="color:#334155;font-size:.92rem">' . $h($p[3]) . '</div>' : '') . '</li>';
        }
        $sec('الخبرات العملية', '<ul style="padding-inline-start:20px">' . $items . '</ul>');
    }
    if (!empty($d['education'])) {
        $items = '';
        foreach (cv_lines((string)$d['education']) as $l) {
            $p = array_map('trim', explode('|', $l));
            $items .= '<li><b>' . $h($p[0] ?? '') . '</b>' . (!empty($p[1]) ? ' — ' . $h($p[1]) : '') . (!empty($p[2]) ? ' <span style="color:#64748b">(' . $h($p[2]) . ')</span>' : '') . '</li>';
        }
        $sec('التعليم والشهادات', '<ul style="padding-inline-start:20px">' . $items . '</ul>');
    }
    if (!empty($d['skills'])) {
        $sks = array_values(array_filter(array_map('trim', preg_split('/[,،|]+/u', (string)$d['skills']) ?: [])));
        $chips = implode(' ', array_map(fn($s) => '<span style="display:inline-block;background:' . $chipBg . ';color:' . $accent . ';border:1px solid ' . $line . ';border-radius:99px;padding:2px 11px;margin:2px;font-size:.85rem;font-weight:600">' . $h($s) . '</span>', $sks));
        $sec('المهارات التقنية', '<div>' . ($chips ?: $h($d['skills'])) . '</div>');
    }
    if (!empty($d['projects'])) $sec('أبرز المشاريع', '<ul style="padding-inline-start:20px">' . implode('', array_map(fn($l) => '<li>' . $h($l) . '</li>', cv_lines((string)$d['projects']))) . '</ul>');
    if (!empty($d['languages'])) $sec('اللغات', '<p>' . $h($d['languages']) . '</p>');
    return $out . '</div>';
}

return [
    'title' => ['ar' => 'مولّد السيرة الذاتية', 'en' => 'CV generator'], 'icon' => '📄', 'group' => 'app', 'order' => 20, 'version' => 1,
    'schema' => fn(array $T) => [
        "CREATE TABLE IF NOT EXISTS cvs (id {$T['id']}, title VARCHAR(190), data {$T['big']} NULL, created INT, updated INT)" . $T['tail'],
    ],
    'widgets' => fn() => [['label' => 'سير ذاتية محفوظة', 'value' => (int)val('SELECT COUNT(*) FROM cvs'), 'link' => 'cvgen']],
    'actions' => [
        'save' => function (): void {
            $id = PN('id');
            $d = ['name' => mb_substr(P('name'), 0, 190), 'job' => mb_substr(P('job'), 0, 190), 'email' => mb_substr(P('email'), 0, 190),
                'phone' => mb_substr(P('phone'), 0, 60), 'location' => mb_substr(P('location'), 0, 120), 'website' => mb_substr(P('website'), 0, 255),
                'summary' => P('summary'), 'experience' => P('experience'), 'education' => P('education'), 'skills' => P('skills'),
                'projects' => P('projects'), 'languages' => P('languages'), 'template' => array_key_exists(P('template'), CV_TEMPLATES) ? P('template') : 'modern'];
            $title = mb_substr(P('title') ?: ($d['name'] . ' — CV'), 0, 190);
            if ($id && row('SELECT id FROM cvs WHERE id=?', [$id])) { upd('cvs', ['title' => $title, 'data' => json_encode($d, JSON_UNESCAPED_UNICODE), 'updated' => time()], 'id=?', [$id]); }
            else $id = ins('cvs', ['title' => $title, 'data' => json_encode($d, JSON_UNESCAPED_UNICODE), 'created' => time(), 'updated' => time()]);
            audit('cv_save', (string)$id); flash('حُفظت السيرة الذاتية.');
            adm_back('cvgen', ['view' => $id]);
        },
        'delete' => function (): void {
            q('DELETE FROM cvs WHERE id=?', [PN('id')]);
            audit('cv_delete', (string)PN('id')); flash('حُذفت السيرة.'); adm_back('cvgen');
        },
    ],
    'download' => function (string $what): void {
        $cv = row('SELECT * FROM cvs WHERE id=?', [(int)($_GET['id'] ?? 0)]);
        if (!$cv) { http_response_code(404); echo 'Not found'; return; }
        $d = json_decode((string)$cv['data'], true) ?: [];
        header('Content-Type: text/html; charset=utf-8'); header('Content-Disposition: attachment; filename="cv-' . (int)$cv['id'] . '.html"');
        echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($cv['title']) . '</title><style>body{font-family:Cairo,Tahoma,sans-serif;background:#fff;color:#111;padding:30px;line-height:1.7}.cv-preview{max-width:820px;margin:auto}.cv-preview h1{font-size:1.75rem;margin:0}.cv-preview h2{font-size:1.02rem;padding-bottom:4px;margin:18px 0 8px}.cv-preview li{margin-bottom:5px}@media print{body{padding:0}}</style></head><body>' . cv_html($d) . '</body></html>';
    },
    'view' => function (array $mod): void {
        if (isset($_GET['view'])) {
            $cv = row('SELECT * FROM cvs WHERE id=?', [(int)$_GET['view']]);
            if (!$cv) { echo '<div class="note bad">غير موجودة.</div>'; return; }
            $d = json_decode((string)$cv['data'], true) ?: [];
            adm_h('معاينة: ' . $cv['title']);
            echo '<div class="row no-print" style="margin-bottom:14px"><button class="btn" type="button" onclick="window.print()">🖨 طباعة / حفظ PDF</button>'
               . '<a class="btn ghost" href="' . e(adm_url('cvgen', ['dl' => 'cv', 'id' => $cv['id']])) . '">⬇ تنزيل HTML</a>'
               . '<a class="btn ghost" href="' . e(adm_url('cvgen', ['edit' => $cv['id']])) . '">تعديل</a>'
               . '<a class="btn ghost" href="' . e(adm_url('cvgen')) . '">رجوع</a></div>';
            echo cv_html($d);
            return;
        }
        $edit = isset($_GET['edit']) ? row('SELECT * FROM cvs WHERE id=?', [(int)$_GET['edit']]) : null;
        $prefillLang = in_array((string)($_GET['import'] ?? ''), ['ar', 'en'], true) ? (string)$_GET['import'] : '';
        $d = $edit ? (json_decode((string)$edit['data'], true) ?: []) : ($prefillLang !== '' ? cv_from_portfolio($prefillLang) : [
            'name' => setting_l('name'), 'job' => setting_l('title'), 'email' => '', 'phone' => '', 'location' => '', 'website' => site_origin(),
            'summary' => '', 'experience' => '', 'education' => '', 'skills' => '', 'projects' => '', 'languages' => setting_l('spoken'), 'template' => 'modern']);

        adm_h($edit ? 'تعديل السيرة الذاتية' : 'مولّد السيرة الذاتية (CV)', 'عبّئ البيانات يدوياً أو استوردها بنقرة واحدة من معرض أعمالك، ثم اطبعها كـ PDF أو نزّلها HTML.');
        echo '<div class="panel"><div class="row" style="justify-content:space-between;margin-bottom:10px"><h2 style="margin:0">' . ($edit ? 'تعديل السيرة الذاتية' : 'إنشاء سيرة ذاتية جديدة') . '</h2>'
           . '<span class="row"><a class="btn ghost sm" href="' . e(adm_url('cvgen', ['import' => 'ar'])) . '">⚡ استيراد تلقائي من المعرض (عربي)</a>'
           . '<a class="btn ghost sm" href="' . e(adm_url('cvgen', ['import' => 'en'])) . '">⚡ استيراد تلقائي (English)</a></span></div>';
        f_open('cvgen', 'save', ['id' => $edit['id'] ?? 0], false, 'form');
        f_in('title', 'عنوان السيرة (للحفظ في الأرشيف)', $edit['title'] ?? '', 'text', 'maxlength="190" placeholder="مثال: سيرة يوسف — مطوّر برمجيات 2026"');
        echo '<div class="two">'; f_in('name', 'الاسم الكامل *', $d['name'] ?? '', 'text', 'required'); f_in('job', 'المسمّى الوظيفي', $d['job'] ?? '', 'text'); echo '</div>';
        echo '<div class="two">'; f_in('email', 'البريد الإلكتروني', $d['email'] ?? '', 'text', 'dir="ltr"'); f_in('phone', 'الهاتف', $d['phone'] ?? '', 'text', 'dir="ltr"'); echo '</div>';
        echo '<div class="two">'; f_in('location', 'المدينة / الدولة', $d['location'] ?? '', 'text'); f_in('website', 'الموقع / المعرض', $d['website'] ?? '', 'text', 'dir="ltr"'); echo '</div>';
        f_ta('summary', 'نبذة مهنية', $d['summary'] ?? '', 4);
        f_ta('experience', 'الخبرات (سطر لكل خبرة: المسمّى | الجهة | الفترة | وصف)', $d['experience'] ?? '', 5);
        f_ta('education', 'التعليم والشهادات (سطر لكل شهادة: الدرجة | الجهة | السنة)', $d['education'] ?? '', 3);
        f_ta('skills', 'المهارات (مفصولة بفاصلة)', $d['skills'] ?? '', 3);
        f_ta('projects', 'أبرز المشاريع (سطر لكل مشروع)', $d['projects'] ?? '', 4);
        echo '<div class="two">';
        f_in('languages', 'اللغات', $d['languages'] ?? '', 'text');
        f_sel('template', 'القالب والتصميم', CV_TEMPLATES, $d['template'] ?? 'modern');
        echo '</div>';
        echo '<div class="row"><button class="btn" type="submit">💾 حفظ ومعاينة للطباعة</button>' . ($edit ? '<a class="btn ghost" href="' . e(adm_url('cvgen')) . '">إلغاء</a>' : '') . '</div></form></div>';

        // ---- saved CVs with filters ----
        $q = trim((string)($_GET['q'] ?? ''));
        $tplF = array_key_exists((string)($_GET['tpl'] ?? ''), CV_TEMPLATES) ? (string)$_GET['tpl'] : '';
        $sort = ($_GET['sort'] ?? 'newest') === 'oldest' ? 'oldest' : 'newest';
        $allCvs = rows('SELECT * FROM cvs ORDER BY ' . ($sort === 'oldest' ? 'id ASC' : 'id DESC'));
        if ($allCvs) {
            echo '<h2>السير الذاتية المحفوظة</h2>';
            echo '<form method="get" class="filter-bar"><input type="hidden" name="m" value="cvgen">'
               . '<input type="search" name="q" value="' . e($q) . '" placeholder="🔎 بحث في السير المحفوظة…">'
               . '<select name="tpl" onchange="this.form.submit()"><option value="">كل القوالب</option>';
            foreach (CV_TEMPLATES as $tk => $tl) echo '<option value="' . $tk . '"' . ($tplF === $tk ? ' selected' : '') . '>' . e($tl) . '</option>';
            echo '</select><select name="sort" onchange="this.form.submit()"><option value="newest"' . ($sort === 'newest' ? ' selected' : '') . '>الترتيب: الأحدث</option><option value="oldest"' . ($sort === 'oldest' ? ' selected' : '') . '>الترتيب: الأقدم</option></select>'
               . '<button class="btn ghost sm" type="submit">تصفية</button>'
               . (($q !== '' || $tplF !== '' || $sort !== 'newest') ? '<a class="btn ghost sm" href="' . e(adm_url('cvgen')) . '">إعادة تعيين</a>' : '')
               . '</form>';

            $filtered = array_values(array_filter($allCvs, function ($c) use ($q, $tplF) {
                $cd = json_decode((string)$c['data'], true) ?: [];
                if ($tplF !== '' && ($cd['template'] ?? 'modern') !== $tplF) return false;
                if ($q !== '' && mb_stripos($c['title'] . ' ' . ($cd['name'] ?? '') . ' ' . ($cd['job'] ?? ''), $q) === false) return false;
                return true;
            }));
            if (!$filtered) { echo '<div class="panel"><p class="muted">لا سير مطابقة.</p></div>'; return; }
            echo '<div class="panel tw"><table><thead><tr><th>العنوان</th><th>القالب</th><th>آخر تحديث</th><th></th></tr></thead><tbody>';
            foreach ($filtered as $c) {
                $cd = json_decode((string)$c['data'], true) ?: [];
                echo '<tr><td><b>' . e($c['title']) . '</b></td><td><span class="chip">' . e(CV_TEMPLATES[$cd['template'] ?? 'modern'] ?? '') . '</span></td><td>' . e(fmt_date($c['updated'])) . '</td><td class="acts"><a class="btn ghost sm" href="' . e(adm_url('cvgen', ['view' => $c['id']])) . '">معاينة / PDF</a><a class="btn ghost sm" href="' . e(adm_url('cvgen', ['edit' => $c['id']])) . '">تعديل</a>';
                btn_post('cvgen', 'delete', ['id' => $c['id']], 'حذف', 'btn danger sm', 'حذف هذه السيرة؟');
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
    },
];
