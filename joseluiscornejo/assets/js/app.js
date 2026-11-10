// Botones + / − de cantidad
document.querySelectorAll('.qty').forEach(function (box) {
  var input = box.querySelector('input');
  box.querySelectorAll('[data-q]').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = Math.min(50, Math.max(1, (parseInt(input.value, 10) || 1) + parseInt(b.dataset.q, 10)));
      input.value = v;
    });
  });
});
