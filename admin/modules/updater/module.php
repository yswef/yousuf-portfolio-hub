<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

return [
    'title' => ['ar' => 'تحديث الموقع', 'en' => 'Site updater'], 'icon' => '🔄', 'group' => 'system', 'order' => 96, 'version' => 1,
    'widgets' => function (): array {
        $h = json_decode(setting('updater_history', '[]'), true) ?: [];
        return [['label' => 'آخر تحديث للنظام', 'value' => $h ? gmdate('m-d', (int)$h[0]['ts']) : 'الأصلي', 'link' => 'updater']];
    },
    'actions' => [
        'upload_zip' => function (): void {
            $f = $_FILES['release_zip'] ?? null;
            if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($f['tmp_name'])) {
                flash('يرجى اختيار ملف ZIP صالح لإصدار الموقع الجديد.', false);
                return;
            }
            $origName = basename((string)($f['name'] ?? 'release.zip'));
            if (!preg_match('/\.zip$/i', $origName)) {
                flash('يجب أن يكون الملف بصيغة .zip فقط.', false);
                return;
            }
            try {
                $res = updater_apply_from_zip((string)$f['tmp_name'], 'ZIP: ' . $origName);
                audit('site_update_zip', $origName . ' (' . $res['updated'] . ' files)');
                flash("تم تحديث الموقع بنجاح! استُبدل {$res['updated']} ملف برمجي مع الحفاظ الكامل على قاعدة البيانات والصور والإعدادات.");
            } catch (Throwable $e) {
                flash('فشل التحديث: ' . $e->getMessage(), false);
            }
        },
        'github_pull' => function (): void {
            $repo = trim(P('gh_repo', 'yswef/yousuf-portfolio-hub'), '/ ');
            $branch = trim(P('gh_branch', 'main'));
            if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) || !preg_match('#^[A-Za-z0-9_./-]+$#', $branch)) {
                flash('صيغة المستودع أو الفرع غير صحيحة (مثال: yswef/yousuf-portfolio-hub والفرع main).', false);
                return;
            }
            set_setting('updater_repo', $repo);
            set_setting('updater_branch', $branch);
            $tmp = '';
            try {
                $tmp = updater_download_github_zip($repo, $branch);
                $res = updater_apply_from_zip($tmp, "GitHub: $repo@$branch");
                audit('site_update_github', "$repo@$branch ({$res['updated']} files)");
                flash("تم سحب وتطبيق أحدث إصدار من GitHub ($repo@$branch) — حُدّث {$res['updated']} ملف دون فقدان أي بيانات.");
            } catch (Throwable $e) {
                flash('فشل التحديث من GitHub: ' . $e->getMessage(), false);
            } finally {
                if ($tmp !== '' && is_file($tmp)) @unlink($tmp);
            }
        },
        'rollback' => function (): void {
            $name = basename(P('name'));
            $path = updater_rollback_dir() . '/' . $name;
            if (!preg_match('/^rollback-\d{8}-\d{6}(?:-[a-f0-9]{4})?\.zip$/', $name) || !is_file($path)) {
                flash('نسخة التراجع غير موجودة.', false);
                return;
            }
            try {
                $res = updater_apply_from_zip($path, 'تراجع: ' . $name, true);
                audit('site_rollback', $name);
                flash("تم التراجع بنجاح إلى النسخة ($name) — استُعيد {$res['updated']} ملف برمجي دون المساس ببياناتك.");
            } catch (Throwable $e) {
                flash('فشل التراجع: ' . $e->getMessage(), false);
            }
        },
        'delete_rollback' => function (): void {
            $name = basename(P('name'));
            $path = updater_rollback_dir() . '/' . $name;
            if (preg_match('/^rollback-\d{8}-\d{6}(?:-[a-f0-9]{4})?\.zip$/', $name) && is_file($path)) {
                @unlink($path);
                flash('تم حذف نقطة التراجع.');
            }
        },
    ],
    'download' => function (string $what): void {
        $name = basename((string)($_GET['f'] ?? ''));
        $path = updater_rollback_dir() . '/' . $name;
        if ($what !== 'rollback' || !preg_match('/^rollback-\d{8}-\d{6}(?:-[a-f0-9]{4})?\.zip$/', $name) || !is_file($path)) {
            http_response_code(404); echo 'Not found'; return;
        }
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    },
    'view' => function (array $mod): void {
        adm_h('تحديث الموقع');
        $lim = 'حد الرفع: ' . ini_get('upload_max_filesize') . ' · post: ' . ini_get('post_max_size');

        echo '<div class="panel"><h2>📦 رفع إصدار جديد (ZIP)</h2>';
        f_open('updater', 'upload_zip', [], true, 'form');
        echo '<label class="dropzone" data-dropzone style="margin:10px 0">'
           . '<div style="font-size:1.8rem;margin-bottom:4px">📦</div>'
           . '<div><b>اسحب ملف إصدار الموقع (.zip) هنا</b> أو اضغط للاختيار</div>'
           . '<div class="muted" style="font-size:.8rem;margin-top:4px">' . e($lim) . '</div>'
           . '<input type="file" name="release_zip" accept=".zip,application/zip" required>'
           . '<div class="dz-file" data-dz-file></div>'
           . '<div class="dz-add" data-dz-add style="display:none"><button class="btn" type="submit">🚀 بدء التحديث الآمن</button></div>'
           . '</label></form>'
           . '<div class="note ok" style="margin:8px 0 0;font-size:.86rem">🛡 قاعدة البيانات و<code>config.php</code> و<code>data/</code> و<code>assets/uploads/</code> محمية تلقائياً ولا تُمس.</div>'
           . '</div>';

        $defRepo = setting('updater_repo', (gh_user() ?: 'yswef') . '/yousuf-portfolio-hub');
        $defBranch = setting('updater_branch', 'main');
        echo '<div class="panel"><h2>🐙 تحديث مباشر من GitHub</h2>';
        f_open('updater', 'github_pull', [], false, 'form');
        echo '<div class="two">';
        f_in('gh_repo', 'المستودع (owner/repo)', $defRepo, 'text', 'dir="ltr" required placeholder="yswef/yousuf-portfolio-hub"');
        f_in('gh_branch', 'الفرع', $defBranch, 'text', 'dir="ltr" required placeholder="main"');
        echo '</div><div class="row"><button class="btn ghost" type="submit" onclick="return confirm(\'سحب أحدث ملفات الكود من GitHub وتطبيقها الآن؟\')">⬇ سحب وتحديث من GitHub</button></div></form></div>';

        $rollbacks = updater_rollbacks();
        echo '<div class="cols">';
        echo '<div class="panel"><h2>⏪ نقاط التراجع السريع (تُنشأ تلقائياً قبل كل تحديث)</h2>';
        if (!$rollbacks) {
            echo '<p class="muted">لم يُجرَ تحديث بعد — ستظهر هنا تلقائياً نسخة من الكود السابق قبل أول عملية تحديث.</p>';
        } else {
            echo '<div class="tw"><table><thead><tr><th>النسخة</th><th>التاريخ</th><th>الحجم</th><th></th></tr></thead><tbody>';
            foreach ($rollbacks as $rb) {
                echo '<tr><td dir="ltr"><code>' . e($rb['name']) . '</code></td><td>' . e(ts_fmt($rb['ts'])) . '</td><td dir="ltr">' . e(fmt_size($rb['size'])) . '</td><td class="acts">';
                btn_post('updater', 'rollback', ['name' => $rb['name']], '⏪ استرجاع', 'btn sm', 'استرجاع ملفات الكود من هذه النسخة؟ بياناتك لن تتأثر.');
                echo ' <a class="btn ghost sm" href="' . e(adm_url('updater', ['dl' => 'rollback', 'f' => $rb['name']])) . '">⬇</a>';
                btn_post('updater', 'delete_rollback', ['name' => $rb['name']], '×', 'btn danger sm', 'حذف نقطة التراجع؟');
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';

        $hist = json_decode(setting('updater_history', '[]'), true) ?: [];
        echo '<div class="panel"><h2>📋 سجل التحديثات السابقة</h2>';
        if (!$hist) {
            echo '<p class="muted">لا توجد عمليات تحديث مسجّلة بعد.</p>';
        } else {
            echo '<div class="tw"><table><thead><tr><th>التاريخ</th><th>المصدر</th><th>الملفات المحدّثة</th></tr></thead><tbody>';
            foreach ($hist as $h) {
                echo '<tr><td>' . e(ts_fmt($h['ts'] ?? 0)) . '</td><td dir="auto">' . e((string)($h['source'] ?? '')) . '</td><td>' . (int)($h['files'] ?? 0) . ' ملف</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div>';
    },
];
