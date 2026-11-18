<?php
$p = row('SELECT * FROM projects WHERE slug=? AND hidden=0', [$arg]);
if (!$p) return false;
$app = table_exists('apps') ? row('SELECT slug FROM apps WHERE project_id=? AND hidden=0 AND ignored=0', [$p['id']]) : null;
$title = tr($p, 'title', 'name'); $desc = tr($p, 'desc', 'description');
$ld = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
  ['@type' => 'ListItem', 'position' => 1, 'name' => t('nav_projects'), 'item' => site_origin() . '/' . cur_lang() . '/projects'], ['@type' => 'ListItem', 'position' => 2, 'name' => $title]]];
page_start(['title' => $title, 'desc' => $desc, 'jsonld' => $ld, 'image' => $p['image'] ? site_origin() . '/' . UPLOAD_DIR_REL . '/' . rawurlencode($p['image']) : null]);
$topics = array_filter(explode(',', (string)$p['topics']));
?>
<article class="container doc">
  <a class="back" href="<?= lurl('projects') ?>">← <?= e(t('back')) ?></a>
  <h1><?= e($title) ?></h1><p class="lead"><?= e($desc) ?></p>
  <?php if ($p['image']): ?><img class="hero-img" src="<?= e(upload_url($p['image'])) ?>" alt="<?= e($title) ?>"><?php endif; ?>
  <div class="facts">
    <?php if ($p['language']): ?><div><span><?= e(t('language_lbl')) ?></span><b><?= e($p['language']) ?></b></div><?php endif; ?>
    <?php if ($p['gh_id']): ?><div><span><?= e(t('stars')) ?></span><b>★ <?= (int)$p['stars'] ?></b></div><div><span><?= e(t('forks')) ?></span><b><?= (int)$p['forks'] ?></b></div><?php endif; ?>
    <?php if ($p['license'] && $p['license'] !== 'NOASSERTION'): ?><div><span><?= e(t('license')) ?></span><b><?= e($p['license']) ?></b></div><?php endif; ?>
    <?php if ($p['pushed_at'] || $p['updated']): ?><div><span><?= e(t('last_update')) ?></span><b><?= e(fmt_date($p['pushed_at'] ?: $p['updated'])) ?></b></div><?php endif; ?>
  </div>
  <?php if ($topics): ?><div class="chips"><?php foreach ($topics as $tp): ?><span class="chip"><?= e($tp) ?></span><?php endforeach; ?></div><?php endif; ?>
  <div class="cta">
    <?php if ($u = safe_url((string)$p['html_url'])): ?><a class="btn btn-gold" href="<?= e($u) ?>" target="_blank" rel="noopener"><?= e(t('repository')) ?></a><?php endif; ?>
    <?php if ($u = safe_url((string)$p['homepage'])): ?><a class="btn btn-ghost" href="<?= e($u) ?>" target="_blank" rel="noopener"><?= e(t('website')) ?></a><?php endif; ?>
    <?php if ($u = safe_url((string)$p['download_url'])): ?><a class="btn btn-ghost" href="<?= e($u) ?>" target="_blank" rel="noopener"><?= e(t('download')) ?></a><?php endif; ?>
    <?php if ($app): ?><a class="btn btn-ghost" href="<?= lurl('apps/' . $app['slug']) ?>"><?= e(t('download')) ?> ⬇</a><?php endif; ?>
  </div>
  <?php if (trim((string)$p['readme']) !== ''): ?><h2><?= e(t('readme')) ?></h2><div class="prose" dir="auto"><?= md(md_clean_readme((string)$p['readme'])) ?></div><?php endif; ?>
</article>
<?php page_end(); ?>
