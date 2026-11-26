<?php
declare(strict_types=1);
/**
 * Standalone "update in progress" screen for the admin panel.
 * Loaded from admin/index.php BEFORE the core bootstrap so a half-applied tree can never break it.
 * It only reads data/update/state.json and polls update_step.php with the per-update token.
 */
$__stateFile = dirname(__DIR__, 2) . '/data/update/state.json';
$__s = is_file($__stateFile) ? json_decode((string)@file_get_contents($__stateFile), true) : null;
if (!is_array($__s)) { header('Location: ./'); exit; }

$__phase = (string)($__s['phase'] ?? '');
$__token = (string)($__s['token'] ?? '');
$__total = (int)($__s['total'] ?? 0);
$__done  = (int)($__s['done'] ?? 0);
$__tag   = (string)(($__s['tag'] ?? '') ?: ($__s['ref'] ?? ''));
$__pct   = $__phase === 'verify' ? 96 : ($__phase === 'apply' ? 20 + (int)floor(72 * ($__total > 0 ? min(1, $__done / $__total) : 0)) : 8);
$__labels = [
    'download' => 'تنزيل الحزمة', 'extract' => 'فكّ الضغط', 'plan' => 'قراءة سجل الملفات المتغيّرة',
    'apply' => 'استبدال الملفات', 'verify' => 'التحقق النهائي', 'done' => 'اكتمل',
    'failed' => 'توقّف', 'rolledback' => 'تم التراجع',
];
$__label = $__labels[$__phase] ?? $__phase;
$__script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'));
$__root = preg_replace('#/admin/(index\.php)?$#', '', $__script);
$__root = rtrim((string)$__root, '/');
$__stepUrl = $__root . '/update_step.php';
$__adminUrl = $__root . '/admin/';
$__logoUrl = $__root . '/assets/img/favicon-192.png';
?><!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>جاري تحديث الموقع — لوحة التحكم</title>
<link rel="icon" href="<?= htmlspecialchars($__logoUrl, ENT_QUOTES) ?>">
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;min-height:100svh;display:grid;place-items:center;background:radial-gradient(1200px 600px at 50% -10%,#12232f,#070c12 60%);color:#e9eef5;font-family:Cairo,system-ui,Tahoma,sans-serif;padding:22px}
.card{width:100%;max-width:430px;background:#0e1922;border:1px solid #1d3243;border-radius:20px;padding:26px 22px;box-shadow:0 24px 60px rgba(0,0,0,.45);text-align:center}
.ring{position:relative;width:118px;height:118px;margin:0 auto 18px;display:grid;place-items:center}
.ring::before{content:"";position:absolute;inset:0;border-radius:50%;border:3px solid rgba(45,212,191,.15);border-top-color:#2dd4bf;animation:spin 1.05s linear infinite}
.ring img{width:76px;height:76px;border-radius:20px;object-fit:cover}
h1{font-size:1.08rem;margin:0 0 6px}
p{margin:0 0 16px;color:#8fa9c3;font-size:.84rem;line-height:1.7}
.bar{height:8px;border-radius:99px;background:rgba(255,255,255,.07);overflow:hidden}
.bar i{display:block;height:100%;width:<?= (int)$__pct ?>%;background:linear-gradient(90deg,#0d9488,#0284c7);transition:width .4s}
.meta{display:flex;justify-content:space-between;gap:8px;font-size:.76rem;color:#7c96ad;margin-top:10px}
a{color:#2dd4bf;font-size:.78rem;text-decoration:none}
ul.errors{margin:12px 0 0;padding-inline-start:18px;text-align:start;color:#fca5a5;font-size:.76rem}
@keyframes spin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion: reduce){.ring::before{animation:none}}
</style></head>
<body>
<div class="card" id="card" data-token="<?= htmlspecialchars($__token, ENT_QUOTES) ?>" data-url="<?= htmlspecialchars($__stepUrl, ENT_QUOTES) ?>">
  <div class="ring"><img src="<?= htmlspecialchars($__logoUrl, ENT_QUOTES) ?>" alt=""></div>
  <h1>جاري تحديث الموقع</h1>
  <p>يتم استبدال الملفات بأمان ملفاً بملف مع نسخة احتياطية لكل ملف. لا تُغلق هذه الصفحة — تعود تلقائياً بعد الانتهاء.</p>
  <div class="bar"><i id="bar"></i></div>
  <div class="meta"><span id="label"><?= htmlspecialchars($__label, ENT_QUOTES) ?><?= $__total ? ' · ' . $__done . '/' . $__total : '' ?></span><span id="pct"><?= (int)$__pct ?>%</span></div>
  <?php if ($__tag !== ''): ?><div class="meta" style="justify-content:center"><span><?= htmlspecialchars($__tag, ENT_QUOTES) ?></span></div><?php endif; ?>
  <ul class="errors" id="errs"></ul>
  <p style="margin-top:16px"><a href="<?= htmlspecialchars($__adminUrl, ENT_QUOTES) ?>">↻ تحديث الصفحة</a></p>
</div>
<script>
(function(){
  var c = document.getElementById('card');
  var token = c.dataset.token, url = c.dataset.url || 'update_step.php';
  var bar = document.getElementById('bar'), label = document.getElementById('label'), pct = document.getElementById('pct'), errs = document.getElementById('errs');
  var stopped = false, tries = 0;
  function tick(){
    if (stopped) return;
    fetch(url + '?token=' + encodeURIComponent(token), {credentials:'same-origin', cache:'no-store'})
      .then(function(r){ return r.json(); })
      .then(function(s){
        if (!s) return;
        if (typeof s.percent === 'number') { bar.style.width = s.percent + '%'; pct.textContent = s.percent + '%'; }
        if (s.phase_label) label.textContent = s.phase_label;
        if (s.errors && s.errors.length) { errs.innerHTML = ''; s.errors.forEach(function(e){ var li = document.createElement('li'); li.textContent = e; errs.appendChild(li); }); }
        if (s.done_all || s.phase === 'idle') { stopped = true; location.href = <?= json_encode($__adminUrl) ?>; return; }
        if (s.phase === 'failed') { stopped = true; location.href = <?= json_encode($__adminUrl) ?>; return; }
        tries = 0; setTimeout(tick, 1100);
      })
      .catch(function(){ tries++; setTimeout(tick, tries > 4 ? 4000 : 2000); });
  }
  setTimeout(tick, 800);
})();
</script>
</body></html>
