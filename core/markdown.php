<?php
declare(strict_types=1);
/* Small safe Markdown: input is escaped first, then formatted (safe for untrusted READMEs). */
function md_inline(string $s): string {
    $s = (string)preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
    $s = (string)preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)[^)]*\)/', function ($m) {
        return preg_match('#^https://#', html_entity_decode($m[2])) ? '<img src="' . $m[2] . '" alt="' . $m[1] . '" loading="lazy">' : $m[1];
    }, $s);
    $s = (string)preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)[^)]*\)/', function ($m) {
        $u = html_entity_decode($m[2]);
        if (!preg_match('#^(https?://|mailto:|/|\#)#i', $u)) return $m[1];
        return '<a href="' . $m[2] . '" rel="noopener nofollow">' . $m[1] . '</a>';
    }, $s);
    $s = (string)preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    return (string)preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<em>$1</em>', $s);
}
function md_clean_readme(string $t): string {
    $t = (string)preg_replace('/<!--.*?-->/s', '', $t);
    $out = []; $fence = false;
    foreach (preg_split('/\R/', $t) as $ln) {
        if (preg_match('/^\s*```/', $ln)) $fence = !$fence;
        if (!$fence && preg_match('/^\s*<\/?[a-zA-Z][^>]*>\s*$/', $ln)) continue;
        $out[] = $ln;
    }
    return implode("\n", $out);
}
function md(string $text): string {
    $lines = preg_split('/\R/', $text);
    $html = ''; $para = []; $list = null; $code = null;
    $flush = function () use (&$para, &$html) { if ($para) { $html .= '<p>' . md_inline(e(implode(' ', $para))) . '</p>'; $para = []; } };
    $closeList = function () use (&$list, &$html) { if ($list) { $html .= "</$list>"; $list = null; } };
    foreach ($lines as $ln) {
        if (preg_match('/^\s*```/', $ln)) {
            if ($code === null) { $flush(); $closeList(); $code = []; }
            else { $html .= '<pre><code>' . e(implode("\n", $code)) . '</code></pre>'; $code = null; }
            continue;
        }
        if ($code !== null) { $code[] = $ln; continue; }
        if (trim($ln) === '') { $flush(); $closeList(); continue; }
        if (preg_match('/^(#{1,6})\s+(.*)$/', $ln, $m)) { $flush(); $closeList(); $n = min(6, strlen($m[1]) + 1); $html .= "<h$n>" . md_inline(e(trim($m[2]))) . "</h$n>"; continue; }
        if (preg_match('/^\s*([-*+]|\d+\.)\s+(.*)$/', $ln, $m)) {
            $flush(); $tag = ctype_digit($m[1][0]) ? 'ol' : 'ul';
            if ($list !== $tag) { $closeList(); $html .= "<$tag>"; $list = $tag; }
            $html .= '<li>' . md_inline(e($m[2])) . '</li>'; continue;
        }
        if (preg_match('/^\s*>\s?(.*)$/', $ln, $m)) { $flush(); $closeList(); $html .= '<blockquote>' . md_inline(e($m[1])) . '</blockquote>'; continue; }
        if (preg_match('/^\s*([-*_])\1{2,}\s*$/', $ln)) { $flush(); $closeList(); $html .= '<hr>'; continue; }
        $closeList(); $para[] = trim($ln);
    }
    if ($code !== null) $html .= '<pre><code>' . e(implode("\n", $code)) . '</code></pre>';
    $flush(); $closeList();
    return $html;
}
