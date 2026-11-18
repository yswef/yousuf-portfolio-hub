<?php
$pg = row('SELECT * FROM pages WHERE slug=? AND published=1', [$arg]);
if (!$pg) return false;
$ttl = L($pg['title_i18n'], $pg['slug']);
page_start(['title' => $ttl, 'desc' => mb_substr(strip_tags(L($pg['body_i18n'])), 0, 200)]);
?>
<article class="container doc"><h1><?= e($ttl) ?></h1><div class="prose" dir="auto"><?= md(L($pg['body_i18n'])) ?></div></article>
<?php page_end(); ?>
