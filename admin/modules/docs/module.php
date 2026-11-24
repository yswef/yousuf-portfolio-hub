<?php
declare(strict_types=1);

/** Select options "slug => name" from a table, with an empty "no link" choice first. */
function docs_link_opts(string $table, string $labelSql): array {
    $o = ['' => '— بدون ربط —'];
    try { if (table_exists($table)) foreach (rows("SELECT slug, $labelSql AS n FROM $table ORDER BY n") as $r) $o[(string)$r['slug']] = (string)$r['n']; }
    catch (Throwable $e) { error_log('[docs] link options: ' . $e->getMessage()); }
    return $o;
}

return crud_module([
    'title' => ['ar' => 'التوثيق', 'en' => 'Docs'], 'icon' => '📘', 'group' => 'content', 'order' => 25, 'version' => 1,
    'table' => 'docs', 'one' => 'توثيق', 'new_label' => 'توثيق جديد',
    'schema' => fn(array $T) => [
        "CREATE TABLE IF NOT EXISTS docs (id {$T['id']}, slug VARCHAR(190) UNIQUE, title VARCHAR(190), summary TEXT NULL, body {$T['big']} NULL,
          project_slug VARCHAR(190) NULL, app_slug VARCHAR(190) NULL, hidden INT DEFAULT 0, sort_order INT DEFAULT 0, created INT, updated INT)" . $T['tail'],
        "CREATE INDEX idx_docs_project ON docs (project_slug)",
    ],
    'widgets' => fn() => [['label' => 'التوثيقات', 'value' => (int)val('SELECT COUNT(*) FROM docs')]],
    'fields' => [
        'title' => ['t' => 'text', 'label' => 'العنوان', 'req' => true, 'max' => 190],
        'slug' => ['t' => 'slug', 'label' => 'الرابط المختصر', 'from' => 'title'],
        'project_slug' => ['t' => 'select', 'label' => 'مرتبط بالمشروع', 'opts' => docs_link_opts('projects', 'name')],
        'app_slug' => ['t' => 'select', 'label' => 'مرتبط بالتطبيق', 'opts' => docs_link_opts('apps', 'name')],
        'summary' => ['t' => 'textarea', 'label' => 'ملخص قصير (يظهر في البطاقة)', 'rows' => 2, 'max' => 500],
        'body' => ['t' => 'md', 'label' => 'محتوى التوثيق', 'rows' => 18],
        'hidden' => ['t' => 'check', 'label' => 'مخفي عن الزوار'],
        'sort_order' => ['t' => 'num', 'label' => 'الترتيب'],
        'created' => ['t' => 'ts_created'], 'updated' => ['t' => 'ts_updated'],
    ],
    'list' => ['order' => 'sort_order, id DESC', 'columns' => [
        ['label' => 'العنوان', 'fmt' => fn($r) => '<b>' . e($r['title']) . '</b>'],
        ['label' => 'مشروع', 'fmt' => fn($r) => e((string)$r['project_slug'])],
        ['label' => 'تطبيق', 'fmt' => fn($r) => e((string)$r['app_slug'])],
        ['label' => 'مخفي', 'fmt' => fn($r) => $r['hidden'] ? '🚫' : ''],
    ]],
]);
