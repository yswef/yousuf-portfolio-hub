<?php
$per = 12; $pg = max(1, (int)($_GET['page'] ?? 1)); $tag = trim((string)($_GET['tag'] ?? ''));
$where = 'published=1 AND lang=?'; $prm = [cur_lang()];
if (!val("SELECT COUNT(*) FROM posts WHERE $where", $prm)) { $where = 'published=1'; $prm = []; }       // no posts in this language → show all
if ($tag !== '') { $where .= ' AND tags LIKE ?'; $prm[] = '%' . str_replace(['%', '_'], '', $tag) . '%'; }
$total = (int)val("SELECT COUNT(*) FROM posts WHERE $where", $prm);
$list = rows("SELECT * FROM posts WHERE $where ORDER BY created DESC LIMIT $per OFFSET " . (($pg - 1) * $per), $prm);
page_start(['title' => t('blog_title'), 'desc' => t('blog_sub')]);
page_head(t('nav_blog'), t('blog_title'), t('blog_sub'));
?>
<section><div class="container">
<?php search_box(); ?>
<?php if (!$list): ?><p class="projects-empty"><?= e(t('no_posts')) ?></p><?php else: ?><div class="grid" data-filter-list><?php foreach ($list as $p) post_card($p); ?></div><?php endif; ?>
<?php if ($total > $per): ?><div class="pager"><?php if ($pg > 1): ?><a class="btn btn-ghost" href="?page=<?= $pg - 1 ?>">← <?= e(t('newer')) ?></a><?php endif; ?><?php if ($pg * $per < $total): ?><a class="btn btn-ghost" href="?page=<?= $pg + 1 ?>"><?= e(t('older')) ?> →</a><?php endif; ?></div><?php endif; ?>
</div></section>
<?php page_end(); ?>
