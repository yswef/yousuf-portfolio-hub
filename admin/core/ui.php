<?php
declare(strict_types=1);

const ADM_GROUPS = ['content' => 'المحتوى', 'app' => 'التطبيقات والأنظمة', 'system' => 'النظام'];

function adm_url(string $m = 'dashboard', array $q = []): string {
    return url('admin/') . '?' . http_build_query(['m' => $m] + $q);
}
function adm_back(string $m = 'dashboard', array $q = []): never { redirect(adm_url($m, $q)); }
function flash(string $msg, bool $ok = true): void { $_SESSION['flash'] = [$msg, $ok]; }
function adm_fatal(string $msg): never {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><body style="font:16px system-ui;background:#14110f;color:#eee;padding:30px"><h2>خطأ</h2><pre style="white-space:pre-wrap">' . e($msg) . '</pre>';
    exit;
}
function adm_title(array $m): string { return $m['title']['ar'] ?? $m['title']['en'] ?? $m['id']; }

function adm_page_open(string $title): void {
    ?><!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?= e($title) ?></title>
<link rel="icon" href="<?= url('assets/img/favicon-32.png') ?>"><link rel="stylesheet" href="<?= url('assets/css/fonts.css') ?>"><link rel="stylesheet" href="<?= url('admin/assets/admin.css') ?>?v=3"></head><?php
}
function adm_login_page(string $step, string $err): void {
    adm_page_open('تسجيل الدخول');
    ?><body class="login"><form method="post" class="login-box" autocomplete="off"><?= csrf_field() ?>
<div class="login-logo">🔐</div><h1>لوحة التحكم</h1>
<?php if ($err): ?><div class="note bad"><?= e($err) ?></div><?php endif; ?>
<?php if ($step === '2fa'): ?>
<input type="hidden" name="do" value="2fa"><p class="muted">أدخل رمز التحقق من تطبيق المصادقة.</p>
<label>الرمز<input name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" dir="ltr" autofocus required></label>
<button class="btn" type="submit">تأكيد</button>
<button class="btn ghost" type="submit" name="do" value="cancel_2fa" formnovalidate>رجوع</button>
<?php else: ?>
<input type="hidden" name="do" value="login">
<label>اسم المستخدم<input name="username" autocomplete="username" dir="ltr" autofocus required></label>
<label>كلمة المرور<input name="password" type="password" autocomplete="current-password" dir="ltr" required></label>
<button class="btn" type="submit">دخول</button>
<?php endif; ?>
</form></body></html><?php
}

function adm_head(string $cur): void {
    global $MODS;
    $groups = [];
    foreach ($MODS as $id => $m) $groups[$m['group'] ?? 'app'][] = $m;
    adm_page_open('لوحة التحكم — ' . ($cur === 'dashboard' ? 'الرئيسية' : adm_title($MODS[$cur])));
    ?><body><div class="shell">
<aside class="side" data-side>
  <div class="side-top"><a class="brand" href="<?= adm_url() ?>">◆ <?= e(setting_l('name') ?: 'لوحة التحكم') ?></a>
  <button class="burger" data-side-toggle aria-label="menu">☰</button></div>
  <nav>
    <a class="<?= $cur === 'dashboard' ? 'on' : '' ?>" href="<?= adm_url() ?>"><i>⌂</i>الرئيسية</a>
    <?php foreach (ADM_GROUPS as $g => $gl): if (empty($groups[$g])) continue; ?>
      <div class="grp"><?= e($gl) ?></div>
      <?php foreach ($groups[$g] as $m): $b = isset($m['badge']) ? (int)($m['badge'])() : 0; ?>
        <a class="<?= $cur === $m['id'] ? 'on' : '' ?>" href="<?= adm_url($m['id']) ?>"><i><?= e($m['icon']) ?></i><?= e(adm_title($m)) ?><?= $b ? '<b class="pill">' . $b . '</b>' : '' ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="side-bot">
    <a href="<?= url('') ?>" target="_blank" rel="noopener">↗ عرض الموقع</a>
    <div class="who"><b><?= e((team_roles()[team_role()] ?? ['icon' => '•'])['icon'] ?? '•') ?> <?= e($_SESSION['admin_name'] ?? '') ?></b><span><?= e((team_roles()[team_role()] ?? ['label' => ''])['label']) ?></span></div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="logout"><button class="link">⎋ خروج</button></form>
  </div>
</aside>
<main class="main">
<?php if (!empty($_SESSION['flash'])): [$fm, $fo] = $_SESSION['flash']; unset($_SESSION['flash']); ?><div class="note <?= $fo ? 'ok' : 'bad' ?>"><?= e($fm) ?></div><?php endif;
}
function adm_foot(): void { echo '</main></div><script src="' . url('admin/assets/admin.js') . '?v=5" defer></script></body></html>'; }
function adm_h(string $t, string $sub = ''): void { echo '<header class="ph"><h1>' . e($t) . '</h1>' . ($sub !== '' ? '<p class="muted">' . e($sub) . '</p>' : '') . '</header>'; }

/* ---------- form helpers ---------- */
function f_open(string $m, string $do, array $hidden = [], bool $multipart = false, string $cls = ''): void {
    echo '<form method="post"' . ($multipart ? ' enctype="multipart/form-data"' : '') . ($cls ? ' class="' . e($cls) . '"' : '') . '>' . csrf_field()
       . '<input type="hidden" name="m" value="' . e($m) . '"><input type="hidden" name="do" value="' . e($do) . '">';
    foreach ($hidden as $k => $v) echo '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
}
function f_in(string $name, string $label, $val = '', string $type = 'text', string $attr = ''): void {
    echo '<label>' . e($label) . '<input type="' . e($type) . '" name="' . e($name) . '" value="' . e((string)$val) . '" ' . $attr . '></label>';
}
function f_ta(string $name, string $label, $val = '', int $rows = 4, string $attr = ''): void {
    echo '<label>' . e($label) . '<textarea name="' . e($name) . '" rows="' . $rows . '" ' . $attr . '>' . e((string)$val) . '</textarea></label>';
}
function f_chk(string $name, string $label, $on): void {
    echo '<label class="chk"><input type="checkbox" name="' . e($name) . '" value="1" ' . ($on ? 'checked' : '') . '>' . e($label) . '</label>';
}
function f_sel(string $name, string $label, array $opts, $cur): void {
    echo '<label>' . e($label) . '<select name="' . e($name) . '">';
    foreach ($opts as $v => $n) echo '<option value="' . e((string)$v) . '"' . ((string)$v === (string)$cur ? ' selected' : '') . '>' . e($n) . '</option>';
    echo '</select></label>';
}
/** Per-language field: user enters Arabic only (auto-translated on save); language tabs remain available for optional review/override. */
function f_i18n(string $name, string $label, array $vals = [], bool $area = false, int $rows = 3): void {
    echo '<div class="i18n"><div class="i18n-h"><span class="i18n-lbl">' . e($label) . '</span>'
       . '<span class="tabs"><button type="button" class="tab auto-btn" data-autotranslate title="ترجمة فورية من العربية لكل اللغات">🌐 ترجمة</button>';
    $first = true;
    foreach (LANGS as $l => $info) {
        echo '<button type="button" class="tab' . ($first ? ' on' : '') . (trim((string)($vals[$l] ?? '')) !== '' ? ' has' : '') . '" data-lang="' . $l . '">' . strtoupper($l) . '</button>';
        $first = false;
    }
    echo '</span></div>'; $first = true;
    foreach (LANGS as $l => $info) {
        echo '<div class="pane' . ($first ? ' on' : '') . '" data-lang="' . $l . '">';
        $v = e((string)($vals[$l] ?? ''));
        $ph = $l === 'ar' ? ' placeholder="النص بالعربية…"' : ' placeholder="يُترجم تلقائياً من العربية"';
        if ($area) echo '<textarea name="' . e($name) . '[' . $l . ']" rows="' . $rows . '" dir="' . $info['dir'] . '"' . $ph . '>' . $v . '</textarea>';
        else echo '<input name="' . e($name) . '[' . $l . ']" value="' . $v . '" dir="' . $info['dir'] . '"' . $ph . '>';
        echo '</div>'; $first = false;
    }
    echo '</div>';
}
function f_image(string $name, string $label, ?string $cur = null): void {
    echo '<div class="fimg"><label>' . e($label) . '<input type="file" name="' . e($name) . '" accept="image/jpeg,image/png,image/webp"></label>';
    if ($cur) echo '<div class="fimg-cur"><img src="' . e(upload_url($cur)) . '" alt=""><label class="chk"><input type="checkbox" name="' . e($name) . '_remove" value="1">حذف الصورة</label></div>';
    echo '</div>';
}
function btn_post(string $m, string $do, array $hidden, string $label, string $cls = 'btn ghost sm', string $confirm = ''): void {
    echo '<form method="post" class="inline"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
       . '<input type="hidden" name="m" value="' . e($m) . '"><input type="hidden" name="do" value="' . e($do) . '">';
    foreach ($hidden as $k => $v) echo '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
    echo '<button class="' . e($cls) . '">' . e($label) . '</button></form>';
}
function P(string $k, string $d = ''): string { return trim((string)($_POST[$k] ?? $d)); }
function PN(string $k): int { return (int)($_POST[$k] ?? 0); }
function PB(string $k): int { return isset($_POST[$k]) ? 1 : 0; }
function ts_fmt($ts): string { return $ts ? gmdate('Y-m-d H:i', (int)$ts) . ' UTC' : ''; }
