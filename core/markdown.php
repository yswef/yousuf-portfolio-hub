<?php
declare(strict_types=1);
/* Small safe UTF-8 Markdown: input is escaped first, then formatted (safe for untrusted READMEs and Arabic/multilingual text). */
function md_lines(string $s): array {
    // Normalize CRLF/CR to LF — never use /\R/ without /u (0x85 collides with UTF-8 Arabic 'م' = D9 85).
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    // Heal any legacy lone-0xD9 / U+FFFD corruption if ever stored earlier
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = (string)preg_replace('/\xD9(?![\x80-\xBF])/', "\xD9\x85", $s);
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }
    return explode("\n", $s);
}
function md_inline(string $s): string {
    $s = (string)preg_replace('/`([^`]+)`/u', '<code>$1</code>', $s);
    $s = (string)preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)[^)]*\)/u', function ($m) {
        return preg_match('#^https://#i', html_entity_decode($m[2])) ? '<img src="' . $m[2] . '" alt="' . $m[1] . '" loading="lazy">' : $m[1];
    }, $s);
    $s = (string)preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)[^)]*\)/u', function ($m) {
        $u = html_entity_decode($m[2]);
        if (!preg_match('#^(https?://|mailto:|/|\#)#i', $u)) return $m[1];
        return '<a href="' . $m[2] . '" rel="noopener nofollow">' . $m[1] . '</a>';
    }, $s);
    $s = (string)preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $s);
    return (string)preg_replace('/(?<![\p{L}\p{N}_*])\*([^*\n]+)\*(?![\p{L}\p{N}_*])/u', '<em>$1</em>', $s);
}
function md_clean_readme(string $t): string {
    $t = (string)preg_replace('/<!--.*?-->/su', '', $t);
    $out = []; $fence = false;
    foreach (md_lines($t) as $ln) {
        if (preg_match('/^\s*```/u', $ln)) $fence = !$fence;
        if (!$fence && preg_match('/^\s*<\/?[a-zA-Z][^>]*>\s*$/u', $ln)) continue;
        $out[] = $ln;
    }
    return implode("\n", $out);
}
function md(string $text): string {
    $lines = md_lines($text);
    $html = ''; $para = []; $list = null; $code = null;
    $flush = function () use (&$para, &$html) { if ($para) { $html .= '<p>' . md_inline(e(implode(' ', $para))) . '</p>'; $para = []; } };
    $closeList = function () use (&$list, &$html) { if ($list) { $html .= "</$list>"; $list = null; } };
    foreach ($lines as $ln) {
        if (preg_match('/^\s*```/u', $ln)) {
            if ($code === null) { $flush(); $closeList(); $code = []; }
            else { $html .= '<pre><code>' . e(implode("\n", $code)) . '</code></pre>'; $code = null; }
            continue;
        }
        if ($code !== null) { $code[] = $ln; continue; }
        if (trim($ln) === '') { $flush(); $closeList(); continue; }
        if (preg_match('/^(#{1,6})\s+(.*)$/u', $ln, $m)) { $flush(); $closeList(); $n = min(6, strlen($m[1]) + 1); $html .= "<h$n>" . md_inline(e(trim($m[2]))) . "</h$n>"; continue; }
        if (preg_match('/^\s*([-*+]|\d+\.)\s+(.*)$/u', $ln, $m)) {
            $flush(); $tag = ctype_digit($m[1][0]) ? 'ol' : 'ul';
            if ($list !== $tag) { $closeList(); $html .= "<$tag>"; $list = $tag; }
            $html .= '<li>' . md_inline(e($m[2])) . '</li>'; continue;
        }
        if (preg_match('/^\s*>\s?(.*)$/u', $ln, $m)) { $flush(); $closeList(); $html .= '<blockquote>' . md_inline(e($m[1])) . '</blockquote>'; continue; }
        if (preg_match('/^\s*([-*_])\1{2,}\s*$/u', $ln)) { $flush(); $closeList(); $html .= '<hr>'; continue; }
        $closeList(); $para[] = trim($ln);
    }
    if ($code !== null) $html .= '<pre><code>' . e(implode("\n", $code)) . '</code></pre>';
    $flush(); $closeList();
    return $html;
}
