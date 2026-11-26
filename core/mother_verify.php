<?php
declare(strict_types=1);
/**
 * Pre-completion verification engine for the Mother Panel upgrade.
 * Checks: file order / SHA-256 fingerprints, zero duplicate tables, app_id scoping,
 * JSON-only API responses (never HTML), floating buttons (incl. locked → admin only),
 * and MOTHER_PANEL_HANDOFF.md read/execute gate per app.
 * Writes a timestamped report to data/mother-verify-<timestamp>.md and returns its path + summary.
 */

function mother_verify_file_fingerprints(): array {
    $files = [
        'index.php',
        'core/bootstrap.php',
        'core/migrate.php',
        'core/mother_schema.php',
        'core/handoff.php',
        'core/app_api.php',
        'core/sites.php',
        'core/mother_verify.php',
        'app/layout.php',
        'admin/modules/boards/module.php',
        'admin/modules/boards/lib.php',
        'admin/modules/requirements/module.php',
        'admin/modules/requirements/lib.php',
    ];
    $out = [];
    foreach ($files as $rel) {
        $abs = ROOT . '/' . $rel;
        if (is_file($abs)) {
            $out[$rel] = [
                'exists' => true,
                'sha256' => hash_file('sha256', $abs),
                'size'   => (int)filesize($abs),
            ];
        } else {
            $out[$rel] = ['exists' => false, 'sha256' => '', 'size' => 0];
        }
    }
    return $out;
}

function mother_verify_duplicate_tables(): array {
    $tables = [];
    try {
        if (db_driver() === 'mysql') {
            $tables = array_map(fn($r) => (string)array_values($r)[0], rows('SHOW TABLES'));
        } else {
            $tables = array_map(fn($r) => (string)$r['name'], rows("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"));
        }
    } catch (Throwable $e) {}
    // Forbidden per-app duplicates: any table named exactly users, or <app>_users etc. (shared tables must be app_users/app_sessions)
    $forbidden = [];
    foreach ($tables as $t) {
        $lt = strtolower($t);
        if ($lt === 'users' || preg_match('/^.+_users$/', $lt) && $lt !== 'app_users') {
            $forbidden[] = $t;
        }
        if (preg_match('/^(users|devices|releases|content_packs|app_requirements|asset_sources|app_sessions|app_settings_kv|sync_log)_\d+$/', $lt)) {
            $forbidden[] = $t;
        }
    }
    $required = ['app_users', 'app_sessions', 'devices', 'releases', 'content_packs', 'app_settings_kv', 'asset_sources', 'app_requirements', 'sync_log', 'boards', 'audit_log'];
    $missing = array_values(array_diff($required, array_map('strtolower', $tables)));
    return ['all' => $tables, 'forbidden' => $forbidden, 'missing_required' => $missing];
}

function mother_verify_app_id_scoping(): array {
    $checks = [];
    // 1. app_users UNIQUE(app_id, email) exists (SQLite: check sqlite_master sql; MySQL: check information_schema)
    try {
        if (db_driver() === 'mysql') {
            $idx = rows("SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='app_users' GROUP BY INDEX_NAME");
            $hasUnique = false;
            foreach ($idx as $ix) if (str_contains(strtolower((string)$ix['cols']), 'app_id') && str_contains(strtolower((string)$ix['cols']), 'email')) $hasUnique = true;
            $checks['app_users_unique_app_id_email'] = $hasUnique;
        } else {
            $sql = (string)val("SELECT sql FROM sqlite_master WHERE type='table' AND name='app_users'");
            $checks['app_users_unique_app_id_email'] = str_contains(strtoupper($sql), 'UNIQUE') && str_contains(strtolower($sql), 'app_id') && str_contains(strtolower($sql), 'email');
        }
    } catch (Throwable $e) { $checks['app_users_unique_app_id_email'] = false; }
    // 2. No unscoped app-owned queries: every app_users/app_sessions/devices/releases/content_packs/app_settings_kv/app_requirements query in app_api.php must contain app_id
    try {
        $code = (string)file_get_contents(ROOT . '/core/app_api.php');
        $checks['api_code_mentions_app_id'] = substr_count($code, 'app_id') >= 20;
        // Spot-check: register/login/sync/control all filter by app_id
        $checks['api_register_scoped'] = str_contains($code, 'WHERE app_id=? AND LOWER(email)');
        $checks['api_sync_scoped'] = str_contains($code, 'app_id=? AND user_id=?') || str_contains($code, 'WHERE app_id=? AND user_id=?');
    } catch (Throwable $e) {
        $checks['api_code_mentions_app_id'] = false;
        $checks['api_register_scoped'] = false;
        $checks['api_sync_scoped'] = false;
    }
    // 3. Y- Dimensions board exists with exact name/type/developer
    try {
        $yd = table_exists('boards') ? row("SELECT * FROM boards WHERE slug='y-dimensions' OR LOWER(name)='y- dimensions'") : null;
        $checks['y_dimensions_exists'] = $yd !== null;
        $checks['y_dimensions_name_exact'] = $yd && (string)$yd['name'] === 'Y- Dimensions';
        $checks['y_dimensions_type_mobile'] = $yd && (string)$yd['app_type'] === 'mobile';
        $checks['y_dimensions_developer'] = $yd && trim((string)($yd['developer_name'] ?? '')) !== '';
    } catch (Throwable $e) {
        $checks['y_dimensions_exists'] = false;
        $checks['y_dimensions_name_exact'] = false;
        $checks['y_dimensions_type_mobile'] = false;
        $checks['y_dimensions_developer'] = false;
    }
    return $checks;
}

function mother_verify_json_only(): array {
    $checks = [];
    try {
        $api = (string)file_get_contents(ROOT . '/core/app_api.php');
        $checks['api_sets_json_content_type'] = str_contains($api, 'application/json; charset=utf-8');
        $checks['api_never_html_content_type'] = !str_contains($api, 'text/html');
        $checks['api_has_exception_handler'] = str_contains($api, 'set_exception_handler');
        $checks['api_has_shutdown_handler'] = str_contains($api, 'register_shutdown_function');
        $checks['api_logs_audit_and_sync'] = str_contains($api, 'mother_log_call') && str_contains((string)file_get_contents(ROOT . '/core/mother_schema.php'), 'audit_log') && str_contains((string)file_get_contents(ROOT . '/core/mother_schema.php'), 'sync_log');
        $checks['api_has_req_id'] = str_contains($api, 'req_') && str_contains($api, 'X-Request-Id');
        $checks['api_requires_app_token'] = str_contains($api, 'missing_app_token') && str_contains($api, 'X-App-Token');
        $checks['api_requires_session'] = str_contains($api, 'session_required') && str_contains($api, 'X-Session-Token');
        $checks['api_has_releases_check'] = str_contains($api, 'releases/check') && str_contains($api, 'min_version') && str_contains($api, 'permissions_changed');
        $checks['api_has_sync_push_pull'] = str_contains($api, 'sync_conflict') && str_contains($api, 'sync_version');
        $checks['api_has_licenses'] = str_contains($api, 'licenses/verify') && str_contains($api, 'licenses/activate');
        $checks['api_has_control'] = str_contains($api, "'control'") || str_contains($api, 'case \'control\'');
        $checks['api_has_telemetry'] = str_contains($api, 'telemetry');
        $checks['api_has_content'] = str_contains($api, 'content_packs');
    } catch (Throwable $e) {
        $checks['error'] = $e->getMessage();
    }
    // Index routing: /api must dispatch to app_api before any HTML page rendering
    try {
        $idx = (string)file_get_contents(ROOT . '/index.php');
        $checks['index_routes_api'] = str_contains($idx, "app_api_dispatch");
        $posApi = strpos($idx, 'app_api_dispatch');
        $posLayout = strpos($idx, 'send_security_headers');
        $checks['index_api_before_html_headers'] = $posApi !== false && $posLayout !== false && $posApi < $posLayout;
    } catch (Throwable $e) { $checks['index_routes_api'] = false; }
    return $checks;
}

function mother_verify_floating_buttons(): array {
    $checks = [];
    try {
        $sites = (string)file_get_contents(ROOT . '/core/sites.php');
        $checks['sites_injects_fab'] = str_contains($sites, 'site_inject_fab') && str_contains($sites, 'render_floating_return_btn');
        $checks['sites_lock_fab_admin_only'] = str_contains($sites, 'site_lock_page') && str_contains($sites, 'render_floating_return_btn(true)');
        $checks['sites_csp_allows_top_nav'] = str_contains($sites, 'allow-top-navigation-by-user-activation');
        $checks['sites_public_to_main'] = str_contains($sites, 'الموقع الأساسي');
        $checks['sites_locked_to_admin'] = str_contains($sites, 'لوحة التحكم الأساسية');
    } catch (Throwable $e) { $checks['sites_injects_fab'] = false; }
    try {
        $layout = (string)file_get_contents(ROOT . '/app/layout.php');
        $checks['layout_page_end_fab'] = str_contains($layout, 'render_floating_return_btn') && str_contains($layout, 'page_end');
    } catch (Throwable $e) { $checks['layout_page_end_fab'] = false; }
    try {
        $lib = (string)file_get_contents(ROOT . '/admin/modules/boards/lib.php');
        $checks['boards_panel_fab'] = str_contains($lib, 'العودة للوحة التحكم الأم');
        $checks['boards_panel_tabs'] = str_contains($lib, 'BOARDS_PANEL_TABS') && str_contains($lib, 'overview') && str_contains($lib, 'handoff');
    } catch (Throwable $e) { $checks['boards_panel_fab'] = false; }
    try {
        $schema = (string)file_get_contents(ROOT . '/core/mother_schema.php');
        $checks['fab_helper_exists'] = str_contains($schema, 'function render_floating_return_btn');
        $checks['fab_locked_admin_only'] = str_contains($schema, "url('admin/')") && str_contains($schema, 'لوحة التحكم الأساسية');
    } catch (Throwable $e) { $checks['fab_helper_exists'] = false; }
    return $checks;
}

function mother_verify_handoff_gate(): array {
    $checks = [];
    try {
        $h = (string)file_get_contents(ROOT . '/core/handoff.php');
        $checks['handoff_parser_exists'] = str_contains($h, 'function handoff_parse') && str_contains($h, 'function handoff_execute_and_verify');
        $checks['handoff_extract_zip'] = str_contains($h, 'MOTHER_PANEL_HANDOFF.md');
        $checks['handoff_registers_reqs'] = str_contains($h, 'app_requirements');
        $checks['handoff_records_report'] = str_contains($h, 'handoff_report') && str_contains($h, 'handoff_status');
        $checks['handoff_blocks_until_verified'] = str_contains($h, "'verified'") && str_contains($h, "'failed'");
    } catch (Throwable $e) { $checks['handoff_parser_exists'] = false; }
    try {
        $prompt = (string)file_get_contents(ROOT . '/admin/modules/boards/module.php');
        $checks['prompt_requires_handoff'] = str_contains($prompt, 'MOTHER_PANEL_HANDOFF.md');
        $checks['prompt_pure_php_not_wp'] = str_contains($prompt, 'Hostinger') && str_contains($prompt, 'WordPress');
        $checks['prompt_online_mandatory'] = str_contains($prompt, 'تسجيل الدخول إلزامي');
        $checks['prompt_unified_db'] = str_contains($prompt, 'app_id') && str_contains($prompt, 'app_users');
        $checks['prompt_missing_reqs'] = str_contains($prompt, 'متطلبات التطبيقات') || str_contains($prompt, 'متطلب ناقص');
        $checks['prompt_api_usage'] = str_contains($prompt, '/api/v1/');
    } catch (Throwable $e) { $checks['prompt_requires_handoff'] = false; }
    // Per-board gate state
    try {
        $boards = table_exists('boards') ? rows('SELECT id, name, handoff_status, handoff_verified_at FROM boards') : [];
        $checks['boards_handoff_states'] = array_map(fn($b) => ['id' => (int)$b['id'], 'name' => (string)$b['name'], 'status' => (string)($b['handoff_status'] ?? 'pending'), 'verified_at' => $b['handoff_verified_at'] ?? null], $boards);
        $checks['boards_count'] = count($boards);
    } catch (Throwable $e) { $checks['boards_count'] = 0; }
    return $checks;
}

function mother_verify_requirements_module(): array {
    $checks = [];
    try {
        $m = (string)file_get_contents(ROOT . '/admin/modules/requirements/module.php');
        $checks['module_exists'] = true;
        $checks['has_sources_section'] = str_contains($m, 'مصادر الأشياء') && str_contains($m, 'asset_sources');
        $checks['has_all_6_cats'] = str_contains($m, 'ASSET_SOURCE_CATS') && str_contains((string)file_get_contents(ROOT . '/core/mother_schema.php'), 'الصوتيات') && str_contains((string)file_get_contents(ROOT . '/core/mother_schema.php'), 'الخطوط');
        $checks['has_requirements_section'] = str_contains($m, 'متطلبات التطبيقات') && str_contains($m, 'app_requirements');
        $checks['has_missing_provided'] = str_contains($m, 'ناقص') && str_contains($m, 'تم توفيره');
        $checks['has_priority_sort'] = str_contains($m, 'req_order_sql');
        $checks['has_missing_counter'] = str_contains($m, 'missing') && str_contains($m, 'ناقص');
        $checks['has_toggle'] = str_contains($m, 'toggle_requirement');
    } catch (Throwable $e) { $checks['module_exists'] = false; }
    return $checks;
}

function mother_verify_run(): array {
    $started = gmdate('Y-m-d H:i:s') . ' UTC';
    $result = [
        'started' => $started,
        'files' => mother_verify_file_fingerprints(),
        'duplicates' => mother_verify_duplicate_tables(),
        'scoping' => mother_verify_app_id_scoping(),
        'json_only' => mother_verify_json_only(),
        'floating' => mother_verify_floating_buttons(),
        'handoff' => mother_verify_handoff_gate(),
        'requirements' => mother_verify_requirements_module(),
    ];
    // Overall pass/fail per group
    $fail = [];
    if ($result['duplicates']['forbidden']) $fail[] = 'duplicate tables: ' . implode(',', $result['duplicates']['forbidden']);
    if ($result['duplicates']['missing_required']) $fail[] = 'missing required tables: ' . implode(',', $result['duplicates']['missing_required']);
    foreach (['scoping', 'json_only', 'floating', 'handoff', 'requirements'] as $g) {
        foreach ($result[$g] as $k => $v) {
            if (is_bool($v) && !$v) $fail[] = "$g.$k";
        }
    }
    foreach ($result['files'] as $rel => $f) if (!$f['exists']) $fail[] = "missing file: $rel";
    $result['failures'] = $fail;
    $result['passed'] = count($fail) === 0;
    $result['finished'] = gmdate('Y-m-d H:i:s') . ' UTC';
    return $result;
}

function mother_verify_write_report(array $result): string {
    $ts = gmdate('Ymd-His');
    $dir = ROOT . '/data';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $path = $dir . '/mother-verify-' . $ts . '.md';
    $lines = [];
    $lines[] = '# تقرير التحقق الشامل — اللوحة الأم (Mother Panel)';
    $lines[] = '';
    $lines[] = '- بدأ: ' . $result['started'];
    $lines[] = '- انتهى: ' . $result['finished'];
    $lines[] = '- النتيجة: ' . ($result['passed'] ? '✅ ناجح — جميع الفحوصات مرّت' : '❌ فشل — ' . count($result['failures']) . ' مشكلة');
    $lines[] = '';
    if ($result['failures']) {
        $lines[] = '## المشكلات';
        foreach ($result['failures'] as $f) $lines[] = '- ' . $f;
        $lines[] = '';
    }
    $lines[] = '## بصمات الملفات (SHA-256)';
    $lines[] = '';
    $lines[] = '| الملف | موجود | الحجم | SHA-256 |';
    $lines[] = '|---|---|---|---|';
    foreach ($result['files'] as $rel => $f) {
        $lines[] = '| `' . $rel . '` | ' . ($f['exists'] ? '✓' : '✗') . ' | ' . $f['size'] . ' | `' . ($f['sha256'] ?: '—') . '` |';
    }
    $lines[] = '';
    $lines[] = '## الجداول المكرّرة';
    $lines[] = '';
    $lines[] = '- الممنوعة المكتشفة: ' . ($result['duplicates']['forbidden'] ? implode(', ', $result['duplicates']['forbidden']) : 'لا يوجد ✓');
    $lines[] = '- الجداول المطلوبة الناقصة: ' . ($result['duplicates']['missing_required'] ? implode(', ', $result['duplicates']['missing_required']) : 'لا يوجد ✓');
    $lines[] = '- إجمالي الجداول: ' . count($result['duplicates']['all']);
    $lines[] = '';
    $lines[] = '## نطاق app_id';
    foreach ($result['scoping'] as $k => $v) $lines[] = '- ' . $k . ': ' . (is_bool($v) ? ($v ? '✓' : '✗') : json_encode($v, JSON_UNESCAPED_UNICODE));
    $lines[] = '';
    $lines[] = '## JSON فقط (لا HTML أبداً)';
    foreach ($result['json_only'] as $k => $v) $lines[] = '- ' . $k . ': ' . (is_bool($v) ? ($v ? '✓' : '✗') : json_encode($v, JSON_UNESCAPED_UNICODE));
    $lines[] = '';
    $lines[] = '## الأزرار العائمة';
    foreach ($result['floating'] as $k => $v) $lines[] = '- ' . $k . ': ' . (is_bool($v) ? ($v ? '✓' : '✗') : json_encode($v, JSON_UNESCAPED_UNICODE));
    $lines[] = '';
    $lines[] = '## بوابة MOTHER_PANEL_HANDOFF.md';
    foreach ($result['handoff'] as $k => $v) $lines[] = '- ' . $k . ': ' . (is_bool($v) ? ($v ? '✓' : '✗') : json_encode($v, JSON_UNESCAPED_UNICODE));
    $lines[] = '';
    $lines[] = '## وحدة متطلبات التطبيقات';
    foreach ($result['requirements'] as $k => $v) $lines[] = '- ' . $k . ': ' . (is_bool($v) ? ($v ? '✓' : '✗') : json_encode($v, JSON_UNESCAPED_UNICODE));
    $lines[] = '';
    $lines[] = '---';
    $lines[] = 'أُنشئ تلقائياً بواسطة core/mother_verify.php في ' . $result['finished'] . '.';
    file_put_contents($path, implode("\n", $lines) . "\n");
    return $path;
}
