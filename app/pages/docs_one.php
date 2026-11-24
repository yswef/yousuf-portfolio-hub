<?php
$d = table_exists('docs') ? row('SELECT * FROM docs WHERE slug=? AND hidden=0', [$arg]) : null;
if (!$d) return false;
$proj = !empty($d['project_slug']) ? row('SELECT slug, name, title_i18n FROM projects WHERE slug=? AND hidden=0', [$d['project_slug']]) : null;
$app = !empty($d['app_slug']) ? row('SELECT slug, name FROM apps WHERE slug=? AND hidden=0 AND ignored=0', [$d['app_slug']]) : null;
page_start(['title' => $d['title'], 'desc' => (string)$d['summary'], 'type' => 'article']);
?>
<article class="container doc">
  <a class="back" href="<?= lurl('docs') ?>">← <?= e(t('back')) ?></a>
  <h1><?= e($d['title']) ?></h1>
  <?php if (trim((string)$d['summary']) !== ''): ?><p class="lead"><?= e($d['summary']) ?></p><?php endif; ?>
  <?php if ($proj || $app): ?><div class="cta">
    <?php if ($proj): ?><a class="btn btn-ghost" href="<?= lurl('projects/' . $proj['slug']) ?>"><?= e(t('nav_projects')) ?>: <?= e(tr($proj, 'title', 'name')) ?></a><?php endif; ?>
    <?php if ($app): ?><a class="btn btn-ghost" href="<?= lurl('apps/' . $app['slug']) ?>"><?= e(t('nav_apps')) ?>: <?= e($app['name']) ?></a><?php endif; ?>
  </div><?php endif; ?>
  <div class="prose" dir="auto"><?= md((string)$d['body']) ?></div>
</article>
<?php page_end(); ?>
