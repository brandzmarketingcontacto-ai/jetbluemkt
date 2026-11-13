// Vista previa en vivo dentro del editor del panel.
(function () {
  if (window.parent === window) return;
  var parentOrigin = location.origin;
  function send(msg) { window.parent.postMessage(msg, parentOrigin); }

  var css = document.createElement('style');
  css.textContent =
    '[data-edit]{transition:outline-color .15s, background-color .3s;outline:2px dashed transparent;outline-offset:4px;border-radius:4px;cursor:pointer}' +
    '[data-edit]:hover{outline-color:rgba(229,50,45,.75)}' +
    '[data-edit].pv-active{outline:2px solid #E5322D;outline-offset:4px}' +
    '[data-edit].pv-flash{background-color:rgba(229,50,45,.18)}' +
    '.pv-tip{position:fixed;z-index:9999;pointer-events:none;background:#E5322D;color:#fff;font:600 11px Inter,sans-serif;padding:4px 8px;border-radius:6px;opacity:0;transition:opacity .12s}';
  document.head.appendChild(css);
  var tip = document.createElement('div');
  tip.className = 'pv-tip';
  tip.textContent = 'Clic para editar';
  document.body.appendChild(tip);

  function els(key) { return document.querySelectorAll('[data-edit="' + key + '"]'); }
  var band = document.querySelector('[data-hero-band]');

  function apply(key, value) {
    if (band && (key === 'hero_bg' || key === 'hero_bg_opacity' || key === 'hero_bg_blur')) {
      if (key === 'hero_bg') band.style.setProperty('--hero-bg', value ? 'url("' + value + '")' : 'none');
      if (key === 'hero_bg_opacity') band.style.setProperty('--hero-op', Math.max(0, Math.min(1, parseFloat(String(value).replace(',', '.')) || 0)));
      if (key === 'hero_bg_blur') band.style.setProperty('--hero-blur', Math.max(0, Math.min(40, parseInt(value, 10) || 0)) + 'px');
      return;
    }
    els(key).forEach(function (el) {
      var mode = el.getAttribute('data-mode') || 'text';
      if (mode === 'html') el.innerHTML = value;
      else if (mode === 'src') { if (value) el.src = value; }
      else if (mode === 'show') el.style.display = value === '1' ? '' : 'none';
      else el.textContent = value;
    });
  }

  function reveal(key, flash) {
    document.querySelectorAll('.pv-active').forEach(function (e) { e.classList.remove('pv-active'); });
    var list = els(key);
    if (!list.length && band && key.indexOf('hero_bg') === 0) list = [band];
    if (!list.length) return;
    var el = list[0];
    if (el.getAttribute) el.classList.add('pv-active');
    var r = el.getBoundingClientRect();
    if (r.top < 70 || r.bottom > innerHeight - 20) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (flash) { el.classList.add('pv-flash'); setTimeout(function () { el.classList.remove('pv-flash'); }, 500); }
  }

  window.addEventListener('message', function (e) {
    if (e.origin !== parentOrigin || !e.data || typeof e.data !== 'object') return;
    if (e.data.type === 'set') apply(e.data.key, e.data.value);
    if (e.data.type === 'reveal') reveal(e.data.key, e.data.flash);
  });

  // Clic en un elemento editable: abre su campo en el editor. Los enlaces y formularios no navegan.
  document.addEventListener('click', function (e) {
    var ed = e.target.closest('[data-edit]');
    var link = e.target.closest('a,button');
    if (ed) { e.preventDefault(); e.stopPropagation(); send({ type: 'focus', key: ed.getAttribute('data-edit') }); reveal(ed.getAttribute('data-edit')); return; }
    if (link) e.preventDefault();
  }, true);
  document.addEventListener('submit', function (e) { e.preventDefault(); }, true);

  document.addEventListener('mousemove', function (e) {
    var ed = e.target.closest('[data-edit]');
    tip.style.opacity = ed ? '1' : '0';
    if (ed) { tip.style.left = (e.clientX + 14) + 'px'; tip.style.top = (e.clientY + 14) + 'px'; }
  });

  // Conservar la posición al recargar tras guardar.
  var k = 'pv-scroll:' + location.pathname + location.search;
  try { var y = sessionStorage.getItem(k); if (y) window.scrollTo(0, parseInt(y, 10)); } catch (err) {}
  window.addEventListener('scroll', function () { try { sessionStorage.setItem(k, String(scrollY)); } catch (err) {} }, { passive: true });

  send({ type: 'ready' });
})();
