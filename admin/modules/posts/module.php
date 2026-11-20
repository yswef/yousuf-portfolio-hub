<?php
declare(strict_types=1);

return crud_module([
    'title' => ['ar' => 'المدونة', 'en' => 'Blog'], 'icon' => '✎', 'group' => 'content', 'order' => 40, 'version' => 1,
    'table' => 'posts', 'one' => 'مقال',
    'schema' => fn(array $T) => [
        "CREATE TABLE IF NOT EXISTS posts (id {$T['id']}, slug VARCHAR(190) UNIQUE, lang VARCHAR(5) DEFAULT 'ar', title VARCHAR(255), summary VARCHAR(500) NULL,
          body {$T['big']} NULL, tags VARCHAR(255) NULL, cover VARCHAR(255) NULL, published INT DEFAULT 0, views INT DEFAULT 0, created INT, updated INT)" . $T['tail'],
    ],
    'widgets' => fn() => [['label' => 'مقالات منشورة', 'value' => (int)val('SELECT COUNT(*) FROM posts WHERE published=1')]],
    'fields' => [
        'title' => ['t' => 'text', 'label' => 'العنوان', 'req' => true],
        'slug' => ['t' => 'slug', 'label' => 'الرابط المختصر', 'from' => 'title'],
        'lang' => ['t' => 'select', 'label' => 'لغة المقال', 'opts' => array_map(fn($l) => $l['name'], LANGS)],
        'summary' => ['t' => 'textarea', 'label' => 'ملخص', 'rows' => 2, 'max' => 500],
        'cover' => ['t' => 'image', 'label' => 'صورة الغلاف'],
        'body' => ['t' => 'md', 'label' => 'المحتوى', 'rows' => 16],
        'tags' => ['t' => 'text', 'label' => 'الوسوم (مفصولة بفاصلة)'],
        'published' => ['t' => 'check', 'label' => 'منشور'],
        'created' => ['t' => 'ts_created'], 'updated' => ['t' => 'ts_updated'],
    ],
    'list' => [
        'order' => 'id DESC',
        'columns' => [
            ['label' => 'العنوان', 'fmt' => fn($r) => '<b>' . e($r['title']) . '</b>'],
            ['label' => 'اللغة', 'fmt' => fn($r) => e(strtoupper($r['lang']))],
            ['label' => 'الحالة', 'fmt' => fn($r) => $r['published'] ? '✓ منشور' : 'مسودة'],
            ['label' => 'المشاهدات', 'fmt' => fn($r) => (string)(int)$r['views']],
            ['label' => 'التاريخ', 'fmt' => fn($r) => e(fmt_date($r['created']))],
        ],
    ],
]);
