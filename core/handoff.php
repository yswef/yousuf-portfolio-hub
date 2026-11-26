<?php
declare(strict_types=1);
/**
 * MOTHER_PANEL_HANDOFF.md parser, executor, and installation gatekeeper.
 * No app installation can be marked complete until its MOTHER_PANEL_HANDOFF.md
 * has been read, its requirements/settings registered, and verification logged.
 */

/** Extract MOTHER_PANEL_HANDOFF.md (and optional map.json / model_data.json) from a panel zip archive. */
function handoff_extract_from_zip(string $zipPath): array {
    $out = ['handoff_md' => '', 'panel_map' => '', 'model_data' => ''];
    if (!is_file($zipPath) || !class_exists('ZipArchive')) return $out;
    $z = new ZipArchive();
    if ($z->open($zipPath) !== true) return $out;
    for ($i = 0; $i < $z->numFiles; $i++) {
        $st = $z->statIndex($i);
        if (!$st || (int)$st['size'] > 2 * 1048576) continue;
        $base = strtolower(basename(str_replace('\\', '/', (string)$st['name'])));
        if ($base === 'mother_panel_handoff.md') {
            $out['handoff_md'] = trim((string)$z->getFromIndex($i));
        } elseif ($base === 'map.json' && $out['panel_map'] === '') {
            $out['panel_map'] = trim((string)$z->getFromIndex($i));
        } elseif (($base === 'model_data.json' || $base === 'app_data.json') && $out['model_data'] === '') {
            $out['model_data'] = trim((string)$z->getFromIndex($i));
        }
    }
    $z->close();
    return $out;
}

/** Parse structured JSON blocks + Markdown requirement bullets/tables out of MOTHER_PANEL_HANDOFF.md. */
function handoff_parse(string $md): array {
    $md = trim(str_replace(["\r\n", "\r"], "\n", $md));
    $res = [
        'valid_sections' => [],
        'json_blocks' => [],
        'requirements' => [],
        'settings' => [],
        'tabs' => [],
        'min_version' => '',
        'warnings' => [],
    ];
    if ($md === '') {
        $res['warnings'][] = 'ملف MOTHER_PANEL_HANDOFF.md فارغ أو غير مرفق.';
        return $res;
    }

    // Check required conceptual sections
    $checks = [
        'api'          => '/\bapi\b|نقاط\s*(الـ\s*)?api|endpoints?/iu',
        'tables_scope' => '/\bapp_id\b|الجداول|قاعدة\s*البيانات/iu',
        'permissions'  => '/صلاحيات|permissions?/iu',
        'requirements' => '/متطلبات|نواقص|requirements?/iu',
        'verify'       => '/تحقق|خطوات\s*التحقق|verif/iu',
    ];
    foreach ($checks as $k => $rx) {
        if (preg_match($rx, $md)) $res['valid_sections'][] = $k;
    }

    // Guard against invented hardcoded credentials/tokens inside handoff
    if (preg_match('/(ghp_[A-Za-z0-9]{20,}|AIza[0-9A-Za-z_-]{30,}|sk-[A-Za-z0-9]{20,})/', $md)) {
        $res['warnings'][] = 'تحذير أمني: يحتوي ملف التسليم على مفتاح سري خام — يجب طلب القيم الحساسة من المطوّر وعدم تضمينها نصياً.';
    }

    // Extract any ```json ... ``` blocks
    if (preg_match_all('/```json\s*([\s\S]*?)```/iu', $md, $matches)) {
        foreach ($matches[1] as $rawJson) {
            $j = json_decode(trim($rawJson), true);
            if (!is_array($j)) continue;
            $res['json_blocks'][] = $j;
            if (!empty($j['min_version']) && is_string($j['min_version'])) {
                $res['min_version'] = trim($j['min_version']);
            }
            if (!empty($j['settings']) && is_array($j['settings'])) {
                foreach ($j['settings'] as $sk => $sv) {
                    if (is_string($sk)) $res['settings'][$sk] = is_scalar($sv) ? (string)$sv : json_encode($sv, JSON_UNESCAPED_UNICODE);
                }
            }
            if (!empty($j['panel_tabs']) && is_array($j['panel_tabs'])) {
                $res['tabs'] = $j['panel_tabs'];
            }
            if (!empty($j['requirements']) && is_array($j['requirements'])) {
                foreach ($j['requirements'] as $rq) {
                    if (!is_array($rq) || empty($rq['title'])) continue;
                    $res['requirements'][] = [
                        'category'    => isset(REQ_CATS[$rq['category'] ?? '']) ? (string)$rq['category'] : 'other',
                        'title'       => mb_substr(trim((string)$rq['title']), 0, 255),
                        'details'     => trim((string)($rq['details'] ?? '')),
                        'source_name' => mb_substr(trim((string)($rq['source'] ?? $rq['source_name'] ?? '')), 0, 255),
                        'source_url'  => mb_substr(trim((string)($rq['source_url'] ?? '')), 0, 500),
                        'priority'    => isset(REQ_PRIORITIES[$rq['priority'] ?? '']) ? (string)$rq['priority'] : 'high',
                        'status'      => ($rq['status'] ?? 'missing') === 'provided' ? 'provided' : 'missing',
                        'notes'       => trim((string)($rq['notes'] ?? '')),
                    ];
                }
            }
        }
    }

    // Fallback: also parse bullet lines like "- [critical|audio] Title — Source: ... | Note: ..." if no JSON requirements were given
    if (!$res['requirements'] && preg_match_all('/^[-*]\s*\[([a-z_]+)(?:\s*\|\s*([a-z_]+))?\]\s*(.+)$/imu', $md, $bm, PREG_SET_ORDER)) {
        foreach ($bm as $m) {
            $p1 = strtolower(trim($m[1]));
            $p2 = strtolower(trim($m[2] ?? ''));
            $prio = isset(REQ_PRIORITIES[$p1]) ? $p1 : (isset(REQ_PRIORITIES[$p2]) ? $p2 : 'high');
            $cat = isset(REQ_CATS[$p1]) ? $p1 : (isset(REQ_CATS[$p2]) ? $p2 : 'other');
            $parts = array_map('trim', explode('|', trim($m[3])));
            $res['requirements'][] = [
                'category'    => $cat,
                'title'       => mb_substr($parts[0], 0, 255),
                'details'     => '',
                'source_name' => mb_substr($parts[1] ?? '', 0, 255),
                'source_url'  => '',
                'priority'    => $prio,
                'status'      => 'missing',
                'notes'       => $parts[2] ?? '',
            ];
        }
    }

    return $res;
}

/** Read, execute, register requirements/settings, verify compliance, and record result for a Board. */
function handoff_execute_and_verify(int $boardId): array {
    $b = row('SELECT * FROM boards WHERE id=?', [$boardId]);
    if (!$b) throw new RuntimeException('اللوحة غير موجودة.');
    $reqId = 'handoff_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(3));
    $md = trim((string)($b['handoff_md'] ?? ''));

    // Auto-extract from panel.zip if handoff_md not yet loaded in DB
    if ($md === '' && function_exists('boards_panel_path')) {
        $zp = boards_panel_path((string)$b['slug']);
        if (is_file($zp)) {
            $ex = handoff_extract_from_zip($zp);
            if ($ex['handoff_md'] !== '') {
                $md = $ex['handoff_md'];
                upd('boards', ['handoff_md' => $md], 'id=?', [$boardId]);
            }
        }
    }

    $parsed = handoff_parse($md);
    $log = [];
    $ok = true;

    if ($md === '') {
        $ok = false;
        $log[] = '✗ لم يتم العثور على ملف MOTHER_PANEL_HANDOFF.md (أرفقه داخل zip اللوحة أو الصقه في تبويب التسليم).';
    } else {
        $log[] = '✓ قُرئ ملف MOTHER_PANEL_HANDOFF.md (' . strlen($md) . ' بايت).';
        $missingSecs = array_diff(['api', 'tables_scope', 'permissions', 'requirements', 'verify'], $parsed['valid_sections']);
        if ($missingSecs) {
            $ok = false;
            $log[] = '✗ أقسام ناقصة في ملف التسليم: ' . implode(', ', $missingSecs);
        } else {
            $log[] = '✓ جميع الأقسام الإلزامية الخمسة موجودة (API، الجداول ونطاق app_id، الصلاحيات، المتطلبات، خطوات التحقق).';
        }
    }

    foreach ($parsed['warnings'] as $w) {
        $ok = false;
        $log[] = '✗ ' . $w;
    }

    // Execute: register requirements in app_requirements (idempotent by app_id + title)
    $addedReq = 0; $updatedReq = 0;
    $now = time();
    foreach ($parsed['requirements'] as $rq) {
        $existing = row('SELECT id, status FROM app_requirements WHERE app_id=? AND LOWER(title)=LOWER(?)', [$boardId, $rq['title']]);
        if (!$existing) {
            // Try matching source_id by name if source_name given
            $srcId = null;
            if ($rq['source_name'] !== '' && table_exists('asset_sources')) {
                $sRow = row('SELECT id, url FROM asset_sources WHERE LOWER(name)=LOWER(?)', [$rq['source_name']]);
                if ($sRow) {
                    $srcId = (int)$sRow['id'];
                    if ($rq['source_url'] === '') $rq['source_url'] = (string)$sRow['url'];
                }
            }
            ins('app_requirements', [
                'app_id'      => $boardId,
                'category'    => $rq['category'],
                'title'       => $rq['title'],
                'details'     => $rq['details'],
                'source_id'   => $srcId,
                'source_name' => $rq['source_name'],
                'source_url'  => $rq['source_url'],
                'priority'    => $rq['priority'],
                'status'      => $rq['status'],
                'notes'       => $rq['notes'],
                'from_handoff'=> 1,
                'provided_at' => $rq['status'] === 'provided' ? $now : null,
                'created'     => $now,
                'updated'     => $now,
            ]);
            $addedReq++;
        } else {
            upd('app_requirements', [
                'priority'    => $rq['priority'],
                'category'    => $rq['category'],
                'source_name' => $rq['source_name'],
                'notes'       => $rq['notes'],
                'updated'     => $now,
            ], 'id=?', [$existing['id']]);
            $updatedReq++;
        }
    }
    if ($addedReq || $updatedReq) {
        $log[] = "✓ سُجّلت متطلبات التطبيق في تبويب «متطلبات التطبيقات» (جديد: $addedReq · محدّث: $updatedReq).";
    }

    // Execute: apply default remote settings into app_settings_kv (without overwriting existing live edits)
    $addedCfg = 0;
    foreach ($parsed['settings'] as $sk => $sv) {
        if (!row('SELECT id FROM app_settings_kv WHERE app_id=? AND cfg_key=?', [$boardId, $sk])) {
            ins('app_settings_kv', ['app_id' => $boardId, 'cfg_key' => mb_substr($sk, 0, 100), 'cfg_val' => (string)$sv, 'updated' => $now]);
            $addedCfg++;
        }
    }
    if ($addedCfg) $log[] = "✓ أُضيفت $addedCfg إعدادات سحابية افتراضية للتطبيق.";

    $bUpd = [
        'handoff_status'      => $ok ? 'verified' : 'failed',
        'handoff_verified_at' => $ok ? $now : null,
        'updated'             => $now,
    ];
    if ($parsed['min_version'] !== '') {
        $curMin = trim((string)($b['min_app_version'] ?? ''));
        // Never silently downgrade the minimum version: only raise it (or fill it when empty).
        if ($curMin === '' || version_compare(ltrim($parsed['min_version'], 'vV'), ltrim($curMin, 'vV'), '>')) {
            $bUpd['min_app_version'] = mb_substr($parsed['min_version'], 0, 60);
        }
    }
    if ($parsed['tabs']) {
        $bUpd['panel_tabs_json'] = json_encode($parsed['tabs'], JSON_UNESCAPED_UNICODE);
    }
    if (empty($b['api_token_hash'])) {
        board_issue_api_token($boardId);
        $log[] = '✓ جُهّز مفتاح ربط API مشفّر للوحة الأم (أُنشئت بصمة التوكن).';
    }

    $report = '[' . gmdate('Y-m-d H:i:s') . " UTC · $reqId]\n" . implode("\n", $log);
    $bUpd['handoff_report'] = $report;
    if ($ok && ($b['status'] ?? 'draft') === 'draft') {
        $bUpd['status'] = 'ready';
    }
    upd('boards', $bUpd, 'id=?', [$boardId]);
    mother_log_call($reqId, $boardId, 'handoff/verify', $ok, $ok ? "verified (+$addedReq reqs)" : 'failed');

    return ['ok' => $ok, 'req_id' => $reqId, 'report' => $report, 'added_reqs' => $addedReq];
}
