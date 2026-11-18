<?php
$p = row('SELECT * FROM posts WHERE slug=? AND published=1', [$arg]);
if (!$p) return false;
if (!is_bot_ua($_SERVER['HTTP_USER_AGENT'] ?? '')) q('UPDATE posts SET views=views+1 WHERE id=?', [$p['id']]);
$ld = ['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => $p['title'], 'datePublished' => gmdate('c', (int)$p['created']), 'dateModified' => gmdate('c', (int)($p['updated'] ?: $p['created'])),
       'author' => ['@type' => 'Person', 'name' => setting_l('name')], 'inLanguage' => $p['lang']];
page_start(['title' => $p['title'], 'desc' => (string)$p['summary'], 'type' => 'article', 'jsonld' => $ld]);
?>
<article class="container doc"><a class="back" href="<?= lurl('blog') ?>">← <?= e(t('back')) ?></a><h1><?= e($p['title']) ?></h1>
<div class="meta"><span><?= e(fmt_date($p['created'])) ?></span><span><?= max(1, (int)ceil(mb_strlen((string)$p['body']) / 900)) ?> <?= e(t('min_read')) ?></span><span><?= (int)$p['views'] ?> <?= e(t('views')) ?></span>
<?php foreach (array_filter(array_map('trim', explode(',', (string)$p['tags']))) as $tg): ?><a class="chip" href="<?= e(lurl('blog')) ?>?tag=<?= e(rawurlencode($tg)) ?>"><?= e($tg) ?></a><?php endforeach; ?></div>
<?php if ($p['cover']): ?><img class="hero-img" src="<?= e(upload_url($p['cover'])) ?>" alt=""><?php endif; ?>
<div class="prose" dir="auto"><?= md((string)$p['body']) ?></div></article>
<?php page_end(); ?>
