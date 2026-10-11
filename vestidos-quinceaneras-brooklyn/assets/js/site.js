// Filtros de la colección, "ver más" y ventana de acceso admin.
(function () {
  var LIMIT = 8;
  var grid = document.getElementById('dresses');
  var more = document.getElementById('more');
  var chips = document.querySelectorAll('.chip');
  var filter = 'todos';
  var expanded = false;

  function render() {
    if (!grid) return;
    var cards = grid.querySelectorAll('.dress');
    var matching = 0;
    cards.forEach(function (c) {
      var ok = filter === 'todos' || c.getAttribute('data-cat') === filter;
      if (ok) matching++;
      c.hidden = !ok || (!expanded && matching > LIMIT);
    });
    if (more) {
      more.hidden = matching <= LIMIT;
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
  render();

  var dlg = document.getElementById('admin-dialog');
  var open = document.getElementById('admin-open');
  var close = document.getElementById('admin-close');
  if (dlg && open) open.addEventListener('click', function () { dlg.showModal(); });
  if (dlg && close) close.addEventListener('click', function () { dlg.close(); });
})();
