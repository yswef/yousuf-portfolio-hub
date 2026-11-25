<?php
declare(strict_types=1);
/**
 * App keys generator (مولّد مفاتيح التطبيقات): bulk license / API / device keys,
 * optionally bound to a board (app). Keys are unambiguous (no 0/O/1/I).
 */
const KEY_TYPES = ['license' => 'ترخيص', 'api_key' => 'مفتاح API', 'device' => 'مفتاح جهاز'];
const KEY_STATUS = ['active' => 'نشط', 'revoked' => 'ملغى', 'expired' => 'منتهي'];

function key_make(string $prefix, int $len): string {
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    $bytes = random_bytes($len);
    for ($i = 0; $i < $len; $i++) $out .= $alpha[ord($bytes[$i]) % strlen($alpha)];
    $groups = str_split($out, 4);
    return ($prefix !== '' ? strtoupper(preg_replace('/[^A-Z0-9]/', '', $prefix)) . '-' : '') . implode('-', $groups);
}
function key_boards_opts(): array {
    $o = [0 => '— بدون ربط بتطبيق —'];
    try { if (table_exists('boards')) foreach (rows('SELECT id, name FROM boards ORDER BY name') as $b) $o[(int)$b['id']] = (string)$b['name']; } catch (Throwable $e) {}
    return $o;
}

return [
    'title' => ['ar' => 'مولّد مفاتيح التطبيقات', 'en' => 'App keys generator'], 'icon' => '🔑', 'group' => 'app', 'order' => 18, 'version' => 1,
    'schema' => fn(array $T) => [
        "CREATE TABLE IF NOT EXISTS app_keys (id {$T['id']}, board_id INT NULL, key_code VARCHAR(100) UNIQUE, key_type VARCHAR(20) DEFAULT 'license',
          plan VARCHAR(100) NULL, email VARCHAR(190) NULL, status VARCHAR(20) DEFAULT 'active', uses INT DEFAULT 0, max_uses INT DEFAULT 1,
          last_used INT NULL, created INT, expires INT NULL)" . $T['tail'],
        "CREATE INDEX idx_ak_board ON app_keys (board_id)", "CREATE INDEX idx_ak_status ON app_keys (status)",
    ],
    'widgets' => fn() => [
        ['label' => 'مفاتيح نشطة', 'value' => (int)val("SELECT COUNT(*) FROM app_keys WHERE status='active'"), 'link' => 'appkeys'],
        ['label' => 'إجمالي المفاتيح', 'value' => (int)val('SELECT COUNT(*) FROM app_keys'), 'link' => 'appkeys'],
    ],
    'actions' => [
        'generate' => function (): void {
            $count = max(1, min(200, PN('count')));
            $len = max(8, min(32, PN('key_len') ?: 16));
            $prefix = mb_substr(P('prefix'), 0, 10);
            $type = array_key_exists(P('key_type'), KEY_TYPES) ? P('key_type') : 'license';
            $boardId = PN('board_id') ?: null;
            $expDays = PN('expiry_days');
            $expires = $expDays > 0 ? time() + $expDays * 86400 : null;
            $made = [];
            for ($i = 0; $i < $count; $i++) {
                do { $code = key_make($prefix, $len); } while (row('SELECT id FROM app_keys WHERE key_code=?', [$code]));
                ins('app_keys', ['board_id' => $boardId, 'key_code' => $code, 'key_type' => $type, 'plan' => mb_substr(P('plan'), 0, 100),
                    'email' => mb_substr(P('email'), 0, 190), 'status' => 'active', 'max_uses' => max(1, PN('max_uses')), 'created' => time(), 'expires' => $expires]);
                $made[] = $code;
            }
            if ($boardId && $type === 'license') upd('boards', ['licenses_count' => (int)val('SELECT COUNT(*) FROM app_keys WHERE board_id=? AND key_type=\'license\'', [$boardId]), 'updated' => time()], 'id=?', [$boardId]);
            audit('keys_generate', $count . ' x ' . $type);
            $_SESSION['last_keys'] = $made;
            flash('وُلّد ' . $count . ' مفتاحاً.');
            adm_back('appkeys');
        },
        'key_toggle' => function (): void {
            $k = row('SELECT * FROM app_keys WHERE id=?', [PN('id')]);
            if ($k) upd('app_keys', ['status' => $k['status'] === 'active' ? 'revoked' : 'active'], 'id=?', [$k['id']]);
            flash('تم تحديث حالة المفتاح.'); adm_back('appkeys');
        },
        'key_delete' => function (): void {
            q('DELETE FROM app_keys WHERE id=?', [PN('id')]);
            audit('key_delete', (string)PN('id')); flash('حُذف المفتاح.'); adm_back('appkeys');
        },
    ],
    'download' => function (string $what): void {
        if ($what !== 'csv') { http_response_code(404); echo 'Not found'; return; }
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="app-keys-' . gmdate('Ymd') . '.csv"');
        echo "\xEF\xBB\xBFالمفتاح,النوع,التطبيق,الخطة,البريد,الحالة,الاستخدامات,الإنشاء,الانتهاء\n";
        foreach (rows('SELECT k.*, COALESCE(b.name,"") AS board FROM app_keys k LEFT JOIN boards b ON b.id=k.board_id ORDER BY k.id DESC') as $k) {
            $row = [$k['key_code'], KEY_TYPES[$k['key_type']] ?? $k['key_type'], $k['board'], (string)$k['plan'], (string)$k['email'], KEY_STATUS[$k['status']] ?? $k['status'], (int)$k['uses'], gmdate('Y-m-d', (int)$k['created']), $k['expires'] ? gmdate('Y-m-d', (int)$k['expires']) : ''];
            echo implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $row)) . "\n";
        }
    },
    'view' => function (array $mod): void {
        adm_h('مولّد مفاتيح التطبيقات', 'ولّد مفاتيح ترخيص / API / أجهزة دفعة واحدة، واربطها بتطبيق من لوحات الإدارة إن أردت.');
        $boards = key_boards_opts();
        echo '<div class="panel"><h2>توليد مفاتيح جديدة</h2>';
        f_open('appkeys', 'generate', [], false, 'form');
        echo '<div class="two">';
        f_sel('board_id', 'التطبيق (اختياري)', $boards, 0);
        f_sel('key_type', 'نوع المفتاح', KEY_TYPES, 'license');
        f_in('prefix', 'البادئة (مثال: LIC)', 'LIC', 'text', 'dir="ltr" maxlength="10"');
        f_in('key_len', 'طول المفتاح (8-32)', '16', 'number', 'dir="ltr" min="8" max="32"');
        f_in('count', 'العدد (1-200)', '10', 'number', 'dir="ltr" min="1" max="200"');
        f_in('plan', 'الخطة (اختياري)', '', 'text');
        f_in('email', 'بريد العميل (اختياري)', '', 'text', 'dir="ltr"');
        f_in('max_uses', 'أقصى استخدامات للمفتاح', '1', 'number', 'dir="ltr" min="1"');
        f_in('expiry_days', 'الصلاحية بالأيام (0 = بلا انتهاء)', '0', 'number', 'dir="ltr" min="0"');
        echo '</div><div class="row"><button class="btn" type="submit">🔑 توليد المفاتيح</button></div></form></div>';

        if (!empty($_SESSION['last_keys'])) {
            $made = (array)$_SESSION['last_keys']; unset($_SESSION['last_keys']);
            echo '<div class="panel"><h2>المفاتيح المولّدة (' . count($made) . ')</h2>';
            echo '<textarea class="keys-box" readonly>' . e(implode("\n", $made)) . '</textarea>';
            echo '<div class="row" style="margin-top:10px">' . '<button type="button" class="btn ghost sm" data-copy="' . e(implode("\n", $made)) . '">📋 نسخ الكل</button></div></div>';
        }

        // ---- filters ----
        $q = trim((string)($_GET['q'] ?? '')); $st = (string)($_GET['status'] ?? ''); $ty = (string)($_GET['key_type'] ?? ''); $bd = (string)($_GET['board_id'] ?? '');
        echo '<form method="get" class="filter-bar"><input type="hidden" name="m" value="appkeys">'
           . '<input type="search" name="q" value="' . e($q) . '" placeholder="🔎 بحث بالمفتاح أو البريد…">'
           . '<select name="status"><option value="">كل الحالات</option>' . implode('', array_map(fn($k, $v) => '<option value="' . $k . '"' . ($st === $k ? ' selected' : '') . '>' . $v . '</option>', array_keys(KEY_STATUS), array_values(KEY_STATUS))) . '</select>'
           . '<select name="key_type"><option value="">كل الأنواع</option>' . implode('', array_map(fn($k, $v) => '<option value="' . $k . '"' . ($ty === $k ? ' selected' : '') . '>' . $v . '</option>', array_keys(KEY_TYPES), array_values(KEY_TYPES))) . '</select>'
           . '<select name="board_id"><option value="">كل التطبيقات</option>' . implode('', array_map(fn($k, $v) => '<option value="' . $k . '"' . ($bd === (string)$k ? ' selected' : '') . '>' . e($v) . '</option>', array_keys($boards), array_values($boards))) . '</select>'
           . '<button class="btn ghost sm" type="submit">بحث</button>'
           . (($q !== '' || $st !== '' || $ty !== '' || $bd !== '') ? '<a class="btn ghost sm" href="' . e(adm_url('appkeys')) . '">إعادة تعيين</a>' : '')
           . '<a class="btn ghost sm" href="' . e(adm_url('appkeys', ['dl' => 'csv'])) . '">⬇ CSV</a></form>';

        $where = '1=1'; $p = [];
        if ($q !== '') { $where .= ' AND (k.key_code LIKE ? OR k.email LIKE ? OR k.plan LIKE ?)'; $p = array_merge($p, ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%']); }
        if (in_array($st, array_keys(KEY_STATUS), true)) { $where .= ' AND k.status=?'; $p[] = $st; }
        if (in_array($ty, array_keys(KEY_TYPES), true)) { $where .= ' AND k.key_type=?'; $p[] = $ty; }
        if ($bd !== '' && (int)$bd > 0) { $where .= ' AND k.board_id=?'; $p[] = (int)$bd; }
        $keys = rows("SELECT k.*, COALESCE(b.name,'—') AS board FROM app_keys k LEFT JOIN boards b ON b.id=k.board_id WHERE $where ORDER BY k.id DESC LIMIT 500", $p);
        if (!$keys) { echo '<div class="panel"><p class="muted">لا مفاتيح مطابقة.</p></div>'; return; }
        echo '<div class="panel tw"><table><thead><tr><th>المفتاح</th><th>النوع</th><th>التطبيق</th><th>الخطة</th><th>الحالة</th><th>الاستخدام</th><th>الإنشاء</th><th>الانتهاء</th><th></th></tr></thead><tbody>';
        foreach ($keys as $k) {
            echo '<tr><td dir="ltr"><code>' . e($k['key_code']) . '</code> <button type="button" class="btn ghost sm" data-copy="' . e($k['key_code']) . '">نسخ</button></td>'
               . '<td>' . e(KEY_TYPES[$k['key_type']] ?? $k['key_type']) . '</td><td>' . e((string)$k['board']) . '</td><td>' . e((string)$k['plan']) . '</td>'
               . '<td><span class="chip ' . ($k['status'] === 'active' ? 'ok' : 'bad') . '">' . e(KEY_STATUS[$k['status']] ?? $k['status']) . '</span></td>'
               . '<td dir="ltr">' . (int)$k['uses'] . '/' . (int)$k['max_uses'] . '</td><td>' . e(fmt_date($k['created'])) . '</td><td>' . e($k['expires'] ? fmt_date($k['expires']) : '—') . '</td><td class="acts">';
            btn_post('appkeys', 'key_toggle', ['id' => $k['id']], $k['status'] === 'active' ? 'إلغاء' : 'تفعيل');
            btn_post('appkeys', 'key_delete', ['id' => $k['id']], 'حذف', 'btn danger sm', 'حذف هذا المفتاح نهائياً؟');
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    },
];
