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

  // per-language tabs
  $$('.i18n').forEach(function (box) {
    var tabs = $$('.tab', box), panes = $$('.pane', box);
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
})();
