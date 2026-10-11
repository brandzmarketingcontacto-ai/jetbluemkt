// Panel de administración: todo se guarda en MySQL a través de api.php.

const CSRF = document.querySelector('meta[name="csrf"]').content;
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const src = (url) => (/^https?:/i.test(url) ? url : '../' + url);

const CATEGORIES = { rosa: 'Rosa', azul: 'Azul', verde: 'Verde y plata', lila: 'Lila', charro: 'Charro', rojo: 'Rojo y vino', marfil: 'Marfil y dorado', negro: 'Negro' };
const LINKS = { wa: 'WhatsApp (agendar cita)', coleccion: 'Sección Colección', accesorios: 'Sección Accesorios', visitanos: 'Sección Visítanos', salon: 'Sección Salón EMA', web: 'Sitio del salón (emaeventsvenue.com)' };
const BGS = [
  { id: 'claro', label: 'Claro', hex: '#FFF6F9' }, { id: 'rosa', label: 'Rosa', hex: '#FBE1EC' },
  { id: 'fucsia', label: 'Fucsia', hex: '#C2185B' }, { id: 'ciruela', label: 'Ciruela', hex: '#2B0B1E' },
];
const ANCHOR = { hero: 'inicio', valor: 'valor', coleccion: 'coleccion', accesorios: 'accesorios', como: 'como', visitanos: 'visitanos', salon: 'salon', faq: 'faq', cta: 'cta', equipo: 'equipo' };
const VIEWS = {
  pagina: ['Página', 'Ordena secciones, edita textos con formato y cambia imágenes'],
  vestidos: ['Vestidos', 'Catálogo: fotos, nombres, precios y estilos'],
  accesorios: ['Accesorios', 'Ramos, crinolinas y complementos'],
  faqs: ['Preguntas frecuentes', 'Preguntas y respuestas del sitio'],
  media: ['Imágenes', 'Logo, fotos del sitio y biblioteca'],
  ajustes: ['Ajustes', 'Contacto, WhatsApp, textos generales y contraseña'],
};

const state = { data: null, view: 'pagina', sel: null, device: 'desktop', editors: [] };

/* ---------------- API ---------------- */

async function request(url, opts) {
  const res = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts));
  let json = null;
  try { json = await res.json(); } catch (e) { /* respuesta no JSON */ }
  if (res.status === 401) { location.href = '../index.php?login=1'; throw new Error('Sesión expirada'); }
  if (!res.ok || !json || !json.ok) throw new Error((json && json.error) || 'Error del servidor (' + res.status + ')');
  return json;
}
const api = {
  load: () => request('api.php'),
  post: (body) => request('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF }, body: JSON.stringify(body) }),
  upload: (file) => {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('csrf', CSRF);
    return request('api.php', { method: 'POST', body: fd });
  },
};

/* ---------------- Estado de guardado y avisos ---------------- */

let pending = 0;
function setStatus(kind, text) {
  const el = $('#status');
  el.dataset.state = kind;
  el.textContent = text;
}
async function save(promiseFn, okMsg) {
  pending++;
  setStatus('saving', 'Guardando…');
  try {
    const r = await promiseFn();
    pending--;
    if (!pending) setStatus('ok', 'Todo guardado');
    if (okMsg) toast(okMsg);
    return r;
  } catch (err) {
    pending--;
    setStatus('error', 'Error al guardar');
    toast(err.message, true);
    throw err;
  }
}
let toastTimer;
function toast(msg, err) {
  const t = $('#toast');
  t.textContent = msg;
  t.classList.toggle('is-err', !!err);
  t.classList.add('is-on');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('is-on'), 2600);
}
function debounce(fn, ms) {
  let t;
  const d = (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
  d.flush = (...a) => { clearTimeout(t); fn(...a); };
  return d;
}
window.addEventListener('beforeunload', (e) => { if (pending) { e.preventDefault(); e.returnValue = ''; } });

/* ---------------- Librerías (TipTap y SortableJS) ---------------- */

// TipTap y SortableJS van empaquetados en vendor/editor.js (sin depender de un CDN).
let vendorPromise = null;
function loadVendor() {
  if (!vendorPromise) vendorPromise = import('./vendor/editor.js?v=2.27.3').catch((err) => { console.warn('No cargó el editor', err); return null; });
  return vendorPromise;
}
async function loadTiptap() {
  const m = await loadVendor();
  if (!m) return null;
  return {
    Editor: m.Editor,
    extensions: [
      m.StarterKit.configure({ heading: { levels: [2, 3] }, codeBlock: false, code: false, horizontalRule: false }),
      m.Underline, m.TextStyle, m.Color,
      m.Link.configure({ openOnClick: false, autolink: true, protocols: ['tel', 'mailto'] }),
      m.TextAlign.configure({ types: ['heading', 'paragraph'] }),
      m.Placeholder.configure({ placeholder: 'Escribe el contenido…' }),
    ],
  };
}
async function loadSortable() {
  const m = await loadVendor();
  return m ? m.Sortable : null;
}
async function makeSortable(el, onEnd, opts = {}) {
  const Sortable = await loadSortable();
  if (!Sortable || !el.isConnected) return;
  if (el._sortable) el._sortable.destroy(); // una sola instancia por lista
  el._sortable = Sortable.create(el, Object.assign({ animation: 160, handle: '.grip', forceFallback: true, fallbackTolerance: 3, ghostClass: 'sortable-ghost', chosenClass: 'sortable-chosen', onEnd: () => onEnd($$(':scope > [data-id]', el).map((n) => n.dataset.id)) }, opts));
}

/* ---------------- Editor de texto enriquecido ---------------- */

const TOOLS = [
  ['undo', '↶', 'Deshacer'], ['redo', '↷', 'Rehacer'], '|',
  ['h2', 'H2', 'Título'], ['h3', 'H3', 'Subtítulo'], ['p', '¶', 'Párrafo'], '|',
  ['bold', '<b>B</b>', 'Negrita'], ['italic', '<i>I</i>', 'Cursiva'], ['underline', '<u>U</u>', 'Subrayado'], ['strike', '<s>S</s>', 'Tachado'], '|',
  ['ul', '• Lista', 'Lista con viñetas'], ['ol', '1. Lista', 'Lista numerada'], ['quote', '“ ”', 'Cita'], '|',
  ['left', 'Izq.', 'Alinear a la izquierda'], ['center', 'Centro', 'Centrar'], '|',
  ['link', 'Enlace', 'Insertar enlace'], ['clear', 'Limpiar', 'Quitar formato'],
];
const COLORS = [['#C2185B', 'Fucsia'], ['#2B0B1E', 'Ciruela'], ['#9A6F1E', 'Dorado'], ['#6B4A5C', 'Gris']];

async function createRTE(host, html, onChange, { small = false } = {}) {
  host.innerHTML = `
    <div class="rte">
      <div class="rte-bar" role="toolbar" aria-label="Formato de texto">
        ${TOOLS.map((t) => (t === '|' ? '<span class="sep" aria-hidden="true"></span>' : `<button type="button" data-cmd="${t[0]}" title="${t[2]}" aria-label="${t[2]}">${t[1]}</button>`)).join('')}
        <span class="sep" aria-hidden="true"></span>
        ${COLORS.map((c) => `<button type="button" class="sw" data-cmd="color" data-arg="${c[0]}" title="Texto ${c[1]}" aria-label="Texto ${c[1]}" style="background:${c[0]}"></button>`).join('')}
      </div>
      <div class="rte-link"><input type="url" aria-label="Dirección del enlace" placeholder="https://wa.me/19172388863"><button type="button" class="btn btn-sm btn-dark" data-link="apply">Aplicar</button><button type="button" class="btn btn-sm btn-light" data-link="remove">Quitar enlace</button></div>
      <div class="rte-body${small ? ' small' : ''}"></div>
      <div class="rte-foot"><span>Selecciona texto para darle formato</span><span class="count"></span></div>
    </div>`;
  const bar = $('.rte-bar', host);
  const body = $('.rte-body', host);
  const linkRow = $('.rte-link', host);
  const linkInput = $('input', linkRow);
  const count = $('.count', host);
  const lib = await loadTiptap();
  let ed;

  const updateCount = (text) => {
    const t = text.trim();
    count.textContent = (t ? t.split(/\s+/).length : 0) + ' palabras · ' + t.length + ' caracteres';
  };

  if (lib) {
    const editor = new lib.Editor({
      element: body,
      extensions: lib.extensions,
      content: html || '',
      onUpdate: ({ editor: e }) => { onChange(e.getHTML()); updateCount(e.getText()); },
      onSelectionUpdate: () => refresh(),
      onTransaction: () => refresh(),
    });
    const run = (cmd, arg) => {
      const c = editor.chain().focus();
      ({
        undo: () => c.undo(), redo: () => c.redo(),
        h2: () => c.toggleHeading({ level: 2 }), h3: () => c.toggleHeading({ level: 3 }), p: () => c.setParagraph(),
        bold: () => c.toggleBold(), italic: () => c.toggleItalic(), underline: () => c.toggleUnderline(), strike: () => c.toggleStrike(),
        ul: () => c.toggleBulletList(), ol: () => c.toggleOrderedList(), quote: () => c.toggleBlockquote(),
        left: () => c.setTextAlign('left'), center: () => c.setTextAlign('center'),
        clear: () => c.unsetAllMarks().clearNodes(), color: () => c.setColor(arg),
        setLink: () => c.extendMarkRange('link').setLink({ href: arg }), unsetLink: () => c.extendMarkRange('link').unsetLink(),
      }[cmd] || (() => c))().run();
    };
    const isOn = (cmd) => ({
      h2: editor.isActive('heading', { level: 2 }), h3: editor.isActive('heading', { level: 3 }),
      bold: editor.isActive('bold'), italic: editor.isActive('italic'), underline: editor.isActive('underline'), strike: editor.isActive('strike'),
      ul: editor.isActive('bulletList'), ol: editor.isActive('orderedList'), quote: editor.isActive('blockquote'),
      center: editor.isActive({ textAlign: 'center' }), link: editor.isActive('link'),
    }[cmd] || false);
    ed = { run, isOn, getHTML: () => editor.getHTML(), destroy: () => editor.destroy(), linkHref: () => editor.getAttributes('link').href || '' };
    updateCount(editor.getText());
  } else {
    // Editor básico de respaldo si el CDN de TipTap no responde.
    body.innerHTML = '<div contenteditable="true" role="textbox" aria-multiline="true"></div>';
    const ce = body.firstChild;
    ce.innerHTML = html || '';
    let range = null;
    ce.addEventListener('input', () => { onChange(ce.innerHTML); updateCount(ce.innerText); });
    ['keyup', 'mouseup'].forEach((ev) => ce.addEventListener(ev, () => refresh()));
    const ex = (c, a) => { ce.focus(); document.execCommand('styleWithCSS', false, true); document.execCommand(c, false, a); onChange(ce.innerHTML); };
    const run = (cmd, arg) => {
      if (range) { const s = getSelection(); s.removeAllRanges(); s.addRange(range); }
      ({
        undo: () => ex('undo'), redo: () => ex('redo'), h2: () => ex('formatBlock', '<h2>'), h3: () => ex('formatBlock', '<h3>'), p: () => ex('formatBlock', '<p>'),
        bold: () => ex('bold'), italic: () => ex('italic'), underline: () => ex('underline'), strike: () => ex('strikeThrough'),
        ul: () => ex('insertUnorderedList'), ol: () => ex('insertOrderedList'), quote: () => ex('formatBlock', '<blockquote>'),
        left: () => ex('justifyLeft'), center: () => ex('justifyCenter'), clear: () => ex('removeFormat'), color: () => ex('foreColor', arg),
        setLink: () => ex('createLink', arg), unsetLink: () => ex('unlink'),
      }[cmd] || (() => {}))();
      refresh();
    };
    const q = (c) => { try { return document.queryCommandState(c); } catch (e) { return false; } };
    const isOn = (cmd) => ({ bold: q('bold'), italic: q('italic'), underline: q('underline'), strike: q('strikeThrough'), ul: q('insertUnorderedList'), ol: q('insertOrderedList'), center: q('justifyCenter') }[cmd] || false);
    ce.addEventListener('blur', () => { const s = getSelection(); if (s.rangeCount) range = s.getRangeAt(0).cloneRange(); });
    ed = { run, isOn, getHTML: () => ce.innerHTML, destroy: () => {}, linkHref: () => '' };
    updateCount(ce.innerText);
  }

  function refresh() {
    $$('button[data-cmd]', bar).forEach((b) => { if (b.dataset.cmd !== 'color') b.classList.toggle('is-on', ed && ed.isOn(b.dataset.cmd)); });
  }
  bar.addEventListener('mousedown', (e) => { if (e.target.closest('button')) e.preventDefault(); });
  bar.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-cmd]');
    if (!b) return;
    if (b.dataset.cmd === 'link') {
      linkRow.classList.toggle('is-open');
      linkInput.value = ed.linkHref() || 'https://';
      if (linkRow.classList.contains('is-open')) linkInput.focus();
      return;
    }
    ed.run(b.dataset.cmd, b.dataset.arg);
  });
  linkRow.addEventListener('click', (e) => {
    const b = e.target.closest('[data-link]');
    if (!b) return;
    if (b.dataset.link === 'apply') {
      const url = linkInput.value.trim();
      if (!/^(https?:|tel:|mailto:|#)/i.test(url)) { toast('El enlace debe empezar con https://, tel: o mailto:', true); return; }
      ed.run('setLink', url);
    } else ed.run('unsetLink');
    linkRow.classList.remove('is-open');
  });
  linkInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('[data-link="apply"]', linkRow).click(); } });
  state.editors.push(ed);
  return ed;
}

/* ---------------- Imágenes: subir, soltar y elegir ---------------- */

async function uploadFiles(files) {
  const out = [];
  for (const f of Array.from(files || [])) {
    if (!/^image\//.test(f.type)) { toast(f.name + ' no es una imagen', true); continue; }
    try {
      const r = await save(() => api.upload(f));
      state.data.media.unshift(r.media);
      out.push(r.media);
    } catch (e) { /* aviso ya mostrado */ }
  }
  if (out.length) toast(out.length === 1 ? 'Imagen subida' : out.length + ' imágenes subidas');
  return out;
}
function bindDrop(el, onFiles) {
  el.addEventListener('dragover', (e) => { if (e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files')) { e.preventDefault(); el.classList.add('is-drop'); } });
  el.addEventListener('dragleave', () => el.classList.remove('is-drop'));
  el.addEventListener('drop', (e) => {
    if (!e.dataTransfer || !e.dataTransfer.files.length) return;
    e.preventDefault();
    el.classList.remove('is-drop');
    onFiles(e.dataTransfer.files);
  });
}

let pickerResolve = null;
function renderPickerGrid() {
  $('#picker-grid').innerHTML = state.data.media.map((m) => `<button type="button" class="media-item" data-url="${esc(m.url)}" title="${esc(m.name)}"><img src="${esc(src(m.url))}" alt="" loading="lazy"><span>${esc(m.name)}</span></button>`).join('') || '<p class="muted">Aún no hay imágenes.</p>';
}
function openPicker() {
  renderPickerGrid();
  $('#picker').showModal();
  return new Promise((res) => { pickerResolve = res; });
}
function closePicker(url) {
  $('#picker').close();
  if (pickerResolve) { pickerResolve(url || null); pickerResolve = null; }
}
function initPicker() {
  $('#picker-close').addEventListener('click', () => closePicker(null));
  $('#picker').addEventListener('cancel', () => closePicker(null));
  $('#picker-grid').addEventListener('click', (e) => { const b = e.target.closest('[data-url]'); if (b) closePicker(b.dataset.url); });
  $('#picker-file').addEventListener('change', async (e) => { const up = await uploadFiles(e.target.files); e.target.value = ''; if (up.length === 1) closePicker(up[0].url); else renderPickerGrid(); });
  bindDrop($('#picker-drop'), async (files) => { const up = await uploadFiles(files); if (up.length === 1) closePicker(up[0].url); else renderPickerGrid(); });
}

/** Campo de imagen reutilizable: miniatura + subir + biblioteca + quitar + soltar. */
function imageField(host, url, onPick) {
  const draw = (u) => {
    host.innerHTML = `
      <div class="img-field">
        ${u ? `<img class="thumb" src="${esc(src(u))}" alt="Imagen actual">` : '<div class="empty">Sin imagen</div>'}
        <div style="flex:1 1 200px;display:flex;flex-direction:column;gap:8px">
          <span class="muted" style="font-size:13px">Suelta una foto aquí, súbela o elige una de la biblioteca.</span>
          <div class="row">
            <label class="btn btn-sm btn-dark">Subir foto<input type="file" accept="image/*" class="sr"></label>
            <button type="button" class="btn btn-sm btn-light" data-act="lib">Elegir de la biblioteca</button>
            ${u ? '<button type="button" class="btn btn-sm btn-danger" data-act="clear">Quitar</button>' : ''}
          </div>
        </div>
      </div>`;
    const set = (v) => { draw(v); onPick(v); };
    $('input[type=file]', host).addEventListener('change', async (e) => { const up = await uploadFiles(e.target.files); if (up[0]) set(up[0].url); });
    $('[data-act=lib]', host).addEventListener('click', async () => { const v = await openPicker(); if (v) set(v); });
    const c = $('[data-act=clear]', host);
    if (c) c.addEventListener('click', () => set(''));
    bindDrop($('.img-field', host), async (files) => { const up = await uploadFiles(files); if (up[0]) set(up[0].url); });
  };
  draw(url);
}

/* ---------------- Navegación ---------------- */

function go(view) {
  state.editors.forEach((e) => { try { e.destroy(); } catch (x) { /* ignorar */ } });
  state.editors = [];
  state.view = view;
  $$('.side-nav button').forEach((b) => {
    const on = b.dataset.view === view;
    b.classList.toggle('is-on', on);
    if (on) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current');
  });
  $('#view-title').textContent = VIEWS[view][0];
  $('#view-sub').textContent = VIEWS[view][1];
  ({ pagina: viewPagina, vestidos: viewVestidos, accesorios: viewAccesorios, faqs: viewFaqs, media: viewMedia, ajustes: viewAjustes })[view]();
  try { localStorage.setItem('vqb_view', view); } catch (e) { /* sin almacenamiento */ }
}

const reloadPreview = debounce(() => {
  const f = $('#preview');
  if (!f) return;
  const s = state.data.sections.find((x) => x.id === state.sel);
  const anchor = s ? (s.kind === 'custom' ? 's-' + s.id : ANCHOR[s.kind] || '') : '';
  // Sin "#ancla" en la URL: eso desplazaría también el panel. Se desplaza solo dentro del iframe.
  f.onload = () => {
    try {
      const w = f.contentWindow;
      const el = anchor && w.document.getElementById(anchor);
      if (el) w.scrollTo(0, el.getBoundingClientRect().top + w.scrollY - 70);
    } catch (e) { /* vista previa no disponible */ }
  };
  f.src = '../index.php?preview=' + Date.now();
}, 500);

/* ---------------- Vista: Página ---------------- */

function viewPagina() {
  const secs = state.data.sections;
  if (!state.sel || !secs.find((s) => s.id === state.sel)) state.sel = secs[0] ? secs[0].id : null;
  $('#view').innerHTML = `
    <div class="cols">
      <section class="panel col-list">
        <div class="panel-head"><h2>Secciones</h2><span class="muted" style="font-size:13px" id="vis-count"></span></div>
        <div class="list" id="sec-list"></div>
        <button type="button" class="btn btn-light" id="add-block">+ Agregar bloque</button>
        <p class="muted" style="font-size:13px;margin:0">Arrastra con ⠿ para ordenar. El interruptor muestra u oculta la sección.</p>
      </section>
      <section class="panel col-edit" id="sec-edit"></section>
      <section class="panel col-prev">
        <div class="panel-head"><h2>Vista previa en vivo</h2>
          <div class="seg" role="group" aria-label="Dispositivo"><button type="button" data-dev="desktop">Escritorio</button><button type="button" data-dev="mobile">Móvil</button></div>
        </div>
        <div class="preview-frame" id="pv-frame"><iframe id="preview" title="Vista previa del sitio" loading="lazy"></iframe></div>
      </section>
    </div>`;
  drawSectionList();
  drawSectionEditor();
  const setDev = () => {
    $('#pv-frame').classList.toggle('mobile', state.device === 'mobile');
    $$('[data-dev]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.dev === state.device)));
  };
  $$('[data-dev]').forEach((b) => b.addEventListener('click', () => { state.device = b.dataset.dev; setDev(); }));
  setDev();
  reloadPreview.flush();
  $('#add-block').addEventListener('click', async () => {
    const r = await save(() => api.post({ action: 'create', table: 'sections', fields: {} }), 'Bloque agregado');
    state.data.sections.push(r.row);
    state.sel = r.row.id;
    drawSectionList();
    drawSectionEditor();
    reloadPreview();
  });
}

function drawSectionList() {
  const list = $('#sec-list');
  const secs = state.data.sections;
  $('#vis-count').textContent = secs.filter((s) => +s.visible).length + ' visibles';
  list.innerHTML = secs.map((s) => `
    <div class="item${s.id === state.sel ? ' is-on' : ''}${+s.visible ? '' : ' is-hidden'}" data-id="${esc(s.id)}">
      <span class="grip" title="Arrastrar para ordenar" aria-hidden="true">⠿</span>
      ${s.image ? `<img src="${esc(src(s.image))}" alt="">` : ''}
      <button type="button" class="item-name" data-act="sel">${esc(s.name)}</button>
      <button type="button" class="icon-btn" data-act="up" aria-label="Subir">↑</button>
      <button type="button" class="icon-btn" data-act="down" aria-label="Bajar">↓</button>
      <button type="button" class="switch" role="switch" aria-checked="${+s.visible ? 'true' : 'false'}" aria-label="Mostrar ${esc(s.name)}" data-act="vis"></button>
    </div>`).join('');
  list.onclick = async (e) => {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    const id = b.closest('[data-id]').dataset.id;
    const i = secs.findIndex((s) => s.id === id);
    const act = b.dataset.act;
    if (act === 'sel') { state.sel = id; drawSectionList(); drawSectionEditor(); reloadPreview(); return; }
    if (act === 'vis') {
      const v = +secs[i].visible ? 0 : 1;
      await save(() => api.post({ action: 'update', table: 'sections', id, fields: { visible: v } }), v ? 'Sección visible' : 'Sección oculta');
      secs[i].visible = v;
      drawSectionList();
      reloadPreview();
      return;
    }
    const j = act === 'up' ? i - 1 : i + 1;
    if (j < 0 || j >= secs.length) return;
    [secs[i], secs[j]] = [secs[j], secs[i]];
    drawSectionList();
    await saveOrder('sections', secs.map((s) => s.id));
  };
  makeSortable(list, async (ids) => {
    state.data.sections = ids.map((id) => secs.find((s) => s.id === id));
    drawSectionList();
    await saveOrder('sections', ids);
  });
}
async function saveOrder(table, ids) {
  await save(() => api.post({ action: 'reorder', table, ids }), 'Orden guardado');
  if (table === 'sections') reloadPreview();
}

function drawSectionEditor() {
  state.editors.forEach((e) => { try { e.destroy(); } catch (x) { /* ignorar */ } });
  state.editors = [];
  const host = $('#sec-edit');
  const s = state.data.sections.find((x) => x.id === state.sel);
  if (!s) { host.innerHTML = '<p class="muted">Selecciona una sección.</p>'; return; }
  const noImg = ['valor', 'coleccion', 'accesorios', 'como', 'faq', 'cta', 'equipo'].includes(s.kind);
  const noCta = ['valor', 'como', 'faq'].includes(s.kind);
  host.innerHTML = `
    <div class="panel-head">
      <div><span class="kicker">Editando sección</span><h2 style="font-size:20px">${esc(s.name)}</h2></div>
      ${s.kind === 'custom' ? '<button type="button" class="btn btn-sm btn-danger" id="sec-del">Eliminar bloque</button>' : ''}
    </div>
    <div class="grid2">
      <label class="field">Nombre interno<input data-f="name" value="${esc(s.name)}"></label>
      <label class="field">Texto pequeño superior<input data-f="eyebrow" value="${esc(s.eyebrow)}" placeholder="Opcional"></label>
    </div>
    <label class="field">Título<input data-f="title" value="${esc(s.title)}" style="font-size:18px;font-weight:700"><small>Escribe *entre asteriscos* la parte que quieras resaltar en cursiva de color.</small></label>
    <div class="field"><span id="rte-label">Contenido</span><div id="rte"></div>
      ${s.kind === 'valor' ? '<small>Cada elemento de la lista es un beneficio; el texto en negrita se muestra grande.</small>' : ''}
      ${s.kind === 'como' ? '<small>Cada elemento de la lista numerada es un paso; usa H3 para el título de cada paso.</small>' : ''}
      ${s.kind === 'visitanos' ? '<small>Cada elemento de la lista es una fila de horario: la negrita va a la izquierda.</small>' : ''}
    </div>
    ${noCta ? '' : `<div class="grid2">
      <label class="field">Texto del botón<input data-f="cta" value="${esc(s.cta)}" placeholder="Sin botón"></label>
      <label class="field">El botón lleva a<select data-f="link">${Object.entries(LINKS).map(([k, v]) => `<option value="${k}"${s.link === k ? ' selected' : ''}>${v}</option>`).join('')}</select></label>
    </div>`}
    <div class="field"><span>Fondo de la sección</span>
      <div class="swatches" role="group" aria-label="Fondo">${BGS.map((g) => `<button type="button" data-bg="${g.id}" aria-pressed="${s.bg === g.id}"><i style="background:${g.hex}"></i>${g.label}</button>`).join('')}</div>
    </div>
    ${noImg ? '' : '<div class="field"><span>Imagen de la sección</span><div id="sec-img"></div></div>'}`;

  // Acumula los cambios de varios campos y los guarda juntos tras una pausa al escribir.
  let queued = {};
  const push = debounce(async () => {
    const fields = queued;
    queued = {};
    await save(() => api.post({ action: 'update', table: 'sections', id: s.id, fields }));
    reloadPreview();
  }, 700);
  const queueMerge = (f, v) => { s[f] = v; queued[f] = v; push(); };

  $$('[data-f]', host).forEach((inp) => {
    inp.addEventListener('input', () => {
      queueMerge(inp.dataset.f, inp.value);
      if (inp.dataset.f === 'name') { const n = $(`[data-id="${CSS.escape(s.id)}"] .item-name`); if (n) n.textContent = inp.value; }
    });
  });
  $$('[data-bg]', host).forEach((b) => b.addEventListener('click', () => {
    $$('[data-bg]', host).forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    queueMerge('bg', b.dataset.bg);
  }));
  createRTE($('#rte', host), s.body, (html) => queueMerge('body', html));
  const del = $('#sec-del', host);
  if (del) del.addEventListener('click', async () => {
    if (!confirm('¿Eliminar este bloque? Esta acción no se puede deshacer.')) return;
    await save(() => api.post({ action: 'delete', table: 'sections', id: s.id }), 'Bloque eliminado');
    state.data.sections = state.data.sections.filter((x) => x.id !== s.id);
    state.sel = null;
    viewPagina();
  });
  const imgHost = $('#sec-img', host);
  if (imgHost) imageField(imgHost, s.image, (url) => { queueMerge('image', url); drawSectionList(); });
}

/* ---------------- Vista: Vestidos y accesorios ---------------- */

function viewVestidos() { viewCards('dresses', 'vestido'); }
function viewAccesorios() { viewCards('accessories', 'accesorio'); }

function viewCards(table, noun) {
  const rows = state.data[table];
  const isDress = table === 'dresses';
  $('#view').innerHTML = `
    <div class="panel-head">
      <span class="muted">${rows.length} ${noun}s · arrastra con ⠿ para ordenar · suelta una foto sobre una tarjeta para cambiarla</span>
      <button type="button" class="btn btn-dark" id="add">+ Agregar ${noun}</button>
    </div>
    <div class="cards" id="cards"></div>`;
  const grid = $('#cards');
  grid.innerHTML = rows.map((r, i) => `
    <article class="dcard${+r.featured ? ' is-feat' : ''}" data-id="${r.id}">
      <div class="dcard-img">
        ${r.image ? `<img src="${esc(src(r.image))}" alt="${esc(r.name)}" draggable="false">` : ''}
        <span class="num">#${i + 1}</span>
        <span class="grip" title="Arrastrar para ordenar" aria-hidden="true">⠿</span>
        <button type="button" class="change" data-act="img">Cambiar foto</button>
      </div>
      <div class="dcard-body">
        <label class="field">Nombre<input data-f="name" value="${esc(r.name)}"></label>
        ${isDress ? `
        <div class="grid2" style="grid-template-columns:repeat(2,minmax(0,1fr))">
          <label class="field">Precio desde<input data-f="price" value="${esc(r.price)}"></label>
          <label class="field">Estilo<select data-f="category">${Object.entries(CATEGORIES).map(([k, v]) => `<option value="${k}"${r.category === k ? ' selected' : ''}>${v}</option>`).join('')}</select></label>
        </div>
        <label class="field">Etiqueta en la foto<input data-f="tag" value="${esc(r.tag)}" placeholder="Ej. Todos los colores"></label>` : ''}
        <div class="dcard-foot">
          <div class="row">
            <label class="check"><input type="checkbox" data-f="visible"${+r.visible ? ' checked' : ''}>Visible</label>
            ${isDress ? `<label class="check"><input type="checkbox" data-f="featured"${+r.featured ? ' checked' : ''}>Destacado</label>` : ''}
          </div>
          <button type="button" class="btn btn-sm btn-danger" data-act="del">Eliminar</button>
        </div>
      </div>
    </article>`).join('');

  const savers = {};
  const pendingFields = {};
  const saverFor = (row) => savers[row.id] || (savers[row.id] = debounce(() => {
    const fields = pendingFields[row.id];
    delete pendingFields[row.id];
    return save(() => api.post({ action: 'update', table, id: row.id, fields }));
  }, 600));
  const queue = (row, f, v) => {
    row[f] = v;
    pendingFields[row.id] = Object.assign(pendingFields[row.id] || {}, { [f]: v });
    saverFor(row)();
  };
  const setImage = (row, card, url) => {
    queue(row, 'image', url);
    const box = $('.dcard-img', card);
    let img = $('img', box);
    if (!img) { img = document.createElement('img'); img.draggable = false; box.prepend(img); }
    img.src = src(url);
  };

  $$('.dcard', grid).forEach((card) => {
    const row = rows.find((r) => String(r.id) === card.dataset.id);
    $$('[data-f]', card).forEach((inp) => {
      const ev = inp.type === 'checkbox' || inp.tagName === 'SELECT' ? 'change' : 'input';
      inp.addEventListener(ev, () => {
        const v = inp.type === 'checkbox' ? (inp.checked ? 1 : 0) : inp.value;
        queue(row, inp.dataset.f, v);
        if (inp.dataset.f === 'featured') card.classList.toggle('is-feat', !!v);
      });
    });
    $('[data-act=img]', card).addEventListener('click', async () => { const u = await openPicker(); if (u) setImage(row, card, u); });
    $('[data-act=del]', card).addEventListener('click', async () => {
      if (!confirm('¿Eliminar "' + row.name + '"?')) return;
      await save(() => api.post({ action: 'delete', table, id: row.id }), 'Eliminado');
      state.data[table] = state.data[table].filter((x) => x.id !== row.id);
      viewCards(table, noun);
    });
    bindDrop(card, async (files) => { const up = await uploadFiles(files); if (up[0]) setImage(row, card, up[0].url); });
  });

  $('#add').addEventListener('click', async () => {
    const r = await save(() => api.post({ action: 'create', table, fields: {} }), noun[0].toUpperCase() + noun.slice(1) + ' agregado');
    state.data[table].push(r.row);
    viewCards(table, noun);
    const last = $('#cards').lastElementChild;
    if (last) { last.scrollIntoView({ behavior: 'smooth', block: 'center' }); $('input', last).select(); }
  });
  makeSortable(grid, async (ids) => {
    state.data[table] = ids.map((id) => rows.find((r) => String(r.id) === id));
    $$('.dcard .num', grid).forEach((n, i) => { n.textContent = '#' + (i + 1); });
    await saveOrder(table, ids);
  });
}

/* ---------------- Vista: Preguntas ---------------- */

function viewFaqs() {
  const rows = state.data.faqs;
  let open = rows[0] ? rows[0].id : null;
  $('#view').innerHTML = `
    <div class="panel-head"><span class="muted">${rows.length} preguntas · arrastra con ⠿ para ordenar</span><button type="button" class="btn btn-dark" id="add">+ Agregar pregunta</button></div>
    <div class="list" id="faq-list"></div>`;
  const list = $('#faq-list');
  const savers = {};
  const pendingFields = {};
  const queue = (row, f, v) => {
    row[f] = v;
    pendingFields[row.id] = Object.assign(pendingFields[row.id] || {}, { [f]: v });
    (savers[row.id] || (savers[row.id] = debounce(() => {
      const fields = pendingFields[row.id];
      delete pendingFields[row.id];
      return save(() => api.post({ action: 'update', table: 'faqs', id: row.id, fields }));
    }, 700)))();
  };
  const draw = () => {
    state.editors.forEach((e) => { try { e.destroy(); } catch (x) { /* ignorar */ } });
    state.editors = [];
    list.innerHTML = rows.map((f) => `
      <div class="faq-item${f.id === open ? ' is-open' : ''}" data-id="${f.id}">
        <div class="faq-head">
          <span class="grip" aria-hidden="true">⠿</span>
          <label class="sr" for="q${f.id}">Pregunta</label><input id="q${f.id}" data-f="question" value="${esc(f.question)}">
          <button type="button" class="switch" role="switch" aria-checked="${+f.visible ? 'true' : 'false'}" aria-label="Mostrar pregunta" data-act="vis"></button>
          <button type="button" class="btn btn-sm btn-light" data-act="toggle">${f.id === open ? 'Cerrar' : 'Editar respuesta'}</button>
          <button type="button" class="icon-btn" data-act="del" aria-label="Eliminar pregunta">✕</button>
        </div>
        ${f.id === open ? '<div class="faq-body"><div class="faq-rte"></div></div>' : ''}
      </div>`).join('');
    const openRow = rows.find((r) => r.id === open);
    const rteHost = $('.faq-rte', list);
    if (openRow && rteHost) createRTE(rteHost, openRow.answer, (html) => queue(openRow, 'answer', html), { small: true });
  };
  list.addEventListener('input', (e) => {
    const inp = e.target.closest('[data-f]');
    if (!inp) return;
    queue(rows.find((r) => String(r.id) === inp.closest('[data-id]').dataset.id), 'question', inp.value);
  });
  list.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    const row = rows.find((r) => String(r.id) === b.closest('[data-id]').dataset.id);
    if (b.dataset.act === 'toggle') { open = open === row.id ? null : row.id; draw(); }
    if (b.dataset.act === 'vis') {
      const v = +row.visible ? 0 : 1;
      await save(() => api.post({ action: 'update', table: 'faqs', id: row.id, fields: { visible: v } }));
      row.visible = v;
      b.setAttribute('aria-checked', v ? 'true' : 'false');
    }
    if (b.dataset.act === 'del') {
      if (!confirm('¿Eliminar esta pregunta?')) return;
      await save(() => api.post({ action: 'delete', table: 'faqs', id: row.id }), 'Pregunta eliminada');
      state.data.faqs = state.data.faqs.filter((x) => x.id !== row.id);
      viewFaqs();
    }
  });
  $('#add').addEventListener('click', async () => {
    const r = await save(() => api.post({ action: 'create', table: 'faqs', fields: {} }), 'Pregunta agregada');
    state.data.faqs.push(r.row);
    viewFaqs();
  });
  draw();
  makeSortable(list, async (ids) => {
    state.data.faqs = ids.map((id) => rows.find((r) => String(r.id) === id));
    await saveOrder('faqs', ids);
  });
}

/* ---------------- Vista: Imágenes ---------------- */

function viewMedia() {
  const st = state.data.settings;
  const slots = [
    { k: 'logo', label: 'Logo a color', where: 'Encabezado del sitio', dark: false },
    { k: 'logo_white', label: 'Logo blanco', where: 'Pie de página y panel', dark: true },
    { k: 'hero_inset', label: 'Portada · foto pequeña', where: 'Portada', dark: false },
  ];
  const secImgs = state.data.sections.filter((s) => s.image || ['hero', 'visitanos', 'salon'].includes(s.kind));
  $('#view').innerHTML = `
    <section class="panel">
      <h2>Imágenes del sitio</h2>
      <div class="slots" id="slots">
        ${slots.map((s) => `<div class="slot" data-slot="${s.k}"><div class="slot-img${s.dark ? ' dark' : ''}">${st[s.k] ? `<img src="${esc(src(st[s.k]))}" alt="${esc(s.label)}">` : '<span class="muted">Sin imagen</span>'}</div><div class="slot-body">${esc(s.label)}<span class="muted" style="font-weight:600;font-size:12px">${esc(s.where)}</span><div class="row"><label class="btn btn-sm btn-dark">Reemplazar<input type="file" accept="image/*" class="sr"></label><button type="button" class="btn btn-sm btn-light" data-act="lib">De la biblioteca</button></div></div></div>`).join('')}
        ${secImgs.map((s) => `<div class="slot" data-sec="${esc(s.id)}"><div class="slot-img">${s.image ? `<img src="${esc(src(s.image))}" alt="" style="width:100%;height:100%;object-fit:cover">` : '<span class="muted">Sin imagen</span>'}</div><div class="slot-body">${esc(s.name)} · imagen<span class="muted" style="font-weight:600;font-size:12px">Sección de la página</span><div class="row"><label class="btn btn-sm btn-dark">Reemplazar<input type="file" accept="image/*" class="sr"></label><button type="button" class="btn btn-sm btn-light" data-act="lib">De la biblioteca</button></div></div></div>`).join('')}
      </div>
    </section>
    <section class="panel">
      <div class="panel-head"><h2>Biblioteca <span class="muted">(${state.data.media.length})</span></h2><label class="btn">+ Subir fotos<input type="file" accept="image/*" multiple class="sr" id="lib-file"></label></div>
      <div class="dropzone" id="lib-drop">Arrastra aquí tus fotos para agregarlas a la biblioteca</div>
      <div class="media-grid" id="lib-grid">${state.data.media.map((m) => `<div class="media-item" data-id="${m.id}" title="${esc(m.name)}"><img src="${esc(src(m.url))}" alt="" loading="lazy"><span>${esc(m.name)}</span>${/^uploads\//.test(m.url) ? `<button type="button" class="del" data-del="${m.id}" aria-label="Borrar ${esc(m.name)}">×</button>` : ''}</div>`).join('')}</div>
    </section>`;

  const assign = async (slotEl, url) => {
    if (slotEl.dataset.slot) {
      const k = slotEl.dataset.slot;
      await save(() => api.post({ action: 'settings', values: { [k]: url } }), 'Imagen actualizada');
      state.data.settings[k] = url;
      if (k === 'logo_white') $('#side-logo').src = src(url);
    } else {
      const s = state.data.sections.find((x) => x.id === slotEl.dataset.sec);
      const r = await save(() => api.post({ action: 'update', table: 'sections', id: s.id, fields: { image: url } }), 'Imagen actualizada');
      Object.assign(s, r.row);
    }
    viewMedia();
  };
  $$('.slot').forEach((slot) => {
    $('input[type=file]', slot).addEventListener('change', async (e) => { const up = await uploadFiles(e.target.files); if (up[0]) assign(slot, up[0].url); });
    $('[data-act=lib]', slot).addEventListener('click', async () => { const u = await openPicker(); if (u) assign(slot, u); });
    bindDrop(slot, async (files) => { const up = await uploadFiles(files); if (up[0]) assign(slot, up[0].url); });
  });
  $('#lib-file').addEventListener('change', async (e) => { await uploadFiles(e.target.files); viewMedia(); });
  bindDrop($('#lib-drop'), async (files) => { await uploadFiles(files); viewMedia(); });
  $('#lib-grid').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-del]');
    if (!b) return;
    if (!confirm('¿Borrar esta imagen del servidor? Si se usa en el sitio, dejará de verse.')) return;
    await save(() => api.post({ action: 'media_delete', id: +b.dataset.del }), 'Imagen borrada');
    state.data.media = state.data.media.filter((m) => m.id !== +b.dataset.del);
    viewMedia();
  });
}

/* ---------------- Vista: Ajustes ---------------- */

function viewAjustes() {
  const st = state.data.settings;
  const f = (k, label, opts = {}) => `<label class="field">${label}<input data-k="${k}" type="${opts.type || 'text'}" value="${esc(st[k] || '')}" placeholder="${esc(opts.ph || '')}">${opts.help ? `<small>${opts.help}</small>` : ''}</label>`;
  $('#view').innerHTML = `
    <div class="cols">
      <section class="panel" style="flex:1 1 420px">
        <h2>Contacto y WhatsApp</h2>
        <div class="grid2">${f('whatsapp', 'Número de WhatsApp', { type: 'tel', help: 'Todos los botones de WhatsApp usan este número.' })}${f('phone2', 'Teléfono adicional', { type: 'tel' })}</div>
        ${f('wa_message', 'Mensaje predeterminado de WhatsApp')}
        ${f('address', 'Dirección')}
        <div class="grid2">${f('maps_url', 'Enlace de Google Maps', { type: 'url' })}${f('facebook_url', 'Página de Facebook', { type: 'url' })}</div>
        <label class="check"><input type="checkbox" data-k="float_button"${st.float_button === '1' ? ' checked' : ''}>Mostrar botón flotante de WhatsApp</label>
        ${f('float_label', 'Texto del botón flotante')}
      </section>
      <section class="panel" style="flex:1 1 360px">
        <h2>Textos generales</h2>
        ${f('site_title', 'Nombre del negocio')}
        ${f('tagline', 'Frase del pie de página')}
        ${f('topbar', 'Barra superior', { help: 'Déjalo vacío para ocultarla.' })}
        ${f('hero_badge', 'Precio destacado en la portada', { ph: '$999' })}
        <h2 style="margin-top:8px">Salón EMA</h2>
        <div class="grid2">${f('salon_web', 'Sitio web del salón', { type: 'url' })}${f('salon_capacity', 'Capacidad', { ph: 'Ej. 80 personas' })}</div>
      </section>
      <section class="panel" style="flex:1 1 320px">
        <h2>Seguridad</h2>
        <p class="muted" style="margin:0;font-size:14px">Contraseña del botón Admin del sitio.</p>
        <label class="field">Contraseña actual<input type="password" id="pw-cur" autocomplete="current-password"></label>
        <label class="field">Nueva contraseña<input type="password" id="pw-new" autocomplete="new-password"><small>Mínimo 6 caracteres.</small></label>
        <label class="field">Repetir nueva contraseña<input type="password" id="pw-rep" autocomplete="new-password"></label>
        <button type="button" class="btn btn-dark" id="pw-save">Cambiar contraseña</button>
      </section>
    </div>`;
  const pendingVals = {};
  const push = debounce(async () => {
    const values = Object.assign({}, pendingVals);
    Object.keys(pendingVals).forEach((k) => delete pendingVals[k]);
    await save(() => api.post({ action: 'settings', values }));
    Object.assign(state.data.settings, values);
  }, 700);
  $$('[data-k]').forEach((inp) => {
    const ev = inp.type === 'checkbox' ? 'change' : 'input';
    inp.addEventListener(ev, () => { pendingVals[inp.dataset.k] = inp.type === 'checkbox' ? (inp.checked ? '1' : '0') : inp.value; push(); });
  });
  $('#pw-save').addEventListener('click', async () => {
    const cur = $('#pw-cur').value, next = $('#pw-new').value, rep = $('#pw-rep').value;
    if (next.length < 6) { toast('La nueva contraseña debe tener al menos 6 caracteres.', true); return; }
    if (next !== rep) { toast('Las contraseñas nuevas no coinciden.', true); return; }
    await save(() => api.post({ action: 'password', current: cur, next }), 'Contraseña actualizada');
    $('#pw-cur').value = $('#pw-new').value = $('#pw-rep').value = '';
  });
}

/* ---------------- Inicio ---------------- */

async function init() {
  initPicker();
  $$('.side-nav button').forEach((b) => b.addEventListener('click', () => go(b.dataset.view)));
  try {
    const r = await api.load();
    state.data = r.data;
  } catch (e) {
    $('#view').innerHTML = `<p class="loading">No se pudo cargar el contenido: ${esc(e.message)}</p>`;
    return;
  }
  loadTiptap();
  loadSortable();
  let start = 'pagina';
  try { start = localStorage.getItem('vqb_view') || 'pagina'; } catch (e) { /* sin almacenamiento */ }
  go(VIEWS[start] ? start : 'pagina');
}
init();
