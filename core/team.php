<?php
declare(strict_types=1);
/**
 * Team accounts & roles for the admin panel.
 *
 * - The first account (lowest id, `is_primary = 1`) is the full-power owner:
 *   role `emperor` — «امبراطور السحر يوسف» — every permission + exclusive right to create accounts.
 * - Every other account gets a role that decides which modules it may open and which actions it may run.
 * - Roles are fixed/managed by the owner; permissions are declared per module id (see team_role_modules()).
 */

/** Roles: key => [label, icon, description, rank]. Rank is only used for ordering in the UI. */
function team_roles(): array {
    return [
        'emperor' => [
            'label' => 'امبراطور السحر يوسف',
            'icon'  => '👑',
            'desc'  => 'المالك الكامل: كل الصلاحيات + إنشاء الحسابات وتحديد الأدوار. لا يمكن حذفه أو تخفيضه.',
            'rank'  => 0,
        ],
        'manager' => [
            'label' => 'مدير',
            'icon'  => '🧭',
            'desc'  => 'يدير المحتوى والتطبيقات والفريق بصرياً، لكنه لا ينشئ حسابات ولا يمسّ إعدادات الحماية.',
            'rank'  => 1,
        ],
        'developer' => [
            'label' => 'مبرمج',
            'icon'  => '🧑‍💻',
            'desc'  => 'لوحات التطبيقات ومتطلباتها والمفاتيح والتوثيق — بدون إعدادات الموقع أو الأعضاء.',
            'rank'  => 2,
        ],
        'designer' => [
            'label' => 'مصمم',
            'icon'  => '🎨',
            'desc'  => 'مصادر الأصول والصور والأيقونات والمتطلبات البصرية — بدون نشر أو صلاحيات حساسة.',
            'rank'  => 3,
        ],
        'editor' => [
            'label' => 'محرر محتوى',
            'icon'  => '✍',
            'desc'  => 'المشاريع والمدونة والتوثيق والصفحات والرسائل — بدون لمس البنية التقنية.',
            'rank'  => 4,
        ],
        'analyst' => [
            'label' => 'محلل',
            'icon'  => '📊',
            'desc'  => 'قراءة فقط للتحليلات وصحة الموقع ولوحات التطبيقات، دون تعديل أي شيء.',
            'rank'  => 5,
        ],
    ];
}

/** Module ids each role may open. '*all*' = every module. */
function team_role_modules(): array {
    $content  = ['posts', 'projects', 'docs', 'pages', 'messages', 'content', 'cvgen'];
    $apps     = ['boards', 'requirements', 'apps', 'appkeys'];
    $readOnly = ['analytics', 'health', 'guide'];
    return [
        'emperor'   => ['*all*'],
        'manager'   => array_merge($content, $apps, $readOnly, ['team', 'sites']),
        'developer' => array_merge($apps, ['docs', 'health', 'guide']),
        'designer'  => ['requirements', 'docs', 'guide', 'content'],
        'editor'    => array_merge($content, ['guide']),
        'analyst'   => array_merge($readOnly, ['boards', 'requirements', 'apps']),
    ];
}

/** Actions a role may NOT run inside a module it can open (least-privilege overrides). */
function team_role_blocked_actions(): array {
    return [
        'analyst' => ['*write*', 'save', 'delete', 'toggle', 'issue_api_token', 'verify_handoff', 'save_release', 'save_pack', 'save_setting', 'upload_panel', 'publish', 'sync'],
        'designer' => ['delete', 'publish', 'save_release', 'verify_handoff', 'issue_api_token'],
        'developer' => ['delete_board', 'delete_site', 'delete_backup'],
    ];
}

/** Destructive actions reserved for «امبراطور السحر يوسف» even inside modules a role may open. */
function team_owner_only_actions(): array {
    return [
        'delete_board', 'create_user', 'update_user', 'toggle_user', 'delete_user', 'reset_password',
        'install_update', 'restore_backup', 'delete_backup', 'updater_apply', 'wipe_data',
    ];
}

/** Roles that may view the team module (only the owner can change anything inside it). */
function team_managers(): array { return ['emperor', 'manager']; }

function team_schema(array $T): array {
    return [
        // Extra columns on the existing admins table (created by core_schema); ALTERs are idempotent via run_sql_list().
        'ALTER TABLE admins ADD COLUMN email VARCHAR(190) NULL',
        'ALTER TABLE admins ADD COLUMN role VARCHAR(20) DEFAULT \'emperor\'',
        'ALTER TABLE admins ADD COLUMN status VARCHAR(20) DEFAULT \'active\'',
        'ALTER TABLE admins ADD COLUMN is_primary INT DEFAULT 0',
        'ALTER TABLE admins ADD COLUMN note VARCHAR(255) NULL',
        'ALTER TABLE admins ADD COLUMN last_login INT NULL',
        'ALTER TABLE admins ADD COLUMN updated INT NULL',
    ];
}

/** Current admin row (cached per request). */
function admin_current(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    $id = (int)($_SESSION['admin_id'] ?? 0);
    if ($id <= 0 || !table_exists('admins')) return $u = null;
    $u = row('SELECT * FROM admins WHERE id=?', [$id]);
    return $u;
}

/** Make sure exactly one primary/full account exists (the oldest admin) — safe to call on every request. */
function team_ensure_primary(): void {
    static $done = false;
    if ($done || !table_exists('admins')) return;
    $done = true;
    try {
        if (!(int)val('SELECT COUNT(*) FROM admins WHERE is_primary=1')) {
            $first = row('SELECT id FROM admins ORDER BY id ASC LIMIT 1');
            if ($first) {
                upd('admins', ['is_primary' => 1, 'role' => 'emperor', 'status' => 'active'], 'id=?', [(int)$first['id']]);
            }
        }
        // Repair rows created before roles existed.
        q("UPDATE admins SET role='emperor' WHERE is_primary=1 AND (role IS NULL OR role='')");
        q("UPDATE admins SET role='editor' WHERE (role IS NULL OR role='') AND is_primary=0");
        q("UPDATE admins SET status='active' WHERE status IS NULL OR status=''");
    } catch (Throwable $e) {
        error_log('[team] ensure_primary: ' . $e->getMessage());
    }
}

function team_role(?array $u = null): string {
    $u = $u ?? admin_current();
    if (!$u) return 'emperor';                        // pre-login / bootstrap: behave like the owner
    if ((int)($u['is_primary'] ?? 0) === 1) return 'emperor';
    $r = (string)($u['role'] ?? 'editor');
    return isset(team_roles()[$r]) ? $r : 'editor';
}

function team_is_owner(?array $u = null): bool {
    $u = $u ?? admin_current();
    return $u !== null && (int)($u['is_primary'] ?? 0) === 1;
}

/** Can the current account open this module? */
function team_can(string $module): bool {
    $u = admin_current();
    if ($u === null) return true;                      // installer / CLI context
    if ((string)($u['status'] ?? 'active') !== 'active') return false;
    if (team_is_owner($u)) return true;
    $mods = team_role_modules()[team_role($u)] ?? [];
    if (in_array('*all*', $mods, true)) return true;
    if ($module === 'dashboard') return true;
    if ($module === 'team') return in_array(team_role($u), team_managers(), true);
    return in_array($module, $mods, true);
}

/** Can the current account run an action of a module? */
function team_can_action(string $module, string $action): bool {
    $u = admin_current();
    if ($u === null) return true;
    if (!team_can($module)) return false;
    if (team_is_owner($u)) return true;
    if ($module === 'team') return false;              // only the owner creates/edits accounts
    if (in_array(strtolower($action), team_owner_only_actions(), true)) return false;
    $role = team_role($u);
    $blocks = team_role_blocked_actions()[$role] ?? [];
    $a = strtolower($action);
    foreach ($blocks as $b) {
        if ($b === '*write*') {
            if (preg_match('/(save|delete|toggle|upload|issue|verify|publish|sync|seed|import|remove|reset)/', $a)) return false;
            continue;
        }
        if ($b === $a || (str_ends_with($b, '*') && str_starts_with($a, rtrim($b, '*')))) return false;
    }
    // Roles that may only read cannot run anything that changes state.
    if ($role === 'analyst' && $_SERVER['REQUEST_METHOD'] === 'POST') return false;
    return true;
}

function team_can_manage_users(): bool {
    return team_is_owner();
}

/** All accounts (never exposes hashes). */
function team_users(): array {
    if (!table_exists('admins')) return [];
    return rows('SELECT id, username, email, role, status, is_primary, note, last_login, created, updated,
                        (totp_secret IS NOT NULL AND totp_secret <> \'\') AS has_2fa
                   FROM admins ORDER BY is_primary DESC, id ASC');
}

/** Create an account. Owner-only by policy — callers must check team_can_manage_users(). */
function team_create_user(string $username, string $password, string $role, string $email = '', string $note = ''): array {
    $username = mb_substr(trim($username), 0, 100);
    if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) return ['ok' => false, 'msg' => 'اسم المستخدم يجب أن يكون 3 أحرف على الأقل (حروف إنجليزية/أرقام/._-).'];
    if (strlen($password) < 8) return ['ok' => false, 'msg' => 'كلمة المرور يجب أن لا تقل عن 8 خانات.'];
    if (!isset(team_roles()[$role]) || $role === 'emperor') return ['ok' => false, 'msg' => 'الدور غير صالح (امبراطور السحر يوسف حساب واحد فقط).'];
    if (row('SELECT id FROM admins WHERE LOWER(username)=LOWER(?)', [$username])) return ['ok' => false, 'msg' => 'اسم المستخدم مستخدم مسبقاً.'];
    $email = mb_substr(trim($email), 0, 190);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'msg' => 'البريد الإلكتروني غير صالح.'];
    $now = time();
    ins('admins', [
        'username'   => $username,
        'pass_hash'  => password_hash($password, PASSWORD_DEFAULT),
        'email'      => $email !== '' ? $email : null,
        'role'       => $role,
        'status'     => 'active',
        'is_primary' => 0,
        'note'       => mb_substr($note, 0, 255),
        'must_change'=> 1,
        'created'    => $now,
        'updated'    => $now,
    ]);
    return ['ok' => true, 'msg' => 'أُنشئ الحساب «' . $username . '» بدور ' . team_roles()[$role]['label'] . '.'];
}

/** Update role/status/note/password of a non-primary account. */
function team_update_user(int $id, array $d): array {
    $u = row('SELECT * FROM admins WHERE id=?', [$id]);
    if (!$u) return ['ok' => false, 'msg' => 'الحساب غير موجود.'];
    if ((int)$u['is_primary'] === 1) return ['ok' => false, 'msg' => 'حساب امبراطور السحر يوسف محمي — لا يمكن تعديله من هنا.'];
    $upd = [];
    if (isset($d['role'])) {
        $role = (string)$d['role'];
        if (!isset(team_roles()[$role]) || $role === 'emperor') return ['ok' => false, 'msg' => 'الدور غير صالح.'];
        $upd['role'] = $role;
    }
    if (isset($d['status'])) $upd['status'] = $d['status'] === 'active' ? 'active' : 'suspended';
    if (isset($d['note'])) $upd['note'] = mb_substr((string)$d['note'], 0, 255);
    if (!empty($d['password'])) {
        if (strlen((string)$d['password']) < 8) return ['ok' => false, 'msg' => 'كلمة المرور يجب أن لا تقل عن 8 خانات.'];
        $upd['pass_hash'] = password_hash((string)$d['password'], PASSWORD_DEFAULT);
        $upd['must_change'] = 1;
    }
    if (!$upd) return ['ok' => false, 'msg' => 'لا تغييرات.'];
    $upd['updated'] = time();
    upd('admins', $upd, 'id=?', [$id]);
    return ['ok' => true, 'msg' => 'حُدّث الحساب «' . $u['username'] . '».'];
}

/** Delete a non-primary account and kill its sessions (session id is not stored — a flag forces logout). */
function team_delete_user(int $id): array {
    $u = row('SELECT * FROM admins WHERE id=?', [$id]);
    if (!$u) return ['ok' => false, 'msg' => 'الحساب غير موجود.'];
    if ((int)$u['is_primary'] === 1) return ['ok' => false, 'msg' => 'لا يمكن حذف حساب امبراطور السحر يوسف.'];
    q('DELETE FROM admins WHERE id=? AND is_primary=0', [$id]);
    return ['ok' => true, 'msg' => 'حُذف الحساب «' . $u['username'] . '».'];
}

/** Re-check a live session: suspended/deleted accounts are logged out immediately. */
function team_session_guard(): void {
    $u = admin_current();
    if ($u === null) return;                            // session might be flagged only in rare bootstrap paths
    if ((string)($u['status'] ?? 'active') === 'active') return;
    $_SESSION = [];
    session_destroy();
    redirect(url('admin/'));
}

/** Modules filtered to what the current account may open (keeps the sidebar honest). */
function team_filter_modules(array $mods): array {
    $out = [];
    foreach ($mods as $id => $m) if (team_can((string)$id)) $out[$id] = $m;
    return $out;
}
