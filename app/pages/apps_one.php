<?php
$a = row('SELECT * FROM apps WHERE slug=? AND hidden=0 AND ignored=0', [$arg]);
if (!$a) return false;
$files = rows('SELECT * FROM app_files WHERE app_id=? AND hidden=0 ORDER BY id', [$a['id']]);
$docsList = table_exists('docs') ? rows('SELECT slug, title FROM docs WHERE app_slug=? AND hidden=0 ORDER BY sort_order, id', [$a['slug']]) : [];
$proj = $a['project_id'] ? row('SELECT slug FROM projects WHERE id=?', [$a['project_id']]) : null;
$total = array_sum(array_column($files, 'gh_count')) + (int)val('SELECT COUNT(*) FROM downloads WHERE app_id=?', [$a['id']]);
page_start(['title' => $a['name'], 'desc' => (string)$a['description']]);
$price = t($a['price_type'] === 'paid' ? 'paid' : ($a['price_type'] === 'donation' ? 'donation' : 'free'));
?>
<article class="container doc">
  <a class="back" href="<?= lurl('apps') ?>">← <?= e(t('back')) ?></a>
  <h1><?= e($a['name']) ?></h1><p class="lead"><?= e($a['description']) ?></p>
  <div class="facts">
    <?php if ($a['version']): ?><div><span><?= e(t('version')) ?></span><b dir="ltr"><?= e($a['version']) ?></b></div><?php endif; ?>
    <?php if ($a['platform']): ?><div><span><?= e(t('platform')) ?></span><b><?= e($a['platform']) ?></b></div><?php endif; ?>
    <div><span><?= e(t('price')) ?></span><b><?= e($price) ?><?= $a['price_note'] ? ' · ' . e($a['price_note']) : '' ?></b></div>
    <div><span><?= e(t('downloads')) ?></span><b><?= (int)$total ?></b></div>
    <?php if ($a['published_at']): ?><div><span><?= e(t('last_update')) ?></span><b><?= e(fmt_date($a['published_at'])) ?></b></div><?php endif; ?>
  </div>
  <?php if ($files): ?><h2><?= e(t('files')) ?></h2><div class="files">
    <?php foreach ($files as $f): ?><div class="file"><div class="file-i"><b dir="ltr"><?= e($f['label'] !== '' ? $f['label'] : $f['name']) ?></b>
      <span class="muted" dir="ltr"><?= e($f['name']) ?> · <?= e(fmt_size($f['size'])) ?> · <?= (int)$f['gh_count'] ?> ↓</span>
      <?php if (!empty($f['digest'])): ?><code class="sha" dir="ltr"><?= e(t('sha')) ?>: <?= e(preg_replace('/^sha256:/', '', $f['digest'])) ?></code><?php endif; ?></div>
      <a class="btn btn-gold" rel="nofollow" href="<?= e(url('dl/' . (int)$f['id'])) ?>"><?= e(t('download')) ?></a></div><?php endforeach; ?></div><?php endif; ?>
  <?php foreach (['requirements', 'install_notes', 'release_notes'] as $k): if (trim((string)$a[$k]) !== ''): ?><h2><?= e(t($k)) ?></h2><div class="prose" dir="auto"><?= md((string)$a[$k]) ?></div><?php endif; endforeach; ?>
  <?php if ($docsList): ?><div class="cta"><?php foreach ($docsList as $dc): ?><a class="btn btn-ghost" href="<?= lurl('docs/' . $dc['slug']) ?>">📘 <?= e(count($docsList) > 1 ? $dc['title'] : t('nav_docs')) ?></a><?php endforeach; ?></div><?php endif; ?>
  <?php if ($proj): ?><div class="cta"><a class="btn btn-ghost" href="<?= lurl('projects/' . $proj['slug']) ?>"><?= e(t('nav_projects')) ?></a></div><?php endif; ?>
</article>
<?php page_end(); ?>
