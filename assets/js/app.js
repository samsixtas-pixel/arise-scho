/* ============================================================
   app.js — shell behaviour (sidebar, connectivity, small helpers)
   ============================================================ */
(function () {
  'use strict';

  // ---------- mobile sidebar ----------
  const sidebar  = document.getElementById('sidebar');
  const backdrop = document.getElementById('sidebarBackdrop');
  const toggle   = document.getElementById('sidebarToggle');

  function closeSidebar() {
    if (!sidebar) return;
    sidebar.classList.remove('show');
    if (backdrop) backdrop.classList.remove('show');
  }

  if (toggle && sidebar) {
    toggle.addEventListener('click', function () {
      sidebar.classList.toggle('show');
      if (backdrop) backdrop.classList.toggle('show');
    });
  }

  if (backdrop) backdrop.addEventListener('click', closeSidebar);

  window.addEventListener('resize', function () {
    if (window.innerWidth >= 992) closeSidebar();
  });

  // ---------- connectivity ----------
  const status = document.getElementById('networkStatus');

  function updateNetwork() {
    if (!status) return;
    const online = navigator.onLine;
    status.className = 'network-status ' + (online ? 'online' : 'offline');
  }

  window.addEventListener('online', updateNetwork);
  window.addEventListener('offline', updateNetwork);
  updateNetwork();

  // ---------- auto-submit filter selects ----------
  document.querySelectorAll('[data-auto-submit]').forEach(function (el) {
    el.addEventListener('change', function () {
      const form = el.closest('form');
      if (form) form.submit();
    });
  });

  // ---------- dismissible inline alerts ----------
  document.querySelectorAll('.alert-dismissible').forEach(function (alert) {
    setTimeout(function () {
      alert.classList.add('fade');
      setTimeout(function () { alert.remove(); }, 250);
    }, 6000);
  });

  // ---------- print ----------
  document.querySelectorAll('[data-print]').forEach(function (btn) {
    btn.addEventListener('click', function () { window.print(); });
  });
})();
