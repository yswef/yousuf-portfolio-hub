<?php
declare(strict_types=1);
/**
 * crud_module($cfg): builds a complete list/edit/delete module from a field map.
 *
 * Field types (key 't'): text, url, num, date, textarea, md, select(opts), check, slug(from),
 *   i18n, i18n_area, image, ts_created, ts_updated, hidden(value)
 * Optional keys: label, req, max, help, ph(placeholder), dir
 * Config: table, one (singular label), fields, list[columns|order|where|filter], before_save(&$data,$old), after_delete($row),
 *   list_top(callable), edit_extra(callable $row), extra_actions[name=>callable] merged into the module.
 * Everything not CRUD-shaped can still be added through 'actions' / 'view' in the same manifest.
 */
function crud_module(array $c): array {
    $c['id'] = basename(dirname(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file']));   // module id = its folder name
    $c['list'] += ['columns' => [], 'order' => 'id DESC', 'where' => '', 'filter' => null];
    $c += ['one' => 'عنصر', 'new_label' => null];
    $m = $c;
    $m['view'] = fn(array $mod) => crud_view($mod, $c);
    $m['actions'] = ($c['extra_actions'] ?? []) + [
        'save' => fn() => crud_save($c),
        'delete' => fn() => crud_delete($c),
    ];
    return $m;
}

/** Multi-type modules ('types' => [type => [label, one, fields, columns]]): merge the active type into the config. */
function crud_resolve(array $c): array {
    if (empty($c['types'])) return $c;
    $t = (string)($_POST['ctype'] ?? $_GET['f'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['edit']) && ($r = row("SELECT type FROM {$c['table']} WHERE id=?", [(int)$_GET['edit']]))) $t = (string)$r['type'];   // edit link wins over ?f=
    if (!isset($c['types'][$t])) $t = (string)array_key_first($c['types']);
    $ty = $c['types'][$t];
    $c['ctype'] = $t;
    $c['one'] = $ty['one'] ?? $c['one'];
    $c['fields'] = ['type' => ['t' => 'hidden', 'value' => $t]] + $ty['fields'] + ($c['fields'] ?? []);
    $c['list']['columns'] = $ty['columns'] ?? $c['list']['columns'];
    $c['list']['where'] = "type='" . str_replace("'", '', $t) . "'";
    return $c;
}
function crud_q(array $c, array $q = []): array { return (isset($c['ctype']) ? ['f' => $c['ctype']] : []) + $q; }

function crud_collect(array $c, ?array $old): array {
    $d = []; $now = time();
    foreach ($c['fields'] as $f => $d0) {
        $t = $d0['t'] ?? 'text'; $max = (int)($d0['max'] ?? 255);
        switch ($t) {
            case 'text': case 'url': case 'date': $d[$f] = mb_substr(P($f), 0, $max); break;
            case 'textarea': case 'md': $d[$f] = mb_substr(P($f), 0, (int)($d0['max'] ?? 60000)); break;
            case 'num': $d[$f] = PN($f); break;
            case 'check': $d[$f] = PB($f); break;
            case 'select': $v = P($f); $d[$f] = array_key_exists($v, $d0['opts']) ? $v : (string)array_key_first($d0['opts']); break;
            case 'i18n': case 'i18n_area': $d[$f] = i18n_post($f, (int)($d0['max'] ?? 20000)); break;
            case 'hidden': $d[$f] = $d0['value']; break;
            case 'ts_updated': $d[$f] = $now; break;
            case 'ts_created': if (!$old) $d[$f] = $now; break;
            case 'slug': break;                                // computed after the loop
            case 'image':
                $cur = $old[$f] ?? null; $d[$f] = $cur;
                if (!empty($_FILES[$f]['name'])) {
                    $n = handle_image_upload($_FILES[$f]);
                    if ($n) { delete_upload($cur); $d[$f] = $n; } else flash('تعذّر رفع الصورة (JPG/PNG/WebP حتى 5MB).', false);
                } elseif (PB($f . '_remove')) { delete_upload($cur); $d[$f] = null; }
                break;
        }
    }
    foreach ($c['fields'] as $f => $d0) if (($d0['t'] ?? '') === 'slug') {
        $src = $d[$d0['from'] ?? 'name'] ?? '';
        if (is_string($src) && str_starts_with($src, '{')) $src = L($src);          // i18n source → pick a language
        $d[$f] = unique_slug($c['table'], slugify(P($f) !== '' ? P($f) : (string)$src), (int)($old['id'] ?? 0));
    }
    return $d;
}

function crud_save(array $c): void {
    $c = crud_resolve($c);
    $id = PN('id');
    $old = $id ? row("SELECT * FROM {$c['table']} WHERE id=?", [$id]) : null;
    if ($id && !$old) { flash('العنصر غير موجود.', false); return; }
    foreach ($c['fields'] as $f => $d0) if (!empty($d0['req']) && in_array($d0['t'] ?? 'text', ['text', 'url', 'textarea', 'md'], true) && P($f) === '') {
        flash('الحقل مطلوب: ' . ($d0['label'] ?? $f), false); adm_back($c['id'], crud_q($c, ['edit' => $id]));
    }
    $d = crud_collect($c, $old);
    if (isset($c['before_save'])) { $r = ($c['before_save'])($d, $old); if (is_string($r)) { flash($r, false); adm_back($c['id'], crud_q($c, ['edit' => $id])); } }
    if ($old) upd($c['table'], $d, 'id=?', [$id]); else $id = ins($c['table'], $d);
    audit($c['id'] . '_save', (string)$id);
    flash('تم الحفظ.');
    adm_back($c['id'], crud_q($c, !empty($_POST['stay']) ? ['edit' => $id] : []));
}
function crud_delete(array $c): void {
    $c = crud_resolve($c);
    $id = PN('id'); $old = row("SELECT * FROM {$c['table']} WHERE id=?", [$id]);
    if (!$old) return;
    foreach ($c['fields'] as $f => $d0) if (($d0['t'] ?? '') === 'image') delete_upload($old[$f] ?? null);
    if (isset($c['after_delete'])) ($c['after_delete'])($old);
    q("DELETE FROM {$c['table']} WHERE id=?", [$id]);
    audit($c['id'] . '_delete', (string)$id); flash('تم الحذف.');
    adm_back($c['id'], crud_q($c));
}

function crud_view(array $mod, array $c): void {
    $c = crud_resolve($c);
    $id = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
    adm_h(adm_title($mod));
    if ($id !== null) { crud_form($c, $id); return; }

    if (!empty($c['types'])) {
        echo '<div class="row" style="margin-bottom:12px">';
        foreach ($c['types'] as $k => $ty) echo '<a class="btn sm ' . ($k === $c['ctype'] ? '' : 'ghost') . '" href="' . adm_url($c['id'], ['f' => $k]) . '">' . e($ty['label']) . '</a>';
        echo '</div>';
    }
    if (isset($c['list_top'])) ($c['list_top'])();
    $where = $c['list']['where'] ?: '1=1'; $p = [];
    $flt = empty($c['types']) ? ($c['list']['filter'] ?? null) : null;
    if ($flt) {
        $cur = (string)($_GET['f'] ?? '');
        echo '<div class="row" style="margin-bottom:12px"><a class="btn sm ' . ($cur === '' ? '' : 'ghost') . '" href="' . adm_url($c['id']) . '">الكل</a>';
        foreach ($c['fields'][$flt]['opts'] as $v => $n) echo '<a class="btn sm ' . ((string)$v === $cur ? '' : 'ghost') . '" href="' . adm_url($c['id'], ['f' => $v]) . '">' . e($n) . '</a>';
        echo '</div>';
        if ($cur !== '' && isset($c['fields'][$flt]['opts'][$cur])) { $where .= " AND $flt=?"; $p[] = $cur; }
    }
    $data = rows("SELECT * FROM {$c['table']} WHERE $where ORDER BY {$c['list']['order']} LIMIT 500", $p);
    echo '<div class="row" style="margin-bottom:14px"><a class="btn" href="' . adm_url($c['id'], crud_q($c, ['edit' => 0])) . '">+ ' . e($c['new_label'] ?? ('إضافة ' . $c['one'])) . '</a></div>';
    if (!$data) { echo '<div class="panel"><p class="muted">لا توجد عناصر بعد.</p></div>'; return; }
    echo '<div class="panel tw"><table><thead><tr>';
    foreach ($c['list']['columns'] as $col) echo '<th>' . e($col['label']) . '</th>';
    echo '<th></th></tr></thead><tbody>';
    foreach ($data as $r) {
        echo '<tr>';
        foreach ($c['list']['columns'] as $col) {
            $v = isset($col['fmt']) ? ($col['fmt'])($r) : e((string)($r[$col['f']] ?? ''));
            echo '<td' . (!empty($col['dir']) ? ' dir="' . $col['dir'] . '"' : '') . '>' . $v . '</td>';
        }
        echo '<td class="acts"><a class="btn ghost sm" href="' . adm_url($c['id'], crud_q($c, ['edit' => $r['id']])) . '">تعديل</a>';
        btn_post($c['id'], 'delete', ['id' => $r['id']] + (isset($c['ctype']) ? ['ctype' => $c['ctype']] : []), 'حذف', 'btn danger sm', 'حذف هذا العنصر نهائياً؟');
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}

function crud_form(array $c, int $id): void {
    $r = $id ? row("SELECT * FROM {$c['table']} WHERE id=?", [$id]) : null;
    if ($id && !$r) { echo '<div class="note bad">العنصر غير موجود.</div>'; return; }
    $multipart = (bool)array_filter($c['fields'], fn($f) => ($f['t'] ?? '') === 'image');
    echo '<div class="panel"><h2>' . e($id ? 'تعديل ' . $c['one'] : 'إضافة ' . $c['one']) . '</h2>';
    f_open($c['id'], 'save', ['id' => $id] + (isset($c['ctype']) ? ['ctype' => $c['ctype']] : []), $multipart, 'form');
    foreach ($c['fields'] as $f => $d) {
        $t = $d['t'] ?? 'text'; $lab = ($d['label'] ?? $f) . (!empty($d['req']) ? ' *' : ''); $v = $r[$f] ?? ($d['default'] ?? '');
        $attr = (!empty($d['dir']) ? 'dir="' . e($d['dir']) . '" ' : '') . (!empty($d['ph']) ? 'placeholder="' . e($d['ph']) . '" ' : '') . 'maxlength="' . (int)($d['max'] ?? 255) . '"';
        switch ($t) {
            case 'text': f_in($f, $lab, $v, 'text', $attr); break;
            case 'url': f_in($f, $lab, $v, 'text', 'dir="ltr" placeholder="https://" ' . 'maxlength="255"'); break;
            case 'date': f_in($f, $lab, $v, 'text', 'dir="ltr" placeholder="YYYY-MM or YYYY-MM-DD" maxlength="20"'); break;
            case 'num': f_in($f, $lab, $v === '' ? 0 : $v, 'number', 'dir="ltr"'); break;
            case 'textarea': f_ta($f, $lab, $v, (int)($d['rows'] ?? 4)); break;
            case 'md': f_ta($f, $lab . ' (Markdown)', $v, (int)($d['rows'] ?? 12), 'dir="auto" class="mono"'); break;
            case 'check': f_chk($f, $lab, $id ? !empty($v) : !empty($d['default'])); break;
            case 'select': f_sel($f, $lab, $d['opts'], $v ?: array_key_first($d['opts'])); break;
            case 'i18n': f_i18n($f, $lab, i18n_decode((string)$v)); break;
            case 'i18n_area': f_i18n($f, $lab, i18n_decode((string)$v), true, (int)($d['rows'] ?? 4)); break;
            case 'slug': f_in($f, $lab . ' (اتركه فارغاً للتوليد التلقائي)', $v, 'text', 'dir="ltr" maxlength="80"'); break;
            case 'image': f_image($f, $lab, $v ?: null); break;
        }
        if (!empty($d['help'])) echo '<p class="muted hint">' . e($d['help']) . '</p>';
    }
    echo '<div class="row"><button class="btn" type="submit">حفظ</button><button class="btn ghost" type="submit" name="stay" value="1">حفظ والبقاء</button><a class="btn ghost" href="' . adm_url($c['id'], crud_q($c)) . '">رجوع</a></div></form></div>';
    if ($r && isset($c['edit_extra'])) ($c['edit_extra'])($r);
}
