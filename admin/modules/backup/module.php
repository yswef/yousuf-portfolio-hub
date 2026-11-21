<?php
declare(strict_types=1);

return [
    'title' => ['ar' => 'النسخ الاحتياطي الكامل', 'en' => 'Full backup'], 'icon' => '💾', 'group' => 'system', 'order' => 98, 'version' => 1,
    'widgets' => function (): array { $l = backup_list(); return [['label' => 'آخر نسخة احتياطية', 'value' => $l ? gmdate('m-d', $l[0]['ts']) : '—', 'link' => 'backup']]; },
    'actions' => [
        'backup_now' => function (): void { try { flash('تم: ' . backup_run('manual')); } catch (Throwable $e) { flash('فشل النسخ: ' . $e->getMessage(), false); } },
        'backup_delete' => function (): void { backup_delete(P('name')); audit('backup_delete', P('name')); flash('تم حذف النسخة.'); },
        'backup_email' => function (): void {
            $m = P('email');
            if ($m !== '' && !filter_var($m, FILTER_VALIDATE_EMAIL)) { flash('البريد غير صالح.', false); return; }
            set_setting('backup_email', $m); flash($m === '' ? 'تم إلغاء الإشعارات.' : 'تم حفظ بريد الإشعارات.');
        },
        'backup_test_mail' => function (): void { flash(backup_notify('اختبار إشعارات النسخ الاحتياطي', 'يعمل الإشعار ✓') ? 'أُرسل بريد اختبار.' : 'تعذّر الإرسال (تحقق من البريد أو من دعم mail() في الاستضافة).', backup_email() !== ''); },
    ],
    'download' => function (string $what): void {
        $n = (string)($_GET['f'] ?? ''); $f = backup_dir() . '/' . $n;
        if ($what !== 'file' || !preg_match('/^backup-\d{8}-\d{6}\.zip$/', $n) || !is_file($f)) { http_response_code(404); echo 'Not found'; return; }
        @set_time_limit(0); while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="' . $n . '"'); header('Content-Length: ' . filesize($f)); readfile($f);
    },
    'view' => function (array $mod): void {
        adm_h('النسخ الاحتياطي الكامل', 'ملف zip واحد: كل ملفات الموقع (بما فيها المواقع المنشورة والصور وconfig.php) + قاعدة البيانات + سكربت restore.php لإعادة التثبيت على استضافة أخرى.');
        $list = backup_list(); $last = json_decode(setting('backup_last', '{}'), true) ?: [];
        if ($last) echo '<div class="note ' . (!empty($last['ok']) ? 'ok' : 'bad') . '">آخر تشغيل: ' . e(ts_fmt($last['ts'] ?? 0)) . ' — ' . e((string)($last['msg'] ?? '')) . '</div>';
        echo '<div class="panel"><div class="row">'; btn_post('backup', 'backup_now', [], '💾 إنشاء نسخة كاملة الآن', 'btn', 'سيستغرق الأمر من ثوانٍ إلى دقائق حسب حجم الموقع. متابعة؟'); echo '</div>';
        echo '<p class="muted hint">يُحتفظ بآخر نسختين فقط، وما كان أقدم من 4 أسابيع يُحذف (النسخة الأحدث لا تُحذف أبداً). الحذف يتم بعد إرسال الإشعار.</p></div>';
        echo '<div class="panel tw"><h2>النسخ المحفوظة</h2>';
        if (!$list) echo '<p class="muted">لا نسخ بعد.</p>';
        else {
            echo '<table><thead><tr><th>الملف</th><th>التاريخ</th><th>الحجم</th><th>مفتاح الاستعادة</th><th></th></tr></thead><tbody>';
            foreach ($list as $b) {
                echo '<tr><td dir="ltr">' . e($b['name']) . '</td><td>' . e(ts_fmt($b['ts'])) . '</td><td>' . e(fmt_size($b['size'])) . '</td><td dir="ltr"><code>' . e($b['key']) . '</code></td><td class="acts"><a class="btn ghost sm" href="' . e(adm_url('backup', ['dl' => 'file', 'f' => $b['name']])) . '">⬇ تنزيل</a>';
                btn_post('backup', 'backup_delete', ['name' => $b['name']], 'حذف', 'btn danger sm', 'حذف هذه النسخة؟'); echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div><div class="panel"><h2>إشعار البريد</h2>'; f_open('backup', 'backup_email', [], false, 'form');
        f_in('email', 'البريد الذي يصله إشعار كل نسخة وكل حذف', setting('backup_email'), 'email', 'dir="ltr"');
        echo '<div class="row"><button class="btn" type="submit">حفظ</button>'; echo '</div></form>';
        if (backup_email() !== '') btn_post('backup', 'backup_test_mail', [], 'إرسال بريد اختبار'); else echo '<p class="muted hint">⚠ بلا بريد: تُحذف النسخ القديمة وتُسجَّل في سجل النشاط فقط.</p>';
        $cmd = 'php ' . ROOT . '/cron_backup.php';
        echo '</div><div class="panel"><h2>الجدولة الأسبوعية (Cron Job)</h2><p class="muted">hPanel ← Advanced ← Cron Jobs ← «مرة في الأسبوع» والأمر:</p><p dir="ltr"><code>' . e($cmd) . '</code> <button type="button" class="btn ghost sm" data-copy="' . e($cmd) . '">نسخ</button></p>'
           . '<p class="muted hint">السكربت يتجاهل التشغيل إن وُجدت نسخة أحدث من 6 أيام، ويرسل بريداً عند الفشل.</p></div>';
        echo '<div class="panel"><h2>الاستعادة على استضافة أخرى</h2><ol class="muted"><li>أنشئ قاعدة MySQL على الاستضافة الجديدة.</li><li>فُكّ ملف الـ zip داخل <code>public_html</code> (مع مجلد <code>data</code>).</li>'
           . '<li>افتح <code dir="ltr">https://دومينك/restore.php</code> وأدخل مفتاح الاستعادة وبيانات القاعدة الجديدة.</li><li>السكربت يستورد القاعدة ويعدّل <code>config.php</code> ويحذف نفسه.</li></ol>'
           . '<p class="muted hint">⚠ الملف يحتوي config.php وكلمات مرور مُجزّأة وتوكن GitHub — لا تشاركه ولا ترفعه علناً.</p></div>';
    },
];
