<?php
header('Content-Type: application/xml; charset=utf-8');
$o = site_origin(); $urls = [];
foreach (['' => '1.0', 'about' => '0.8', 'resume' => '0.8', 'projects' => '0.8', 'apps' => '0.7', 'services' => '0.7', 'blog' => '0.7', 'library' => '0.6', 'docs' => '0.6', 'learning' => '0.4', 'social' => '0.4', 'contact' => '0.5', 'privacy' => '0.2'] as $p => $pri) $urls[] = [$p, null, $pri];
foreach (rows('SELECT slug, updated FROM projects WHERE hidden=0') as $r) $urls[] = ['projects/' . $r['slug'], $r['updated'], '0.6'];
foreach (rows('SELECT slug, published_at FROM apps WHERE hidden=0 AND ignored=0') as $r) $urls[] = ['apps/' . $r['slug'], $r['published_at'], '0.6'];
if (table_exists('docs')) foreach (rows('SELECT slug, updated FROM docs WHERE hidden=0') as $r) $urls[] = ['docs/' . $r['slug'], $r['updated'], '0.6'];
foreach (rows('SELECT slug, COALESCE(updated, created) AS u FROM posts WHERE published=1') as $r) $urls[] = ['blog/' . $r['slug'], $r['u'], '0.6'];
foreach (rows('SELECT slug, updated FROM pages WHERE published=1') as $r) $urls[] = ['p/' . $r['slug'], $r['updated'], '0.5'];
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
foreach ($urls as [$path, $ts, $pri]) foreach (array_keys(LANGS) as $l) {
    echo '<url><loc>' . e($o . '/' . $l . '/' . $path) . '</loc>' . ($ts ? '<lastmod>' . e(fmt_date($ts)) . '</lastmod>' : '') . '<priority>' . $pri . '</priority>';
    foreach (array_keys(LANGS) as $a) echo '<xhtml:link rel="alternate" hreflang="' . $a . '" href="' . e($o . '/' . $a . '/' . $path) . '"/>';
    echo "</url>\n";
}
echo '</urlset>'; exit;
