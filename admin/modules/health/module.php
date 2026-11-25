<?php
declare(strict_types=1);

function health_checks(): array {
    $c = []; $add = function (string $n, bool $ok, string $d = '') use (&$c) { $c[] = [$n, $ok, $d]; };
    $add('PHP ≥ 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION);
    foreach (['pdo', 'mbstring', 'json', 'fileinfo', 'gd', 'curl'] as $x) $add("امتداد $x", extension_loaded($x));
    $add('PDO ' . db_driver(), extension_loaded('pdo_' . db_driver()));
    $add('GD: دعم WebP', function_exists('imagecreatefromwebp'), 'اختياري');
    $add('config.php', is_file(ROOT . '/config.php'));
    $add('مجلد data قابل للكتابة', is_writable(ROOT . '/data'));
    $add('مجلد assets/uploads قابل للكتابة', is_writable(upload_dir()));
    $add('HTTPS', is_https(), is_https() ? '' : 'فعّل SSL من هوستنجر ثم أعد التوجيه إلى https');
    $add('install.php محذوف', !is_file(ROOT . '/install.php'), 'يجب حذفه بعد التثبيت');
    $add('المفتاح السري قوي', strlen((string)cfg('secret', '')) >= 20);
    try { $add('الاتصال بقاعدة البيانات', (int)val('SELECT 1') === 1, db_driver()); } catch (Throwable $e) { $add('الاتصال بقاعدة البيانات', false, $e->getMessage()); }
    foreach (['settings', 'admins', 'projects', 'apps', 'app_files', 'posts', 'items', 'pages', 'messages', 'visits', 'downloads'] as $t) {
        if (table_exists($t)) $add("جدول $t", true, (string)(int)val("SELECT COUNT(*) FROM $t") . ' صف'); else $add("جدول $t", false, 'غير موجود (قد لا تحتاجه إن حذفت وحدته)');
    }
    foreach (['memory_limit', 'max_execution_time', 'upload_max_filesize', 'post_max_size'] as $i) $add("php.ini: $i", true, (string)ini_get($i));
    $add('GitHub user في config', gh_user() !== '', gh_user());
    $add('GitHub token', gh_token() !== '', gh_token() !== '' ? 'مضبوط' : 'غير مضبوط (حد 60 طلب/ساعة) — اختياري');
    $lg = ROOT . '/data/php-error.log'; $add('سجل الأخطاء', true, is_file($lg) ? fmt_size(filesize($lg)) : 'فارغ');
    return $c;
}

return [
    'title' => ['ar' => 'فحص النظام والنسخ', 'en' => 'Health & backup'], 'icon' => '✚', 'group' => 'system', 'order' => 99, 'version' => 1,
    'actions' => [
        'test_github' => function (): void {
            require_once modules_dir() . '/projects/github.php';
            try { [$code, , $rem] = gh_get('/rate_limit'); flash("GitHub API: HTTP $code" . ($rem !== null ? " · متبقي $rem" : ''), $code === 200); }
            catch (Throwable $e) { flash('فشل الاتصال بـ GitHub: ' . $e->getMessage(), false); }
        },
        'clear_log' => function (): void { @file_put_contents(ROOT . '/data/php-error.log', ''); flash('تم تفريغ سجل الأخطاء.'); },
    ],
    'download' => function (string $what): void {
        if ($what === 'report') {
            header('Content-Type: text/plain; charset=utf-8'); header('Content-Disposition: attachment; filename="health-report.txt"');
            echo "Health report " . gmdate('c') . "\nPHP " . PHP_VERSION . ' · ' . PHP_SAPI . ' · ' . PHP_OS . "\nbase=" . base() . " origin=" . site_origin() . "\n\n";
            foreach (health_checks() as [$n, $ok, $d]) echo ($ok ? '[ OK ] ' : '[FAIL] ') . $n . ($d !== '' ? " — $d" : '') . "\n";
            $lg = ROOT . '/data/php-error.log'; echo "\n--- last 60 lines of php-error.log ---\n";
            if (is_file($lg)) echo implode('', array_slice(file($lg) ?: [], -60));
        } elseif ($what === 'backup') {
            header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="backup-' . gmdate('Ymd-His') . '.json"');
            $out = ['generated' => gmdate('c'), 'driver' => db_driver(), 'tables' => []];
            foreach (['settings', 'projects', 'apps', 'app_files', 'posts', 'items', 'pages', 'messages'] as $t) if (table_exists($t)) $out['tables'][$t] = $t === 'settings' ? rows("SELECT * FROM settings WHERE k<>'github_token'") : rows("SELECT * FROM $t");
            echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
    },
    'view' => function (array $mod): void {
        adm_h('فحص النظام والنسخ الاحتياطي', 'عند ظهور أي خطأ: نزّل تقرير الفحص وأرسله لمن يساعدك — لا يحتوي كلمات مرور ولا مفاتيح.');
        echo '<div class="row" style="margin-bottom:14px"><a class="btn" href="' . adm_url('health', ['dl' => 'report']) . '">⬇ تقرير الفحص (txt)</a><a class="btn ghost" href="' . adm_url('health', ['dl' => 'backup']) . '">⬇ نسخة احتياطية للمحتوى (JSON)</a>';
        btn_post('health', 'test_github', [], 'اختبار الاتصال بـ GitHub'); btn_post('health', 'clear_log', [], 'تفريغ سجل الأخطاء', 'btn ghost sm', 'تفريغ السجل؟'); echo '</div>';

        $q = trim((string)($_GET['q'] ?? ''));
        $st = (string)($_GET['st'] ?? '');
        echo '<form method="get" class="filter-bar"><input type="hidden" name="m" value="health">'
           . '<input type="search" name="q" value="' . e($q) . '" placeholder="🔎 بحث في عناصر الفحص…">'
           . '<select name="st" onchange="this.form.submit()"><option value="">الحالة: الكل</option><option value="ok"' . ($st === 'ok' ? ' selected' : '') . '>✅ سليم فقط</option><option value="fail"' . ($st === 'fail' ? ' selected' : '') . '>⚠️ يحتاج انتباه</option></select>'
           . '<button class="btn ghost sm" type="submit">تصفية</button>'
           . (($q !== '' || $st !== '') ? '<a class="btn ghost sm" href="' . e(adm_url('health')) . '">إعادة تعيين</a>' : '')
           . '</form>';

        echo '<div class="panel tw"><table><thead><tr><th></th><th>الفحص</th><th>التفاصيل</th></tr></thead><tbody>';
        foreach (health_checks() as [$n, $ok, $d]) {
            if ($st === 'ok' && !$ok) continue;
            if ($st === 'fail' && $ok) continue;
            if ($q !== '' && mb_stripos($n . ' ' . $d, $q) === false) continue;
            echo '<tr><td>' . ($ok ? '✅' : '⚠️') . '</td><td>' . e($n) . '</td><td dir="auto">' . e($d) . '</td></tr>';
        }
        echo '</tbody></table></div><p class="muted">النسخة الاحتياطية تشمل المحتوى والإعدادات (بلا حسابات المدير ولا الزيارات). صور المشاريع في <code dir="ltr">assets/uploads/</code> تُنسخ يدوياً من مدير الملفات.</p>';
    },
];
