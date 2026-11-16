(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); }, $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fine = window.matchMedia && matchMedia('(hover: hover) and (pointer: fine)').matches;
  var body = document.body;

  function run(fn) { try { fn(); } catch (e) { console.error('[' + fn.name + ']', e); } }

  function theme() {
    var t = $('#themeSwitch'); if (!t) return;
    t.checked = document.documentElement.classList.contains('dark');
    t.addEventListener('change', function () {
      document.documentElement.classList.toggle('dark', t.checked);
      try { localStorage.setItem('theme', t.checked ? 'dark' : 'light'); } catch (e) {}
    });
  }
  function nav() {
    var n = $('#navbar'); if (n) addEventListener('scroll', function () { n.classList.toggle('scrolled', scrollY > 30); }, { passive: true });
    var b = $('#navBurger'), d = $('#mobileDrawer'), c = $('#closeDrawer'); if (!b || !d) return;
    b.addEventListener('click', function () { d.classList.add('open'); });
    if (c) c.addEventListener('click', function () { d.classList.remove('open'); });
    $$('a', d).forEach(function (a) { a.addEventListener('click', function () { d.classList.remove('open'); }); });
    document.addEventListener('click', function (e) { var m = $('.langsw[open]'); if (m && !m.contains(e.target)) m.removeAttribute('open'); });
  }
  function reveal() {
    var items = $$('.reveal');
    if (!items.length || !window.IntersectionObserver) { items.forEach(function (el) { el.classList.add('in'); }); return; }
    var o = new IntersectionObserver(function (es) { es.forEach(function (x) { if (x.isIntersecting) { x.target.classList.add('in'); o.unobserve(x.target); } }); }, { threshold: .12 });
    items.forEach(function (el) { o.observe(el); });
  }
  function counters() {
    $$('[data-counter]').forEach(function (el) {
      var target = parseInt(el.dataset.counter, 10) || 0, cur = 0, step = Math.max(1, Math.ceil(target / 40));
      (function tick() { cur = Math.min(target, cur + step); el.textContent = cur; if (cur < target) requestAnimationFrame(tick); })();
    });
  }
  function typed() {
    var ty = $('[data-typed]'); if (!ty || reduce) return;
    var words = ty.getAttribute('data-typed').split('|'), wi = 0, ci = 0, del = false;
    (function tick() {
      var w = words[wi]; ty.textContent = w.slice(0, ci);
      if (!del && ci < w.length) { ci++; setTimeout(tick, 80); }
      else if (!del) { del = true; setTimeout(tick, 1400); }
      else if (ci > 0) { ci--; setTimeout(tick, 35); }
      else { del = false; wi = (wi + 1) % words.length; setTimeout(tick, 250); }
    })();
  }
  function filter() {
    var fi = $('[data-filter]'), fl = $('[data-filter-list]'); if (!fi || !fl) return;
    fi.addEventListener('input', function () {
      var q = fi.value.trim().toLowerCase();
      $$('[data-search]', fl).forEach(function (c) { c.style.display = !q || (c.getAttribute('data-search') || '').indexOf(q) > -1 ? '' : 'none'; });
    });
  }
  function print() { $$('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); }); }
  function glow() {
    if (!fine || body.dataset.glow !== '1') return;
    var g = document.createElement('div'); g.className = 'cursor-glow'; body.appendChild(g);
    document.addEventListener('mousemove', function (e) { g.style.left = e.clientX + 'px'; g.style.top = e.clientY + 'px'; });
  }
  function cursor() {
    if (!fine || reduce || body.dataset.cursor !== '1') return;
    var d = document.createElement('div'), r = document.createElement('div'); d.className = 'cur'; r.className = 'cur-r';
    body.appendChild(d); body.appendChild(r); body.classList.add('has-cur');
    var x = 0, y = 0, rx = 0, ry = 0;
    addEventListener('mousemove', function (e) { x = e.clientX; y = e.clientY; d.style.transform = 'translate(' + x + 'px,' + y + 'px)'; });
    (function loop() { rx += (x - rx) * .18; ry += (y - ry) * .18; r.style.transform = 'translate(' + rx + 'px,' + ry + 'px)'; requestAnimationFrame(loop); })();
    document.addEventListener('mouseover', function (e) { var t = e.target.closest && e.target.closest('a,button,summary,input,textarea,select'); r.classList.toggle('big', !!t); });
  }
  function tilt() {
    if (!fine || reduce || body.dataset.tilt !== '1') return;
    $$('.tilt').forEach(function (c) {
      c.addEventListener('mousemove', function (e) { var b = c.getBoundingClientRect(), px = (e.clientX - b.left) / b.width - .5, py = (e.clientY - b.top) / b.height - .5; c.style.transform = 'perspective(800px) rotateY(' + (px * 7) + 'deg) rotateX(' + (-py * 7) + 'deg) translateY(-4px)'; });
      c.addEventListener('mouseleave', function () { c.style.transform = ''; });
    });
  }
  [theme, nav, reveal, counters, typed, filter, print, glow, cursor, tilt].forEach(run);
})();
