/* Sofi — product editor: media manager, variants, live preview, completeness */
(function () {
  'use strict';
  var A = window.Admin, esc = A.esc;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var form = $('#product-form'); if (!form) return;
  var D = JSON.parse($('#product-data').textContent);
  var media = D.media || [];
  var options = D.options || [];
  var variants = D.variants || [];
  var selMedia = media.length ? 0 : -1;
  var curLang = 'en';
  var dirty = function () { if (form._markDirty) form._markDirty(); };
  var money = function (n) { return D.money + (Number(n) || 0).toFixed(2); };
  var num = function (el) { var v = parseFloat(String(el && el.value || '').replace(/[^0-9.]/g, '')); return isNaN(v) ? 0 : v; };
  document.addEventListener('langtab', function (e) { curLang = e.detail; live(); });

  /* ---------- rich text ---------- */
  $$('.rte').forEach(function (rte) {
    var area = $('.rte-area', rte), ta = $('textarea', rte);
    var sync = function () { ta.value = area.innerHTML.trim() === '<br>' ? '' : area.innerHTML; };
    area.addEventListener('input', function () { sync(); dirty(); score(); });
    $$('[data-cmd]', rte).forEach(function (b) {
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () {
        var cmd = b.getAttribute('data-cmd'), arg = null;
        if (cmd === 'createLink') { arg = prompt('Dirección del enlace (https://…)'); if (!arg) return; }
        area.focus(); document.execCommand(cmd, false, arg); sync(); dirty();
      });
    });
    var cp = $('[data-copy-from]', rte);
    if (cp) cp.addEventListener('click', function () {
      var src = $('[data-rte="description_' + cp.getAttribute('data-copy-from') + '"]');
      if (src && (!area.textContent.trim() || confirm('¿Reemplazar la descripción actual?'))) { area.innerHTML = src.innerHTML; sync(); dirty(); score(); }
    });
  });

  /* ---------- slug ---------- */
  var slug = $('[data-slug]'), slugSrc = $('[data-slug-source]'), slugTouched = !!(slug && slug.value);
  function slugify(s) { return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''); }
  if (slug) slug.addEventListener('input', function () { slugTouched = true; slug.value = slugify(slug.value) || slug.value; google(); });
  if (slugSrc) slugSrc.addEventListener('input', function () { if (!slugTouched) slug.value = slugify(slugSrc.value); google(); });

  /* ---------- media manager ---------- */
  var grid = $('[data-media-grid]'), detail = $('[data-media-detail]'), dz = $('[data-dropzone]'), fi = $('[data-file-input]');
  var prog = $('[data-upload-progress]');
  function thumbOf(m) { return m.kind === 'video' ? (m.posterUrl || '') : (m.kind === 'embed' ? (m.thumb || '') : m.url); }
  function kindLabel(k) { return { image: 'Foto', gif: 'GIF', video: 'Video', embed: 'YouTube' }[k] || k; }
  function syncMedia() {
    $('[data-media-json]').value = JSON.stringify(media.map(function (m) { return { path: m.path, kind: m.kind, poster: m.poster || '', alt_en: m.alt_en || '', alt_es: m.alt_es || '' }; }));
  }
  function renderMedia() {
    grid.innerHTML = '';
    media.forEach(function (m, i) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'mtile' + (i === selMedia ? ' on' : ''); b.draggable = true; b.dataset.i = i;
      b.setAttribute('aria-label', kindLabel(m.kind) + ' ' + (i + 1) + (i === 0 ? ' (portada)' : ''));
      var t = thumbOf(m);
      b.innerHTML = (m.kind === 'video' && !t ? '<video src="' + esc(m.url) + '" muted preload="metadata"></video>' : (t ? '<img src="' + esc(t) + '" alt="">' : '<span class="ph">' + kindLabel(m.kind) + '</span>')) +
        '<span class="k">' + kindLabel(m.kind) + '</span>' + (i === 0 ? '<span class="cover">Portada</span>' : '') +
        ((m.kind === 'video' || m.kind === 'embed') ? '<span class="play"><span>▶</span></span>' : '') + '<span class="grip2" aria-hidden="true">⋮⋮</span>';
      b.addEventListener('click', function () { selMedia = i; renderMedia(); });
      grid.appendChild(b);
    });
    renderDetail(); syncMedia(); live(); score();
  }
  var dragM = null;
  grid.addEventListener('dragstart', function (e) { var t = e.target.closest('.mtile'); if (!t) return; dragM = +t.dataset.i; t.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', 'm'); });
  grid.addEventListener('dragover', function (e) { if (dragM === null) return; e.preventDefault(); $$('.drop', grid).forEach(function (x) { x.classList.remove('drop'); }); var t = e.target.closest('.mtile'); if (t) t.classList.add('drop'); });
  grid.addEventListener('drop', function (e) {
    if (dragM === null) return; e.preventDefault(); e.stopPropagation();
    var t = e.target.closest('.mtile'); if (!t) return;
    var to = +t.dataset.i; var item = media.splice(dragM, 1)[0]; media.splice(to, 0, item);
    selMedia = to; dragM = null; renderMedia(); dirty();
  });
  grid.addEventListener('dragend', function () { dragM = null; $$('.dragging,.drop', grid).forEach(function (x) { x.classList.remove('dragging', 'drop'); }); });

  function addFiles(files) {
    if (!files || !files.length) return;
    A.upload(files, prog).then(function (res) {
      res.forEach(function (f) { media.push({ path: f.path, url: f.url, kind: f.kind, poster: '', posterUrl: '', alt_en: '', alt_es: '' }); });
      if (selMedia < 0) selMedia = 0;
      renderMedia(); dirty();
      A.toast(res.length + ' archivo(s) agregado(s)', 'ok');
    }).catch(function (e) { A.toast(e.message, 'bad'); });
  }
  fi.addEventListener('change', function () { addFiles(fi.files); fi.value = ''; });
  ['dragenter', 'dragover'].forEach(function (ev) { dz.addEventListener(ev, function (e) { if (dragM !== null) return; e.preventDefault(); dz.classList.add('over'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { dz.addEventListener(ev, function () { dz.classList.remove('over'); }); });
  dz.addEventListener('drop', function (e) { if (dragM !== null) return; e.preventDefault(); addFiles(e.dataTransfer.files); });
  // also accept files dropped anywhere on the media card
  var mediaCard = $('#media');
  mediaCard.addEventListener('dragover', function (e) { if (dragM === null && e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') >= 0) e.preventDefault(); });
  mediaCard.addEventListener('drop', function (e) { if (dragM === null && e.dataTransfer.files.length) { e.preventDefault(); addFiles(e.dataTransfer.files); } });

  $('[data-media-library]').addEventListener('click', function () {
    A.pick({ multiple: true }).then(function (sel) {
      sel.forEach(function (f) { media.push({ path: f.path, url: f.url, kind: f.kind, poster: '', posterUrl: '', alt_en: '', alt_es: '' }); });
      if (sel.length) { if (selMedia < 0) selMedia = 0; renderMedia(); dirty(); }
    });
  });
  $('[data-media-url]').addEventListener('click', function () {
    var u = prompt('Pega el enlace del video de YouTube o Vimeo:');
    if (!u) return;
    var m = u.match(/(?:youtube\.com\/(?:watch\?v=|shorts\/|embed\/)|youtu\.be\/)([A-Za-z0-9_-]{6,})/);
    if (!m && !/vimeo\.com\/\d+/.test(u)) { A.toast('Ese enlace no parece de YouTube o Vimeo.', 'bad'); return; }
    media.push({ path: u.trim(), url: u.trim(), kind: 'embed', poster: '', posterUrl: '', alt_en: '', alt_es: '', thumb: m ? 'https://img.youtube.com/vi/' + m[1] + '/hqdefault.jpg' : '' });
    selMedia = media.length - 1; renderMedia(); dirty();
  });

  function renderDetail() {
    var m = media[selMedia];
    if (!m) { detail.hidden = true; return; }
    detail.hidden = false;
    var t = thumbOf(m);
    var file = m.kind === 'embed' ? m.path : m.path.split('/').pop();
    detail.innerHTML = (m.kind === 'video' ? '<video src="' + esc(m.url) + '" muted controls preload="metadata"></video>' : (t ? '<img src="' + esc(t) + '" alt="">' : '<span class="ph"></span>')) +
      '<div class="md-body"><div class="row-gap"><strong class="ell">' + esc(file) + '</strong><span class="st st-draft">' + kindLabel(m.kind) + '</span>' + (selMedia === 0 ? '<span class="st st-new">Portada</span>' : '') + '</div>' +
      '<div class="grid-2 tight"><label class="fld">Texto alternativo (inglés)<input type="text" data-alt="en" value="' + esc(m.alt_en || '') + '" placeholder="Describe la foto en inglés"></label>' +
      '<label class="fld">Texto alternativo (español)<input type="text" data-alt="es" value="' + esc(m.alt_es || '') + '" placeholder="Describe la foto"></label></div>' +
      '<div class="row-gap">' + (selMedia > 0 ? '<button type="button" class="btn btn-ghost sm" data-cover>Usar como portada</button>' : '') +
      (m.kind === 'video' ? '<button type="button" class="btn btn-ghost sm" data-poster>' + (m.poster ? 'Cambiar' : 'Elegir') + ' imagen de portada del video</button>' : '') +
      '<button type="button" class="btn btn-ghost sm" data-left' + (selMedia === 0 ? ' disabled' : '') + '>← Mover</button><button type="button" class="btn btn-ghost sm" data-right' + (selMedia === media.length - 1 ? ' disabled' : '') + '>Mover →</button>' +
      '<button type="button" class="btn btn-danger sm" data-remove>Quitar del producto</button></div></div>';
    $$('[data-alt]', detail).forEach(function (inp) {
      inp.addEventListener('input', function () { m['alt_' + inp.getAttribute('data-alt')] = inp.value; syncMedia(); dirty(); score(); });
    });
    var c = $('[data-cover]', detail); if (c) c.addEventListener('click', function () { media.unshift(media.splice(selMedia, 1)[0]); selMedia = 0; renderMedia(); dirty(); });
    var l = $('[data-left]', detail); if (l) l.addEventListener('click', function () { if (selMedia < 1) return; media.splice(selMedia - 1, 0, media.splice(selMedia, 1)[0]); selMedia--; renderMedia(); dirty(); });
    var r = $('[data-right]', detail); if (r) r.addEventListener('click', function () { if (selMedia >= media.length - 1) return; media.splice(selMedia + 1, 0, media.splice(selMedia, 1)[0]); selMedia++; renderMedia(); dirty(); });
    var p = $('[data-poster]', detail); if (p) p.addEventListener('click', function () {
      A.pick({ kind: 'image' }).then(function (sel) { if (sel.length) { m.poster = sel[0].path; m.posterUrl = sel[0].url; renderMedia(); dirty(); } });
    });
    $('[data-remove]', detail).addEventListener('click', function () {
      media.splice(selMedia, 1); selMedia = Math.min(selMedia, media.length - 1); renderMedia(); dirty();
    });
  }

  /* ---------- prices ---------- */
  var priceEl = $('[data-price]'), cmpEl = $('[data-compare]'), costEl = $('[data-cost]'), chips = $('[data-price-chips]');
  function prices() {
    var p = num(priceEl), c = num(cmpEl), k = num(costEl), h = '';
    if (c > p && p > 0) h += '<span class="chip rose">Etiqueta automática: -' + Math.round(100 - p / c * 100) + '%</span><span class="chip">El cliente ahorra ' + money(c - p) + '</span>';
    else if (cmpEl.value && c <= p) h += '<span class="chip todo">El precio “antes” debe ser mayor que el precio de venta</span>';
    if (k > 0 && p > 0) h += '<span class="chip">Ganancia: ' + money(p - k) + ' · margen ' + Math.round((p - k) / p * 100) + '%</span>';
    chips.innerHTML = h; live(); score();
  }
  [priceEl, cmpEl, costEl].forEach(function (el) { el.addEventListener('input', prices); });

  /* ---------- options & variants ---------- */
  var optBox = $('[data-options]'), varBody = $('[data-variants]'), varWrap = $('[data-variants-wrap]');
  function comboNames(combo) { return combo.map(function (vi, oi) { return (options[oi].values[vi] || {}).en || ''; }).join(' / '); }
  function rebuildVariants() {
    var byName = {};
    variants.forEach(function (v) { byName[v.name] = v; });
    var combos = [[]];
    options.forEach(function (o) {
      var next = [];
      combos.forEach(function (c) { (o.values || []).forEach(function (v, vi) { next.push(c.concat([vi])); }); });
      combos = next;
    });
    if (!options.length || !options.every(function (o) { return o.values && o.values.length; })) combos = [];
    var sku = ($('[name=sku]').value || 'SKU');
    variants = combos.map(function (c) {
      var name = comboNames(c), old = byName[name] || {};
      return { key: c.join('-'), name: name, price: old.price || '', stock: old.stock !== undefined ? old.stock : '', sku: old.sku || (sku + '-' + c.join('')), image: old.image || '' };
    });
  }
  function syncOptions() {
    $('[data-options-json]').value = JSON.stringify(options);
    $('[data-variants-json]').value = JSON.stringify(variants);
  }
  function renderOptions() {
    optBox.innerHTML = '';
    options.forEach(function (o, oi) {
      var row = document.createElement('div'); row.className = 'option-row';
      row.innerHTML = '<div class="option-head"><label class="fld">Nombre (inglés)<input type="text" data-on="en" value="' + esc(o.name_en || '') + '" placeholder="Color, Size, Length…"></label>' +
        '<label class="fld">Nombre (español)<input type="text" data-on="es" value="' + esc(o.name_es || '') + '" placeholder="Color, Talla, Largo…"></label>' +
        '<label class="check"><input type="checkbox" data-colors ' + (o.values.some(function (v) { return v.color; }) ? 'checked' : '') + '> Mostrar como colores</label>' +
        '<button type="button" class="icon-btn danger" data-rm-opt aria-label="Quitar opción">✕</button></div>' +
        '<div class="values">' + o.values.map(function (v, vi) {
          var colors = o.values.some(function (x) { return x.color; });
          return '<span class="val-chip">' + (colors ? '<input type="color" data-vc="' + vi + '" value="' + esc(v.color || '#D9B567') + '" aria-label="Color">' : '') +
            '<input type="text" data-ve="' + vi + '" value="' + esc(v.en || '') + '" placeholder="EN" aria-label="Valor en inglés"><input type="text" data-vs="' + vi + '" value="' + esc(v.es || '') + '" placeholder="ES" aria-label="Valor en español">' +
            '<button type="button" data-rm-val="' + vi + '" aria-label="Quitar valor">✕</button></span>';
        }).join('') + '<button type="button" class="btn btn-ghost sm" data-add-val>+ Valor</button></div>';
      optBox.appendChild(row);
      $$('[data-on]', row).forEach(function (inp) { inp.addEventListener('input', function () { o['name_' + inp.getAttribute('data-on')] = inp.value; syncOptions(); dirty(); }); });
      $('[data-colors]', row).addEventListener('change', function (e) { o.values.forEach(function (v) { v.color = e.target.checked ? (v.color || '#D9B567') : ''; }); renderOptions(); });
      $('[data-rm-opt]', row).addEventListener('click', function () { options.splice(oi, 1); rebuildVariants(); renderOptions(); dirty(); });
      $('[data-add-val]', row).addEventListener('click', function () { o.values.push({ en: '', es: '', color: o.values.some(function (v) { return v.color; }) ? '#C9CBCF' : '' }); rebuildVariants(); renderOptions(); dirty(); var ins = $$('[data-ve]', row); });
      $$('[data-ve]', row).forEach(function (inp) {
        inp.addEventListener('input', function () { o.values[+inp.getAttribute('data-ve')].en = inp.value; });
        inp.addEventListener('change', function () { var old = variants.slice(); rebuildVariants(); renderVariants(); dirty(); });
      });
      $$('[data-vs]', row).forEach(function (inp) { inp.addEventListener('input', function () { o.values[+inp.getAttribute('data-vs')].es = inp.value; syncOptions(); dirty(); }); });
      $$('[data-vc]', row).forEach(function (inp) { inp.addEventListener('input', function () { o.values[+inp.getAttribute('data-vc')].color = inp.value; syncOptions(); dirty(); }); });
      $$('[data-rm-val]', row).forEach(function (b) { b.addEventListener('click', function () { o.values.splice(+b.getAttribute('data-rm-val'), 1); rebuildVariants(); renderOptions(); dirty(); }); });
    });
    renderVariants();
  }
  function renderVariants() {
    varWrap.hidden = !variants.length;
    varBody.innerHTML = '';
    variants.forEach(function (v) {
      var tr = document.createElement('tr');
      tr.innerHTML = '<td><strong>' + esc(v.name) + '</strong></td>' +
        '<td><button type="button" class="var-img" data-vimg aria-label="Foto de la variante">' + (v.image ? '<img src="' + esc(A.media(v.image)) + '" alt="">' : '+') + '</button></td>' +
        '<td><span class="money"><i>' + esc(D.money) + '</i><input type="text" inputmode="decimal" data-vp value="' + esc(v.price) + '" placeholder="' + esc(priceEl.value || '0.00') + '" aria-label="Precio ' + esc(v.name) + '"></span></td>' +
        '<td><input type="number" min="0" class="w-80" data-vstock value="' + esc(v.stock) + '" aria-label="Stock ' + esc(v.name) + '"></td>' +
        '<td><input type="text" data-vsku value="' + esc(v.sku) + '" aria-label="SKU ' + esc(v.name) + '"></td>';
      $('[data-vp]', tr).addEventListener('input', function (e) { v.price = e.target.value; syncOptions(); dirty(); });
      $('[data-vstock]', tr).addEventListener('input', function (e) { v.stock = e.target.value === '' ? '' : +e.target.value; syncOptions(); dirty(); });
      $('[data-vsku]', tr).addEventListener('input', function (e) { v.sku = e.target.value; syncOptions(); dirty(); });
      $('[data-vimg]', tr).addEventListener('click', function () {
        A.pick({ kind: 'image' }).then(function (sel) { if (sel.length) { v.image = sel[0].path; renderVariants(); dirty(); } });
      });
      varBody.appendChild(tr);
    });
    syncOptions(); score();
  }
  $('[data-add-option]').addEventListener('click', function () {
    var first = !options.length;
    options.push(first ? { name_en: 'Color', name_es: 'Color', values: [{ en: 'Gold', es: 'Dorado', color: '#D9B567' }, { en: 'Silver', es: 'Plateado', color: '#C9CBCF' }] }
      : { name_en: '', name_es: '', values: [{ en: '', es: '', color: '' }] });
    rebuildVariants(); renderOptions(); dirty();
  });

  /* ---------- bundle ---------- */
  var bt = $('[data-bundle-toggle]'), bp = $('[data-bundle-pick]');
  function bundle() { if (bt && bp) bp.hidden = !bt.checked; }
  if (bt) bt.addEventListener('change', bundle); bundle();

  /* ---------- live preview ---------- */
  function cover() {
    for (var i = 0; i < media.length; i++) { var t = thumbOf(media[i]); if (t) return t; }
    return '';
  }
  function live() {
    var name = ($('[name=name_' + curLang + ']').value || $('[name=name_en]').value || $('[name=name_es]').value || 'Nombre del producto');
    $('[data-live-title]').textContent = name;
    $('[data-live-name]').textContent = $('[name=name_es]').value || $('[name=name_en]').value || 'Nuevo producto';
    var p = num(priceEl), c = num(cmpEl);
    $('[data-live-price]').textContent = money(p);
    $('[data-live-compare]').textContent = c > p ? money(c) : '';
    var badge = $('[name=badge_' + curLang + ']').value || (c > p && p > 0 ? '-' + Math.round(100 - p / c * 100) + '%' : '');
    var lb = $('[data-live-badge]'); lb.hidden = !badge; lb.textContent = badge;
    var cv = cover(), img = $('[data-live-img]');
    img.hidden = !cv; $('[data-live-ph]').hidden = !!cv; if (cv) img.src = cv;
    var cat = $('[name="cats[]"]:checked');
    $('[data-live-cat]').textContent = [cat ? cat.getAttribute('data-cat-name') : '', $('[data-type]').value].filter(Boolean).join(' · ');
    google();
  }
  function google() {
    var s = (slug.value || slugify($('[name=name_en]').value || ''));
    $('[data-g-url]').textContent = D.siteUrl.replace(/^https?:\/\//, '') + ' › product › ' + s;
    var t = $('[name=seo_title_' + curLang + ']').value || (($('[name=name_' + curLang + ']').value || $('[name=name_en]').value) + ' | Sofi Love & Faith');
    $('[data-g-title]').textContent = t;
    var d = $('[name=seo_desc_' + curLang + ']').value || (($('[name=tagline_' + curLang + ']').value || '') + ' ' + ($('[data-rte="description_' + curLang + '"]') || { textContent: '' }).textContent).trim();
    $('[data-g-desc]').textContent = d.length > 158 ? d.slice(0, 156) + '…' : d;
  }
  form.addEventListener('input', function () { live(); score(); });
  form.addEventListener('change', function () { live(); score(); });

  /* ---------- completeness score ---------- */
  function score() {
    var rteText = function (l) { var el = $('[data-rte="description_' + l + '"]'); return el ? el.textContent.trim() : ''; };
    var images = media.filter(function (m) { return m.kind === 'image' || m.kind === 'gif'; });
    var checks = [
      ['Nombre en inglés y español', $('[name=name_en]').value.trim() && $('[name=name_es]').value.trim(), '#info'],
      ['Al menos 1 foto', images.length >= 1, '#media'],
      ['3 o más fotos/videos', media.length >= 3, '#media'],
      ['Precio', num(priceEl) > 0, '#price'],
      ['Descripción EN/ES', rteText('en') && rteText('es'), '#info'],
      ['Categoría', !!$('[name="cats[]"]:checked'), '#product-form .pe-side'],
      ['Texto alternativo en fotos', images.length && images.every(function (m) { return (m.alt_en || m.alt_es); }), '#media'],
      ['Descripción SEO', $('[name=seo_desc_en]').value.trim() || $('[name=seo_desc_es]').value.trim(), '#seo'],
    ];
    var ok = checks.filter(function (c) { return c[1]; }).length, pct = Math.round(ok / checks.length * 100);
    var ring = $('[data-score-ring]'); ring.style.setProperty('--p', pct); $('[data-score]').textContent = pct + '%';
    $('[data-score-title]').textContent = pct === 100 ? '¡Listo para vender!' : pct >= 70 ? 'Listo para vender — casi completo' : 'Completa tu producto para vender más';
    var box = $('[data-score-chips]'); box.innerHTML = '';
    checks.forEach(function (c) {
      var el = document.createElement(c[1] ? 'span' : 'button');
      el.className = 'chip ' + (c[1] ? 'ok' : 'todo'); el.textContent = (c[1] ? '✓ ' : '! ') + c[0];
      if (!c[1]) { el.type = 'button'; el.addEventListener('click', function () { var t = document.querySelector(c[2]); if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' }); }); }
      box.appendChild(el);
    });
  }

  /* ---------- submit ---------- */
  form.addEventListener('submit', function (e) {
    $$('.rte').forEach(function (r) { var a = $('.rte-area', r); $('textarea', r).value = a.innerHTML.trim() === '<br>' ? '' : a.innerHTML; });
    syncMedia(); syncOptions();
    if (num(priceEl) <= 0 && $('[name=status]').value === 'published') {
      if (!confirm('El precio es $0. ¿Publicar de todos modos?')) { e.preventDefault(); return; }
    }
    var tab = $('[data-tab-field]'); var sec = document.activeElement && document.activeElement.closest('.card[id]'); if (tab && sec) tab.value = sec.id;
  });
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); if (form.requestSubmit) form.requestSubmit(); else form.submit(); }
  });

  renderMedia(); renderOptions(); prices(); live(); score();
})();
