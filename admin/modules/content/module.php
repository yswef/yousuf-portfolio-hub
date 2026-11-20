<?php
declare(strict_types=1);

$common = [
    'sort_order' => ['t' => 'num', 'label' => 'الترتيب'],
    'hidden' => ['t' => 'check', 'label' => 'مخفي عن الزوار'],
];
$ord = ['label' => 'ترتيب', 'fmt' => fn($r) => (string)(int)$r['sort_order']];
$ttl = ['label' => 'العنوان', 'fmt' => fn($r) => '<b>' . e(tr($r, 'title')) . '</b>' . ($r['hidden'] ? ' 🚫' : '')];

return crud_module([
    'title' => ['ar' => 'المحتوى (الخبرات والمهارات…)', 'en' => 'Content'], 'icon' => '☰', 'group' => 'content', 'order' => 50, 'version' => 1,
    'table' => 'items', 'one' => 'عنصر',
    'schema' => fn(array $T) => [
        "CREATE TABLE IF NOT EXISTS items (id {$T['id']}, type VARCHAR(20), title_i18n TEXT NULL, sub_i18n TEXT NULL, text_i18n TEXT NULL, extra VARCHAR(255) NULL,
          icon VARCHAR(30) NULL, level INT DEFAULT 0, date_from VARCHAR(20) NULL, date_to VARCHAR(20) NULL, is_current INT DEFAULT 0, sort_order INT DEFAULT 0, hidden INT DEFAULT 0)" . $T['tail'],
        "CREATE INDEX idx_items_type ON items (type, sort_order)",
    ],
    'seed' => function (): void {
        foreach (json_decode((string)file_get_contents(__DIR__ . '/seed.json'), true) ?: [] as $it) {
            $j = fn($a) => $a ? json_encode($a, JSON_UNESCAPED_UNICODE) : '';
            ins('items', ['type' => $it['type'], 'title_i18n' => $j($it['title']), 'sub_i18n' => $j($it['sub']), 'text_i18n' => $j($it['text']), 'extra' => $it['extra'],
                'icon' => $it['icon'], 'level' => $it['level'], 'date_from' => $it['date_from'], 'date_to' => $it['date_to'], 'is_current' => $it['is_current'], 'sort_order' => $it['sort_order']]);
        }
    },
    'fields' => $common,
    'list' => ['order' => 'sort_order, id'],
    'types' => [
        'experience' => ['label' => 'الخبرات والعمل', 'one' => 'خبرة', 'fields' => [
            'title_i18n' => ['t' => 'i18n', 'label' => 'المسمّى / العمل'],
            'sub_i18n' => ['t' => 'i18n', 'label' => 'الجهة (الشركة / المطعم…)'],
            'text_i18n' => ['t' => 'i18n_area', 'label' => 'وصف مختصر', 'rows' => 3],
            'date_from' => ['t' => 'date', 'label' => 'من'], 'date_to' => ['t' => 'date', 'label' => 'إلى (اتركه فارغاً إن كان مستمراً)'],
            'is_current' => ['t' => 'check', 'label' => 'أعمل فيه حالياً'],
        ], 'columns' => [$ttl, ['label' => 'الجهة', 'fmt' => fn($r) => e(tr($r, 'sub'))], ['label' => 'حالياً', 'fmt' => fn($r) => $r['is_current'] ? '✓' : ''], $ord]],
        'education' => ['label' => 'التعليم والشهادات', 'one' => 'شهادة', 'fields' => [
            'title_i18n' => ['t' => 'i18n', 'label' => 'العنوان'], 'sub_i18n' => ['t' => 'i18n', 'label' => 'الجهة المانحة'],
            'text_i18n' => ['t' => 'i18n_area', 'label' => 'ملاحظة', 'rows' => 2], 'date_to' => ['t' => 'date', 'label' => 'سنة الحصول'],
        ], 'columns' => [$ttl, ['label' => 'الجهة', 'fmt' => fn($r) => e(tr($r, 'sub'))], $ord]],
        'skill' => ['label' => 'المهارات', 'one' => 'مهارة', 'fields' => [
            'title_i18n' => ['t' => 'i18n', 'label' => 'الاسم'], 'icon' => ['t' => 'text', 'label' => 'أيقونة (إيموجي)', 'max' => 30],
            'level' => ['t' => 'num', 'label' => 'المستوى 0–100 (0 = بدون شريط)'], 'extra' => ['t' => 'text', 'label' => 'التصنيف'],
        ], 'columns' => [['label' => '', 'fmt' => fn($r) => e($r['icon'])], $ttl, ['label' => 'المستوى', 'fmt' => fn($r) => (string)(int)$r['level']], $ord]],
        'service' => ['label' => 'الخدمات', 'one' => 'خدمة', 'fields' => [
            'title_i18n' => ['t' => 'i18n', 'label' => 'العنوان'], 'text_i18n' => ['t' => 'i18n_area', 'label' => 'الوصف', 'rows' => 3],
            'icon' => ['t' => 'text', 'label' => 'أيقونة (إيموجي)', 'max' => 30], 'extra' => ['t' => 'text', 'label' => 'ملاحظة السعر / الوسم'],
        ], 'columns' => [['label' => '', 'fmt' => fn($r) => e($r['icon'])], $ttl, $ord]],
        'learning' => ['label' => 'أتعلّم حالياً', 'one' => 'موضوع', 'fields' => [
            'title_i18n' => ['t' => 'i18n', 'label' => 'الموضوع'], 'text_i18n' => ['t' => 'i18n_area', 'label' => 'وصف', 'rows' => 2],
            'level' => ['t' => 'num', 'label' => 'نسبة التقدّم 0–100'],
        ], 'columns' => [$ttl, ['label' => 'التقدّم', 'fmt' => fn($r) => (int)$r['level'] . '%'], $ord]],
        'link' => ['label' => 'روابط التواصل', 'one' => 'رابط', 'fields' => [
            'title_i18n' => ['t' => 'i18n', 'label' => 'الاسم'], 'extra' => ['t' => 'url', 'label' => 'الرابط', 'max' => 255],
            'icon' => ['t' => 'text', 'label' => 'أيقونة (إيموجي)', 'max' => 30],
        ], 'columns' => [['label' => '', 'fmt' => fn($r) => e($r['icon'])], $ttl, ['label' => 'الرابط', 'dir' => 'ltr', 'fmt' => fn($r) => e($r['extra'])], $ord]],
    ],
]);
