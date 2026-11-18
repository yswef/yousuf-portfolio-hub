<?php
$list = rows('SELECT * FROM projects WHERE hidden=0 ORDER BY featured DESC, sort_order, stars DESC, id DESC');
page_start(['title' => t('nav_projects'), 'desc' => t('projects_page_sub')]);
page_head(t('projects_eyebrow'), t('nav_projects'), t('projects_page_sub'));
?>
<section><div class="container"><?php search_box(); ?>
<?php if (!$list): ?><p class="projects-empty"><?= e(t('projects_empty')) ?></p><?php else: ?><div class="projects-grid" data-filter-list><?php foreach ($list as $p) project_card($p); ?></div><?php endif; ?></div></section>
<?php page_end(); ?>
