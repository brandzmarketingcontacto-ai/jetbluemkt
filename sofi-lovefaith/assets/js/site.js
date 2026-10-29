/* Sofi Love & Faith — storefront interactions */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  // Mobile menu
  var mb = $('[data-menu]'), nav = $('#main-nav');
  if (mb && nav) mb.addEventListener('click', function () {
    var open = nav.classList.toggle('open');
    mb.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  // Auto-submit filters / cart quantities
  $$('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form && el.form.submit(); });
  });

  // Price range label
  $$('[data-range]').forEach(function (r) {
    var lbl = r.closest('label').querySelector('[data-range-label]');
    if (!lbl) return;
    var tpl = lbl.getAttribute('data-range-label');
    r.addEventListener('input', function () { lbl.textContent = tpl.replace('%', '$' + r.value); });
  });

  // Countdowns
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  var cds = $$('[data-ends]');
  function tick() {
    var now = Date.now();
    cds.forEach(function (cd) {
      var left = Math.max(0, Math.floor((new Date(cd.getAttribute('data-ends')).getTime() - now) / 1000));
      var v = { d: Math.floor(left / 86400), h: Math.floor(left % 86400 / 3600), m: Math.floor(left % 3600 / 60), s: left % 60 };
      Object.keys(v).forEach(function (k) { var el = cd.querySelector('[data-u="' + k + '"]'); if (el) el.textContent = pad(v[k]); });
    });
  }
  if (cds.length) { tick(); setInterval(tick, 1000); }

  // Product page
  var pdp = $('[data-pdp]');
  if (pdp) {
    // gallery
    var slides = $$('[data-slide]', pdp), thumbs = $$('[data-thumb]', pdp);
    function show(i) {
      slides.forEach(function (s, j) {
        var on = j === i;
        s.hidden = !on; s.classList.toggle('on', on);
        var v = s.querySelector('video'); if (v) { if (on) { v.play && v.play().catch(function () {}); } else v.pause(); }
        var f = s.querySelector('iframe[data-src]'); if (f && on && !f.src) f.src = f.getAttribute('data-src');
      });
      thumbs.forEach(function (t, j) { t.classList.toggle('on', j === i); t.setAttribute('aria-selected', j === i ? 'true' : 'false'); });
    }
    thumbs.forEach(function (t) { t.addEventListener('click', function () { show(+t.getAttribute('data-thumb')); }); });
    show(0);

    // lightbox
    var lb = $('[data-lightbox]');
    function openLb() {
      var img = $('.slide.on img', pdp); if (!img || !lb) return;
      lb.querySelector('img').src = img.src; lb.querySelector('img').alt = img.alt; lb.hidden = false; lb.querySelector('button').focus();
    }
    $$('[data-zoomable]', pdp).forEach(function (i) { i.addEventListener('click', openLb); });
    var zb = $('[data-zoom]', pdp); if (zb) zb.addEventListener('click', openLb);
    if (lb) {
      lb.addEventListener('click', function (e) { if (e.target === lb || e.target.closest('[data-lightbox-close]')) lb.hidden = true; });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape') lb.hidden = true; });
    }

    // quantity
    var q = $('[data-qty] input', pdp);
    var dec = $('[data-dec]', pdp), inc = $('[data-inc]', pdp);
    if (q) {
      dec.addEventListener('click', function () { q.value = Math.max(1, (+q.value || 1) - 1); });
      inc.addEventListener('click', function () { q.value = Math.min(+q.max || 99, (+q.value || 1) + 1); });
    }

    // gift message
    var gt = $('[data-gift-toggle]', pdp), gm = $('[data-gift-msg]', pdp);
    if (gt && gm) gt.addEventListener('change', function () { gm.hidden = !gt.checked; if (gt.checked) gm.focus(); });

    // variants
    var variants = JSON.parse(pdp.getAttribute('data-variants') || '[]');
    var money = pdp.getAttribute('data-money') || '$';
    var opts = $$('[data-opt]', pdp);
    var vin = $('[data-variant-input]', pdp), add = $('[data-add]', pdp);
    var addLabel = add ? add.textContent : '';
    function fmt(n) { return money + Number(n).toFixed(2); }
    function syncVariant() {
      if (!opts.length) return;
      var key = opts.map(function (o) {
        var c = o.querySelector('input:checked');
        var lbl = o.querySelector('[data-opt-label]'); if (lbl && c) lbl.textContent = c.getAttribute('data-label');
        return c ? c.value : '0';
      }).join('-');
      var v = variants.filter(function (x) { return x.key === key; })[0];
      if (vin) vin.value = v ? key : '';
      $$('[data-price]').forEach(function (el) { el.textContent = fmt(v ? v.price : pdp.getAttribute('data-base-price')); });
      if (add) {
        var out = v && v.stock <= 0;
        add.disabled = out || (variants.length && !v);
        add.textContent = out ? (document.documentElement.lang === 'es' ? 'Agotado' : 'Out of stock') : addLabel;
      }
      if (v && v.image) {
        var img = $('.slide.on img', pdp); if (img) img.src = v.image;
      }
    }
    opts.forEach(function (o) { o.addEventListener('change', syncVariant); });
    syncVariant();

    // sticky add-to-cart (mobile)
    var sa = $('[data-sticky-add]');
    if (sa) sa.addEventListener('click', function () {
      var f = $('[data-buy-form]'); if (f) { if (f.requestSubmit) f.requestSubmit(add); else f.submit(); }
    });
  }

  // First-visit language modal: remember choice
  var lm = $('[data-lang-modal]');
  if (lm) {
    var first = lm.querySelector('a'); if (first) first.focus();
  }
})();
