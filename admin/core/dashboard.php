<?php
/** Core dashboard: aggregates every module's 'widgets' (KPI tiles) + environment warnings. */
adm_h('الرئيسية', 'مرحباً ' . ($ADMIN['name'] ?? ''));
$warn = [];
if (is_file(ROOT . '/install.php')) $warn[] = 'ملف install.php ما زال موجوداً على الاستضافة — احذفه الآن من مدير الملفات.';
if (cfg('secret', 'x') === 'x' || strlen((string)cfg('secret', '')) < 20) $warn[] = 'المفتاح السري (secret) في config.php ضعيف أو غير مضبوط.';
if (!is_writable(upload_dir())) $warn[] = 'مجلد assets/uploads غير قابل للكتابة — لن ترفع الصور.';
if (!is_writable(ROOT . '/data')) $warn[] = 'مجلد data غير قابل للكتابة — لن تُكتب سجلات الأخطاء.';
foreach ($warn as $w) echo '<div class="note bad">⚠ ' . e($w) . '</div>';

$tiles = [];
foreach ($MODS as $m) if (isset($m['widgets'])) foreach (($m['widgets'])() as $w) $tiles[] = $w + ['module' => $m['id']];
if ($tiles): ?><div class="kpis"><?php foreach ($tiles as $w): ?>
  <a class="kpi" href="<?= e(adm_url($w['link'] ?? $w['module'])) ?>"><b><?= e((string)$w['value']) ?></b><span><?= e($w['label']) ?></span></a>
<?php endforeach; ?></div><?php endif; ?>
<div class="panel"><h2>الوحدات المثبّتة</h2><div class="tw"><table><thead><tr><th>الوحدة</th><th>المعرّف</th><th>المجموعة</th><th>الإصدار</th></tr></thead><tbody>
<?php foreach ($MODS as $m): ?><tr><td><a href="<?= e(adm_url($m['id'])) ?>"><?= e($m['icon'] . ' ' . adm_title($m)) ?></a></td><td dir="ltr"><?= e($m['id']) ?></td><td><?= e(ADM_GROUPS[$m['group']] ?? $m['group']) ?></td><td dir="ltr">v<?= (int)$m['version'] ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<p class="muted">لإضافة لوحة تحكم لتطبيق آخر: ضع مجلد الوحدة في <code dir="ltr">admin/modules/</code> فتظهر هنا تلقائياً. التفاصيل في <code dir="ltr">admin/MODULES.md</code>.</p></div>
