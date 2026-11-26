<?php
declare(strict_types=1);
/**
 * Multi-engine Automatic Translation (Arabic → en, es, fr, ja):
 *  1. Gemini AI (optional, if gemini_api_key is configured in Settings — native human quality)
 *  2. Google Neural Translate GTX (default primary — free, no key, coherent full-paragraph NMT)
 *  3. Lingva Neural mirror (fallback — free, no key)
 *  4. MyMemory (last-resort fallback)
 *
 * Includes a Technical-Term & Markdown Shield so code spans, URLs, and tech names
 * (Python, WordPress, ERP, GitHub, etc.) are never mangled during translation.
 */
function auto_translate_enabled(): bool { return setting('auto_translate', '1') === '1'; }

/** HTTP helper supporting GET and POST with short timeouts so saving never hangs. */
function auto_translate_http(string $url, ?string $postBody = null, array $headers = []): ?string {
    if (!function_exists('curl_init')) return null;
    try {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
            CURLOPT_FOLLOWLOCATION => true,
        ];
        if ($postBody !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $postBody;
        }
        if ($headers) $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 300) ? (string)$body : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Protect code blocks, URLs, and technical brand names with placeholders before NMT. */
function autotranslate_protect(string $text): array {
    $map = [];
    $put = function (string $val) use (&$map): string {
        $tok = 'ZXQ' . count($map) . 'QXZ';
        $map[$tok] = $val;
        return $tok;
    };
    // Protect fenced code, inline code, and URLs
    $text = (string)preg_replace_callback('/```[\s\S]*?```|`[^`\n]+`|https?:\/\/[^\s)]+/u', fn($m) => $put($m[0]), $text);
    // Protect common technical brand terms (case-insensitive whole-token match)
    $terms = 'Python|WordPress|ERP|Frappe|ERPNext|GitHub|Windows|Linux|Android|macOS|iOS|Flutter|React Native|TypeScript|JavaScript|Kotlin|PHP|MySQL|SQLite|PySide6|PyQt|CustomTkinter|PyWebView|PowerShell|Docker|Cloudflare|API|REST|CLI|GUI|JSON|HTML5|CSS3';
    $text = (string)preg_replace_callback('/(?<![A-Za-z0-9])(?:' . $terms . ')(?![A-Za-z0-9])/iu', fn($m) => $put($m[0]), $text);
    return [$text, $map];
}

function autotranslate_restore(string $translated, array $map): string {
    foreach ($map as $tok => $orig) {
        $translated = (string)preg_replace('/' . preg_quote($tok, '/') . '/iu', str_replace(['\\', '$'], ['\\\\', '\\$'], $orig), $translated);
    }
    return trim($translated);
}

/** Engine 1 (Optional): Gemini AI translation for top-tier natural fluency. */
function auto_translate_gemini(string $text, string $target, string $source = 'ar'): string {
    $apiKey = trim(setting('gemini_api_key'));
    if ($apiKey === '') return '';
    $langName = ['en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'ja' => 'Japanese', 'ar' => 'Arabic'][$target] ?? $target;
    $payload = json_encode([
        'contents' => [[
            'parts' => [[
                'text' => "Translate the following software developer portfolio text from $source to natural, professional $langName. Preserve Markdown formatting, line breaks, and technical terms accurately. Return ONLY the translated text without quotes or explanations:\n\n" . $text,
            ]],
        ]],
        'generationConfig' => ['temperature' => 0.2],
    ], JSON_UNESCAPED_UNICODE);
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . rawurlencode($apiKey);
    $resp = auto_translate_http($url, (string)$payload, ['Content-Type: application/json']);
    if ($resp === null) return '';
    $j = json_decode($resp, true);
    return trim((string)($j['candidates'][0]['content']['parts'][0]['text'] ?? ''));
}

/** Engine 2 (Default Primary): Google Neural Machine Translation (GTX) via POST (coherent multi-sentence NMT). */
function auto_translate_google(string $text, string $target, string $source = 'ar'): string {
    [$safeText, $map] = autotranslate_protect($text);
    $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=' . rawurlencode($source) . '&tl=' . rawurlencode($target) . '&dt=t';
    $body = auto_translate_http($url, 'q=' . rawurlencode($safeText), ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8']);
    if ($body === null && mb_strlen($safeText) <= 900) {
        $body = auto_translate_http($url . '&q=' . rawurlencode($safeText));
    }
    if ($body === null) return '';
    $gj = json_decode($body, true);
    if (!is_array($gj[0] ?? null)) return '';
    $pieces = [];
    foreach ($gj[0] as $seg) {
        if (isset($seg[0]) && is_string($seg[0])) $pieces[] = $seg[0];
    }
    $joined = trim(implode('', $pieces));
    return $joined !== '' ? autotranslate_restore($joined, $map) : '';
}

/** Engine 3 (Fallback): Lingva public neural mirror. */
function auto_translate_lingva(string $text, string $target, string $source = 'ar'): string {
    if (mb_strlen($text) > 900) return '';
    [$safeText, $map] = autotranslate_protect($text);
    foreach (['https://translate.plausibility.cloud', 'https://lingva.ml'] as $host) {
        $body = auto_translate_http($host . '/api/v1/' . rawurlencode($source) . '/' . rawurlencode($target) . '/' . rawurlencode($safeText));
        if ($body !== null) {
            $j = json_decode($body, true);
            $tr = trim((string)($j['translation'] ?? ''));
            if ($tr !== '') return autotranslate_restore($tr, $map);
        }
    }
    return '';
}

/** Engine 4 (Last resort): MyMemory API for short strings only. */
function auto_translate_mymemory(string $text, string $target, string $source = 'ar'): string {
    if (mb_strlen($text) > 450) return '';
    [$safeText, $map] = autotranslate_protect($text);
    $url = 'https://api.mymemory.translated.net/get?q=' . rawurlencode($safeText) . '&langpair=' . rawurlencode($source . '|' . $target);
    $body = auto_translate_http($url);
    if ($body === null) return '';
    $j = json_decode($body, true);
    $t = trim((string)($j['responseData']['translatedText'] ?? ''));
    if ($t === '' || stripos($t, 'INVALID') !== false || stripos($t, 'QUERY LENGTH LIMIT') !== false || stripos($t, 'MYMEMORY WARNING') !== false) return '';
    return autotranslate_restore(html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $map);
}

/** Translate one coherent block (≤ 2500 chars) using the multi-engine cascade. */
function auto_translate_block(string $text, string $target, string $source = 'ar'): string {
    static $cache = [];
    $text = trim($text);
    if ($text === '' || $target === $source || !isset(LANGS[$target])) return '';
    $ck = $source . '|' . $target . '|' . md5($text);
    if (isset($cache[$ck])) return $cache[$ck];

    $provider = setting('translate_provider', 'auto');
    if (($provider === 'auto' || $provider === 'gemini') && ($res = auto_translate_gemini($text, $target, $source)) !== '') {
        return $cache[$ck] = $res;
    }
    if (($res = auto_translate_google($text, $target, $source)) !== '') {
        return $cache[$ck] = $res;
    }
    if (($res = auto_translate_lingva($text, $target, $source)) !== '') {
        return $cache[$ck] = $res;
    }
    if (($res = auto_translate_mymemory($text, $target, $source)) !== '') {
        return $cache[$ck] = $res;
    }
    return $cache[$ck] = '';
}

/** Translate full Arabic text (preserving paragraphs) to $target. */
function auto_translate(string $text, string $target, string $source = 'ar'): string {
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    if ($text === '' || $target === $source) return '';
    if (mb_strlen($text) <= 2400) return auto_translate_block($text, $target, $source);

    $paras = preg_split('/\n{2,}/u', $text) ?: [$text];
    $outParas = [];
    foreach ($paras as $para) {
        $para = trim($para);
        if ($para === '') continue;
        if (mb_strlen($para) <= 2400) {
            $t = auto_translate_block($para, $target, $source);
            if ($t !== '') $outParas[] = $t;
            continue;
        }
        $parts = preg_split('/(?<=[.!؟?\n])\s+/u', $para) ?: [$para];
        $out = []; $buf = '';
        foreach ($parts as $p) {
            if (mb_strlen($buf . ' ' . $p) > 1800 && $buf !== '') { $out[] = auto_translate_block($buf, $target, $source); $buf = ''; }
            $buf = trim($buf . ' ' . $p);
        }
        if ($buf !== '') $out[] = auto_translate_block($buf, $target, $source);
        $joined = trim(implode(' ', array_filter($out)));
        if ($joined !== '') $outParas[] = $joined;
    }
    return trim(implode("\n\n", $outParas));
}

/**
 * Given decoded i18n values [lang=>text], fill missing or stale languages from Arabic.
 * Set $force = true to overwrite all non-Arabic languages from Arabic.
 */
function i18n_auto_fill(array $vals, array $oldVals = [], bool $force = false): array {
    if (!$force && !auto_translate_enabled()) return $vals;
    $ar = trim((string)($vals['ar'] ?? ''));
    if ($ar === '') return $vals;
    $oldAr = trim((string)($oldVals['ar'] ?? ''));
    $arChanged = ($oldVals !== [] && $ar !== $oldAr);
    foreach (array_keys(LANGS) as $l) {
        if ($l === 'ar') continue;
        $cur = trim((string)($vals[$l] ?? ''));
        $oldL = trim((string)($oldVals[$l] ?? ''));
        $needsFill = $force || ($cur === '') || ($arChanged && $cur === $oldL);
        if (!$needsFill) continue;
        $t = auto_translate($ar, $l, 'ar');
        if ($t !== '') $vals[$l] = $t;
    }
    return $vals;
}

/** Auto-fill + re-encode a stored i18n JSON string. */
function i18n_json_auto_fill(string $json, ?string $oldJson = null, bool $force = false): string {
    if (trim($json) === '') return $json;
    $vals = i18n_auto_fill(i18n_decode($json), $oldJson !== null ? i18n_decode($oldJson) : [], $force);
    return json_encode($vals, JSON_UNESCAPED_UNICODE);
}
