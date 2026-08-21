document.addEventListener('DOMContentLoaded', function () {
  var root = document.documentElement;
  var toggle = document.querySelector('[data-theme-toggle]');
  var currentTheme = localStorage.getItem('lakeland-theme') || root.getAttribute('data-theme') || 'light';

  function applyTheme(theme) {
    root.setAttribute('data-theme', theme);
    localStorage.setItem('lakeland-theme', theme);

    if (toggle) {
      var isDark = theme === 'dark';
      toggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
      toggle.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');

      var text = toggle.querySelector('.theme-toggle-text');
      if (text) text.textContent = isDark ? 'Light mode' : 'Dark mode';
    }
  }

  applyTheme(currentTheme);

  if (toggle) {
    toggle.addEventListener('click', function () {
      applyTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    });
  }

  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!confirm(el.getAttribute('data-confirm'))) {
        e.preventDefault();
      }
    });
  });

  document.querySelectorAll('[data-toast]').forEach(function (toast) {
    var close = toast.querySelector('[data-toast-close]');
    var timer = null;

    function dismiss() {
      toast.classList.add('hide');
      setTimeout(function () {
        var stack = toast.closest('.toast-stack');
        toast.remove();
        if (stack && !stack.querySelector('[data-toast]')) stack.remove();
      }, 260);
    }

    function startTimer() {
      timer = setTimeout(dismiss, 5000);
    }

    function stopTimer() {
      clearTimeout(timer);
    }

    if (close) close.addEventListener('click', dismiss);
    toast.addEventListener('mouseenter', stopTimer);
    toast.addEventListener('mouseleave', startTimer);
    startTimer();
  });
});
