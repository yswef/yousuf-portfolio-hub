<?php
$projSorts = [
    'custom' => t('sort_featured'),
    'stars' => t('sort_stars'),
    'updated' => t('sort_updated'),
    'newest' => t('sort_newest'),
    'oldest' => t('sort_oldest'),
    'name' => t('sort_name'),
];
$defSort = setting('projects_sort', 'custom');
$curSort = array_key_exists((string)($_GET['sort'] ?? ''), $projSorts) ? (string)$_GET['sort'] : (isset($projSorts[$defSort]) ? $defSort : 'custom');
$curLangF = trim((string)($_GET['lang'] ?? ''));

$langs = array_values(array_filter(array_column(rows('SELECT DISTINCT language FROM projects WHERE hidden=0 AND language IS NOT NULL AND language<>"" ORDER BY language'), 'language')));
$where = 'hidden=0'; $prm = [];
if ($curLangF !== '') { $where .= ' AND language=?'; $prm[] = $curLangF; }
$orderSql = projects_order_sql($curSort);
$list = rows("SELECT * FROM projects WHERE $where ORDER BY $orderSql", $prm);

page_start(['title' => t('nav_projects'), 'desc' => t('projects_page_sub')]);
page_head(t('projects_eyebrow'), t('nav_projects'), t('projects_page_sub'));
?>
<section><div class="container">
  <form method="get" class="pub-toolbar">
    <input class="search" type="search" name="q" value="<?= e((string)($_GET['q'] ?? '')) ?>" placeholder="<?= e(t('search')) ?>" data-filter aria-label="<?= e(t('search')) ?>">
    <?php if ($langs): ?>
    <select name="lang" class="pub-select" onchange="this.form.submit()" aria-label="<?= e(t('language_lbl')) ?>">
      <option value=""><?= e(t('language_lbl')) ?>: <?= e(t('filter_all')) ?></option>
      <?php foreach ($langs as $lg): ?><option value="<?= e($lg) ?>"<?= $curLangF === $lg ? ' selected' : '' ?>><?= e($lg) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="sort" class="pub-select" onchange="this.form.submit()" aria-label="<?= e(t('sort_by')) ?>">
      <?php foreach ($projSorts as $sk => $sl): ?><option value="<?= e($sk) ?>"<?= $curSort === $sk ? ' selected' : '' ?>><?= e(t('sort_by')) ?>: <?= e($sl) ?></option><?php endforeach; ?>
    </select>
  </form>
  <?php if (!$list): ?><p class="projects-empty"><?= e(t('projects_empty')) ?></p><?php else: ?><div class="projects-grid" data-filter-list><?php foreach ($list as $p) project_card($p); ?></div><?php endif; ?>
</div></section>
<?php page_end(); ?>
