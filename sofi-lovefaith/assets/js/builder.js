/* Sofi — block page editor */
(function () {
  'use strict';
  var A = window.Admin;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var esc = A.esc;
  var root = $('#builder'); if (!root) return;
  var D = JSON.parse($('#builder-data').textContent);
  var S = D.schemas;

  var state = {
    blocks: D.blocks || [],
    seo: D.seo || {},
    sel: D.blocks && D.blocks.length ? 0 : -1,
    tab: 'content',
    lang: 'en',
    dirty: false,
  };
  var undo = [], redo = [], lastSnap = 0;

  var ICONS = {
    hero: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 15l3-3 3 3 3-4 3 4"/>',
    shield: '<path d="M12 3l7 4v5c0 4.5-3 8-7 9-4-1-7-4.5-7-9V7l7-4z"/>',
    cats: '<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/>',
    tag: '<path d="M20 12l-8 8-9-9V3h8l9 9z"/>',
    bag: '<path d="M5 8h14l-1.2 11.2a1 1 0 0 1-1 .8H7.2a1 1 0 0 1-1-.8L5 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
    text: '<path d="M4 5h16M4 10h16M4 15h10M4 20h7"/>',
    instagram: '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/>',
    image: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 15l3-3 3 3 3-4 3 4"/>',
    video: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M10 9l5 3-5 3z"/>',
    quote: '<path d="M4 5h16v11H8l-4 4z"/>',
    help: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.7M12 17h.01"/>',
    mail: '<path d="M3 5h18v14H3zM3 7l9 6 9-6"/>',
    form: '<path d="M4 5h16v14H4zM8 9h8M8 13h5"/>',
    spacer: '<path d="M4 12h16M12 4v4M12 16v4"/>',
    eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    eyeoff: '<path d="M3 3l18 18M10.6 6.1A10 10 0 0 1 12 6c6.5 0 10 6 10 6a17 17 0 0 1-3 3.6M6.6 6.6C3.8 8.4 2 12 2 12s3.5 7 10 7a9.8 9.8 0 0 0 4.4-1"/>',
    grip: '<circle cx="9" cy="6" r="1" fill="currentColor"/><circle cx="15" cy="6" r="1" fill="currentColor"/><circle cx="9" cy="12" r="1" fill="currentColor"/><circle cx="15" cy="12" r="1" fill="currentColor"/><circle cx="9" cy="18" r="1" fill="currentColor"/><circle cx="15" cy="18" r="1" fill="currentColor"/>',
    up: '<path d="M12 19V5M6 11l6-6 6 6"/>', down: '<path d="M12 5v14M6 13l6 6 6-6"/>',
    copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M4 16V4h12"/>',
    trash: '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>', plus: '<path d="M12 5v14M5 12h14"/>',
  };
  function ic(n, s) { return '<svg width="' + (s || 16) + '" height="' + (s || 16) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[n] || ICONS.text) + '</svg>'; }

  var list = $('[data-list]', root), lib = $('[data-lib]', root), insp = $('[data-inspector]', root);
  var frame = $('[data-frame]', root), wrap = $('[data-frame-wrap]', root), status = $('[data-status]', root);

  /* ---------- history ---------- */
  function snapshot(force) {
    var now = Date.now();
    if (!force && now - lastSnap < 1200) return;
    lastSnap = now;
    undo.push(JSON.stringify({ blocks: state.blocks, seo: state.seo }));
    if (undo.length > 60) undo.shift();
    redo = [];
  }
  function restore(json) {
    var s = JSON.parse(json);
    state.blocks = s.blocks; state.seo = s.seo;
    if (state.sel >= state.blocks.length) state.sel = state.blocks.length - 1;
    renderAll(); scheduleSave();
  }
  $('[data-undo]', root).addEventListener('click', doUndo);
  $('[data-redo]', root).addEventListener('click', doRedo);
  function doUndo() { if (!undo.length) return; redo.push(JSON.stringify({ blocks: state.blocks, seo: state.seo })); restore(undo.pop()); }
  function doRedo() { if (!redo.length) return; undo.push(JSON.stringify({ blocks: state.blocks, seo: state.seo })); restore(redo.pop()); }
  document.addEventListener('keydown', function (e) {
    if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 'z') return;
    if (/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) return;
    e.preventDefault(); e.shiftKey ? doRedo() : doUndo();
  });

  /* ---------- saving ---------- */
  var saveTimer = null, saving = false, pendingPublish = false;
  function setStatus(cls, text) { status.className = 'b-status ' + cls; status.innerHTML = '<span class="dot"></span>' + esc(text); }
  function scheduleSave() {
    state.dirty = true; setStatus('', 'Cambios sin guardar…');
    clearTimeout(saveTimer); saveTimer = setTimeout(function () { save(false); }, 900);
  }
  function save(publish) {
    clearTimeout(saveTimer);
    if (saving) { pendingPublish = pendingPublish || publish; setTimeout(function () { save(pendingPublish); }, 400); return Promise.resolve(); }
    saving = true; setStatus('saving', publish ? 'Publicando…' : 'Guardando borrador…');
    return fetch(D.saveUrl, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': A.csrf },
      body: JSON.stringify({ _csrf: A.csrf, slug: D.slug, blocks: state.blocks, seo: state.seo, publish: !!publish })
    }).then(function (r) { return r.json().then(function (j) { if (!r.ok || j.error) throw new Error(j.error || 'Error'); return j; }); })
      .then(function (j) {
        state.dirty = false; pendingPublish = false;
        setStatus('saved', publish ? 'Publicado ' + j.saved_at + ' ✓' : 'Borrador guardado ' + j.saved_at + ' · falta publicar');
        if (publish) A.toast('¡Cambios publicados! Ya los ven tus clientes.', 'ok');
        reloadFrame();
      })
      .catch(function (e) { setStatus('error', 'No se guardó: ' + e.message); A.toast(e.message, 'bad'); })
      .then(function () { saving = false; });
  }
  $('[data-publish]', root).addEventListener('click', function () { save(true); });
  window.addEventListener('beforeunload', function (e) { if (state.dirty) { e.preventDefault(); e.returnValue = ''; } });
  if (D.hasDraft) setStatus('', 'Hay un borrador sin publicar');

  /* ---------- preview frame ---------- */
  function reloadFrame() {
    try {
      var y = frame.contentWindow.scrollY;
      frame.onload = function () { hookFrame(); try { frame.contentWindow.scrollTo(0, y); } catch (e) {} highlight(); };
      frame.contentWindow.location.reload();
    } catch (e) { frame.src = D.previewUrl; }
  }
  function hookFrame() {
    var doc; try { doc = frame.contentDocument; } catch (e) { return; }
    if (!doc || !doc.body) return;
    var st = doc.createElement('style');
    st.textContent = '[data-block]{position:relative;cursor:pointer}[data-block]:hover{outline:2px dashed rgba(184,58,107,.5);outline-offset:-2px}[data-block].sofi-sel{outline:3px solid #B83A6B;outline-offset:-3px}.lang-modal,.wa-float,.preview-bar{display:none!important}';
    doc.head.appendChild(st);
    doc.addEventListener('click', function (e) {
      var el = e.target.closest('[data-block]');
      if (!el) { if (e.target.closest('a')) e.preventDefault(); return; }
      e.preventDefault();
      var id = el.getAttribute('data-block');
      var i = state.blocks.findIndex(function (b) { return b.id === id; });
      if (i >= 0) { state.sel = i; state.tab = 'content'; renderList(); renderInspector(); highlight(true); }
    }, true);
  }
  function highlight(scroll) {
    var doc; try { doc = frame.contentDocument; } catch (e) { return; }
    if (!doc) return;
    $$('.sofi-sel', doc).forEach(function (x) { x.classList.remove('sofi-sel'); });
    var b = state.blocks[state.sel]; if (!b) return;
    var el = doc.querySelector('[data-block="' + b.id + '"]');
    if (el) { el.classList.add('sofi-sel'); if (scroll) el.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
  }
  frame.addEventListener('load', function () { hookFrame(); highlight(); }, { once: true });
  $$('[data-device]', root).forEach(function (b) {
    b.addEventListener('click', function () {
      $$('[data-device]', root).forEach(function (x) { x.classList.toggle('on', x === b); });
      wrap.className = 'frame-wrap ' + b.getAttribute('data-device');
    });
  });
  $$('[data-lang]', root).forEach(function (b) {
    b.addEventListener('click', function () {
      state.lang = b.getAttribute('data-lang');
      $$('[data-lang]', root).forEach(function (x) { x.classList.toggle('on', x === b); });
      frame.src = D.previewUrl + '&lang=' + state.lang;
      frame.onload = function () { hookFrame(); highlight(); };
      renderInspector();
    });
  });

  /* ---------- blocks helpers ---------- */
  function uid() { return 'b' + Math.random().toString(16).slice(2, 10); }
  function newBlock(type) {
    var b = { id: uid(), type: type, visible: true, data: {}, i18n: { en: {}, es: {} }, style: { bg: 'default', pad: 'm', hide_mobile: false } };
    var defaults = {
      products: { data: { source: 'featured', count: 8 }, en: { title: 'Featured', link_text: 'Shop all →' }, es: { title: 'Destacados', link_text: 'Ver todo →' } },
      text: { data: { align: 'center' }, en: { title: 'A title', text: 'Write your text here.' }, es: { title: 'Un título', text: 'Escribe tu texto aquí.' } },
      image_text: { en: { title: 'A title', text: 'Tell your story.', cta_text: 'Shop now' }, es: { title: 'Un título', text: 'Cuenta tu historia.', cta_text: 'Comprar' }, data: { cta_link: '/shop' } },
      newsletter: { en: { title: 'Join our list', text: 'Offers and new arrivals, once in a while.', button: 'Subscribe' }, es: { title: 'Únete a nuestra lista', text: 'Ofertas y novedades de vez en cuando.', button: 'Suscribirme' } },
      testimonials: { en: { title: 'What our customers say', items: 'I love my necklace! | Customer' }, es: { title: 'Lo que dicen nuestros clientes', items: '¡Me encanta mi collar! | Cliente' } },
      faq: { en: { title: 'Frequently asked questions', items: 'Question? | Answer.' }, es: { title: 'Preguntas frecuentes', items: '¿Pregunta? | Respuesta.' } },
      spacer: { data: { height: 40 } },
      video: { en: { title: 'Watch' }, es: { title: 'Mira' } },
      offer_banner: { en: { badge: 'Limited-time offer', title: 'Special offer', cta_text: 'Shop now' }, es: { badge: 'Oferta por tiempo limitado', title: 'Oferta especial', cta_text: 'Comprar' }, data: { cta_link: '/shop?sale=1' } },
    }[type];
    if (defaults) { b.data = defaults.data || {}; b.i18n.en = defaults.en || {}; b.i18n.es = defaults.es || {}; }
    return b;
  }
  function blockTitle(b) {
    var t = (b.i18n && (b.i18n.es.title || b.i18n.en.title || b.i18n.es.script)) || '';
    return t ? String(t).split('\n')[0].slice(0, 28) : '';
  }

  /* ---------- left list ---------- */
  function renderList() {
    $('[data-count]', root).textContent = state.blocks.length + ' bloques';
    list.innerHTML = '';
    state.blocks.forEach(function (b, i) {
      var sc = S[b.type] || { label: b.type, icon: 'text' };
      var li = document.createElement('li');
      li.className = 'b-item' + (i === state.sel ? ' on' : '') + (b.visible === false ? ' off' : '');
      li.draggable = true; li.dataset.i = i;
      var sub = blockTitle(b);
      li.innerHTML = '<span class="grip" title="Arrastra para mover">' + ic('grip', 16) + '</span>' +
        '<button type="button" class="b-name" title="' + esc(sub) + '">' + ic(sc.icon) + '<span>' + esc(sc.label) + (sub ? ' <small class="muted">· ' + esc(sub) + '</small>' : '') + '</span></button>' +
        '<button type="button" class="icon-btn" data-vis aria-label="' + (b.visible === false ? 'Mostrar' : 'Ocultar') + ' bloque">' + ic(b.visible === false ? 'eyeoff' : 'eye') + '</button>';
      li.querySelector('.b-name').addEventListener('click', function () { state.sel = i; state.tab = 'content'; renderList(); renderInspector(); highlight(true); $('.b-pageset', root).classList.remove('on'); });
      li.querySelector('[data-vis]').addEventListener('click', function () { snapshot(true); b.visible = b.visible === false; renderList(); scheduleSave(); });
      list.appendChild(li);
    });
  }
  var dragI = null;
  list.addEventListener('dragstart', function (e) { var li = e.target.closest('.b-item'); if (!li) return; dragI = +li.dataset.i; li.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
  list.addEventListener('dragover', function (e) {
    e.preventDefault(); var li = e.target.closest('.b-item'); $$('.drop-before', list).forEach(function (x) { x.classList.remove('drop-before'); });
    if (li && +li.dataset.i !== dragI) li.classList.add('drop-before');
  });
  list.addEventListener('drop', function (e) {
    e.preventDefault(); var li = e.target.closest('.b-item'); if (!li || dragI === null) return;
    var to = +li.dataset.i; if (to === dragI) return;
    snapshot(true);
    var moved = state.blocks.splice(dragI, 1)[0];
    if (to > dragI) to--;
    state.blocks.splice(to, 0, moved);
    state.sel = to; renderAll(); scheduleSave();
  });
  list.addEventListener('dragend', function () { dragI = null; $$('.dragging,.drop-before', list).forEach(function (x) { x.classList.remove('dragging', 'drop-before'); }); });

  /* ---------- library ---------- */
  Object.keys(S).forEach(function (type) {
    var b = document.createElement('button');
    b.type = 'button'; b.innerHTML = ic(S[type].icon, 18) + '<span>' + esc(S[type].label) + '</span>';
    b.addEventListener('click', function () {
      snapshot(true);
      var at = state.sel >= 0 ? state.sel + 1 : state.blocks.length;
      state.blocks.splice(at, 0, newBlock(type));
      state.sel = at; state.tab = 'content';
      renderAll(); scheduleSave();
      A.toast('Bloque “' + S[type].label + '” añadido');
    });
    lib.appendChild(b);
  });

  /* ---------- inspector ---------- */
  var BG = [['default', '#FDF7F5', 'Crema'], ['white', '#FFFFFF', 'Blanco'], ['blush', '#F8E1E7', 'Rosa suave'], ['rose', '#B83A6B', 'Rosa'], ['plum', '#4B2635', 'Ciruela']];
  function val(b, f) {
    var key = f[0], tr = f[3];
    if (tr) return (b.i18n[state.lang] || {})[key] || '';
    var v = b.data[key]; return v === undefined || v === null ? '' : v;
  }
  function other(b, f) { if (!f[3]) return ''; var o = state.lang === 'en' ? 'es' : 'en'; return (b.i18n[o] || {})[f[0]] || ''; }
  function setVal(b, f, v) {
    snapshot(false);
    if (f[3]) { b.i18n[state.lang] = b.i18n[state.lang] || {}; b.i18n[state.lang][f[0]] = v; }
    else b.data[f[0]] = v;
    scheduleSave();
  }

  function fieldHtml(b, f, idx) {
    var key = f[0], label = f[1], type = f[2], v = val(b, f), id = 'f-' + idx;
    var ph = other(b, f); ph = ph ? ' placeholder="' + esc(String(ph).split('\n')[0].slice(0, 60)) + '"' : '';
    var lab = '<label class="fld" for="' + id + '">' + esc(label) + (f[3] ? ' <small class="muted">' + state.lang.toUpperCase() + '</small>' : '');
    switch (type) {
      case 'textarea': return lab + '<textarea id="' + id + '" data-f="' + idx + '" rows="4"' + ph + '>' + esc(v) + '</textarea></label>';
      case 'lines': return lab + '<textarea id="' + id + '" data-f="' + idx + '" rows="5"' + ph + '>' + esc(v) + '</textarea><small class="muted">Una por línea. Usa “ | ” para separar partes.</small></label>';
      case 'number': return lab + '<input id="' + id + '" type="number" data-f="' + idx + '" value="' + esc(v) + '"></label>';
      case 'datetime': return lab + '<input id="' + id + '" type="datetime-local" data-f="' + idx + '" value="' + esc(v) + '"></label>';
      case 'toggle': return '<label class="switch"><input type="checkbox" data-f="' + idx + '" ' + (v ? 'checked' : '') + '><span></span>' + esc(label) + '</label>';
      case 'select':
        return lab + '<select id="' + id + '" data-f="' + idx + '">' + Object.keys(f[4]).map(function (k) { return '<option value="' + esc(k) + '"' + (String(v) === k ? ' selected' : '') + '>' + esc(f[4][k]) + '</option>'; }).join('') + '</select></label>';
      case 'category':
        return lab + '<select id="' + id + '" data-f="' + idx + '"><option value="">—</option>' + D.categories.map(function (c) { return '<option value="' + esc(c.slug) + '"' + (v === c.slug ? ' selected' : '') + '>' + esc(c.name_es) + '</option>'; }).join('') + '</select></label>';
      case 'image':
        return '<div class="fld"><span>' + esc(label) + '</span><div class="img-one">' + (v ? '<img src="' + esc(A.media(v)) + '" alt="">' : '<span class="ph">' + ic('image', 20) + '</span>') +
          '<span>' + esc(v ? v.split('/').pop() : 'Sin imagen') + '</span><button type="button" class="btn btn-ghost sm" data-img="' + idx + '">' + (v ? 'Cambiar' : 'Elegir') + '</button>' + (v ? '<button type="button" class="icon-btn" data-img-clear="' + idx + '" aria-label="Quitar">' + ic('trash') + '</button>' : '') + '</div></div>';
      case 'images':
        var arr = Array.isArray(v) ? v : [];
        return '<div class="fld"><span>' + esc(label) + ' <small class="muted">(' + arr.length + ')</small></span><div class="imgs-field">' + arr.map(function (p, k) {
          return '<div class="it"><img src="' + esc(A.media(p)) + '" alt=""><button type="button" data-imgs-rm="' + idx + ':' + k + '" aria-label="Quitar">✕</button></div>';
        }).join('') + '<button type="button" class="add" data-imgs-add="' + idx + '" aria-label="Añadir fotos">' + ic('plus', 20) + '</button></div></div>';
      case 'video':
        return lab + '<input id="' + id + '" type="text" data-f="' + idx + '" value="' + esc(v) + '" placeholder="https://youtube.com/…"></label><button type="button" class="btn btn-ghost sm" data-vid="' + idx + '">' + ic('video') + 'Subir o elegir video MP4</button>';
      default: return lab + '<input id="' + id + '" type="text" data-f="' + idx + '" value="' + esc(v) + '"' + ph + '></label>';
    }
  }

  function renderInspector() {
    if (state.sel === -2) return renderPageSettings();
    var b = state.blocks[state.sel];
    if (!b) {
      insp.innerHTML = '<div class="empty-insp">' + ic('plus', 28) + '<strong>Selecciona un bloque</strong><span>Haz clic en un bloque de la lista o directamente en la vista previa. Para añadir uno nuevo usa “Añadir bloque”.</span></div>';
      return;
    }
    var sc = S[b.type];
    var h = '<div class="insp-head"><div><small>Bloque seleccionado</small><h2>' + esc(sc.label) + '</h2></div><div class="row-gap">' +
      '<button type="button" class="icon-btn" data-act="up" aria-label="Subir">' + ic('up') + '</button>' +
      '<button type="button" class="icon-btn" data-act="down" aria-label="Bajar">' + ic('down') + '</button>' +
      '<button type="button" class="icon-btn" data-act="dup" aria-label="Duplicar">' + ic('copy') + '</button>' +
      '<button type="button" class="icon-btn danger" data-act="del" aria-label="Eliminar">' + ic('trash') + '</button></div></div>' +
      '<div class="insp-tabs" role="tablist"><button type="button" role="tab" data-tab="content" class="' + (state.tab === 'content' ? 'on' : '') + '">Contenido</button><button type="button" role="tab" data-tab="style" class="' + (state.tab === 'style' ? 'on' : '') + '">Estilo</button></div>';
    if (state.tab === 'content') {
      if (sc.fields.some(function (f) { return f[3]; })) h += '<span class="lang-badge">Editando versión en ' + (state.lang === 'en' ? 'inglés' : 'español') + ' · cambia arriba EN / ES</span>';
      sc.fields.forEach(function (f, i) { h += fieldHtml(b, f, i); });
      if (b.type === 'shop_grid') h += '<p class="hint">Los productos y filtros se llenan solos desde Productos.</p>';
      if (b.type === 'contact') h += '<p class="hint">El número de WhatsApp y las redes se editan en Ajustes. Los mensajes llegan a Formularios.</p>';
      if (b.type === 'categories') h += '<p class="hint">Las categorías y sus fotos se editan en Productos › Categorías.</p>';
    } else {
      var stl = b.style || {};
      h += '<div class="fld"><span>Fondo</span><div class="bg-swatches">' + BG.map(function (x) {
        return '<button type="button" data-bg="' + x[0] + '" title="' + x[2] + '" aria-label="' + x[2] + '" class="' + ((stl.bg || 'default') === x[0] ? 'on' : '') + '" style="background:' + x[1] + '"></button>';
      }).join('') + '</div></div>' +
        '<label class="fld">Espaciado vertical<select data-pad><option value="none">Sin espacio</option><option value="s">Pequeño</option><option value="m">Mediano</option><option value="l">Grande</option></select></label>' +
        '<label class="switch"><input type="checkbox" data-hidem ' + (stl.hide_mobile ? 'checked' : '') + '><span></span>Ocultar en celulares</label>' +
        '<label class="switch"><input type="checkbox" data-visible ' + (b.visible !== false ? 'checked' : '') + '><span></span>Bloque visible</label>';
    }
    insp.innerHTML = h;
    var pad = $('[data-pad]', insp); if (pad) pad.value = (b.style && b.style.pad) || 'm';
    bindInspector(b, sc);
  }

  function bindInspector(b, sc) {
    $$('[data-tab]', insp).forEach(function (t) { t.addEventListener('click', function () { state.tab = t.getAttribute('data-tab'); renderInspector(); }); });
    $$('[data-act]', insp).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var a = btn.getAttribute('data-act'), i = state.sel;
        snapshot(true);
        if (a === 'up' && i > 0) { state.blocks.splice(i - 1, 0, state.blocks.splice(i, 1)[0]); state.sel--; }
        else if (a === 'down' && i < state.blocks.length - 1) { state.blocks.splice(i + 1, 0, state.blocks.splice(i, 1)[0]); state.sel++; }
        else if (a === 'dup') { var c = JSON.parse(JSON.stringify(b)); c.id = uid(); state.blocks.splice(i + 1, 0, c); state.sel = i + 1; }
        else if (a === 'del') { if (!confirm('¿Eliminar el bloque “' + sc.label + '”? Puedes deshacer con ↶.')) return; state.blocks.splice(i, 1); state.sel = Math.min(i, state.blocks.length - 1); }
        renderAll(); scheduleSave();
      });
    });
    $$('[data-f]', insp).forEach(function (el) {
      var f = sc.fields[+el.getAttribute('data-f')];
      var ev = (el.type === 'checkbox' || el.tagName === 'SELECT' || el.type === 'datetime-local') ? 'change' : 'input';
      el.addEventListener(ev, function () {
        var v = el.type === 'checkbox' ? el.checked : (el.type === 'number' ? (el.value === '' ? '' : +el.value) : el.value);
        setVal(b, f, v);
        if (f[0] === 'title' || f[0] === 'script') renderList();
      });
    });
    $$('[data-img]', insp).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var f = sc.fields[+btn.getAttribute('data-img')];
        A.pick({ kind: 'image' }).then(function (sel) { if (sel.length) { setVal(b, f, sel[0].path); renderInspector(); } });
      });
    });
    $$('[data-img-clear]', insp).forEach(function (btn) {
      btn.addEventListener('click', function () { setVal(b, sc.fields[+btn.getAttribute('data-img-clear')], ''); renderInspector(); });
    });
    $$('[data-imgs-add]', insp).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var f = sc.fields[+btn.getAttribute('data-imgs-add')];
        A.pick({ kind: 'image', multiple: true }).then(function (sel) {
          if (!sel.length) return;
          var arr = (Array.isArray(b.data[f[0]]) ? b.data[f[0]] : []).concat(sel.map(function (s) { return s.path; }));
          setVal(b, f, arr); renderInspector();
        });
      });
    });
    $$('[data-imgs-rm]', insp).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var p = btn.getAttribute('data-imgs-rm').split(':'), f = sc.fields[+p[0]];
        var arr = (b.data[f[0]] || []).slice(); arr.splice(+p[1], 1); setVal(b, f, arr); renderInspector();
      });
    });
    $$('[data-vid]', insp).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var f = sc.fields[+btn.getAttribute('data-vid')];
        A.pick({ kind: 'video' }).then(function (sel) { if (sel.length) { setVal(b, f, sel[0].path); renderInspector(); } });
      });
    });
    $$('[data-bg]', insp).forEach(function (btn) {
      btn.addEventListener('click', function () { snapshot(true); b.style = b.style || {}; b.style.bg = btn.getAttribute('data-bg'); renderInspector(); scheduleSave(); });
    });
    var pad = $('[data-pad]', insp); if (pad) pad.addEventListener('change', function () { snapshot(true); b.style.pad = pad.value; scheduleSave(); });
    var hm = $('[data-hidem]', insp); if (hm) hm.addEventListener('change', function () { snapshot(true); b.style.hide_mobile = hm.checked; scheduleSave(); });
    var vis = $('[data-visible]', insp); if (vis) vis.addEventListener('change', function () { snapshot(true); b.visible = vis.checked; renderList(); scheduleSave(); });
  }

  function renderPageSettings() {
    var s = state.seo;
    insp.innerHTML = '<div class="insp-head"><div><small>Página</small><h2>SEO de esta página</h2></div></div>' +
      '<p class="muted">Cómo aparece esta página en Google. Si lo dejas vacío se usan los datos generales de SEO y Open Graph.</p>' +
      ['en', 'es'].map(function (l) {
        return '<label class="fld">Título (' + (l === 'en' ? 'inglés' : 'español') + ')<input type="text" data-seo="title_' + l + '" value="' + esc(s['title_' + l] || '') + '" maxlength="120"></label>' +
          '<label class="fld">Descripción (' + (l === 'en' ? 'inglés' : 'español') + ')<textarea rows="3" data-seo="desc_' + l + '" maxlength="300">' + esc(s['desc_' + l] || '') + '</textarea></label>';
      }).join('') + '<p class="hint">Imagen al compartir, Google Analytics y más en <a href="' + A.url('/admin/seo') + '">SEO y Open Graph</a>.</p>';
    $$('[data-seo]', insp).forEach(function (el) {
      el.addEventListener('input', function () { snapshot(false); state.seo[el.getAttribute('data-seo')] = el.value; scheduleSave(); });
    });
  }
  $('[data-page-settings]', root).addEventListener('click', function (e) {
    state.sel = -2; renderList(); renderInspector(); e.currentTarget.classList.add('on');
  });

  function renderAll() { renderList(); renderInspector(); highlight(); }
  renderAll();
})();
