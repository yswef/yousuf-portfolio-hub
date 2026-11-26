<?php
$appSorts = [
    'newest' => t('sort_newest'),
    'downloads' => t('sort_downloads'),
    'published' => t('sort_updated'),
    'name' => t('sort_name'),
    'oldest' => t('sort_oldest'),
];
$defSort = setting('apps_sort', 'newest');
$curSort = array_key_exists((string)($_GET['sort'] ?? ''), $appSorts) ? (string)$_GET['sort'] : (isset($appSorts[$defSort]) ? $defSort : 'newest');
$curPlat = trim((string)($_GET['platform'] ?? ''));
$curPrice = in_array((string)($_GET['price'] ?? ''), ['free', 'paid', 'donation'], true) ? (string)$_GET['price'] : '';

$platforms = array_values(array_filter(array_column(rows('SELECT DISTINCT platform FROM apps WHERE hidden=0 AND ignored=0 AND platform IS NOT NULL AND platform<>"" ORDER BY platform'), 'platform')));
$where = 'a.hidden=0 AND a.ignored=0'; $prm = [];
if ($curPlat !== '') { $where .= ' AND a.platform=?'; $prm[] = $curPlat; }
if ($curPrice !== '') { $where .= ' AND a.price_type=?'; $prm[] = $curPrice; }
$orderSql = apps_order_sql($curSort);
$list = rows("SELECT a.*, p.title_i18n AS p_title_i18n, p.desc_i18n AS p_desc_i18n, ((SELECT COALESCE(SUM(gh_count),0) FROM app_files f WHERE f.app_id=a.id AND f.hidden=0) + (SELECT COUNT(*) FROM downloads d WHERE d.app_id=a.id)) AS dl_total FROM apps a LEFT JOIN projects p ON p.id=a.project_id WHERE $where ORDER BY $orderSql", $prm);

page_start(['title' => t('apps_title'), 'desc' => t('apps_sub')]);
page_head(t('nav_apps'), t('apps_title'), t('apps_sub'));
?>
<section><div class="container">
  <form method="get" class="pub-toolbar">
    <input class="search" type="search" name="q" value="<?= e((string)($_GET['q'] ?? '')) ?>" placeholder="<?= e(t('search')) ?>" data-filter aria-label="<?= e(t('search')) ?>">
    <?php if ($platforms): ?>
    <select name="platform" class="pub-select" onchange="this.form.submit()" aria-label="<?= e(t('platform')) ?>">
      <option value=""><?= e(t('platform')) ?>: <?= e(t('filter_all')) ?></option>
      <?php foreach ($platforms as $pl): ?><option value="<?= e($pl) ?>"<?= $curPlat === $pl ? ' selected' : '' ?>><?= e($pl) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="price" class="pub-select" onchange="this.form.submit()" aria-label="<?= e(t('price')) ?>">
      <option value=""><?= e(t('price')) ?>: <?= e(t('filter_all')) ?></option>
      <option value="free"<?= $curPrice === 'free' ? ' selected' : '' ?>><?= e(t('free')) ?></option>
      <option value="paid"<?= $curPrice === 'paid' ? ' selected' : '' ?>><?= e(t('paid')) ?></option>
      <option value="donation"<?= $curPrice === 'donation' ? ' selected' : '' ?>><?= e(t('donation')) ?></option>
    </select>
    <select name="sort" class="pub-select" onchange="this.form.submit()" aria-label="<?= e(t('sort_by')) ?>">
      <?php foreach ($appSorts as $sk => $sl): ?><option value="<?= e($sk) ?>"<?= $curSort === $sk ? ' selected' : '' ?>><?= e(t('sort_by')) ?>: <?= e($sl) ?></option><?php endforeach; ?>
    </select>
  </form>
  <?php if (!$list): ?><p class="projects-empty"><?= e(t('no_results')) ?></p><?php else: ?><div class="grid" data-filter-list><?php foreach ($list as $a) app_card($a); ?></div><?php endif; ?>
</div></section>
<?php page_end(); ?>
