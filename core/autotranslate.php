<?php
declare(strict_types=1);
/**
 * Automatic translation: write in Arabic, the other languages (en, es, fr, ja) are filled automatically.
 * Uses free translation endpoints (no API key required) with an in-request cache and short timeouts.
 * On any network failure, saving still succeeds without blocking.
 * Toggle: Settings → "ترجمة تلقائية عند الحفظ" (setting auto_translate, default '1').
 */
function auto_translate_enabled(): bool { return setting('auto_translate', '1') === '1'; }

/** HTTP GET helper with short timeouts so offline/sandbox environments never hang. */
function auto_translate_http(string $url): ?string {
    if (!function_exists('curl_init')) return null;
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'Mozilla/5.0 portfolio-autotranslate',
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200) ? (string)$body : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Translate one chunk (≤ ~450 chars) from $source to $target. Returns '' on failure. */
function auto_translate_chunk(string $text, string $target, string $source = 'ar'): string {
    static $cache = [];
    $text = trim($text);
    if ($text === '' || $target === $source || !isset(LANGS[$target])) return '';
    $ck = $source . '|' . $target . '|' . md5($text);
    if (isset($cache[$ck])) return $cache[$ck];

    // Primary: MyMemory free API
    $url = 'https://api.mymemory.translated.net/get?q=' . rawurlencode($text) . '&langpair=' . rawurlencode($source . '|' . $target);
    if (($body = auto_translate_http($url)) !== null) {
        $j = json_decode($body, true);
        $t = trim((string)($j['responseData']['translatedText'] ?? ''));
        if ($t !== '' && stripos($t, 'INVALID') === false && stripos($t, 'QUERY LENGTH LIMIT') === false && stripos($t, 'MYMEMORY WARNING') === false) {
            return $cache[$ck] = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    // Fallback: public translate.googleapis.com single endpoint
    $gurl = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=' . rawurlencode($source) . '&tl=' . rawurlencode($target) . '&dt=t&q=' . rawurlencode($text);
    if (($gbody = auto_translate_http($gurl)) !== null) {
        $gj = json_decode($gbody, true);
        if (is_array($gj[0] ?? null)) {
            $pieces = [];
            foreach ($gj[0] as $seg) if (isset($seg[0]) && is_string($seg[0])) $pieces[] = $seg[0];
            $gt = trim(implode('', $pieces));
            if ($gt !== '') return $cache[$ck] = $gt;
        }
    }

    return $cache[$ck] = '';
}

/** Translate Arabic text to $target, chunking long text by lines/sentences while preserving paragraph breaks. */
function auto_translate(string $text, string $target, string $source = 'ar'): string {
    $text = trim($text);
    if ($text === '' || $target === $source) return '';
    if (mb_strlen($text) <= 450) return auto_translate_chunk($text, $target, $source);
    $paras = preg_split('/\n{2,}/u', $text) ?: [$text];
    $outParas = [];
    foreach ($paras as $para) {
        $parts = preg_split('/(?<=[.!؟?\n])\s+/u', trim($para)) ?: [trim($para)];
        $out = []; $buf = '';
        foreach ($parts as $p) {
            if (mb_strlen($buf . ' ' . $p) > 450 && $buf !== '') { $out[] = auto_translate_chunk($buf, $target, $source); $buf = ''; }
            $buf = trim($buf . ' ' . $p);
        }
        if ($buf !== '') $out[] = auto_translate_chunk($buf, $target, $source);
        $joined = trim(implode(' ', array_filter($out)));
        if ($joined !== '') $outParas[] = $joined;
    }
    return trim(implode("\n\n", $outParas));
}

/**
 * Given decoded i18n values [lang=>text], fill missing languages from Arabic.
 * If $oldVals is provided and Arabic changed while $vals[$l] still equals $oldVals[$l],
 * re-translates $l automatically so editing Arabic updates stale translations too!
 */
function i18n_auto_fill(array $vals, array $oldVals = []): array {
    if (!auto_translate_enabled()) return $vals;
    $ar = trim((string)($vals['ar'] ?? ''));
    if ($ar === '') return $vals;
    $oldAr = trim((string)($oldVals['ar'] ?? ''));
    $arChanged = ($oldVals !== [] && $ar !== $oldAr);
    foreach (array_keys(LANGS) as $l) {
        if ($l === 'ar') continue;
        $cur = trim((string)($vals[$l] ?? ''));
        $oldL = trim((string)($oldVals[$l] ?? ''));
        $needsFill = ($cur === '') || ($arChanged && $cur === $oldL);
        if (!$needsFill) continue;
        $t = auto_translate($ar, $l, 'ar');
        if ($t !== '') $vals[$l] = $t;
    }
    return $vals;
}

/** Auto-fill + re-encode a stored i18n JSON string (optionally comparing against $oldJson). */
function i18n_json_auto_fill(string $json, ?string $oldJson = null): string {
    if (trim($json) === '') return $json;
    $vals = i18n_auto_fill(i18n_decode($json), $oldJson !== null ? i18n_decode($oldJson) : []);
    return json_encode($vals, JSON_UNESCAPED_UNICODE);
}
