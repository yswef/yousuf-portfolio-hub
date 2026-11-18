<?php
require_once ROOT . '/core/sites.php';
$admin = is_admin_viewer();
$sites = table_exists('sites') ? rows('SELECT * FROM sites ORDER BY id DESC') : [];
page_start(['title' => t('nav_docs')]);
page_head(t('nav_docs'), t('nav_docs'), '');
?>
<section><div class="container">
<?php if (!$sites): ?><p class="muted">—</p><?php endif; ?>
<div class="grid">
<?php foreach ($sites as $s):
    $pages = rows('SELECT * FROM site_pages WHERE site_id=? ORDER BY sort, id', [$s['id']]);
    $siteLocked = !(int)$s['is_public']; ?>
  <div class="card reveal">
    <div class="icon"><?= $siteLocked && !$admin ? '🔒' : '📘' ?></div>
    <h3><a href="<?= e(url('s/' . $s['slug'] . '/')) ?>"><?= e($s['title']) ?></a> <?= $siteLocked ? '<span class="chip">🔒</span>' : '' ?></h3>
    <?php if (count($pages) > 1 || ($pages && $pages[0]['title'] !== $s['title'])): ?>
    <ul style="list-style:none;padding:0;margin:10px 0 0;display:grid;gap:6px">
      <?php foreach ($pages as $p): $lk = $siteLocked || (int)$p['locked']; ?>
      <li><a href="<?= e(url('s/' . $s['slug'] . '/' . $p['file'])) ?>"><?= $lk ? '🔒 ' : '' ?><?= e($p['title']) ?></a></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
</div></section>
<?php page_end(); ?>
