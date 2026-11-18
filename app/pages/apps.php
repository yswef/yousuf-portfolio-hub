<?php
$list = rows('SELECT * FROM apps WHERE hidden=0 AND ignored=0 ORDER BY id DESC');
page_start(['title' => t('apps_title'), 'desc' => t('apps_sub')]);
page_head(t('nav_apps'), t('apps_title'), t('apps_sub'));
?>
<section><div class="container"><?php search_box(); ?>
<?php if (!$list): ?><p class="projects-empty"><?= e(t('no_results')) ?></p><?php else: ?><div class="grid" data-filter-list><?php foreach ($list as $a) app_card($a); ?></div><?php endif; ?></div></section>
<?php page_end(); ?>
