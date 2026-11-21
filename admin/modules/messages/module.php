<?php
declare(strict_types=1);

const MSG_STATUS = ['new' => 'جديدة', 'read' => 'مقروءة', 'archived' => 'مؤرشفة', 'spam' => 'مزعجة'];

return [
    'title' => ['ar' => 'الرسائل', 'en' => 'Messages'], 'icon' => '✉', 'group' => 'system', 'order' => 70, 'version' => 1,
    'schema' => fn(array $T) => [
        "CREATE TABLE IF NOT EXISTS messages (id {$T['id']}, name VARCHAR(150), email VARCHAR(190), phone VARCHAR(50) NULL, company VARCHAR(150) NULL, kind VARCHAR(100) NULL,
          budget VARCHAR(100) NULL, subject VARCHAR(255) NULL, body TEXT, status VARCHAR(20) DEFAULT 'new', ip_hash VARCHAR(40) NULL, ts INT)" . $T['tail'],
    ],
    'badge' => fn() => (int)val("SELECT COUNT(*) FROM messages WHERE status='new'"),
    'widgets' => fn() => [['label' => 'رسائل جديدة', 'value' => (int)val("SELECT COUNT(*) FROM messages WHERE status='new'")]],
    'actions' => [
        'status' => function (): void {
            $s = array_key_exists(P('status'), MSG_STATUS) ? P('status') : 'read';
            upd('messages', ['status' => $s], 'id=?', [PN('id')]);
            adm_back('messages', ['s' => P('filter', 'new')]);
        },
        'delete' => function (): void { q('DELETE FROM messages WHERE id=?', [PN('id')]); audit('msg_delete', (string)PN('id')); adm_back('messages', ['s' => P('filter', 'new')]); },
    ],
    'download' => function (string $what): void {        // ?m=messages&dl=csv
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="messages.csv"');
        $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, ['id', 'date', 'status', 'name', 'email', 'phone', 'company', 'kind', 'budget', 'subject', 'message']);
        foreach (rows('SELECT * FROM messages ORDER BY id DESC') as $m) {
            $line = [$m['id'], gmdate('Y-m-d H:i', (int)$m['ts']), $m['status'], $m['name'], $m['email'], $m['phone'], $m['company'], $m['kind'], $m['budget'], $m['subject'], $m['body']];
            fputcsv($o, array_map(fn($c) => preg_match('/^[=+\-@\t\r]/', (string)$c) ? "'" . $c : $c, $line));   // CSV-injection guard
        }
    },
    'view' => function (array $mod): void {
        $f = array_key_exists((string)($_GET['s'] ?? 'new'), MSG_STATUS) || ($_GET['s'] ?? '') === 'all' ? (string)($_GET['s'] ?? 'new') : 'new';
        adm_h('الرسائل');
        echo '<div class="row" style="margin-bottom:14px">';
        foreach (['all' => 'الكل'] + MSG_STATUS as $k => $n) echo '<a class="btn sm ' . ($k === $f ? '' : 'ghost') . '" href="' . adm_url('messages', ['s' => $k]) . '">' . e($n) . '</a>';
        echo '<a class="btn ghost sm" href="' . adm_url('messages', ['dl' => 'csv']) . '">تصدير CSV</a></div>';
        $list = $f === 'all' ? rows('SELECT * FROM messages ORDER BY id DESC LIMIT 300') : rows('SELECT * FROM messages WHERE status=? ORDER BY id DESC LIMIT 300', [$f]);
        if (!$list) { echo '<div class="panel"><p class="muted">لا رسائل.</p></div>'; return; }
        foreach ($list as $m) {
            echo '<details class="panel msg"' . ($m['status'] === 'new' ? ' open' : '') . '><summary><b>' . e($m['subject'] ?: $m['name']) . '</b> <span class="muted">— ' . e($m['name']) . ' · ' . e(ts_fmt($m['ts'])) . '</span> <span class="chip">' . e(MSG_STATUS[$m['status']] ?? $m['status']) . '</span></summary>';
            echo '<p dir="ltr"><a href="mailto:' . e($m['email']) . '">' . e($m['email']) . '</a>' . ($m['phone'] ? ' · ' . e($m['phone']) : '') . '</p>';
            if ($m['company'] || $m['kind'] || $m['budget']) echo '<p class="muted">' . e(trim($m['company'] . ' · ' . $m['kind'] . ' · ' . $m['budget'], ' ·')) . '</p>';
            echo '<div class="prose" dir="auto" style="white-space:pre-wrap">' . e($m['body']) . '</div><div class="row">';
            foreach (MSG_STATUS as $k => $n) if ($k !== $m['status']) btn_post('messages', 'status', ['id' => $m['id'], 'status' => $k, 'filter' => $f], $n);
            btn_post('messages', 'delete', ['id' => $m['id'], 'filter' => $f], 'حذف', 'btn danger sm', 'حذف الرسالة نهائياً؟');
            echo '</div></details>';
        }
    },
];
