<?php
declare(strict_types=1);

const LANGS = [
    'ar' => ['name' => 'العربية', 'dir' => 'rtl'],
    'en' => ['name' => 'English', 'dir' => 'ltr'],
    'es' => ['name' => 'Español', 'dir' => 'ltr'],
    'fr' => ['name' => 'Français', 'dir' => 'ltr'],
    'ja' => ['name' => '日本語', 'dir' => 'ltr'],
];
const DEFAULT_LANG = 'ar';

function cur_lang(): string { return defined('LANG') ? LANG : DEFAULT_LANG; }
function lang_dir(?string $l = null): string { return LANGS[$l ?? cur_lang()]['dir'] ?? 'ltr'; }
/** Lookup order for any translated value: current → en → ar → the rest. */
function lang_chain(): array { return array_values(array_unique(array_merge([cur_lang(), 'en', 'ar'], array_keys(LANGS)))); }

/* ---------- UI strings: lang/<code>.json (missing keys fall back along the chain) ---------- */
function lang_file(string $l): array {
    static $c = [];
    if (!isset($c[$l])) {
        $f = ROOT . '/lang/' . $l . '.json';
        $c[$l] = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }
    return $c[$l];
}
function t(string $k): string {
    foreach (lang_chain() as $l) { $v = lang_file($l)[$k] ?? null; if ($v !== null && $v !== '') return (string)$v; }
    return $k;
}

/* ---------- translated DB values: JSON like {"ar":"..","en":".."} ---------- */
function i18n_decode(?string $json): array {
    if ($json === null || $json === '') return [];
    $a = json_decode($json, true);
    return is_array($a) ? $a : [];
}
function L(?string $json, string $fallback = ''): string {
    $a = i18n_decode($json);
    foreach (lang_chain() as $l) if (isset($a[$l]) && trim((string)$a[$l]) !== '') return (string)$a[$l];
    return $fallback;
}
/** tr($row,'title') reads title_i18n (JSON) and falls back to the plain column $plain (default: same name). */
function tr(array $r, string $f, ?string $plain = null): string {
    return L($r[$f . '_i18n'] ?? null, (string)($r[$plain ?? $f] ?? ''));
}
/** Settings stored per language as name_ar / name_en / ... */
function setting_l(string $k): string {
    foreach (lang_chain() as $l) { $v = setting($k . '_' . $l); if ($v !== '') return $v; }
    return '';
}
/** Build the JSON string from a posted array field: name[ar]=.. name[en]=.. */
function i18n_post(string $name, int $max = 20000): string {
    $out = [];
    foreach (array_keys(LANGS) as $l) {
        $v = trim((string)(($_POST[$name] ?? [])[$l] ?? ''));
        if ($v !== '') $out[$l] = mb_substr($v, 0, $max);
    }
    return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : '';
}
