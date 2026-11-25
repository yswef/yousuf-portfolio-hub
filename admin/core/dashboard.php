<?php
/** Core dashboard: period filter, executive KPIs, 14-day visual chart, breakdowns, per-board stats, recent activity. */
$days = in_array((int)($_GET['d'] ?? 30), [7, 30, 90, 0], true) ? (int)($_GET['d'] ?? 30) : 30;
$since = $days > 0 ? time() - $days * 86400 : 0;

echo '<div class="dash-top">';
adm_h('الرئيسية — نظرة شاملة', 'مرحباً ' . ($ADMIN['name'] ?? '') . ' · متابعة حية للمشاريع والتطبيقات والزيارات');
echo '<div class="row">';
foreach ([7 => '7 أيام', 30 => '30 يوم', 90 => '90 يوم', 0 => 'الكل'] as $dk => $dlb) {
    echo '<a class="btn sm ' . ($dk === $days ? '' : 'ghost') . '" href="' . e(adm_url('dashboard', $dk === 30 ? [] : ['d' => $dk])) . '">' . $dlb . '</a>';
}
echo '</div></div>';

$warn = [];
if (is_file(ROOT . '/install.php')) $warn[] = 'ملف install.php ما زال موجوداً على الاستضافة — احذفه الآن من مدير الملفات.';
if (cfg('secret', 'x') === 'x' || strlen((string)cfg('secret', '')) < 20) $warn[] = 'المفتاح السري (secret) في config.php ضعيف أو غير مضبوط.';
if (!is_writable(upload_dir())) $warn[] = 'مجلد assets/uploads غير قابل للكتابة — لن ترفع الصور.';
if (!is_writable(ROOT . '/data')) $warn[] = 'مجلد data غير قابل للكتابة — لن تُكتب سجلات الأخطاء.';
foreach ($warn as $w) echo '<div class="note bad">⚠ ' . e($w) . '</div>';

// Executive summary metrics
$totalStars = table_exists('projects') ? (int)val('SELECT COALESCE(SUM(stars),0) FROM projects WHERE hidden=0') : 0;
$ghDl = table_exists('app_files') ? (int)val('SELECT COALESCE(SUM(gh_count),0) FROM app_files WHERE hidden=0') : 0;
$siteDl = table_exists('downloads') ? (int)val('SELECT COUNT(*) FROM downloads WHERE ts>=?', [$since]) : 0;
$periodVisits = table_exists('visits') ? (int)val('SELECT COUNT(*) FROM visits WHERE is_bot=0 AND ts>=?', [$since]) : 0;
$periodUniq = table_exists('visits') ? (int)val('SELECT COUNT(DISTINCT visitor) FROM visits WHERE is_bot=0 AND ts>=?', [$since]) : 0;
$boardUsers = table_exists('boards') ? (int)val('SELECT COALESCE(SUM(users_count),0) FROM boards') : 0;

echo '<div class="kpis kpis-hero">';
echo '<a class="kpi" href="' . e(adm_url('analytics', ['d' => $days ?: 365])) . '"><span class="kpi-ic">▤</span><b>' . number_format($periodVisits) . '</b><span>زيارات (' . ($days ? $days . ' يوم' : 'الكل') . ') · ' . number_format($periodUniq) . ' فريد</span></a>';
echo '<a class="kpi" href="' . e(adm_url('apps')) . '"><span class="kpi-ic">⬇</span><b>' . number_format($ghDl + $siteDl) . '</b><span>إجمالي التنزيلات (' . number_format($siteDl) . ' عبر الموقع)</span></a>';
echo '<a class="kpi" href="' . e(adm_url('projects')) . '"><span class="kpi-ic">★</span><b>' . number_format($totalStars) . '</b><span>نجوم GitHub للمشاريع</span></a>';
echo '<a class="kpi" href="' . e(adm_url('boards')) . '"><span class="kpi-ic">▦</span><b>' . number_format($boardUsers) . '</b><span>مستخدمو التطبيقات المدارة</span></a>';
echo '</div>';

// Module KPI tiles
$tiles = [];
foreach ($MODS as $m) if (isset($m['widgets'])) foreach (($m['widgets'])() as $w) $tiles[] = $w + ['module' => $m['id'], 'icon' => $m['icon'] ?? '◆'];
if ($tiles): ?><div class="kpis"><?php foreach ($tiles as $w): ?>
  <a class="kpi" href="<?= e(adm_url($w['link'] ?? $w['module'])) ?>"><b><?= e((string)$w['value']) ?></b><span><?= e(($w['icon'] ?? '') . ' ' . $w['label']) ?></span></a>
<?php endforeach; ?></div><?php endif; ?>

<div class="panel"><h2>إجراءات سريعة</h2><div class="row">
  <a class="btn" href="<?= e(adm_url('boards')) ?>">▦ لوحات الإدارة</a>
  <a class="btn ghost" href="<?= e(adm_url('appkeys')) ?>">🔑 مولّد مفاتيح التطبيقات</a>
  <a class="btn ghost" href="<?= e(adm_url('cvgen')) ?>">📄 مولّد السيرة الذاتية</a>
  <a class="btn ghost" href="<?= e(adm_url('projects', ['edit' => 0])) ?>">＋ مشروع جديد</a>
  <a class="btn ghost" href="<?= e(adm_url('apps', ['edit' => 0])) ?>">＋ تطبيق جديد</a>
  <a class="btn ghost" href="<?= e(adm_url('settings')) ?>">⚙ الإعدادات والترجمة</a>
  <a class="btn ghost" href="<?= e(adm_url('backup')) ?>">💾 النسخ الاحتياطي</a>
</div></div>

<?php
// 14-day daily visits + downloads chart
if (table_exists('visits')):
    $chartDays = [];
    for ($i = 13; $i >= 0; $i--) {
        $dKey = gmdate('m-d', time() - $i * 86400);
        $chartDays[$dKey] = ['v' => 0, 'dl' => 0];
    }
    $cut14 = time() - 14 * 86400;
    foreach (rows('SELECT ts FROM visits WHERE is_bot=0 AND ts>=?', [$cut14]) as $v) {
        $k = gmdate('m-d', (int)$v['ts']);
        if (isset($chartDays[$k])) $chartDays[$k]['v']++;
    }
    if (table_exists('downloads')) {
        foreach (rows('SELECT ts FROM downloads WHERE ts>=?', [$cut14]) as $dlRow) {
            $k = gmdate('m-d', (int)$dlRow['ts']);
            if (isset($chartDays[$k])) $chartDays[$k]['dl']++;
        }
    }
    $maxBar = max([1] + array_map(fn($x) => max($x['v'], $x['dl']), $chartDays));
?>
<div class="panel">
  <div class="row" style="justify-content:space-between;margin-bottom:10px">
    <h2 style="margin:0">نشاط آخر 14 يوماً (الزيارات والتنزيلات)</h2>
    <span class="muted" style="font-size:.82rem"><span class="legend-dot v"></span> زيارات &nbsp; <span class="legend-dot dl"></span> تنزيلات</span>
  </div>
  <div class="vchart" dir="ltr">
    <?php foreach ($chartDays as $day => $vals):
      $hv = $vals['v'] > 0 ? max(8, (int)round($vals['v'] / $maxBar * 100)) : 2;
      $hd = $vals['dl'] > 0 ? max(8, (int)round($vals['dl'] / $maxBar * 100)) : 0; ?>
      <div class="vchart-col" title="<?= e($day . ': ' . $vals['v'] . ' زيارة · ' . $vals['dl'] . ' تنزيل') ?>">
        <div class="vchart-bars">
          <i class="b-v" style="height:<?= $hv ?>%"></i>
          <?php if ($hd > 0): ?><i class="b-dl" style="height:<?= $hd ?>%"></i><?php endif; ?>
        </div>
        <span><?= e($day) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php
// Breakdown cards: projects by language, top apps, devices
$langBreak = table_exists('projects') ? rows('SELECT COALESCE(NULLIF(language,""), "أخرى") AS k, COUNT(*) AS n FROM projects WHERE hidden=0 GROUP BY k ORDER BY n DESC LIMIT 6') : [];
$topApps = table_exists('apps') ? rows('SELECT a.name AS k, ((SELECT COALESCE(SUM(gh_count),0) FROM app_files f WHERE f.app_id=a.id) + (SELECT COUNT(*) FROM downloads d WHERE d.app_id=a.id)) AS n FROM apps a WHERE a.ignored=0 ORDER BY n DESC, a.id DESC LIMIT 6') : [];
$devBreak = table_exists('visits') ? rows('SELECT COALESCE(NULLIF(device,""), "غير محدد") AS k, COUNT(*) AS n FROM visits WHERE is_bot=0 AND ts>=? GROUP BY k ORDER BY n DESC LIMIT 5', [$since]) : [];
$renderBars = function (array $items): void {
    if (!$items) { echo '<p class="muted">لا بيانات بعد.</p>'; return; }
    $m = max([1] + array_map(fn($x) => (int)$x['n'], $items));
    echo '<div class="tw"><table><tbody>';
    foreach ($items as $x) {
        $pct = round(((int)$x['n']) / $m * 100);
        echo '<tr><td>' . e((string)$x['k']) . '</td><td style="width:58%"><div class="hb"><i style="width:' . $pct . '%"></i>' . (int)$x['n'] . '</div></td></tr>';
    }
    echo '</tbody></table></div>';
};
?>
<div class="cols" style="margin-bottom:18px">
  <div class="panel" style="margin-bottom:0"><h2>توزيع المستودعات حسب التقنية</h2><?php $renderBars($langBreak); ?></div>
  <div class="panel" style="margin-bottom:0"><h2>أعلى التطبيقات تنزيلاً</h2><?php $renderBars($topApps); ?></div>
  <div class="panel" style="margin-bottom:0"><h2>أجهزة الزوار</h2><?php $renderBars($devBreak); ?></div>
</div>

<?php if (table_exists('boards')):
    $bq = trim((string)($_GET['bq'] ?? ''));
    $bsort = in_array((string)($_GET['bsort'] ?? 'users'), ['users', 'downloads', 'newest'], true) ? (string)($_GET['bsort'] ?? 'users') : 'users';
    $bOrder = $bsort === 'downloads' ? 'downloads_count DESC, id DESC' : ($bsort === 'newest' ? 'id DESC' : 'users_count DESC, id DESC');
    $bWhere = $bq !== '' ? 'name LIKE ? OR description LIKE ?' : '1=1';
    $bPrm = $bq !== '' ? ['%' . $bq . '%', '%' . $bq . '%'] : [];
    $boards = rows("SELECT * FROM boards WHERE $bWhere ORDER BY $bOrder LIMIT 12", $bPrm);
    $totalBoards = (int)val('SELECT COUNT(*) FROM boards');
    if ($totalBoards > 0): ?>
<div class="panel">
  <div class="row" style="justify-content:space-between;margin-bottom:12px">
    <h2 style="margin:0">إحصائيات التطبيقات (منفصلة لكل لوحة)</h2>
    <form method="get" class="filter-bar" style="margin:0">
      <?php if ($days !== 30): ?><input type="hidden" name="d" value="<?= $days ?>"><?php endif; ?>
      <input type="search" name="bq" value="<?= e($bq) ?>" placeholder="🔎 بحث في اللوحات…">
      <select name="bsort" onchange="this.form.submit()">
        <option value="users"<?= $bsort === 'users' ? ' selected' : '' ?>>الترتيب: الأكثر مستخدمين</option>
        <option value="downloads"<?= $bsort === 'downloads' ? ' selected' : '' ?>>الترتيب: الأكثر تنزيلاً</option>
        <option value="newest"<?= $bsort === 'newest' ? ' selected' : '' ?>>الترتيب: الأحدث</option>
      </select>
      <button class="btn ghost sm" type="submit">تطبيق</button>
    </form>
  </div>
  <?php if (!$boards): ?><p class="muted">لا لوحات مطابقة.</p><?php else: ?>
  <div class="boards-grid">
  <?php foreach ($boards as $b): ?>
    <a class="board-card" href="<?= e(adm_url('boards', ['edit' => $b['id']])) ?>" style="text-decoration:none;color:inherit">
      <div class="board-head"><div class="board-icon"><?= !empty($b['icon']) ? '<img src="' . e(upload_url((string)$b['icon'])) . '" alt="">' : '▦' ?></div>
        <div style="flex:1;min-width:0"><div class="board-title"><?= e($b['name']) ?></div><div class="board-meta"><span class="chip"><?= e(BOARDS_TYPES[$b['app_type']] ?? $b['app_type']) ?></span><span class="chip"><?= e(BOARDS_STATUS[$b['status']] ?? $b['status']) ?></span></div></div></div>
      <div class="board-stats">
        <div class="board-stat"><b><?= (int)$b['users_count'] ?></b><span>مستخدمون</span></div>
        <div class="board-stat"><b><?= (int)$b['downloads_count'] ?></b><span>تنزيلات</span></div>
        <div class="board-stat"><b><?= (int)$b['licenses_count'] ?></b><span>تراخيص</span></div>
        <div class="board-stat"><b><?= e((string)($b['rating'] ?: '—')) ?></b><span>تقييم</span></div>
      </div>
    </a>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; endif; ?>

<div class="cols">
<?php if (table_exists('audit_log')): $acts = rows('SELECT * FROM audit_log ORDER BY id DESC LIMIT 8'); if ($acts): ?>
<div class="panel"><h2>آخر النشاطات</h2><div class="tw"><table><tbody>
<?php foreach ($acts as $a): ?><tr><td><?= e(ts_fmt($a['ts'])) ?></td><td><code><?= e($a['action']) ?></code></td><td class="muted" dir="auto"><?= e((string)$a['detail']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><p style="margin-top:8px"><a href="<?= e(adm_url('security')) ?>">عرض السجل الكامل ←</a></p></div>
<?php endif; endif; ?>
<?php if (table_exists('messages')): $msgs = rows('SELECT * FROM messages ORDER BY id DESC LIMIT 5'); if ($msgs): ?>
<div class="panel"><h2>أحدث الرسائل</h2><div class="tw"><table><tbody>
<?php foreach ($msgs as $m): ?><tr><td><b><?= e($m['name']) ?></b><br><span class="muted" dir="ltr"><?= e($m['email']) ?></span></td><td><?= e(mb_substr((string)$m['body'], 0, 90)) ?></td><td><?= e(fmt_date($m['ts'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div><p style="margin-top:8px"><a href="<?= e(adm_url('messages')) ?>">فتح الرسائل ←</a></p></div>
<?php endif; endif; ?>
<div class="panel"><h2>النظام والبيئة</h2><div class="tw"><table><tbody>
<tr><td>إصدار PHP</td><td dir="ltr"><?= e(PHP_VERSION) ?></td></tr>
<tr><td>محرك القاعدة</td><td dir="ltr"><?= e(db_driver()) ?></td></tr>
<tr><td>الترجمة التلقائية</td><td><?= auto_translate_enabled() ? '<span class="chip ok">مفعّلة ✓</span>' : '<span class="chip">معطّلة</span>' ?></td></tr>
<tr><td>الوحدات المثبّتة</td><td><?= count($MODS) ?> وحدة</td></tr>
<tr><td>حجم مجلد data</td><td dir="ltr"><?= e(fmt_size((int)@array_sum(array_map(fn($f) => is_file($f) ? filesize($f) : 0, glob(ROOT . '/data/*') ?: [])))) ?></td></tr>
</tbody></table></div></div>
</div>

<div class="panel"><h2>الوحدات المثبّتة (<?= count($MODS) ?>)</h2><div class="tw"><table><thead><tr><th>الوحدة</th><th>المعرّف</th><th>المجموعة</th><th>الإصدار</th></tr></thead><tbody>
<?php foreach ($MODS as $m): ?><tr><td><a href="<?= e(adm_url($m['id'])) ?>"><?= e($m['icon'] . ' ' . adm_title($m)) ?></a></td><td dir="ltr"><code><?= e($m['id']) ?></code></td><td><?= e(ADM_GROUPS[$m['group']] ?? $m['group']) ?></td><td dir="ltr">v<?= (int)$m['version'] ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<p class="muted" style="margin-top:8px">لإضافة لوحة تحكم لتطبيق آخر: ضع مجلد الوحدة في <code dir="ltr">admin/modules/</code> فتظهر هنا تلقائياً. التفاصيل في <code dir="ltr">admin/MODULES.md</code>.</p></div>
