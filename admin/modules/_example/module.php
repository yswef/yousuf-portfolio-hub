<?php
declare(strict_types=1);
/**
 * EXAMPLE module — a complete dashboard section for a made-up "customers" table.
 * This folder starts with "_" so it is DISABLED. To try it: rename the folder to "customers" (the folder name becomes the module id).
 * Full contract: admin/MODULES.md
 */
return crud_module([
    'title' => ['ar' => 'العملاء', 'en' => 'Customers'],
    'icon' => '👥',
    'group' => 'app',          // content | app | system  (use "app" for another app's dashboard)
    'order' => 200,            // position in the sidebar
    'version' => 1,            // bump it when 'schema' changes → tables are (re)created automatically on next admin load
    'table' => 'customers', 'one' => 'عميل',
    'schema' => fn(array $T) => [
        "CREATE TABLE IF NOT EXISTS customers (id {$T['id']}, name VARCHAR(190), phone VARCHAR(50) NULL, notes TEXT NULL, active INT DEFAULT 1, created INT)" . $T['tail'],
    ],
    'widgets' => fn() => [['label' => 'العملاء', 'value' => (int)val('SELECT COUNT(*) FROM customers')]],   // KPI tile on the dashboard
    'badge' => null,           // or fn() => int  → red counter next to the menu item
    'fields' => [
        'name' => ['t' => 'text', 'label' => 'الاسم', 'req' => true],
        'phone' => ['t' => 'text', 'label' => 'الهاتف', 'dir' => 'ltr'],
        'notes' => ['t' => 'textarea', 'label' => 'ملاحظات'],
        'active' => ['t' => 'check', 'label' => 'نشط', 'default' => 1],
        'created' => ['t' => 'ts_created'],
    ],
    'list' => ['order' => 'id DESC', 'columns' => [
        ['label' => 'الاسم', 'fmt' => fn($r) => '<b>' . e($r['name']) . '</b>'],
        ['label' => 'الهاتف', 'dir' => 'ltr', 'fmt' => fn($r) => e($r['phone'])],
        ['label' => 'نشط', 'fmt' => fn($r) => $r['active'] ? '✓' : ''],
    ]],
    // Custom (non-CRUD) screens: replace 'view' with callable(array $mod): void and add your own 'actions' => ['name' => callable].
]);
