<?php
$a = row('SELECT * FROM apps WHERE slug=? AND hidden=0 AND ignored=0', [$arg]);
if (!$a) return false;
$files = rows('SELECT f.*, (SELECT COUNT(*) FROM downloads d WHERE d.file_id=f.id) AS site_dl FROM app_files f WHERE f.app_id=? AND f.hidden=0 ORDER BY f.id', [$a['id']]);
$docsList = table_exists('docs') ? rows('SELECT slug, title FROM docs WHERE app_slug=? AND hidden=0 ORDER BY sort_order, id', [$a['slug']]) : [];
$proj = $a['project_id'] ? row('SELECT slug, title_i18n, desc_i18n, html_url, homepage, readme, image FROM projects WHERE id=?', [$a['project_id']]) : null;
$dispName = (!$a['locked'] && !empty($proj['title_i18n'])) ? L((string)$proj['title_i18n'], (string)$a['name']) : (string)$a['name'];
$dispDesc = (!$a['locked'] && !empty($proj['desc_i18n'])) ? L((string)$proj['desc_i18n'], (string)$a['description']) : (string)$a['description'];
$total = array_sum(array_column($files, 'gh_count')) + (int)val('SELECT COUNT(*) FROM downloads WHERE app_id=?', [$a['id']]);
page_start(['title' => $dispName, 'desc' => $dispDesc]);
$price = t($a['price_type'] === 'paid' ? 'paid' : ($a['price_type'] === 'donation' ? 'donation' : 'free'));
?>
<article class="container doc">
  <a class="back" href="<?= lurl('apps') ?>">← <?= e(t('back')) ?></a>
  <h1><?= e($dispName) ?></h1><?php if ($dispDesc !== ''): ?><p class="lead"><?= e($dispDesc) ?></p><?php endif; ?>
  <?php if (!empty($proj['image'])): ?><img class="hero-img" src="<?= e(upload_url((string)$proj['image'])) ?>" alt="<?= e($dispName) ?>"><?php endif; ?>
  <div class="facts">
    <?php if ($a['version']): ?><div><span><?= e(t('version')) ?></span><b dir="ltr"><?= e($a['version']) ?></b></div><?php endif; ?>
    <?php if ($a['platform']): ?><div><span><?= e(t('platform')) ?></span><b><?= e($a['platform']) ?></b></div><?php endif; ?>
    <div><span><?= e(t('price')) ?></span><b><?= e($price) ?><?= $a['price_note'] ? ' · ' . e($a['price_note']) : '' ?></b></div>
    <?php if ($total > 0): ?><div><span><?= e(t('downloads')) ?></span><b><?= (int)$total ?></b></div><?php endif; ?>
    <?php if ($a['published_at']): ?><div><span><?= e(t('last_update')) ?></span><b><?= e(fmt_date($a['published_at'])) ?></b></div><?php endif; ?>
  </div>
  <?php if ($files): ?><h2><?= e(t('files')) ?></h2><div class="files">
    <?php foreach ($files as $f):
      $fDl = (int)$f['gh_count'] + (int)($f['site_dl'] ?? 0);
      $metaParts = [e($f['name'])];
      if ((int)$f['size'] > 0) $metaParts[] = e(fmt_size($f['size']));
      if ($fDl > 0) $metaParts[] = $fDl . ' ↓';
    ?><div class="file"><div class="file-i"><b dir="auto"><?= e($f['label'] !== '' ? $f['label'] : $f['name']) ?></b>
      <span class="muted" dir="ltr"><?= implode(' · ', $metaParts) ?></span>
      <?php if (!empty($f['digest'])): ?><code class="sha" dir="ltr"><?= e(t('sha')) ?>: <?= e(preg_replace('/^sha256:/', '', $f['digest'])) ?></code><?php endif; ?></div>
      <a class="btn btn-gold" rel="nofollow" href="<?= e(url('dl/' . (int)$f['id'])) ?>"><?= e(t('download')) ?></a></div><?php endforeach; ?></div><?php endif; ?>
  <?php foreach (['requirements', 'install_notes', 'release_notes'] as $k): if (trim((string)$a[$k]) !== ''): ?><h2><?= e(t($k)) ?></h2><div class="prose" dir="auto"><?= md((string)$a[$k]) ?></div><?php endif; endforeach; ?>
  <?php if (trim((string)$a['release_notes']) === '' && !empty($proj['readme'])): ?><h2><?= e(t('readme')) ?></h2><div class="prose" dir="auto"><?= md(md_clean_readme((string)$proj['readme'])) ?></div><?php endif; ?>
  <?php if ($docsList || $proj): ?><div class="cta">
    <?php foreach ($docsList as $dc): ?><a class="btn btn-ghost" href="<?= lurl('docs/' . $dc['slug']) ?>">📘 <?= e(count($docsList) > 1 ? $dc['title'] : t('nav_docs')) ?></a><?php endforeach; ?>
    <?php if ($proj): ?><a class="btn btn-ghost" href="<?= lurl('projects/' . $proj['slug']) ?>"><?= e(t('nav_projects')) ?></a><?php endif; ?>
    <?php if (!empty($proj['html_url']) && ($gu = safe_url((string)$proj['html_url']))): ?><a class="btn btn-ghost" href="<?= e($gu) ?>" target="_blank" rel="noopener">GitHub</a><?php endif; ?>
    <?php if (!empty($proj['homepage']) && ($hu = safe_url((string)$proj['homepage']))): ?><a class="btn btn-ghost" href="<?= e($hu) ?>" target="_blank" rel="noopener"><?= e(t('website')) ?></a><?php endif; ?>
  </div><?php endif; ?>
</article>
<?php page_end(); ?>
