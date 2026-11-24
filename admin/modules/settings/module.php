<?php
declare(strict_types=1);

const SET_I18N = [     // key => [label, textarea?]
    'name' => ['الاسم', false], 'title' => ['المسمّى الوظيفي', false], 'tagline' => ['الجملة التعريفية (الرئيسية)', true],
    'bio' => ['نبذة عني (Markdown)', true], 'current_job' => ['العمل الحالي (يظهر في الرئيسية وصفحة عني)', false],
    'focus' => ['كلمات الكتابة المتحركة (مفصولة بفاصلة)', false], 'availability' => ['نص التوفّر للمشاريع', false],
    'spoken' => ['اللغات التي أتحدثها', false], 'contact_text' => ['نص صفحة التواصل', true], 'banner' => ['شريط إعلان أعلى الموقع', false],
];
const SET_FLAGS = [    // key => [label, default]
    'available' => ['أظهر شارة «متاح للعمل»', '1'], 'show_age' => ['أظهر العمر في الإحصاءات', '1'], 'banner_on' => ['فعّل شريط الإعلان', '0'], 'show_blog' => ['أظهر تبويب المدونة في القائمة', '0'],
    'fx_glow' => ['توهّج المؤشر', '1'], 'fx_cursor' => ['مؤشر مخصّص (حاسوب فقط)', '1'], 'fx_tilt' => ['إمالة البطاقات ثلاثية الأبعاد', '1'],
    'track' => ['تفعيل التحليلات', '1'], 'contact_enabled' => ['تفعيل نموذج التواصل', '1'], 'sync_forks' => ['مزامنة المستودعات المنسوخة (forks)', '0'], 'noindex' => ['منع محركات البحث (noindex)', '0'],
];

return [
    'title' => ['ar' => 'الإعدادات', 'en' => 'Settings'], 'icon' => '⚙', 'group' => 'system', 'order' => 90, 'version' => 1,
    'seed' => function (): void {
        $v = [
            'name_ar' => 'يوسف الحمزي', 'name_en' => 'Yusef Alhmzy', 'name_es' => 'Yusef Alhmzy', 'name_fr' => 'Yusef Alhmzy', 'name_ja' => 'Yusef Alhmzy',
            'bio_ar' => "مهندس برمجيات شغوف، متخصص في تطوير الأنظمة بلغة Python، ومواقع WordPress، وأنظمة ERP. مطوّر Python منذ نحو خمس سنوات، تعلّمت البرمجة ذاتياً وعملت في شركة ناشئة. أركّز على تطوير الويب، وأتمتة المهام، وتطبيقات سطح المكتب لويندوز، وأبني تطبيقات بديلة مجانية أو منخفضة التكلفة للبرامج المدفوعة.\n\nأتعلّم حالياً تطوير تطبيقات الموبايل والأمن السيبراني. أحب التحديات التقنية وأسعى دائماً لتعلّم أدوات جديدة والتطور في مسيرتي.",
            'bio_en' => "A passionate software engineer specializing in Python systems, WordPress websites and ERP systems. A Python developer for about five years, self-taught, with experience in a startup. I focus on web development, task automation and Windows desktop apps, and I build free or low-cost alternatives to paid software.\n\nRight now I am learning mobile app development and cybersecurity. I love technical challenges and I am always learning new tools and growing in my career.",
            'current_job_ar' => 'أعمل حالياً في مطعم حنيذ.', 'current_job_en' => 'Currently working at Haneeth Restaurant.',
            'focus_ar' => 'Python, WordPress, ERP, أتمتة المهام', 'focus_en' => 'Python, WordPress, ERP, Automation',
            'availability_ar' => 'متاح لمشاريع جديدة', 'availability_en' => 'Available for new projects',
            'spoken_ar' => 'العربية، الإنجليزية', 'spoken_en' => 'Arabic, English',
            'contact_text_ar' => 'عندك فكرة مشروع أو تحتاج مساعدة؟ اكتب تفاصيل العمل المطلوب وأعود إليك.', 'contact_text_en' => 'Have a project idea or need help? Describe the work you need and I will get back to you.',
            'birthdate' => '2006-06-26', 'theme_default' => 'system',
        ];
        foreach (array_keys(LANGS) as $l) {                       // reuse the existing 5-language texts from the original portfolio
            $f = lang_file($l);
            if (!empty($f['hero_role'])) $v['title_' . $l] = $f['hero_role'];
            if (!empty($f['hero_tagline'])) $v['tagline_' . $l] = $f['hero_tagline'];
        }
        foreach (SET_FLAGS as $k => [$lab, $def]) $v[$k] = $def;
        foreach ($v as $k => $val) set_setting($k, (string)$val);
    },
    'actions' => [
        'save' => function (): void {
            foreach (SET_I18N as $k => [$lab, $area]) foreach (array_keys(LANGS) as $l) set_setting($k . '_' . $l, mb_substr(trim((string)(($_POST['s_' . $k] ?? [])[$l] ?? '')), 0, 8000));
            foreach (SET_FLAGS as $k => $x) set_setting($k, (string)PB($k));
            $bd = P('birthdate'); set_setting('birthdate', preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd) ? $bd : '');
            set_setting('theme_default', in_array(P('theme_default'), ['system', 'dark', 'light'], true) ? P('theme_default') : 'system');
            set_setting('google_verify', mb_substr(P('google_verify'), 0, 200)); set_setting('bing_verify', mb_substr(P('bing_verify'), 0, 200));
            if (!empty($_FILES['avatar']['name'])) {
                $n = handle_image_upload($_FILES['avatar'], 700);
                if ($n) { delete_upload(setting('avatar')); set_setting('avatar', $n); } else flash('تعذّر رفع الصورة الشخصية (JPG/PNG/WebP حتى 5MB).', false);
            } elseif (PB('avatar_remove')) { delete_upload(setting('avatar')); set_setting('avatar', ''); }
            if (PB('gh_token_remove')) set_setting('github_token', '');
            elseif (P('github_token') !== '') set_setting('github_token', mb_substr(P('github_token'), 0, 200));       // blank = keep the current one
            if (preg_match('/^[A-Za-z0-9-]{0,39}$/', P('github_user'))) set_setting('github_user', P('github_user'));
            audit('settings_save'); if (empty($_SESSION['flash'])) flash('تم حفظ الإعدادات.');
        },
    ],
    'view' => function (array $mod): void {
        adm_h('الإعدادات');
        f_open('settings', 'save', [], true, 'form');
        echo '<div class="panel"><h2>الهوية والنصوص</h2><p class="muted">اكتب بالعربية والإنجليزية على الأقل؛ اللغات الفارغة تعرض أقرب لغة متوفّرة.</p>';
        foreach (SET_I18N as $k => [$lab, $area]) {
            $vals = []; foreach (array_keys(LANGS) as $l) $vals[$l] = setting($k . '_' . $l);
            f_i18n('s_' . $k, $lab, $vals, $area, $k === 'bio' ? 8 : 3);
        }
        echo '</div><div class="panel"><h2>الصورة الشخصية</h2>'; f_image('avatar', 'صورة جديدة (تُحوَّل إلى JPG)', setting('avatar') ?: null); echo '<p class="muted">إن لم ترفع صورة تُستخدم الصورة الافتراضية للموقع.</p></div>';
        echo '<div class="panel"><h2>العرض</h2>'; f_in('birthdate', 'تاريخ الميلاد (لحساب العمر فقط — لا يُعرض)', setting('birthdate'), 'text', 'dir="ltr" placeholder="YYYY-MM-DD"');
        f_sel('theme_default', 'المظهر الافتراضي', ['system' => 'حسب جهاز الزائر', 'dark' => 'داكن', 'light' => 'فاتح'], setting('theme_default', 'system'));
        foreach (SET_FLAGS as $k => [$lab, $def]) f_chk($k, $lab, setting($k, $def) === '1');
        $tk = gh_token();
        echo '</div><div class="panel"><h2>GitHub</h2>'; f_in('github_user', 'مستخدم GitHub', gh_user(), 'text', 'dir="ltr"');
        f_in('github_token', 'التوكن (يرفع الحد من 60 إلى 5000 طلب/ساعة) — ' . ($tk !== '' ? 'مضبوط ••••' . substr($tk, -4) . ' · اتركه فارغاً للإبقاء عليه' : 'غير مضبوط'), '', 'password', 'dir="ltr" autocomplete="new-password"');
        if ($tk !== '') f_chk('gh_token_remove', 'حذف التوكن المحفوظ', false);
        echo '</div><div class="panel"><h2>محركات البحث</h2>'; f_in('google_verify', 'Google Search Console (قيمة content)', setting('google_verify'), 'text', 'dir="ltr"'); f_in('bing_verify', 'Bing Webmaster (قيمة content)', setting('bing_verify'), 'text', 'dir="ltr"');
        echo '</div><div class="row"><button class="btn" type="submit">حفظ الإعدادات</button></div></form>';
    },
];
