<?php
declare(strict_types=1);
/**
 * App Requirements (متطلبات التطبيقات) helpers — loaded via require_once from module.php.
 * Section 1: مصادر الأشياء (asset_sources by category: audio/images/icons/fonts/video/data)
 * Section 2: متطلبات التطبيقات (app_requirements per app_id, missing-first + priority sort, per-app counters)
 */

function req_boards_opts(): array {
    $o = [0 => '— كل التطبيقات —'];
    try {
        if (table_exists('boards')) foreach (rows('SELECT id, name FROM boards ORDER BY name') as $b) $o[(int)$b['id']] = (string)$b['name'];
    } catch (Throwable $e) {}
    return $o;
}

function req_source_opts(): array {
    $o = [0 => '— بدون مصدر مرتبط —'];
    try {
        if (table_exists('asset_sources')) foreach (rows('SELECT id, name, category FROM asset_sources ORDER BY category, sort_order, name') as $s) {
            $o[(int)$s['id']] = (ASSET_SOURCE_CATS[$s['category']]['label'] ?? $s['category']) . ' · ' . $s['name'];
        }
    } catch (Throwable $e) {}
    return $o;
}

function req_missing_counts(): array {
    $out = [];
    try {
        if (table_exists('app_requirements')) {
            foreach (rows("SELECT app_id, COUNT(*) AS c FROM app_requirements WHERE status='missing' GROUP BY app_id") as $r) {
                $out[(int)$r['app_id']] = (int)$r['c'];
            }
        }
    } catch (Throwable $e) {}
    return $out;
}

function req_fab(): void {
    echo '<a class="mp-fab-return" href="' . e(adm_url('boards')) . '" style="position:fixed;bottom:20px;inset-inline-end:20px;z-index:99999;display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:999px;background:linear-gradient(120deg,#0d9488,#0284c7);color:#fff;font:700 13.5px/1.4 Cairo,system-ui,Tahoma,sans-serif;text-decoration:none;box-shadow:0 10px 28px rgba(2,132,199,.38);border:1px solid rgba(255,255,255,.22)">↩ العودة للوحة التحكم الأم</a>';
}
