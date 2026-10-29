/* Sofi — admin shared helpers (media picker, uploads, toasts, small widgets) */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var meta = function (n) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.content : ''; };

  var Admin = window.Admin = {
    csrf: meta('csrf'),
    base: meta('base'),
    url: function (p) { return Admin.base + '/' + String(p).replace(/^\//, ''); },
    media: function (p) { return !p ? '' : (/^(https?:)?\/\//.test(p) ? p : Admin.url(p)); },
    esc: function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); },
    toast: function (msg, type) {
      var t = $('#toast'); if (!t) { alert(msg); return; }
      t.textContent = msg; t.className = 'toast ' + (type || ''); t.hidden = false;
      clearTimeout(t._h); t._h = setTimeout(function () { t.hidden = true; }, type === 'bad' ? 6000 : 3000);
    },
    /** Upload files with progress. Resolves with [{path,url,kind,...}] */
    upload: function (files, progressEl) {
      return new Promise(function (resolve, reject) {
        var fd = new FormData();
        Array.prototype.forEach.call(files, function (f) { fd.append('files[]', f); });
        fd.append('_csrf', Admin.csrf);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', Admin.url('/admin/api/upload'));
        xhr.setRequestHeader('X-CSRF-Token', Admin.csrf);
        if (progressEl) {
          progressEl.hidden = false;
          var bar = progressEl.querySelector('div'), lbl = progressEl.querySelector('span');
          xhr.upload.onprogress = function (e) {
            if (!e.lengthComputable) return;
            var p = Math.round(e.loaded / e.total * 100);
            bar.style.width = p + '%'; lbl.textContent = 'Subiendo ' + files.length + ' archivo(s)… ' + p + '%';
          };
        }
        xhr.onload = function () {
          if (progressEl) progressEl.hidden = true;
          var res; try { res = JSON.parse(xhr.responseText); } catch (e) { res = { error: 'Respuesta inesperada del servidor (' + xhr.status + ').' }; }
          if (xhr.status >= 400 || res.error) { reject(new Error(res.error || ('Error ' + xhr.status))); return; }
          if (res.errors && res.errors.length) Admin.toast(res.errors.join(' · '), 'bad');
          resolve(res.files || []);
        };
        xhr.onerror = function () { if (progressEl) progressEl.hidden = true; reject(new Error('No se pudo conectar con el servidor.')); };
        xhr.send(fd);
      });
    },
    /** Open the media library. opts: {kind:'image'|'video'|'', multiple:bool}. Resolves with selected items. */
    pick: function (opts) {
      opts = opts || {};
      var modal = $('#media-modal'), grid = $('[data-mm-grid]', modal), up = $('[data-mm-upload]', modal);
      return new Promise(function (resolve) {
        var chosen = [];
        function close(result) { modal.hidden = true; document.removeEventListener('keydown', onKey); resolve(result || []); }
        function onKey(e) { if (e.key === 'Escape') close([]); }
        function render(files) {
          if (!files.length) { grid.innerHTML = '<p class="muted">Aún no hay archivos. Usa “Subir archivos”.</p>'; return; }
          grid.innerHTML = '';
          files.forEach(function (f) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'mm-item'; b.title = f.name || '';
            b.innerHTML = (f.kind === 'video' ? '<video src="' + Admin.esc(f.url) + '" muted preload="metadata"></video><span>VIDEO</span>' : '<img src="' + Admin.esc(f.url) + '" alt="" loading="lazy">' + (f.kind === 'gif' ? '<span>GIF</span>' : ''));
            b.addEventListener('click', function () {
              if (!opts.multiple) { close([f]); return; }
              var i = chosen.indexOf(f);
              if (i >= 0) { chosen.splice(i, 1); b.style.borderColor = ''; } else { chosen.push(f); b.style.borderColor = '#B83A6B'; }
              done.textContent = 'Usar ' + chosen.length + ' seleccionado(s)';
            });
            grid.appendChild(b);
          });
        }
        var done = $('[data-mm-done]', modal);
        if (!done) {
          done = document.createElement('button'); done.type = 'button'; done.className = 'btn btn-primary sm'; done.setAttribute('data-mm-done', '');
          $('.modal-head', modal).insertBefore(done, $('[data-mm-close]', modal));
        }
        done.hidden = !opts.multiple; done.textContent = 'Usar seleccionados';
        done.onclick = function () { close(chosen); };
        $('[data-mm-close]', modal).onclick = function () { close([]); };
        modal.onclick = function (e) { if (e.target === modal) close([]); };
        up.onchange = function () {
          if (!up.files.length) return;
          grid.insertAdjacentHTML('afterbegin', '<p class="muted" style="grid-column:1/-1">Subiendo…</p>');
          Admin.upload(up.files).then(function (files) {
            up.value = '';
            if (!opts.multiple && files.length === 1) { close(files); return; }
            load();
          }).catch(function (e) { Admin.toast(e.message, 'bad'); load(); });
        };
        function load() {
          fetch(Admin.url('/admin/api/media') + '?kind=' + encodeURIComponent(opts.kind || ''), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); }).then(function (d) { render(d.files || []); })
            .catch(function () { grid.innerHTML = '<p class="muted">No se pudo cargar la biblioteca.</p>'; });
        }
        grid.innerHTML = '<p class="muted">Cargando…</p>';
        modal.hidden = false; document.addEventListener('keydown', onKey);
        load();
      });
    },
    post: function (url, data) {
      var fd = new FormData();
      Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
      fd.append('_csrf', Admin.csrf);
      return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); });
    }
  };

  /* ---------- sidebar ---------- */
  var st = $('[data-side-toggle]');
  if (st) st.addEventListener('click', function () { $('#side').classList.toggle('open'); });

  /* ---------- password visibility ---------- */
  $$('[data-pw-toggle]').forEach(function (b) {
    b.addEventListener('click', function () { var i = b.parentNode.querySelector('input'); i.type = i.type === 'password' ? 'text' : 'password'; });
  });

  /* ---------- auto submit / confirm / copy ---------- */
  $$('[data-autosubmit]').forEach(function (el) { el.addEventListener('change', function () { el.form && el.form.submit(); }); });
  document.addEventListener('click', function (e) {
    var c = e.target.closest('[data-confirm]');
    if (c && !confirm(c.getAttribute('data-confirm'))) { e.preventDefault(); e.stopPropagation(); return; }
    var cp = e.target.closest('[data-copy]');
    if (cp) {
      var txt = cp.getAttribute('data-copy');
      (navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject()).then(function () { Admin.toast('Copiado'); }, function () { prompt('Copia este texto:', txt); });
    }
  }, true);

  /* ---------- single image fields ---------- */
  $$('[data-image-field]').forEach(function (box) {
    var input = box.querySelector('input[type=hidden]');
    var prev = box.querySelector('.img-prev') || box.querySelector('.thumb-btn');
    function show() {
      if (!prev || prev.classList.contains('tabs-prev')) return;
      prev.innerHTML = input.value ? '<img src="' + Admin.esc(Admin.media(input.value)) + '" alt="">' : (prev.classList.contains('thumb-btn') ? '+' : '<span class="muted">Sin imagen</span>');
    }
    $$('[data-pick]', box).forEach(function (b) {
      b.addEventListener('click', function () {
        Admin.pick({ kind: 'image' }).then(function (sel) {
          if (!sel.length) return;
          input.value = sel[0].path; show();
          var fav = box.querySelectorAll('.tabs-prev img'); fav.forEach(function (i) { i.src = sel[0].url; });
          var cb = box.querySelector('input[name=favicon_from_logo]'); if (cb) cb.checked = false;
          input.dispatchEvent(new Event('change', { bubbles: true }));
        });
      });
    });
    var clr = box.querySelector('[data-clear]');
    if (clr) clr.addEventListener('click', function () { input.value = ''; show(); input.dispatchEvent(new Event('change', { bubbles: true })); });
  });

  /* ---------- range output ---------- */
  $$('[data-range-in]').forEach(function (r) {
    var out = r.closest('label').querySelector('[data-range-out]');
    r.addEventListener('input', function () { if (out) out.textContent = r.value; });
  });

  /* ---------- character counters ---------- */
  $$('[data-counter]').forEach(function (el) {
    var max = +el.getAttribute('data-counter');
    var out = document.querySelector('[data-count-for="' + el.name + '"]');
    function upd() { if (!out) return; var n = el.value.length; out.textContent = n + ' / ' + max; out.style.color = n > max ? '#9A5B00' : '#1F6B3C'; }
    el.addEventListener('input', upd); upd();
  });

  /* ---------- EN/ES tabs inside a card ---------- */
  $$('[data-lang-tab]').forEach(function (b) {
    b.addEventListener('click', function () {
      var scope = b.closest('.card') || document;
      var l = b.getAttribute('data-lang-tab');
      $$('[data-lang-tab]', scope).forEach(function (x) { x.classList.toggle('on', x === b); x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
      $$('[data-lang-pane]', scope).forEach(function (p) { p.hidden = p.getAttribute('data-lang-pane') !== l; });
      document.dispatchEvent(new CustomEvent('langtab', { detail: l }));
    });
  });

  /* ---------- unsaved changes warning ---------- */
  $$('form[data-dirty-watch]').forEach(function (f) {
    var dirty = false, flag = f.querySelector('[data-dirty]');
    function mark() { dirty = true; if (flag) flag.hidden = false; }
    f.addEventListener('input', mark); f.addEventListener('change', mark);
    f.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    f._markDirty = mark;
  });

  /* ---------- settings: remember section, test buttons, password meter ---------- */
  var sf = $('[data-section-field]');
  if (sf) document.addEventListener('focusin', function (e) { var s = e.target.closest('.card[id]'); if (s) sf.value = s.id; });
  $$('[data-test]').forEach(function (b) {
    b.addEventListener('click', function () {
      var label = b.textContent; b.disabled = true; b.textContent = 'Probando…';
      Admin.post(b.getAttribute('data-test'), {}).then(function (r) { Admin.toast(r.message, r.ok ? 'ok' : 'bad'); })
        .catch(function () { Admin.toast('No se pudo completar la prueba.', 'bad'); })
        .then(function () { b.disabled = false; b.textContent = label; });
    });
  });
  var pw = $('[data-pw-new]'), meter = $('[data-pw-meter]');
  if (pw && meter) pw.addEventListener('input', function () {
    var v = pw.value, s = 0;
    if (v.length >= 8) s++; if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++; if (/\d/.test(v)) s++; if (/[^A-Za-z0-9]/.test(v) || v.length >= 12) s++;
    $$('i', meter).forEach(function (i, k) { i.classList.toggle('on', k < s); });
  });

  /* ---------- products list: bulk + drag sort ---------- */
  var bulk = $('[data-bulk]');
  if (bulk) {
    var bar = $('[data-bulkbar]'), n = $('[data-bulk-n]'), all = $('[data-check-all]');
    function sync() { var c = $$('input[name="ids[]"]:checked', bulk).length; bar.hidden = !c; n.textContent = c + ' seleccionado(s)'; }
    bulk.addEventListener('change', sync);
    if (all) all.addEventListener('change', function () { $$('input[name="ids[]"]', bulk).forEach(function (c) { c.checked = all.checked; }); sync(); });
  }
  $$('tbody[data-sortable]').forEach(function (tb) {
    var drag = null;
    tb.addEventListener('dragstart', function (e) { drag = e.target.closest('tr'); if (drag) { drag.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; } });
    tb.addEventListener('dragover', function (e) {
      e.preventDefault(); var tr = e.target.closest('tr'); if (!tr || tr === drag) return;
      $$('.drop-before', tb).forEach(function (x) { x.classList.remove('drop-before'); }); tr.classList.add('drop-before');
    });
    tb.addEventListener('drop', function (e) {
      e.preventDefault(); var tr = e.target.closest('tr'); if (!tr || !drag || tr === drag) return;
      tb.insertBefore(drag, tr);
      $$('.drop-before', tb).forEach(function (x) { x.classList.remove('drop-before'); });
      var url = tb.getAttribute('data-sortable');
      Promise.all($$('tr[data-id]', tb).map(function (row, i) { return Admin.post(url + '?id=' + row.getAttribute('data-id'), { do: 'sort', sort: i }); }))
        .then(function () { Admin.toast('Orden guardado', 'ok'); });
    });
    tb.addEventListener('dragend', function () { if (drag) drag.classList.remove('dragging'); drag = null; });
  });

  /* ---------- media library page upload ---------- */
  var lu = $('[data-library-upload]');
  if (lu) lu.addEventListener('change', function () {
    Admin.upload(lu.files, $('[data-upload-progress]')).then(function (files) { Admin.toast(files.length + ' archivo(s) subido(s)', 'ok'); setTimeout(function () { location.reload(); }, 600); })
      .catch(function (e) { Admin.toast(e.message, 'bad'); });
  });

  /* ---------- offers: live marquee preview ---------- */
  var mqs = $('[data-mq-source]'), mqp = $('[data-mq-preview]');
  if (mqs && mqp) mqs.addEventListener('input', function () {
    var items = mqs.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
    mqp.innerHTML = items.concat(items).map(function (s) { return '<span>♥ ' + Admin.esc(s) + '</span>'; }).join('');
  });

  /* ---------- SEO page live previews ---------- */
  var seo = $('[data-seo]');
  if (seo) {
    var lang = 'en';
    var tpl = function (sel) { return seo.querySelector(sel); };
    function upd() {
      var title = (tpl('[data-og-title="' + lang + '"]').value || tpl('[data-seo-title="' + lang + '"]').value);
      var desc = (tpl('[data-og-desc="' + lang + '"]').value || tpl('[data-seo-desc="' + lang + '"]').value);
      $$('[data-prev-title]', seo).forEach(function (x) { x.textContent = title; });
      $$('[data-prev-desc]', seo).forEach(function (x) { x.textContent = desc.length > 110 ? desc.slice(0, 108) + '…' : desc; });
      $$('[data-prev-gtitle]', seo).forEach(function (x) { x.textContent = tpl('[data-seo-title="' + lang + '"]').value; });
      $$('[data-prev-gdesc]', seo).forEach(function (x) { x.textContent = tpl('[data-seo-desc="' + lang + '"]').value; });
      var mode = (seo.querySelector('[data-og-mode]:checked') || {}).value;
      var custom = tpl('[data-og-custom]').value;
      var bg = (seo.querySelector('[data-og-bg]:checked') || {}).getAttribute ? seo.querySelector('[data-og-bg]:checked').getAttribute('data-og-bg') : '#FDF7F5';
      $$('[data-og-img]', seo).forEach(function (box) { box.style.setProperty('--og-bg', bg); });
      $$('[data-og-custom-img]', seo).forEach(function (img) {
        var show = mode === 'custom' && custom; img.hidden = !show; if (show) img.src = Admin.media(custom);
      });
    }
    seo.addEventListener('input', upd); seo.addEventListener('change', upd);
    document.addEventListener('langtab', function (e) { lang = e.detail; upd(); });
    $$('[data-prev-tab]', seo).forEach(function (b) {
      b.addEventListener('click', function () {
        $$('[data-prev-tab]', seo).forEach(function (x) { x.classList.toggle('on', x === b); });
        $$('[data-prev]', seo).forEach(function (p) { p.hidden = p.getAttribute('data-prev') !== b.getAttribute('data-prev-tab'); });
      });
    });
    upd();
  }
})();
