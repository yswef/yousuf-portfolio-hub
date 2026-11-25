<?php
declare(strict_types=1);

return [
    'title' => ['ar' => 'الأمان', 'en' => 'Security'], 'icon' => '🛡', 'group' => 'system', 'order' => 95, 'version' => 1,
    'actions' => [
        'password' => function (): void {
            $a = row('SELECT * FROM admins WHERE id=?', [(int)$_SESSION['admin_id']]);
            $new = (string)($_POST['new'] ?? '');
            if (!$a || !password_verify((string)($_POST['current'] ?? ''), $a['pass_hash'])) flash('كلمة المرور الحالية غير صحيحة.', false);
            elseif (strlen($new) < 10) flash('كلمة المرور الجديدة يجب ألا تقل عن 10 أحرف.', false);
            elseif ($new !== (string)($_POST['confirm'] ?? '')) flash('التأكيد لا يطابق.', false);
            else { upd('admins', ['pass_hash' => password_hash($new, PASSWORD_DEFAULT), 'must_change' => 0], 'id=?', [$a['id']]); session_regenerate_id(true); audit('password_change'); flash('تم تغيير كلمة المرور.'); }
        },
        '2fa_start' => function (): void { $_SESSION['totp_new'] = totp_new_secret(); },
        '2fa_enable' => function (): void {
            $s = (string)($_SESSION['totp_new'] ?? '');
            if ($s !== '' && totp_verify($s, P('code'))) { upd('admins', ['totp_secret' => $s], 'id=?', [(int)$_SESSION['admin_id']]); unset($_SESSION['totp_new']); audit('2fa_enable'); flash('تم تفعيل التحقق بخطوتين.'); }
            else flash('الرمز غير صحيح، أعد المحاولة.', false);
        },
        '2fa_disable' => function (): void {
            $a = row('SELECT * FROM admins WHERE id=?', [(int)$_SESSION['admin_id']]);
            if ($a && password_verify((string)($_POST['current'] ?? ''), $a['pass_hash'])) { upd('admins', ['totp_secret' => null], 'id=?', [$a['id']]); audit('2fa_disable'); flash('تم تعطيل التحقق بخطوتين.'); }
            else flash('كلمة المرور غير صحيحة.', false);
        },
    ],
    'view' => function (array $mod): void {
        $a = row('SELECT * FROM admins WHERE id=?', [(int)$_SESSION['admin_id']]);
        adm_h('الأمان');
        echo '<div class="panel"><h2>تغيير كلمة المرور</h2>'; f_open('security', 'password', [], false, 'form');
        f_in('current', 'كلمة المرور الحالية', '', 'password', 'required autocomplete="current-password" dir="ltr"');
        f_in('new', 'كلمة المرور الجديدة (10 أحرف فأكثر)', '', 'password', 'required autocomplete="new-password" dir="ltr"');
        f_in('confirm', 'تأكيد كلمة المرور', '', 'password', 'required autocomplete="new-password" dir="ltr"');
        echo '<button class="btn" type="submit">تغيير</button></form></div>';

        echo '<div class="panel"><h2>التحقق بخطوتين (اختياري)</h2>';
        if (!empty($a['totp_secret'])) {
            echo '<p class="muted">مفعّل ✓</p>'; f_open('security', '2fa_disable', [], false, 'form');
            f_in('current', 'أدخل كلمة المرور لتعطيله', '', 'password', 'required dir="ltr"'); echo '<button class="btn danger" type="submit">تعطيل</button></form>';
        } elseif (!empty($_SESSION['totp_new'])) {
            $sec = $_SESSION['totp_new']; $uri = 'otpauth://totp/' . rawurlencode((setting_l('name') ?: 'Admin') . ':' . ($a['username'] ?? '')) . '?secret=' . $sec . '&issuer=' . rawurlencode(setting_l('name') ?: 'Portfolio');
            echo '<p>افتح تطبيق المصادقة (Google Authenticator / Authy …) ← إضافة حساب ← إدخال مفتاح يدوي:</p><p><code dir="ltr" class="big">' . e(chunk_split($sec, 4, ' ')) . '</code></p>';
            echo '<p class="muted">أو الرابط: <code dir="ltr">' . e($uri) . '</code></p>';
            f_open('security', '2fa_enable', [], false, 'form'); f_in('code', 'أدخل الرمز المكوّن من 6 أرقام للتأكيد', '', 'text', 'inputmode="numeric" maxlength="6" dir="ltr" required'); echo '<button class="btn" type="submit">تفعيل</button></form>';
        } else { echo '<p class="muted">غير مفعّل.</p>'; f_open('security', '2fa_start'); echo '<button class="btn" type="submit">ابدأ التفعيل</button></form>'; }
        echo '</div>';

        $q = trim((string)($_GET['q'] ?? ''));
        $act = trim((string)($_GET['act'] ?? ''));
        $actions = array_values(array_filter(array_column(rows('SELECT DISTINCT action FROM audit_log ORDER BY action'), 'action')));
        $where = '1=1'; $p = [];
        if ($q !== '') { $where .= ' AND (admin LIKE ? OR action LIKE ? OR detail LIKE ?)'; $p = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%']; }
        if ($act !== '') { $where .= ' AND action=?'; $p[] = $act; }

        echo '<div class="panel"><h2>سجل التدقيق</h2>';
        echo '<form method="get" class="filter-bar"><input type="hidden" name="m" value="security">'
           . '<input type="search" name="q" value="' . e($q) . '" placeholder="🔎 بحث في السجل…">'
           . '<select name="act" onchange="this.form.submit()"><option value="">كل العمليات</option>';
        foreach ($actions as $ac) echo '<option value="' . e($ac) . '"' . ($act === $ac ? ' selected' : '') . '>' . e($ac) . '</option>';
        echo '</select><button class="btn ghost sm" type="submit">تصفية</button>'
           . (($q !== '' || $act !== '') ? '<a class="btn ghost sm" href="' . e(adm_url('security')) . '">إعادة تعيين</a>' : '')
           . '</form><div class="tw"><table><thead><tr><th>التاريخ</th><th>المدير</th><th>العملية</th><th>التفاصيل</th></tr></thead><tbody>';
        foreach (rows("SELECT * FROM audit_log WHERE $where ORDER BY id DESC LIMIT 100", $p) as $l) echo '<tr><td>' . e(ts_fmt($l['ts'])) . '</td><td>' . e($l['admin']) . '</td><td dir="ltr"><code>' . e($l['action']) . '</code></td><td dir="auto">' . e((string)$l['detail']) . '</td></tr>';
        echo '</tbody></table></div></div>';
    },
];
