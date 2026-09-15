(function () {
  'use strict';

  function initBackToTop() {
    if (document.getElementById('adminlte-profile-back-to-top')) return;

    var button = document.createElement('button');
    button.id = 'adminlte-profile-back-to-top';
    button.type = 'button';
    button.setAttribute('aria-label', 'Torna in cima');
    button.innerHTML = '<i class="bi bi-arrow-up"></i>';
    button.style.cssText = 'position:fixed;right:24px;bottom:24px;width:44px;height:44px;border:0;border-radius:50%;background:#0d6efd;color:#fff;display:none;align-items:center;justify-content:center;z-index:9999;box-shadow:0 2px 8px rgba(0,0,0,.25);cursor:pointer;';

    document.body.appendChild(button);

    function update() {
      button.style.display = window.scrollY > 400 ? 'flex' : 'none';
    }

    window.addEventListener('scroll', update, { passive: true });
    button.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
    update();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBackToTop);
  } else {
    initBackToTop();
  }
})();
