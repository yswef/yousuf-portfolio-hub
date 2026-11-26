<?php
declare(strict_types=1);

function lurl(string $path = '', ?string $lang = null): string {
    $p = trim($path, '/');
    return url(($lang ?? cur_lang()) . '/' . $p);
}
function items(string $type, int $limit = 0): array {
    return rows("SELECT * FROM items WHERE type=? AND hidden=0 ORDER BY sort_order, id" . ($limit > 0 ? ' LIMIT ' . (int)$limit : ''), [$type]);
}
function nav_pages(): array { try { return rows('SELECT slug, title_i18n FROM pages WHERE published=1 AND in_nav=1 ORDER BY sort_order, id'); } catch (Throwable $e) { return []; } }
function avatar_url(): string { $a = setting('avatar'); return $a ? upload_url($a) : url('assets/img/Yusef.jpg'); }

/** $m: title, desc, type, jsonld, image */
function page_start(array $m = []): void {
    global $REST;
    $name = setting_l('name') ?: 'Portfolio';
    $title = ($m['title'] ?? '') !== '' ? $m['title'] . ' — ' . $name : $name . ' — ' . setting_l('title');
    $desc = mb_substr(trim(strip_tags((string)($m['desc'] ?? setting_l('tagline')))), 0, 200);
    $rest = $m['rest'] ?? $REST;
    $path = fn(string $l) => site_origin() . '/' . $l . '/' . ($rest !== '' ? $rest : '');
    $dir = lang_dir();
    $nav = ['home' => '', 'about' => 'about', 'resume' => 'resume', 'projects' => 'projects', 'apps' => 'apps', 'services' => 'services'];
    if (setting('show_blog', '0') === '1') $nav['blog'] = 'blog';
    try { if (table_exists('sites') && (int)val('SELECT COUNT(*) FROM sites') > 0) $nav['library'] = 'library'; } catch (Throwable $e) {}
    try { if (table_exists('docs') && (int)val('SELECT COUNT(*) FROM docs WHERE hidden=0') > 0) $nav['docs'] = 'docs'; } catch (Throwable $e) {}
    $nav['contact'] = 'contact';
    $first = explode('/', $rest)[0];
    $img = !empty($m['image']) ? $m['image'] : site_origin() . '/assets/img/favicon-192.png';
    ?>
<!doctype html>
<html lang="<?= cur_lang() ?>" dir="<?= $dir ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<link rel="canonical" href="<?= e($path(cur_lang())) ?>">
<?php foreach (array_keys(LANGS) as $l): ?><link rel="alternate" hreflang="<?= $l ?>" href="<?= e($path($l)) ?>">
<?php endforeach; ?><link rel="alternate" hreflang="x-default" href="<?= e($path('ar')) ?>">
<?php if (setting('noindex') === '1'): ?><meta name="robots" content="noindex,nofollow"><?php endif; ?>
<?php if ($v = setting('google_verify')): ?><meta name="google-site-verification" content="<?= e($v) ?>"><?php endif; ?>
<?php if ($v = setting('bing_verify')): ?><meta name="msvalidate.01" content="<?= e($v) ?>"><?php endif; ?>
<meta property="og:title" content="<?= e($title) ?>"><meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:type" content="<?= e($m['type'] ?? 'website') ?>"><meta property="og:url" content="<?= e($path(cur_lang())) ?>"><meta property="og:image" content="<?= e($img) ?>">
<meta name="twitter:card" content="summary"><meta name="theme-color" content="#050b14">
<meta name="theme-default" content="<?= e(setting('theme_default', 'system')) ?>">
<link rel="icon" href="<?= url('assets/img/favicon.ico') ?>"><link rel="icon" type="image/png" sizes="32x32" href="<?= url('assets/img/favicon-32.png') ?>">
<link rel="apple-touch-icon" href="<?= url('assets/img/favicon-192.png') ?>"><link rel="manifest" href="<?= url('manifest.webmanifest') ?>">
<link rel="stylesheet" href="<?= url('assets/css/fonts.css') ?>"><link rel="stylesheet" href="<?= url('assets/css/style.css') ?>?v=4">
<script src="<?= url('assets/js/theme.js') ?>"></script>
<?php if (!empty($m['jsonld'])): ?><script type="application/ld+json"><?= json_encode($m['jsonld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script><?php endif; ?>
</head>
<body data-glow="<?= (int)(setting('fx_glow', '1') === '1') ?>" data-cursor="<?= (int)(setting('fx_cursor', '1') === '1') ?>" data-tilt="<?= (int)(setting('fx_tilt', '1') === '1') ?>">
<a class="skip" href="#main"><?= e(t('skip')) ?></a>
<?php if (setting('banner_on') === '1' && ($bt = setting_l('banner')) !== ''): ?><div class="banner"><?= e($bt) ?></div><?php endif; ?>
<nav class="navbar" id="navbar">
  <a href="<?= lurl() ?>" class="nav-logo"><img src="<?= url('assets/img/favicon-32.png') ?>" alt="" width="34" height="34"><span><?= e($name) ?></span></a>
  <ul class="nav-links">
    <?php foreach ($nav as $k => $p): ?><li><a class="<?= ($p === '' ? $rest === '' : $first === $p) ? 'active' : '' ?>" href="<?= lurl($p) ?>"><?= e(t('nav_' . $k)) ?></a></li><?php endforeach; ?>
    <?php foreach (nav_pages() as $np): ?><li><a class="<?= $rest === 'p/' . $np['slug'] ? 'active' : '' ?>" href="<?= lurl('p/' . $np['slug']) ?>"><?= e(L($np['title_i18n'], $np['slug'])) ?></a></li><?php endforeach; ?>
  </ul>
  <div class="nav-actions">
    <details class="langsw"><summary aria-label="<?= e(t('language')) ?>">🌐 <?= strtoupper(cur_lang()) ?></summary>
      <div class="langsw-menu"><?php foreach (LANGS as $l => $inf): ?><a hreflang="<?= $l ?>" class="<?= $l === cur_lang() ? 'on' : '' ?>" href="<?= e(lurl($rest, $l)) ?>"><?= e($inf['name']) ?></a><?php endforeach; ?></div></details>
    <label class="theme-switch" title="<?= e(t('theme')) ?>"><input type="checkbox" id="themeSwitch" aria-label="<?= e(t('theme')) ?>"><span class="knob"></span></label>
    <button class="nav-burger" id="navBurger" aria-label="<?= e(t('menu')) ?>">☰</button>
  </div>
</nav>
<div class="mobile-drawer" id="mobileDrawer"><button class="close-drawer" id="closeDrawer" aria-label="close">×</button>
  <?php foreach ($nav as $k => $p): ?><a href="<?= lurl($p) ?>"><?= e(t('nav_' . $k)) ?></a><?php endforeach; ?>
  <?php foreach (nav_pages() as $np): ?><a href="<?= lurl('p/' . $np['slug']) ?>"><?= e(L($np['title_i18n'], $np['slug'])) ?></a><?php endforeach; ?>
</div>
<main id="main">
<?php
}

function page_end(): void {
    $links = items('link');
    ?>
</main>
<footer class="site-footer">
  <?php if ($links): ?><div class="foot-links"><?php foreach ($links as $l): if (!($u = safe_url((string)$l['extra']))) continue; ?><a href="<?= e($u) ?>" target="_blank" rel="noopener noreferrer me" title="<?= e(tr($l, 'title')) ?>"><?= e($l['icon']) ?> <?= e(tr($l, 'title')) ?></a><?php endforeach; ?></div><?php endif; ?>
  <div class="foot-nav"><a href="<?= lurl('learning') ?>"><?= e(t('nav_learning')) ?></a><a href="<?= lurl('social') ?>"><?= e(t('nav_social')) ?></a><a href="<?= lurl('privacy') ?>"><?= e(t('nav_privacy')) ?></a>
    <?php foreach (LANGS as $l => $inf): if ($l === cur_lang()) continue; ?><a hreflang="<?= $l ?>" href="<?= e(lurl($GLOBALS['REST'] ?? '', $l)) ?>"><?= e($inf['name']) ?></a><?php endforeach; ?></div>
  <p>© <?= gmdate('Y') ?> <?= e(setting_l('name')) ?> · <?= e(t('rights')) ?></p>
</footer>
<script src="<?= url('assets/js/app.js') ?>?v=3" defer></script>
</body>
</html>
<?php
}
