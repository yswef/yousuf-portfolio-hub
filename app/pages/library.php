<?php
require_once ROOT . '/core/sites.php';
$admin = is_admin_viewer();
$sites = table_exists('sites') ? rows('SELECT * FROM sites') : [];
usort($sites, fn($a, $b) => [(int)($a['sort'] ?? 0), -(int)$a['id']] <=> [(int)($b['sort'] ?? 0), -(int)$b['id']]);
page_start(['title' => t('nav_library')]);
page_head(t('nav_library'), t('nav_library'), t('library_sub'));
?>
<section><div class="container">
<?php if (!$sites): ?><p class="projects-empty">—</p><?php else: ?>
<div class="grid">
<?php foreach ($sites as $s):
    $locked = !(int)$s['is_public'];
    $isPage = ($s['kind'] ?? 'site') === 'page';
    $desc = trim((string)($s['description'] ?? '')); ?>
  <a class="card tilt reveal" href="<?= e(url('s/' . $s['slug'] . '/')) ?>" data-search="<?= e(mb_strtolower($s['title'] . ' ' . $desc)) ?>">
    <div class="icon"><?= $locked && !$admin ? '🔒' : ($isPage ? '📄' : '📘') ?></div>
    <h3><?= e($s['title']) ?></h3>
    <?php if ($desc !== ''): ?><p><?= e(mb_substr($desc, 0, 180)) ?></p><?php endif; ?>
    <?php if ($locked): ?><div class="meta"><span class="chip">🔒</span></div><?php endif; ?>
  </a>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div></section>
<?php page_end(); ?>
