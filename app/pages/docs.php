<?php
$docs = table_exists('docs') ? rows('SELECT * FROM docs WHERE hidden=0 ORDER BY sort_order, id DESC') : [];
$projects = $docs && table_exists('projects') ? array_column(rows('SELECT slug, name, title_i18n FROM projects'), null, 'slug') : [];
$apps = $docs && table_exists('apps') ? array_column(rows('SELECT slug, name FROM apps'), null, 'slug') : [];
page_start(['title' => t('nav_docs'), 'desc' => t('docs_sub')]);
page_head(t('nav_docs'), t('nav_docs'), t('docs_sub'));
?>
<section><div class="container">
<?php if (!$docs): ?><p class="projects-empty">—</p><?php else: ?>
<div class="grid">
<?php foreach ($docs as $d):
    $label = '';
    if (!empty($d['project_slug']) && isset($projects[$d['project_slug']])) $label = tr($projects[$d['project_slug']], 'title', 'name');
    elseif (!empty($d['app_slug']) && isset($apps[$d['app_slug']])) $label = $apps[$d['app_slug']]['name']; ?>
  <a class="card tilt reveal" href="<?= lurl('docs/' . $d['slug']) ?>" data-search="<?= e(mb_strtolower($d['title'] . ' ' . $d['summary'] . ' ' . $label)) ?>">
    <div class="icon">📘</div><h3><?= e($d['title']) ?></h3>
    <?php if (trim((string)$d['summary']) !== ''): ?><p><?= e(mb_substr((string)$d['summary'], 0, 160)) ?></p><?php endif; ?>
    <?php if ($label !== ''): ?><div class="meta"><span class="chip"><?= e($label) ?></span></div><?php endif; ?>
  </a>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div></section>
<?php page_end(); ?>
