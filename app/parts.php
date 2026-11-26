<?php
declare(strict_types=1);

function sec_head(string $eyebrow, string $title, string $sub = '', ?string $more = null): void {
    echo '<div class="sec-head reveal"><div><p class="eyebrow">' . e($eyebrow) . '</p><h2 class="section-title">' . e($title) . '</h2>' . ($sub !== '' ? '<p class="section-sub">' . e($sub) . '</p>' : '') . '</div>';
    if ($more) echo '<a class="btn btn-ghost" href="' . e($more) . '">' . e(t('view_all')) . '</a>';
    echo '</div>';
}
function page_head(string $eyebrow, string $title, string $sub = ''): void {
    echo '<header class="page-head"><div class="container"><p class="eyebrow">' . e($eyebrow) . '</p><h1 class="section-title">' . e($title) . '</h1>' . ($sub !== '' ? '<p class="section-sub">' . e($sub) . '</p>' : '') . '</div></header>';
}

/** SQL ORDER BY clause for public project lists according to user/owner sort choice. */
function projects_order_sql(?string $sort = null): string {
    $s = $sort ?: setting('projects_sort', 'custom');
    return match ($s) {
        'stars' => 'stars DESC, featured DESC, id DESC',
        'updated' => 'COALESCE(pushed_at, "") DESC, updated DESC, id DESC',
        'newest' => 'id DESC',
        'oldest' => 'id ASC',
        'name' => 'name ASC',
        default => 'featured DESC, sort_order ASC, stars DESC, id DESC',
    };
}
/** SQL ORDER BY clause for public app lists (requires dl_total alias on SELECT). */
function apps_order_sql(?string $sort = null): string {
    $s = $sort ?: setting('apps_sort', 'newest');
    return match ($s) {
        'downloads' => 'dl_total DESC, a.id DESC',
        'published' => 'COALESCE(a.published_at, "") DESC, a.id DESC',
        'name' => 'a.name ASC',
        'oldest' => 'a.id ASC',
        default => 'a.id DESC',
    };
}

function project_card(array $p): void {
    $title = tr($p, 'title', 'name'); $desc = tr($p, 'desc', 'description');
    $href = lurl('projects/' . $p['slug']);
    $hay = mb_strtolower($title . ' ' . $desc . ' ' . $p['topics'] . ' ' . $p['language'] . ' ' . ($p['category'] ?? '')); ?>
<article class="project-card tilt reveal" data-search="<?= e($hay) ?>" data-tag="<?= e(mb_strtolower((string)$p['language'])) ?>">
  <?php if ($p['image']): ?><a class="thumb-wrap" href="<?= e($href) ?>"><img src="<?= e(upload_url($p['image'])) ?>" alt="<?= e($title) ?>" loading="lazy"></a><?php endif; ?>
  <div class="project-card-body">
    <h3><a href="<?= e($href) ?>"><?= e($title) ?></a></h3>
    <p><?= e(mb_substr($desc, 0, 150)) ?></p>
    <div class="meta"><?php if ($p['language']): ?><span class="chip"><?= e($p['language']) ?></span><?php endif; ?><?php if ((int)$p['stars']): ?><span>★ <?= (int)$p['stars'] ?></span><?php endif; ?><?php if (!empty($p['featured'])): ?><span class="chip gold">★</span><?php endif; ?></div>
    <div class="project-links"><a href="<?= e($href) ?>"><?= e(t('read_more')) ?></a>
      <?php if ($u = safe_url((string)$p['html_url'])): ?><a href="<?= e($u) ?>" target="_blank" rel="noopener noreferrer">GitHub</a><?php endif; ?>
      <?php if ($u = safe_url((string)$p['download_url'])): ?><a href="<?= e($u) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('download')) ?></a><?php endif; ?></div>
  </div>
</article>
<?php }
function app_card(array $a): void {
    $price = t($a['price_type'] === 'paid' ? 'paid' : ($a['price_type'] === 'donation' ? 'donation' : 'free'));
    $dlTotal = (int)($a['dl_total'] ?? 0);
    $dispName = (!$a['locked'] && !empty($a['p_title_i18n'])) ? L((string)$a['p_title_i18n'], (string)$a['name']) : (string)$a['name'];
    $dispDesc = (!$a['locked'] && !empty($a['p_desc_i18n'])) ? L((string)$a['p_desc_i18n'], (string)$a['description']) : (string)$a['description']; ?>
<a class="card tilt reveal" href="<?= lurl('apps/' . $a['slug']) ?>" data-search="<?= e(mb_strtolower($dispName . ' ' . $dispDesc . ' ' . $a['platform'])) ?>" data-tag="<?= e(mb_strtolower((string)$a['platform'])) ?>">
  <div class="icon">⬇</div><h3><?= e($dispName) ?></h3><p><?= e(mb_substr($dispDesc, 0, 140)) ?></p>
  <div class="meta"><?php if ($a['platform']): ?><span class="chip"><?= e($a['platform']) ?></span><?php endif; ?><?php if ($a['version']): ?><span dir="ltr"><?= e($a['version']) ?></span><?php endif; ?><span class="chip alt"><?= e($price) ?></span><?php if ($dlTotal > 0): ?><span>↓ <?= $dlTotal ?></span><?php endif; ?></div>
</a>
<?php }
function post_card(array $p): void { ?>
<a class="card tilt reveal" href="<?= lurl('blog/' . $p['slug']) ?>" data-search="<?= e(mb_strtolower($p['title'] . ' ' . $p['summary'] . ' ' . $p['tags'])) ?>">
  <?php if (!empty($p['cover'])): ?><img class="cover" src="<?= e(upload_url($p['cover'])) ?>" alt="" loading="lazy"><?php endif; ?>
  <h3><?= e($p['title']) ?></h3><p><?= e($p['summary']) ?></p>
  <div class="meta"><span><?= e(fmt_date($p['created'])) ?></span><span><?= max(1, (int)ceil(mb_strlen(strip_tags((string)$p['body'])) / 900)) ?> <?= e(t('min_read')) ?></span></div>
</a>
<?php }
function service_card(array $s): void { ?>
<div class="service-card tilt reveal"><div class="icon"><?= e($s['icon'] ?: '✦') ?></div><h3><?= e(tr($s, 'title')) ?></h3><p><?= e(tr($s, 'text')) ?></p>
<?php if ($s['extra']): ?><div class="meta"><span class="chip"><?= e($s['extra']) ?></span></div><?php endif; ?></div>
<?php }
function skills_grid(array $skills): void {
    echo '<div class="skills-grid">';
    foreach ($skills as $s) { $lv = max(0, min(100, (int)$s['level']));
        echo '<div class="skill-chip reveal"><div class="icon">' . e($s['icon'] ?: '◆') . '</div><div class="name">' . e(tr($s, 'title')) . '</div>'
           . ($lv ? '<div class="track"><div style="width:' . $lv . '%"></div></div>' : '') . '</div>'; }
    echo '</div>';
}
function learning_list(array $rows): void {
    echo '<div class="bars">';
    foreach ($rows as $s) { $pc = max(0, min(100, (int)$s['level']));
        echo '<div class="bar reveal"><div class="bar-h"><span>' . e(tr($s, 'title')) . '</span>' . ($pc ? '<span>' . $pc . '%</span>' : '') . '</div>'
           . (tr($s, 'text') !== '' ? '<p class="muted">' . e(tr($s, 'text')) . '</p>' : '') . ($pc ? '<div class="track"><div style="width:' . $pc . '%"></div></div>' : '') . '</div>'; }
    echo '</div>';
}
function timeline(array $rows): void {
    echo '<div class="timeline">';
    foreach ($rows as $x) {
        $period = trim($x['date_from'] . ($x['date_from'] || $x['date_to'] ? ' — ' : '') . ($x['is_current'] ? t('present') : $x['date_to']), ' —');
        echo '<div class="tl-item reveal' . ($x['is_current'] ? ' now' : '') . '"><span class="tl-dot"></span><div class="tl-card">';
        if ($x['is_current']) echo '<span class="chip gold">' . e(t('current')) . '</span> ';
        if ($period !== '') echo '<span class="tl-date" dir="ltr">' . e($period) . '</span>';
        echo '<h3>' . e(tr($x, 'title')) . '</h3>';
        if (tr($x, 'sub') !== '') echo '<p class="tl-sub">' . e(tr($x, 'sub')) . '</p>';
        if (tr($x, 'text') !== '') echo '<p>' . e(tr($x, 'text')) . '</p>';
        echo '</div></div>';
    }
    echo '</div>';
}
function social_list(): void {
    echo '<div class="social-list">';
    foreach (items('link') as $l) { if (!($u = safe_url((string)$l['extra']))) continue;
        echo '<a class="social-row reveal" href="' . e($u) . '" target="_blank" rel="noopener noreferrer me"><span class="ic">' . e($l['icon'] ?: '🔗') . '</span> ' . e(tr($l, 'title')) . '</a>'; }
    echo '</div>';
}
function search_box(): void { echo '<input class="search" type="search" placeholder="' . e(t('search')) . '" data-filter aria-label="' . e(t('search')) . '">'; }
