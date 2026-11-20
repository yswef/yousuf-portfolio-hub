<?php
declare(strict_types=1);

return crud_module([
    'title' => ['ar' => 'صفحات مخصّصة', 'en' => 'Custom pages'], 'icon' => '❏', 'group' => 'content', 'order' => 60, 'version' => 1,
    'table' => 'pages', 'one' => 'صفحة',
    'schema' => fn(array $T) => [
        "CREATE TABLE IF NOT EXISTS pages (id {$T['id']}, slug VARCHAR(190) UNIQUE, title_i18n TEXT NULL, body_i18n {$T['big']} NULL, published INT DEFAULT 1,
          in_nav INT DEFAULT 0, sort_order INT DEFAULT 0, updated INT)" . $T['tail'],
    ],
    'fields' => [
        'title_i18n' => ['t' => 'i18n', 'label' => 'العنوان'],
        'slug' => ['t' => 'slug', 'label' => 'الرابط المختصر', 'from' => 'title_i18n', 'help' => 'تظهر الصفحة على /{لغة}/p/{الرابط}'],
        'body_i18n' => ['t' => 'i18n_area', 'label' => 'المحتوى (Markdown)', 'rows' => 12, 'max' => 60000],
        'published' => ['t' => 'check', 'label' => 'منشورة', 'default' => 1],
        'in_nav' => ['t' => 'check', 'label' => 'تظهر في القائمة العلوية'],
        'sort_order' => ['t' => 'num', 'label' => 'الترتيب'],
        'updated' => ['t' => 'ts_updated'],
    ],
    'list' => [
        'order' => 'sort_order, id',
        'columns' => [
            ['label' => 'العنوان', 'fmt' => fn($r) => '<b>' . e(L($r['title_i18n'], $r['slug'])) . '</b>'],
            ['label' => 'الرابط', 'dir' => 'ltr', 'fmt' => fn($r) => e($r['slug'])],
            ['label' => 'الحالة', 'fmt' => fn($r) => $r['published'] ? '✓' : 'مسودة'],
            ['label' => 'القائمة', 'fmt' => fn($r) => $r['in_nav'] ? '✓' : ''],
        ],
    ],
]);
