document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!confirm(el.getAttribute('data-confirm'))) {
        e.preventDefault();
      }
    });
  });

  var flash = document.querySelector('.flash');
  if (flash) {
    setTimeout(function () { flash.style.transition = 'opacity .5s'; flash.style.opacity = '0'; }, 4000);
  }
});
