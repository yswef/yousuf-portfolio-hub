/* Loaded synchronously in <head>: applies the saved theme before first paint (no flash). */
(function () {
  var d = document.documentElement; d.classList.add('js');
  var saved = null; try { saved = localStorage.getItem('theme'); } catch (e) {}
  var def = (document.querySelector('meta[name="theme-default"]') || {}).content || 'system';
  var dark = saved ? saved === 'dark' : def === 'dark' ? true : def === 'light' ? false : !!(window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
  d.classList.toggle('dark', dark);
})();
