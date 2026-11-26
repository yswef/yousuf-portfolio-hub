(function () {
  'use strict';
  document.documentElement.classList.add('js');
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  // copy-to-clipboard buttons: <button data-copy="text">
  $$('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-copy'), done = function () { var o = b.textContent; b.textContent = '✓ تم النسخ'; setTimeout(function () { b.textContent = o; }, 1500); };
      if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t).then(done); return; }
      var a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select(); try { document.execCommand('copy'); done(); } catch (e) {} a.remove();
    });
  });

  // mobile sidebar
  var side = document.querySelector('[data-side]'), tg = document.querySelector('[data-side-toggle]');
  if (side && tg) tg.addEventListener('click', function () { side.classList.toggle('open'); });

  // confirm before destructive forms
  $$('form[data-confirm]').forEach(function (f) { f.addEventListener('submit', function (e) { if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault(); }); });

  // per-language tabs (only buttons carrying [data-lang])
  $$('.i18n').forEach(function (box) {
    var tabs = $$('.tab[data-lang]', box), panes = $$('.pane', box);
    tabs.forEach(function (t) {
      t.addEventListener('click', function () {
        var l = t.getAttribute('data-lang');
        tabs.forEach(function (x) { x.classList.toggle('on', x === t); });
        panes.forEach(function (p) { p.classList.toggle('on', p.getAttribute('data-lang') === l); });
      });
    });
    panes.forEach(function (p) {
      var inp = p.querySelector('input,textarea'), tab = tabs.filter(function (t) { return t.getAttribute('data-lang') === p.getAttribute('data-lang'); })[0];
      if (inp && tab) inp.addEventListener('input', function () { tab.classList.toggle('has', inp.value.trim() !== ''); });
    });
  });

  /* ---------- Boards (لوحات الإدارة) ---------- */
  // modals: [data-modal-open="id"] opens, [data-modal-close] / backdrop click closes
  $$('[data-modal-open]').forEach(function (b) {
    b.addEventListener('click', function () {
      var m = document.getElementById(b.getAttribute('data-modal-open'));
      if (m) m.classList.add('open');
    });
  });
  $$('.modal').forEach(function (m) {
    $$('[data-modal-close]', m).forEach(function (b) { b.addEventListener('click', function () { m.classList.remove('open'); }); });
    m.addEventListener('click', function (e) { if (e.target === m) m.classList.remove('open'); });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') $$('.modal.open').forEach(function (m) { m.classList.remove('open'); }); });

  // 3-dot menus: toggle one at a time, close on outside click
  $$('[data-dots]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      e.stopPropagation();
      var menu = b.parentElement.querySelector('.dots-menu'), was = menu.classList.contains('open');
      $$('.dots-menu.open').forEach(function (m) { m.classList.remove('open'); });
      if (!was) menu.classList.add('open');
    });
  });
  document.addEventListener('click', function () { $$('.dots-menu.open').forEach(function (m) { m.classList.remove('open'); }); });

  // update-panel modal: set the board id from the card menu
  $$('[data-update-board]').forEach(function (b) {
    b.addEventListener('click', function () {
      var inp = document.getElementById('updateBoardId');
      if (inp) inp.value = b.getAttribute('data-update-board');
      var m = document.getElementById('updateModal');
      if (m) m.classList.add('open');
    });
  });

  // dropzones: drag & drop a zip, show file name + "إضافة" button
  $$('[data-dropzone]').forEach(function (dz) {
    var input = dz.querySelector('input[type=file]'), fileEl = dz.querySelector('[data-dz-file]'), addEl = dz.querySelector('[data-dz-add]');
    function show(file) {
      if (!file) return;
      if (fileEl) fileEl.textContent = '📦 ' + file.name + ' (' + Math.round(file.size / 1024) + ' KB)';
      if (addEl) addEl.style.display = 'block';
    }
    if (input) input.addEventListener('change', function () { show(input.files[0]); });
    ['dragenter', 'dragover'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('over'); }); });
    dz.addEventListener('drop', function (e) {
      var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (f && input) { try { input.files = e.dataTransfer.files; } catch (err) {} show(f); }
    });
  });

  // 🌐 auto-translate helper for a single .i18n box
  function translateBox(box, btn, silentIfEmpty) {
    var ar = box.querySelector('.pane[data-lang="ar"] input, .pane[data-lang="ar"] textarea');
    var text = ar ? ar.value.trim() : '';
    if (!text) {
      if (!silentIfEmpty) alert('اكتب النص بالعربية أولاً.');
      return Promise.resolve();
    }
    var csrf = document.querySelector('input[name="_csrf"]');
    var old = btn ? btn.textContent : '';
    if (btn) { btn.textContent = '⏳ جارٍ الترجمة…'; btn.disabled = true; }
    var fd = new FormData();
    fd.append('m', 'settings'); fd.append('do', 'translate'); fd.append('target', 'all'); fd.append('text', text);
    if (csrf) fd.append('_csrf', csrf.value);
    return fetch('?m=settings', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var map = (j && j.translations) || {};
        $$('.pane', box).forEach(function (p) {
          var lang = p.getAttribute('data-lang');
          if (lang === 'ar' || !map[lang]) return;
          var inp = p.querySelector('input,textarea');
          if (inp) { inp.value = map[lang]; inp.dispatchEvent(new Event('input', { bubbles: true })); }
        });
        if (btn) { btn.textContent = '✓ تمت الترجمة'; setTimeout(function () { btn.textContent = old; btn.disabled = false; }, 1400); }
      })
      .catch(function () {
        if (btn) { btn.textContent = old; btn.disabled = false; }
      });
  }

  $$('[data-autotranslate]').forEach(function (b) {
    b.addEventListener('click', function () {
      var box = b.closest('.i18n');
      if (box) translateBox(box, b, false);
    });
  });

  $$('[data-autotranslate-all]').forEach(function (b) {
    b.addEventListener('click', function () {
      var scope = b.closest('form') || document;
      var boxes = $$('.i18n', scope);
      if (!boxes.length) return;
      var old = b.textContent; b.textContent = '⏳ جارٍ ترجمة كل الحقول…'; b.disabled = true;
      var chain = Promise.resolve();
      boxes.forEach(function (box) {
        var subBtn = box.querySelector('[data-autotranslate]');
        chain = chain.then(function () { return translateBox(box, subBtn, true); });
      });
      chain.then(function () {
        b.textContent = '✓ تمت ترجمة الحقول';
        setTimeout(function () { b.textContent = old; b.disabled = false; }, 1600);
      });
    });
  });

  // "تطبيق بيانات الموديل": switch the save form's action to apply_model_data, then submit
  $$('[data-apply-model]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      e.preventDefault();
      var form = b.closest('form'); if (!form) return;
      var doInp = form.querySelector('input[name=do]');
      if (doInp) doInp.value = 'apply_model_data';
      form.submit();
    });
  });


  // GitHub token: reveal + copy (Settings and per-app panel). The full value lives in a hidden input only on admin pages.
  $$('[data-gh-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var box = b.closest('.row, .panel') || document;
      var full = box.querySelector('[data-gh-full]');
      if (!full || !full.value) return;
      var val = full.value, done = function () {
        var old = b.textContent; b.textContent = '✓ نُسخ';
        setTimeout(function () { b.textContent = old; }, 1500);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(val).then(done, fallback); else fallback();
      function fallback() {
        var t = document.createElement('textarea'); t.value = val; t.style.position = 'fixed'; t.style.opacity = '0';
        document.body.appendChild(t); t.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(t); done();
      }
    });
  });
  $$('[data-gh-reveal]').forEach(function (b) {
    b.addEventListener('click', function () {
      var box = b.closest('.row, .panel') || document;
      var full = box.querySelector('[data-gh-full]'), mask = box.querySelector('[data-gh-mask]');
      if (!full || !mask) return;
      var shown = mask.getAttribute('data-shown') === '1';
      if (shown) { mask.textContent = mask.getAttribute('data-mask') || '••••'; mask.setAttribute('data-shown', '0'); b.textContent = '👁 إظهار'; }
      else {
        mask.setAttribute('data-mask', mask.textContent); mask.textContent = full.value; mask.setAttribute('data-shown', '1'); b.textContent = '🙈 إخفاء';
      }
    });
  });

  // instant search inside Site Guide (تعليمات الموقع)
  $$('[data-guide-filter]').forEach(function (inp) {
    var items = $$('[data-guide-item]');
    inp.addEventListener('input', function () {
      var q = inp.value.trim().toLowerCase();
      items.forEach(function (el) {
        el.style.display = (!q || (el.getAttribute('data-search') || '').indexOf(q) !== -1) ? '' : 'none';
      });
    });
  });
})();
