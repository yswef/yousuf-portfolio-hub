<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

// The engine lives in core/update.php. If it is missing (partial upload) this module must degrade
// gracefully instead of throwing "undefined function" and blanking the panel.
if (!function_exists('update_state_read')) {
    return [
        'title' => ['ar' => 'تحديث الموقع', 'en' => 'Site updater'], 'icon' => '🔄', 'group' => 'system', 'order' => 96, 'version' => 2,
        'view' => function (array $mod): void {
            adm_h('تحديث الموقع');
            echo '<div class="note bad">محرّك التحديث غير مكتمل على السيرفر: الملف <code>core/update.php</code> مفقود.</div>';
            echo '<div class="panel"><h2>كيف تُصلحها</h2><ol class="muted" style="line-height:2;padding-inline-start:18px">'
               . '<li>افتح مدير الملفات في hPanel ← <code>public_html/core/</code> وتأكد من وجود <code>update.php</code>.</li>'
               . '<li>أو افتح <a href="' . e(url('status.php')) . '">status.php</a> لرؤية كل الملفات الناقصة.</li>'
               . '<li>أعد رفع الملف الناقص فقط — بياناتك و<code>config.php</code> لا تحتاج إعادة رفع.</li></ol></div>';
        },
    ];
}

return [
    'title' => ['ar' => 'تحديث الموقع', 'en' => 'Site updater'], 'icon' => '🔄', 'group' => 'system', 'order' => 96, 'version' => 2,
    'widgets' => function (): array {
        if (!function_exists('update_last_applied_manifest')) return [];
        $m = update_last_applied_manifest();
        $st = update_state_read();
        if ($st && update_active($st)) return [['label' => 'حالة التحديث', 'value' => update_percent($st) . '%', 'link' => 'updater']];
        return [['label' => 'آخر تحديث', 'value' => $m ? (string)($m['tag'] ?: gmdate('m-d', (int)($m['ts'] ?? 0))) : 'الأصلي', 'link' => 'updater']];
    },
    'actions' => [
        // Kept as a no-JS fallback: the same work the AJAX starter does, then the admin guard shows progress.
        'upload_zip' => function (): void {
            $f = $_FILES['release_zip'] ?? null;
            if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($f['tmp_name'])) {
                flash('يرجى اختيار ملف ZIP صالح لإصدار الموقع الجديد.', false);
                return;
            }
            $name = basename((string)($f['name'] ?? 'release.zip'));
            if (!preg_match('/\.zip$/i', $name)) { flash('يجب أن يكون الملف بصيغة .zip فقط.', false); return; }
            try {
                update_start_zip((string)$f['tmp_name'], 'ZIP: ' . $name);
                audit('site_update_zip', $name);
                flash('بدأ التحديث الآمن — استبدال ملف بملف مع نسخة احتياطية لكل ملف.');
            } catch (Throwable $e) { flash('فشل بدء التحديث: ' . $e->getMessage(), false); }
        },
        'github_pull' => function (): void {
            $repo = trim(P('gh_repo', 'yswef/yousuf-portfolio-hub'), '/ ');
            $branch = trim(P('gh_branch', 'main'));
            if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) || !preg_match('#^[A-Za-z0-9_./-]+$#', $branch)) {
                flash('صيغة المستودع أو الفرع غير صحيحة (مثال: yswef/yousuf-portfolio-hub والفرع main).', false);
                return;
            }
            set_setting('updater_repo', $repo);
            set_setting('updater_branch', $branch);
            try {
                update_start_github($repo, $branch);
                audit('site_update_github', "$repo@$branch");
                flash('بدأ سحب وتطبيق أحدث إصدار — الملفات تُستبدل واحداً بعد الآخر.');
            } catch (Throwable $e) { flash('فشل التحديث من GitHub: ' . $e->getMessage(), false); }
        },
        'resume' => function (): void {
            try { update_step(); flash('تم تنفيذ الخطوة التالية من التحديث.'); }
            catch (Throwable $e) { flash('تعذّر متابعة التحديث: ' . $e->getMessage(), false); }
        },
        'rollback' => function (): void {
            $st = update_state_read();
            $id = (string)P('id', (string)($st['id'] ?? ''));
            try {
                update_rollback($st && ($st['id'] ?? '') === $id ? $st : null);
                audit('site_rollback', $id);
                flash('تم التراجع بنجاح — استُعيدت الملفات السابقة من النسخة الاحتياطية، وبياناتك لم تُمس.');
            } catch (Throwable $e) { flash('فشل التراجع: ' . $e->getMessage(), false); }
        },
        'delete_rollback' => function (): void {
            $name = basename(P('name'));
            $path = updater_rollback_dir() . '/' . $name;
            if (preg_match('/^rollback-\d{8}-\d{6}(?:-[a-f0-9]{4})?\.zip$/', $name) && is_file($path)) {
                @unlink($path);
                flash('تم حذف نسخة ZIP القديمة.');
            }
        },
    ],
    'download' => function (string $what): void {
        $name = basename((string)($_GET['f'] ?? ''));
        $path = updater_rollback_dir() . '/' . $name;
        if ($what !== 'rollback' || !preg_match('/^rollback-\d{8}-\d{6}(?:-[a-f0-9]{4})?\.zip$/', $name) || !is_file($path)) {
            http_response_code(404); echo 'Not found'; return;
        }
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    },
    'view' => function (array $mod): void {
        adm_h('تحديث الموقع');
        $lim = 'حد الرفع: ' . ini_get('upload_max_filesize') . ' · post: ' . ini_get('post_max_size');
        $st = update_state_read();
        $active = $st ? update_active($st) : false;
        $last = update_last_applied_manifest();
        $stepUrl = rtrim(base(), '/') . '/update_step.php';
        $startUrl = rtrim(base(), '/') . '/update_start.php';

        /* ---------------- live status / progress ---------------- */
        echo '<div class="panel" id="upd-card" data-step="' . e($stepUrl) . '" data-start="' . e($startUrl)
           . '" data-token="' . e((string)($st['token'] ?? '')) . '" data-phase="' . e((string)($st['phase'] ?? '')) . '" data-csrf="' . e(csrf_token()) . '">';
        echo '<h2>📡 حالة التحديث</h2>';
        echo '<div id="upd-live">';
        if ($active) {
            echo '<p class="muted">تحديث جارٍ — يتم استبدال الملفات ملفاً بملف. الصفحة العامة تعرض صفحة «جاري تحديث الموقع» حتى ينتهي.</p>';
        } else {
            echo '<p class="muted">لا يوجد تحديث قيد التنفيذ. عند البدء تظهر شارة عائمة أعلى الصفحة بشريط تقدّم، دون مغادرة هذه الصفحة.</p>';
        }
        echo '</div>';
        echo '<div class="bar" id="upd-bar"><i style="width:' . ($active ? update_percent($st) : 0) . '%"></i></div>';
        echo '<div class="meta" id="upd-meta">' . e($active ? update_phase_label((string)$st['phase']) . ' · ' . (int)($st['done'] ?? 0) . '/' . (int)($st['total'] ?? 0) : 'في الانتظار') . '</div>';
        if ($st && !empty($st['errors'])) {
            echo '<ul class="errors" dir="auto">';
            foreach (array_slice((array)$st['errors'], -5) as $er) echo '<li>' . e((string)$er) . '</li>';
            echo '</ul>';
        }
        if ($active) {
            echo '<div class="row"><a class="btn ghost sm" href="' . e(adm_url('updater')) . '">↻ تحديث الصفحة</a>';
            btn_post('updater', 'rollback', ['id' => (string)($st['id'] ?? '')], '⏪ تراجع عن هذا التحديث', 'btn danger sm', 'استعادة الملفات السابقة من النسخة الاحتياطية؟ بياناتك لن تتأثر.');
            echo '</div>';
        }
        echo '</div>';

        /* ---------------- start forms (AJAX, stay on page) ---------------- */
        echo '<div class="cols">';
        echo '<div class="panel"><h2>🐙 تحديث مباشر من GitHub</h2>';
        echo '<p class="muted" style="font-size:.85rem">ينزّل الحزمة إلى مساحة مؤقتة (<code>data/update/stage</code>)، يقرأ سجل الملفات المتغيّرة، ثم يستبدل ملفاً بملف.</p>';
        echo '<form data-upd-form="github" onsubmit="return false">';
        echo '<div class="two">';
        f_in('gh_repo', 'المستودع (owner/repo)', setting('updater_repo', (gh_user() ?: 'yswef') . '/yousuf-portfolio-hub'), 'text', 'dir="ltr" required placeholder="yswef/yousuf-portfolio-hub"');
        f_in('gh_branch', 'الفرع', setting('updater_branch', 'main'), 'text', 'dir="ltr" required placeholder="main"');
        echo '</div><div class="row"><button class="btn" type="submit" data-upd-go>⬇ سحب وتحديث الآن</button></div></form></div>';

        echo '<div class="panel"><h2>📦 رفع إصدار (ZIP)</h2>';
        echo '<form data-upd-form="zip" onsubmit="return false">';
        echo '<label class="dropzone" data-dropzone style="margin:10px 0">'
           . '<div style="font-size:1.8rem;margin-bottom:4px">📦</div>'
           . '<div><b>اسحب ملف إصدار الموقع (.zip) هنا</b> أو اضغط للاختيار</div>'
           . '<div class="muted" style="font-size:.8rem;margin-top:4px">' . e($lim) . '</div>'
           . '<input type="file" name="release_zip" accept=".zip,application/zip" required>'
           . '<div class="dz-file" data-dz-file></div>'
           . '</label>'
           . '<div class="row"><button class="btn" type="submit" data-upd-go>🚀 بدء التحديث الآمن</button></div></form>'
           . '<div class="note ok" style="margin:8px 0 0;font-size:.86rem">🛡 <code>config.php</code> و<code>data/</code> و<code>assets/uploads/</code> و<code>AGENTS.md</code> محمية ولا تُمس — ولا تُحذف أي بيانات.</div>'
           . '</div>';
        echo '</div>';

        /* ---------------- changed-files log of the last applied update ---------------- */
        echo '<div class="panel"><h2>🧾 سجل الملفات المتغيّرة في آخر تحديث' . ($last && !empty($last['tag']) ? ' — <span dir="ltr">' . e((string)$last['tag']) . '</span>' : '') . '</h2>';
        if (!$last) {
            echo '<p class="muted">لم يُطبَّق تحديث بعد. يُنشأ السجل تلقائياً قبل استبدال أي ملف، ويُحفظ في <code>data/update/manifests/</code> باسم الوسم (tag).</p>';
        } else {
            $all = array_merge((array)($last['files'] ?? []), (array)($last['remove'] ?? []));
            echo '<p class="muted" style="font-size:.85rem">' . count($all) . ' ملف · المصدر: <span dir="auto">' . e((string)($last['source'] ?? '')) . '</span> · ' . e(ts_fmt((int)($last['ts'] ?? 0))) . '</p>';
            echo '<details><summary style="cursor:pointer;color:#2dd4bf;font-size:.87rem">عرض المسارات ملفاً بملف</summary>';
            echo '<div class="tw" style="max-height:360px;overflow:auto"><table><thead><tr><th>المسار</th><th>العملية</th><th>الحجم</th></tr></thead><tbody>';
            foreach ($all as $f) {
                echo '<tr><td dir="ltr"><code>' . e((string)($f['path'] ?? '')) . '</code></td><td>' . e((string)($f['op'] ?? 'replace')) . '</td><td dir="ltr">' . e(fmt_size((int)($f['size'] ?? 0))) . '</td></tr>';
            }
            echo '</tbody></table></div></details>';
        }
        echo '</div>';

        /* ---------------- history ---------------- */
        $manifests = [];
        foreach (glob(update_dir('manifests') . '/*.json') ?: [] as $mf) {
            if (basename($mf) === 'last-applied.json') continue;
            $j = json_decode((string)@file_get_contents($mf), true);
            if (is_array($j)) $manifests[] = $j;
        }
        usort($manifests, static fn($a, $b) => (int)($b['ts'] ?? 0) <=> (int)($a['ts'] ?? 0));
        echo '<div class="panel"><h2>📋 سجل التحديثات</h2>';
        if (!$manifests) {
            echo '<p class="muted">لا توجد عمليات تحديث مسجّلة بعد.</p>';
        } else {
            echo '<div class="tw"><table><thead><tr><th>التاريخ</th><th>الوسم</th><th>المصدر</th><th>الملفات</th><th>السجل</th></tr></thead><tbody>';
            foreach (array_slice($manifests, 0, 20) as $m) {
                $cnt = count((array)($m['files'] ?? [])) + count((array)($m['remove'] ?? []));
                echo '<tr><td>' . e(ts_fmt((int)($m['ts'] ?? 0))) . '</td><td dir="ltr">' . e((string)($m['tag'] ?? '—')) . '</td><td dir="auto">' . e((string)($m['source'] ?? '')) . '</td><td>' . $cnt . '</td>'
                   . '<td class="acts">';
                echo '<a class="btn ghost sm" href="' . e(adm_url('updater', ['log' => (string)($m['id'] ?? '')])) . '">🧾</a>';
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        if (!empty($_GET['log'])) {
            $m = update_manifest_by_id((string)$_GET['log']);
            if ($m) {
                echo '<h3 dir="ltr" style="margin-top:14px">' . e((string)($m['id'] ?? '')) . '</h3><div class="tw" style="max-height:420px;overflow:auto"><table><thead><tr><th>المسار</th><th>العملية</th></tr></thead><tbody>';
                foreach (array_merge((array)($m['files'] ?? []), (array)($m['remove'] ?? [])) as $f) {
                    echo '<tr><td dir="ltr"><code>' . e((string)($f['path'] ?? '')) . '</code></td><td>' . e((string)($f['op'] ?? 'replace')) . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
        }
        echo '</div>';

        /* ---------------- floating badge + progress while updating ---------------- */
        echo <<<'HTML'
<div id="upd-float" hidden>
  <span class="upd-ring"></span>
  <div class="upd-txt"><b>جاري التحديث</b><small data-upd-label>بدء…</small></div>
  <div class="upd-bar"><i data-upd-bar></i></div>
  <span class="upd-pct" data-upd-pct>0%</span>
</div>
<style>
#upd-card .bar{height:10px;border-radius:99px;background:rgba(255,255,255,.07);overflow:hidden;margin:12px 0 8px}
#upd-card .bar i{display:block;height:100%;width:0;background:linear-gradient(90deg,#0d9488,#0284c7);transition:width .35s}
#upd-card .meta{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:.8rem;color:var(--muted)}
#upd-card ul.errors{margin:10px 0 0;padding-inline-start:18px;color:#fca5a5;font-size:.8rem;line-height:1.8;overflow-wrap:anywhere}
#upd-float{position:fixed;inset-inline-start:16px;bottom:16px;z-index:9999;display:flex;align-items:center;gap:10px;
  background:#0e1922;border:1px solid #1d3243;border-radius:16px;padding:10px 14px;box-shadow:0 18px 40px rgba(0,0,0,.45);
  color:#e9eef5;font-family:Cairo,system-ui,Tahoma,sans-serif;max-width:calc(100vw - 32px);flex-wrap:wrap}
#upd-float[hidden]{display:none}
#upd-float .upd-ring{width:26px;height:26px;border-radius:50%;border:3px solid rgba(45,212,191,.18);border-top-color:#2dd4bf;animation:updspin 1s linear infinite;flex:0 0 auto}
#upd-float .upd-txt{display:flex;flex-direction:column;line-height:1.35}
#upd-float .upd-txt b{font-size:.86rem}
#upd-float .upd-txt small{font-size:.72rem;color:#8fa9c3}
#upd-float .upd-bar{width:120px;height:6px;border-radius:99px;background:rgba(255,255,255,.08);overflow:hidden}
#upd-float .upd-bar i{display:block;height:100%;width:0;background:linear-gradient(90deg,#0d9488,#0284c7);transition:width .3s}
#upd-float .upd-pct{font-size:.78rem;color:#2dd4bf;font-variant-numeric:tabular-nums}
@keyframes updspin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion: reduce){#upd-float .upd-ring{animation:none}}
@media (max-width:520px){#upd-float{inset-inline:10px;bottom:10px}#upd-float .upd-bar{width:80px}}
</style>
<script>
(function(){
  var card = document.getElementById('upd-card'); if (!card) return;
  var stepUrl = card.dataset.step, startUrl = card.dataset.start, token = card.dataset.token || '';
  var box = document.getElementById('upd-float');
  var bar = box.querySelector('[data-upd-bar]'), pct = box.querySelector('[data-upd-pct]'), label = box.querySelector('[data-upd-label]');
  var meta = document.getElementById('upd-meta'), live = document.getElementById('upd-live'), mainBar = document.querySelector('#upd-bar i');
  var busy = false, timer = null;

  function paint(s){
    if (!s) return;
    var p = typeof s.percent === 'number' ? s.percent : 0;
    if (bar) bar.style.width = p + '%';
    if (mainBar) mainBar.style.width = p + '%';
    if (pct) pct.textContent = p + '%';
    var text = s.phase_label || s.meta || '';
    if (label) label.textContent = text;
    if (meta) meta.textContent = text + (s.total ? ' · ' + s.done + '/' + s.total : '') + (s.eta ? ' · ~' + s.eta + 's' : '');
  }
  function done(s){
    if (!box) return;
    box.hidden = true;
    if (s && s.phase === 'failed'){
      if (live) live.innerHTML = '<div class="note bad">توقّف التحديث: ' + (s.errors && s.errors.length ? s.errors[s.errors.length-1] : 'خطأ غير معروف') + ' — يمكنك التراجع من الزر أدناه.</div>';
      return;
    }
    if (live) live.innerHTML = '<div class="note ok">✅ اكتمل التحديث وتم التحقق من الملفات. أعد تحميل الصفحة لرؤية الواجهة الجديدة.</div>';
    setTimeout(function(){ location.reload(); }, 1200);
  }
  function step(){
    if (!token) return;
    fetch(stepUrl + '?token=' + encodeURIComponent(token), {credentials:'same-origin', cache:'no-store'})
      .then(function(r){ return r.json(); })
      .then(function(s){
        if (!s || s.ok === false) { if (timer) clearTimeout(timer); return; }
        paint(s);
        if (s.done_all || s.phase === 'idle' || s.phase === 'failed') { if (timer) clearTimeout(timer); done(s); return; }
        timer = setTimeout(step, 1200);
      })
      .catch(function(){ timer = setTimeout(step, 2500); });
  }
  function begin(token2){
    token = token2; if (box) box.hidden = false;
    if (live) live.innerHTML = '<p class="muted">تحديث جارٍ — الملفات تُستبدل ملفاً بملف، والصفحة العامة تعرض صفحة «جاري تحديث الموقع» مؤقتاً.</p>';
    step();
  }
  if (card.dataset.phase && ['download','extract','plan','apply','verify'].indexOf(card.dataset.phase) !== -1 && token) begin(token);

  document.querySelectorAll('form[data-upd-form]').forEach(function(f){
    f.addEventListener('submit', function(ev){
      ev.preventDefault();
      if (busy) return; busy = true;
      var mode = f.dataset.updForm;
      var fd = new FormData(f);
      fd.append('mode', mode);
      fd.append('_csrf', card.dataset.csrf || (window.CSRF || ''));
      var csrf = document.querySelector('input[name="_csrf"]');
      if (csrf && !fd.get('_csrf')) fd.set('_csrf', csrf.value);
      if (box) { box.hidden = false; paint({percent:2, phase_label:'بدء التحديث…'}); }
      f.querySelectorAll('[data-upd-go]').forEach(function(b){ b.disabled = true; b.dataset.old = b.textContent; b.textContent = '... جارٍ البدء'; });
      fetch(startUrl, {method:'POST', body:fd, credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(j){
          if (!j || !j.ok) throw new Error((j && j.error) || 'تعذّر بدء التحديث');
          begin(j.token);
        })
        .catch(function(e){
          busy = false;
          if (box) box.hidden = true;
          if (live) live.innerHTML = '<div class="note bad">' + e_msg(e.message) + '</div>';
          f.querySelectorAll('[data-upd-go]').forEach(function(b){ b.disabled = false; if (b.dataset.old) b.textContent = b.dataset.old; });
        });
    });
  });
  function e_msg(t){ var d = document.createElement('div'); d.textContent = String(t); return d.innerHTML; }
})();
</script>
HTML;
        echo '<div class="panel"><h2>🛡 كيف يعمل التحديث الآمن</h2><ol class="muted" style="font-size:.88rem;line-height:2;padding-inline-start:18px">'
           . '<li>تنزيل الحزمة إلى مساحة مؤقتة <code>data/update/dl</code> (الموقع يظل يعمل).</li>'
           . '<li>فكّ الضغط في <code>data/update/stage/&lt;id&gt;</code> خارج مسار الموقع.</li>'
           . '<li>مقارنة كل ملف بالنسخة الحالية وإنشاء <b>سجل الملفات المتغيّرة</b> + بصمة SHA-256 لكل ملف.</li>'
           . '<li><b>تجميد الصفحات العامة</b> على صفحة «جاري تحديث الموقع» (503) ولوحة التحكم على شاشة التقدّم.</li>'
           . '<li>استبدال الملفات <b>واحداً بعد الآخر</b> مع نسخة احتياطية لكل ملف وكتابة ذرّية (tmp + rename).</li>'
           . '<li>التحقق: فحص صحة كل ملف PHP + تشغيل الترقيات + تصفير ذاكرة OPcache.</li>'
           . '<li>عند أي خطأ: زر تراجع فوري يعيد الملفات السابقة، والبيانات لا تُمس إطلاقاً.</li>'
           . '</ol></div>';
    },
];
