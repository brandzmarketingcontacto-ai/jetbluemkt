// Editor del panel: TipTap + editor visual con vista previa en vivo.
(function () {
  'use strict';
  var studio = document.querySelector('[data-studio]');
  var frame = studio && studio.querySelector('[data-frame]');
  var origin = location.origin;
  var editors = {};

  function post(msg) {
    if (frame && frame.contentWindow) frame.contentWindow.postMessage(msg, origin);
  }

  // ---------- TipTap ----------
  document.querySelectorAll('.tt').forEach(function (box) {
    var fld = box.closest('.fld');
    var input = fld.querySelector('[data-tt-input]');
    var key = fld.getAttribute('data-key');
    var mount = box.querySelector('[data-tt]');
    var count = box.querySelector('[data-tt-count]');
    var ed = window.JLCEditor.create(mount, input.value, function (html, editor) {
      input.value = html;
      updateCount(editor);
      if (studio) { post({ type: 'set', key: key, value: html }); markDirty(); }
    });
    editors[key] = ed;
    function updateCount(editor) {
      var words = editor.getText().trim().split(/\s+/).filter(Boolean).length;
      count.textContent = words + (words === 1 ? ' palabra' : ' palabras');
    }
    updateCount(ed);

    var buttons = box.querySelectorAll('[data-cmd]');
    function refresh() {
      buttons.forEach(function (b) {
        var c = b.getAttribute('data-cmd'), on = false;
        if (c === 'h2') on = ed.isActive('heading', { level: 2 });
        else if (c === 'h3') on = ed.isActive('heading', { level: 3 });
        else if (c === 'left') on = ed.isActive({ textAlign: 'left' });
        else if (c === 'center') on = ed.isActive({ textAlign: 'center' });
        else if (['bold', 'italic', 'underline', 'link', 'bulletList', 'orderedList', 'blockquote'].indexOf(c) > -1) on = ed.isActive(c);
        b.classList.toggle('on', on);
        if (c === 'undo') b.disabled = !ed.can().undo();
        if (c === 'redo') b.disabled = !ed.can().redo();
      });
    }
    mount.addEventListener('tt-state', refresh);
    refresh();
    box.addEventListener('click', function (e) {
      var b = e.target.closest('[data-cmd]');
      if (!b) return;
      var c = b.getAttribute('data-cmd'), ch = ed.chain().focus();
      switch (c) {
        case 'undo': ch.undo().run(); break;
        case 'redo': ch.redo().run(); break;
        case 'h2': ch.toggleHeading({ level: 2 }).run(); break;
        case 'h3': ch.toggleHeading({ level: 3 }).run(); break;
        case 'bold': ch.toggleBold().run(); break;
        case 'italic': ch.toggleItalic().run(); break;
        case 'underline': ch.toggleUnderline().run(); break;
        case 'bulletList': ch.toggleBulletList().run(); break;
        case 'orderedList': ch.toggleOrderedList().run(); break;
        case 'blockquote': ch.toggleBlockquote().run(); break;
        case 'left': ch.setTextAlign('left').run(); break;
        case 'center': ch.setTextAlign('center').run(); break;
        case 'clear': ch.unsetAllMarks().clearNodes().run(); break;
        case 'link':
          var prev = ed.getAttributes('link').href || '';
          var url = window.prompt('Dirección del enlace (déjalo vacío para quitarlo):', prev || 'https://');
          if (url === null) return;
          if (url === '' || url === 'https://') ch.extendMarkRange('link').unsetLink().run();
          else ch.extendMarkRange('link').setLink({ href: url }).run();
          break;
      }
    });
    mount.addEventListener('focusin', function () { box.classList.add('focus'); if (studio) post({ type: 'reveal', key: key }); });
    mount.addEventListener('focusout', function () { box.classList.remove('focus'); });
  });

  if (!studio) return;

  // ---------- Editor visual ----------
  var form = studio.querySelector('[data-form]');
  var saveBtn = studio.querySelector('[data-save]');
  var statusEl = studio.querySelector('[data-status]');
  var toast = studio.querySelector('[data-toast]');
  var wrap = studio.querySelector('[data-frame-wrap]');
  var loading = studio.querySelector('[data-loading]');
  var dirty = false, saving = false, ready = false, pending = [];

  function setStatus(kind, text) {
    statusEl.className = 'st-status ' + kind;
    statusEl.querySelector('span').textContent = text;
  }
  function markDirty() {
    if (!dirty) { dirty = true; setStatus('dirty', 'Cambios sin publicar'); }
    saveBtn.disabled = false;
  }
  function showToast(text, err) {
    toast.textContent = text;
    toast.className = 'toast show' + (err ? ' err' : '');
    clearTimeout(showToast.t);
    showToast.t = setTimeout(function () { toast.className = 'toast'; }, 2600);
  }
  function send(key, value) {
    var msg = { type: 'set', key: key, value: value };
    if (ready) post(msg); else pending.push(msg);
  }

  // Cambios en vivo
  form.addEventListener('input', function (e) {
    var el = e.target;
    if (!el.hasAttribute('data-live')) return;
    var key = el.closest('.fld').getAttribute('data-key');
    if (el.type === 'range') {
      var out = el.closest('.fld').querySelector('[data-out]');
      if (out) out.textContent = el.value;
    }
    if (el.type === 'file') return;
    send(key, el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value);
    markDirty();
  });
  form.addEventListener('change', function (e) {
    var el = e.target, fld = el.closest('.fld');
    if (!fld) return;
    var key = fld.getAttribute('data-key');
    if (el.type === 'checkbox' && el.hasAttribute('data-live')) { send(key, el.checked ? '1' : '0'); markDirty(); }
    if (el.type === 'file' && el.files && el.files[0]) {
      var url = URL.createObjectURL(el.files[0]);
      var thumb = fld.querySelector('[data-thumb]');
      thumb.src = url; fld.querySelector('.drop').classList.add('has');
      var clear = fld.querySelector('[data-clear]'); if (clear) clear.checked = false;
      send(key, url); markDirty();
    }
    if (el.hasAttribute('data-clear')) {
      if (el.checked) send(key, '');
      markDirty();
    }
  });

  // Arrastrar y soltar imágenes
  form.querySelectorAll('.drop').forEach(function (d) {
    ['dragenter', 'dragover'].forEach(function (ev) { d.addEventListener(ev, function (e) { e.preventDefault(); d.classList.add('over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { d.addEventListener(ev, function (e) { e.preventDefault(); d.classList.remove('over'); }); });
    d.addEventListener('drop', function (e) {
      var input = d.querySelector('input[type=file]');
      if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; input.dispatchEvent(new Event('change', { bubbles: true })); }
    });
  });

  // Textareas que crecen solas
  form.querySelectorAll('[data-autosize]').forEach(function (t) {
    function fit() { t.style.height = 'auto'; t.style.height = (t.scrollHeight + 2) + 'px'; }
    t.addEventListener('input', fit); fit();
  });

  // Al enfocar un campo, la vista previa muestra dónde está
  form.addEventListener('focusin', function (e) {
    var fld = e.target.closest('.fld');
    form.querySelectorAll('.fld.active').forEach(function (f) { f.classList.remove('active'); });
    if (fld) { fld.classList.add('active'); post({ type: 'reveal', key: fld.getAttribute('data-key') }); }
  });

  // Mensajes desde la vista previa
  window.addEventListener('message', function (e) {
    if (e.origin !== origin || !e.data) return;
    if (e.data.type === 'ready') {
      ready = true; loading.classList.add('done');
      pending.splice(0).forEach(post);
    }
    if (e.data.type === 'focus') focusField(e.data.key);
  });
  frame.addEventListener('load', function () { setTimeout(function () { loading.classList.add('done'); }, 300); });

  function focusField(key) {
    var fld = form.querySelector('.fld[data-key="' + key + '"]');
    if (!fld) return;
    var det = fld.closest('details'); if (det) det.open = true;
    if (window.matchMedia('(max-width: 900px)').matches) setView('edit');
    fld.scrollIntoView({ behavior: 'smooth', block: 'center' });
    fld.classList.remove('pulse'); void fld.offsetWidth; fld.classList.add('pulse');
    setTimeout(function () {
      if (editors[key]) editors[key].commands.focus('end');
      else { var i = fld.querySelector('input:not([type=hidden]),textarea'); if (i) { i.focus({ preventScroll: true }); if (i.select && i.type !== 'range' && i.type !== 'file' && i.type !== 'checkbox') i.setSelectionRange(i.value.length, i.value.length); } }
    }, 250);
  }

  // Guardar sin recargar
  function save() {
    if (saving || !dirty) return;
    saving = true; saveBtn.disabled = true; setStatus('saving', 'Publicando…');
    fetch(form.action || location.href, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { throw new Error('Respuesta inesperada del servidor'); }); })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'No se pudo guardar');
        dirty = false; setStatus('ok', 'Publicado a las ' + res.saved_at);
        showToast('Cambios publicados');
        form.querySelectorAll('input[type=file]').forEach(function (i) { i.value = ''; });
        form.querySelectorAll('[data-clear]').forEach(function (c) {
          if (c.checked) { var f = c.closest('.fld'); f.querySelector('.drop').classList.remove('has'); f.querySelector('[data-thumb]').src = ''; c.checked = false; }
        });
        ready = false; loading.classList.remove('done');
        frame.contentWindow.location.reload();
      })
      .catch(function (err) { setStatus('dirty', 'Sin publicar'); saveBtn.disabled = false; showToast(err.message, true); })
      .then(function () { saving = false; });
  }
  saveBtn.addEventListener('click', save);
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); }
  });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  // Tamaño de pantalla de la vista previa
  studio.querySelectorAll('[data-device]').forEach(function (b) {
    b.addEventListener('click', function () {
      studio.querySelectorAll('[data-device]').forEach(function (x) { x.classList.toggle('on', x === b); });
      wrap.style.maxWidth = b.getAttribute('data-device');
      wrap.classList.toggle('device', b.getAttribute('data-device') !== '100%');
    });
  });

  // Celular: cambiar entre editar y vista previa
  function setView(v) {
    studio.setAttribute('data-view', v);
    studio.querySelectorAll('[data-view]').forEach(function (b) { if (b.tagName === 'BUTTON') b.classList.toggle('on', b.getAttribute('data-view') === v); });
  }
  studio.querySelectorAll('button[data-view]').forEach(function (b) { b.addEventListener('click', function () { setView(b.getAttribute('data-view')); }); });
  setView('edit');
})();
