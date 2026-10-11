// Filtros de la colección, "ver más" y ventana de acceso admin.
(function () {
  var grid = document.getElementById('dresses');
  var more = document.getElementById('more');
  var chips = document.querySelectorAll('.chip');
  var filter = 'todos';
  var expanded = false;

  function render() {
    if (!grid) return;
    var cards = grid.querySelectorAll('.dress');
    // Filas completas: 2 filas con 3 o más columnas, 4 filas con 2 columnas.
    var cols = getComputedStyle(grid).gridTemplateColumns.split(' ').length || 1;
    var LIMIT = cols >= 3 ? cols * 2 : cols * 4;
    var matching = 0;
    cards.forEach(function (c) {
      var ok = filter === 'todos' || c.getAttribute('data-cat') === filter;
      if (ok) matching++;
      c.hidden = !ok || (!expanded && matching > LIMIT);
    });
    if (more) {
      var cols2 = getComputedStyle(grid).gridTemplateColumns.split(' ').length || 1;
      more.hidden = matching <= (cols2 >= 3 ? cols2 * 2 : cols2 * 4);
      more.textContent = expanded ? 'Ver menos' : 'Ver los ' + matching + ' vestidos';
    }
  }

  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      filter = chip.getAttribute('data-filter');
      expanded = false;
      chips.forEach(function (c) {
        var on = c === chip;
        c.classList.toggle('is-on', on);
        c.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      render();
    });
  });
  if (more) more.addEventListener('click', function () { expanded = !expanded; render(); });
  var rt;
  window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(render, 150); });
  render();

  var dlg = document.getElementById('admin-dialog');
  var open = document.getElementById('admin-open');
  var close = document.getElementById('admin-close');
  if (dlg && open) open.addEventListener('click', function () { dlg.showModal(); });
  if (dlg && close) close.addEventListener('click', function () { dlg.close(); });
})();

// Cierra el menú móvil al elegir una sección o al tocar fuera.
(function () {
  var menu = document.querySelector('.menu');
  if (!menu) return;
  menu.addEventListener('click', function (e) { if (e.target.closest('a')) menu.open = false; });
  document.addEventListener('click', function (e) { if (menu.open && !menu.contains(e.target)) menu.open = false; });
})();
