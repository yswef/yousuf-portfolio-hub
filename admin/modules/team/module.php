<?php
declare(strict_types=1);
/**
 * Team & roles (الفريق والصلاحيات).
 * The owner account — «امبراطور السحر يوسف» — is the only one that can create accounts,
 * change roles, reset passwords, suspend or delete members. Everyone else only sees this page
 * if their role allows it (manager), and read-only.
 */

return [
    'title' => ['ar' => 'الفريق والصلاحيات', 'en' => 'Team & roles'], 'icon' => '👥', 'group' => 'system', 'order' => 4, 'version' => 1,

    'widgets' => fn() => [
        ['label' => 'أعضاء الفريق', 'value' => table_exists('admins') ? (int)val('SELECT COUNT(*) FROM admins') : 0, 'link' => 'team'],
        ['label' => 'حسابات موقوفة', 'value' => table_exists('admins') ? (int)val("SELECT COUNT(*) FROM admins WHERE status<>'active'") : 0, 'link' => 'team'],
    ],

    'actions' => [
        'create_user' => function (): void {
            if (!team_can_manage_users()) { flash('إنشاء الحسابات صلاحية امبراطور السحر يوسف وحده.', false); adm_back('team'); }
            $r = team_create_user(P('username'), (string)($_POST['password'] ?? ''), P('role'), P('email'), P('note'));
            flash($r['msg'], $r['ok']);
            if ($r['ok']) audit('team_create', P('username') . ':' . P('role'));
            adm_back('team');
        },
        'update_user' => function (): void {
            if (!team_can_manage_users()) { flash('تعديل الأعضاء صلاحية امبراطور السحر يوسف وحده.', false); adm_back('team'); }
            $d = ['role' => P('role'), 'status' => P('status'), 'note' => P('note')];
            $pw = (string)($_POST['password'] ?? '');
            if ($pw !== '') $d['password'] = $pw;
            $r = team_update_user(PN('id'), $d);
            flash($r['msg'], $r['ok']);
            if ($r['ok']) audit('team_update', (string)PN('id'));
            adm_back('team');
        },
        'toggle_user' => function (): void {
            if (!team_can_manage_users()) { flash('تعديل الأعضاء صلاحية امبراطور السحر يوسف وحده.', false); adm_back('team'); }
            $u = row('SELECT * FROM admins WHERE id=?', [PN('id')]);
            if (!$u) { flash('الحساب غير موجود.', false); adm_back('team'); }
            if ((int)$u['is_primary'] === 1) { flash('حساب امبراطور السحر يوسف لا يُوقف.', false); adm_back('team'); }
            $st = ($u['status'] ?? 'active') === 'active' ? 'suspended' : 'active';
            upd('admins', ['status' => $st, 'updated' => time()], 'id=?', [(int)$u['id']]);
            audit('team_toggle', $u['username'] . ':' . $st);
            flash($st === 'active' ? 'أُعيد تفعيل الحساب.' : 'أُوقف الحساب — سيُخرَج فوراً.');
            adm_back('team');
        },
        'delete_user' => function (): void {
            if (!team_can_manage_users()) { flash('حذف الأعضاء صلاحية امبراطور السحر يوسف وحده.', false); adm_back('team'); }
            $r = team_delete_user(PN('id'));
            flash($r['msg'], $r['ok']);
            if ($r['ok']) audit('team_delete', (string)PN('id'));
            adm_back('team');
        },
    ],

    'view' => function (array $mod): void {
        $me = admin_current();
        $owner = team_is_owner();
        $roles = team_roles();
        adm_h('الفريق والصلاحيات', 'حساب واحد كامل الصلاحيات «امبراطور السحر يوسف»، وبقية الأعضاء بأدوار محدّدة (مبرمج، مصمم، محرر، محلل…).');

        echo '<div class="note ' . ($owner ? 'ok' : '') . '">' . ($owner
            ? '👑 أنت <b>امبراطور السحر يوسف</b> — تملك كل الصلاحيات، وإنشاء الحسابات وتحديد أدوارها وحده صلاحيتك.'
            : '👁 أنت تشاهد الفريق بدور «' . e($roles[team_role()]['label'] ?? '') . '» — إنشاء الحسابات وتعديل الأدوار صلاحية المالك فقط.') . '</div>';

        $users = team_users();
        echo '<div class="panel"><h2>الأعضاء (' . count($users) . ')</h2><div class="tw"><table><thead><tr><th>#</th><th>اسم المستخدم</th><th>الدور</th><th>الحالة</th><th>حماية</th><th>آخر دخول</th><th>ملاحظة</th><th></th></tr></thead><tbody>';
        foreach ($users as $u) {
            $role = $roles[(string)($u['role'] ?? 'editor')] ?? $roles['editor'];
            $isMe = $me && (int)$me['id'] === (int)$u['id'];
            echo '<tr><td dir="ltr">' . (int)$u['id'] . '</td>';
            echo '<td><b dir="ltr">' . e((string)$u['username']) . '</b>' . ($isMe ? ' <span class="chip ok">أنت</span>' : '') . (!empty($u['email']) ? '<br><span class="muted" dir="ltr">' . e((string)$u['email']) . '</span>' : '') . '</td>';
            echo '<td><span class="chip">' . e($role['icon'] . ' ' . $role['label']) . '</span></td>';
            echo '<td><span class="chip ' . (($u['status'] ?? 'active') === 'active' ? 'ok' : 'bad') . '">' . (($u['status'] ?? 'active') === 'active' ? 'نشط' : 'موقوف') . '</span></td>';
            echo '<td>' . (!empty($u['has_2fa']) ? '<span class="chip ok">2FA</span>' : '<span class="chip">—</span>') . ((int)$u['is_primary'] ? ' <span class="chip ok">👑 مالك</span>' : '') . '</td>';
            echo '<td>' . (!empty($u['last_login']) ? e(fmt_date((int)$u['last_login'])) : '—') . '</td>';
            echo '<td>' . e(mb_substr((string)($u['note'] ?? ''), 0, 60)) . '</td>';
            echo '<td class="acts">';
            if ($owner && !(int)$u['is_primary']) {
                btn_post('team', 'toggle_user', ['id' => $u['id']], ($u['status'] ?? 'active') === 'active' ? 'إيقاف' : 'تفعيل', 'btn ghost sm');
                btn_post('team', 'delete_user', ['id' => $u['id']], 'حذف', 'btn danger sm', 'حذف هذا الحساب نهائياً؟');
            }
            echo '</td></tr>';
            if ($owner && !(int)$u['is_primary']) {
                echo '<tr><td colspan="8"><details><summary class="muted">تعديل «' . e((string)$u['username']) . '»</summary>';
                f_open('team', 'update_user', ['id' => $u['id']], false, 'inline-edit');
                f_sel('role', 'الدور', array_map(fn($r) => $r['icon'] . ' ' . $r['label'], array_diff_key($roles, ['emperor' => 1])), (string)$u['role']);
                f_sel('status', 'الحالة', ['active' => 'نشط', 'suspended' => 'موقوف'], (string)($u['status'] ?? 'active'));
                f_in('password', 'كلمة مرور جديدة (اتركها فارغة للإبقاء)', '', 'password', 'dir="ltr" autocomplete="new-password"');
                f_in('note', 'ملاحظة داخلية', (string)($u['note'] ?? ''), 'text');
                echo '<button class="btn sm" type="submit">حفظ التعديل</button></form></details></td></tr>';
            }
        }
        echo '</tbody></table></div></div>';

        if ($owner) {
            echo '<div class="panel"><h2>➕ إضافة عضو جديد</h2>';
            f_open('team', 'create_user', [], false, 'form');
            echo '<div class="two">';
            f_in('username', 'اسم المستخدم *', '', 'text', 'required dir="ltr" autocomplete="off" placeholder="dev.yousef"');
            f_in('password', 'كلمة المرور * (8+)', '', 'password', 'required dir="ltr" autocomplete="new-password"');
            echo '</div><div class="two">';
            f_in('email', 'البريد (اختياري)', '', 'email', 'dir="ltr"');
            f_sel('role', 'الدور *', array_map(fn($r) => $r['icon'] . ' ' . $r['label'] . ' — ' . $r['desc'], array_diff_key($roles, ['emperor' => 1])), 'developer');
            echo '</div>';
            f_in('note', 'ملاحظة داخلية', '', 'text', 'placeholder="مثال: مسؤول تصميم الأمزجة"');
            echo '<button class="btn" type="submit">👥 إنشاء الحساب</button></form>';
            echo '<p class="muted">يُطلب من العضو تغيير كلمة المرور عند أول دخول. حساب «امبراطور السحر يوسف» واحد فقط ولا يُنشأ من هنا.</p></div>';
        }

        echo '<div class="panel"><h2>مصفوفة الأدوار والصلاحيات</h2><div class="role-grid">';
        $map = team_role_modules();
        $mods = modules_load();
        foreach ($roles as $rk => $r) {
            $list = $map[$rk] ?? [];
            echo '<div class="role-card"><b>' . e($r['icon'] . ' ' . $r['label']) . '</b><p>' . e($r['desc']) . '</p>';
            if (in_array('*all*', $list, true)) echo '<span class="chip ok">كل الوحدات (' . count($mods) . ')</span>';
            else {
                foreach (array_slice($list, 0, 10) as $id) {
                    if (isset($mods[$id])) echo '<span class="chip">' . e($mods[$id]['icon'] . ' ' . adm_title($mods[$id])) . '</span> ';
                }
                if (count($list) > 10) echo '<span class="chip">+' . (count($list) - 10) . '</span>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '<p class="muted" style="margin-top:12px">الحساب الأول (المالك) يملك كل الوحدات + الإجراءات. بقية الأدوار تقرأ فقط في التحليلات، ولا تنفّذ إجراءات حذف/نشر حساسة بحسب الدور. الإجراءات محمية في المتحكّم المركزي، لا في الواجهة فقط.</p></div>';
    },
];
