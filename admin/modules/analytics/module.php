<?php
declare(strict_types=1);

return [
    'title' => ['ar' => 'التحليلات', 'en' => 'Analytics'], 'icon' => '▤', 'group' => 'system', 'order' => 80, 'version' => 1,
    'schema' => fn(array $T) => [
        "CREATE TABLE IF NOT EXISTS visits (id {$T['id']}, ts INT, visitor VARCHAR(32), path VARCHAR(190), lang VARCHAR(5) NULL, country VARCHAR(10) NULL,
          device VARCHAR(20) NULL, browser VARCHAR(30) NULL, referrer VARCHAR(190) NULL, is_bot INT DEFAULT 0)" . $T['tail'],
        "CREATE INDEX idx_visits_ts ON visits (ts)",
    ],
    'widgets' => fn() => [['label' => 'زيارات (7 أيام)', 'value' => (int)val('SELECT COUNT(*) FROM visits WHERE is_bot=0 AND ts>?', [time() - 7 * 86400])]],
    'actions' => [
        'prune' => function (): void {
            $days = max(30, PN('days')); $t = time() - $days * 86400;
            q('DELETE FROM visits WHERE ts<?', [$t]); if (table_exists('downloads')) q('DELETE FROM downloads WHERE ts<?', [$t]);
            audit('prune', "$days days"); flash("تم حذف بيانات أقدم من $days يوماً.");
        },
    ],
    'view' => function (array $mod): void {
        $days = in_array((int)($_GET['d'] ?? 30), [7, 30, 90, 365], true) ? (int)($_GET['d'] ?? 30) : 30;
        $bots = !empty($_GET['bots']);
        $q = trim((string)($_GET['q'] ?? ''));
        $dev = trim((string)($_GET['device'] ?? ''));
        $langF = trim((string)($_GET['lang'] ?? ''));
        $since = time() - $days * 86400;
        $where = 'ts>?' . ($bots ? '' : ' AND is_bot=0');
        $prm = [$since];
        if ($q !== '') { $where .= ' AND (path LIKE ? OR referrer LIKE ?)'; $prm[] = '%' . $q . '%'; $prm[] = '%' . $q . '%'; }
        if ($dev !== '') { $where .= ' AND device=?'; $prm[] = $dev; }
        if ($langF !== '') { $where .= ' AND lang=?'; $prm[] = $langF; }

        $views = (int)val("SELECT COUNT(*) FROM visits WHERE $where", $prm);
        $uniq = (int)val("SELECT COUNT(DISTINCT visitor) FROM visits WHERE $where", $prm);
        $dl = table_exists('downloads') ? (int)val('SELECT COUNT(*) FROM downloads WHERE ts>?', [$since]) : 0;
        $top = fn(string $col) => rows("SELECT $col AS k, COUNT(*) AS n FROM visits WHERE $where GROUP BY $col ORDER BY n DESC LIMIT 10", $prm);
        $byDay = [];
        foreach (rows("SELECT ts FROM visits WHERE $where", $prm) as $d) { $k = gmdate('m-d', (int)$d['ts']); $byDay[$k] = ($byDay[$k] ?? 0) + 1; }
        ksort($byDay); $max = max([1] + array_values($byDay));
        $bar = function (array $r): void { $m = max([1] + array_column($r, 'n')); foreach ($r as $x) echo '<tr><td>' . e($x['k'] !== '' && $x['k'] !== null ? $x['k'] : '—') . '</td><td style="width:55%"><div class="hb"><i style="width:' . round($x['n'] / $m * 100) . '%"></i>' . (int)$x['n'] . '</div></td></tr>'; };
        adm_h('التحليلات', 'إحصاءات مجمّعة بلا عنوان IP ولا ملفات تعريف ارتباط.');
        echo '<div class="row" style="margin-bottom:12px">';
        foreach ([7 => '7 أيام', 30 => '30 يوم', 90 => '90 يوم', 365 => 'سنة'] as $d => $dlb) echo '<a class="btn sm ' . ($d === $days ? '' : 'ghost') . '" href="' . adm_url('analytics', ['d' => $d] + ($bots ? ['bots' => 1] : [])) . '">' . $dlb . '</a>';
        echo '<a class="btn ghost sm" href="' . adm_url('analytics', ['d' => $days] + ($bots ? [] : ['bots' => 1])) . '">' . ($bots ? 'إخفاء الزوار الآليين' : 'إظهار الزوار الآليين') . '</a></div>';

        echo '<form method="get" class="filter-bar"><input type="hidden" name="m" value="analytics"><input type="hidden" name="d" value="' . $days . '">'
           . ($bots ? '<input type="hidden" name="bots" value="1">' : '')
           . '<input type="search" name="q" value="' . e($q) . '" placeholder="🔎 فلترة بالصفحة أو المصدر…">'
           . '<select name="device" onchange="this.form.submit()"><option value="">كل الأجهزة</option>';
        foreach (['desktop' => 'حاسوب', 'mobile' => 'هاتف', 'tablet' => 'لوحي'] as $dk => $dl2) echo '<option value="' . $dk . '"' . ($dev === $dk ? ' selected' : '') . '>' . $dl2 . '</option>';
        echo '</select><select name="lang" onchange="this.form.submit()"><option value="">كل اللغات</option>';
        foreach (LANGS as $lk => $li) echo '<option value="' . $lk . '"' . ($langF === $lk ? ' selected' : '') . '>' . e($li['name']) . '</option>';
        echo '</select><button class="btn ghost sm" type="submit">تصفية</button>'
           . (($q !== '' || $dev !== '' || $langF !== '') ? '<a class="btn ghost sm" href="' . e(adm_url('analytics', ['d' => $days] + ($bots ? ['bots' => 1] : []))) . '">إعادة تعيين</a>' : '')
           . '</form>';

        echo '<div class="kpis"><div class="kpi"><b>' . $views . '</b><span>مشاهدات</span></div><div class="kpi"><b>' . $uniq . '</b><span>زوار (زائر-يوم)</span></div><div class="kpi"><b>' . $dl . '</b><span>نقرات تنزيل</span></div></div>';
        echo '<div class="panel"><h2>المشاهدات اليومية</h2><div class="tw"><table>';
        foreach ($byDay as $k => $n) echo '<tr><td dir="ltr">' . e($k) . '</td><td style="width:75%"><div class="hb"><i style="width:' . round($n / $max * 100) . '%"></i>' . $n . '</div></td></tr>';
        echo '</table></div></div><div class="cols">';
        foreach (['path' => 'أكثر الصفحات', 'referrer' => 'مصادر الزيارة', 'lang' => 'اللغات', 'device' => 'الأجهزة', 'browser' => 'المتصفحات', 'country' => 'الدول'] as $c => $lab) {
            echo '<div class="panel"><h2>' . e($lab) . '</h2><div class="tw"><table>'; $bar($top($c)); echo '</table></div>' . ($c === 'country' ? '<p class="muted">تظهر الدولة فقط إذا كان الموقع خلف Cloudflare.</p>' : '') . '</div>';
        }
        if (table_exists('downloads')) { echo '<div class="panel"><h2>أكثر التطبيقات تنزيلاً</h2><div class="tw"><table>'; $bar(rows('SELECT a.name AS k, COUNT(*) AS n FROM downloads d JOIN apps a ON a.id=d.app_id WHERE d.ts>? GROUP BY a.name ORDER BY n DESC LIMIT 10', [$since])); echo '</table></div></div>'; }
        echo '</div><div class="panel"><h2>تنظيف البيانات القديمة</h2>';
        f_open('analytics', 'prune'); f_in('days', 'احذف ما هو أقدم من (يوم، الحد الأدنى 30)', 180, 'number', 'min="30"');
        echo '<button class="btn danger sm" type="submit">حذف</button></form></div>';
    },
];
