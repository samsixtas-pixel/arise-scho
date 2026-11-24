    </main>

    <footer class="app-footer">
      <span><?= e(Settings::businessName()) ?> · <?= t('Weekly Loan Management System') ?></span>
      <span class="d-none d-sm-inline">
        <?= e(date(DISPLAY_DATE_FORMAT)) ?> · v<?= APP_VERSION ?>
      </span>
    </footer>
  </div>
</div>

<script>window.BASE_URL = <?= json_encode(BASE_URL) ?>;</script>
<script src="<?= BASE_URL ?>assets/vendor/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/sweetalert2.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/app.js"></script>
<script src="<?= BASE_URL ?>assets/js/lms.js"></script>

<?php foreach (($pageScripts ?? []) as $script): ?>
<script src="<?= BASE_URL . ltrim((string) $script, '/') ?>"></script>
<?php endforeach; ?>

<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('<?= BASE_URL ?>sw.js', { scope: '<?= BASE_URL ?>' })
      .catch(() => {});
  });
}
</script>

<?php foreach (takeFlashes() as $flash): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  Swal.fire({
    icon: <?= json_encode($flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'error')) ?>,
    title: <?= json_encode($flash['type'] === 'success' ? t('Done') : ($flash['type'] === 'warning' ? t('Please check') : t('Something went wrong'))) ?>,
    text: <?= json_encode($flash['message']) ?>,
    confirmButtonColor: '#0D6348'
  });
});
</script>
<?php endforeach; ?>

<?php if (isset($_GET['success'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  Swal.fire({ icon: 'success', title: <?= json_encode(t('Done')) ?>, text: <?= json_encode((string) $_GET['success']) ?>, timer: 2200, showConfirmButton: false });
});
</script>
<?php endif; ?>

<?php if (isset($_GET['error'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  Swal.fire({ icon: 'error', title: <?= json_encode(t('Something went wrong')) ?>, text: <?= json_encode((string) $_GET['error']) ?>, confirmButtonColor: '#0D6348' });
});
</script>
<?php endif; ?>

<script>
(function () {
  const token = document.querySelector('meta[name="csrf-token"]')?.content;
  if (!token) return;

  document.querySelectorAll('form').forEach(form => {
    if ((form.method || 'get').toLowerCase() === 'post' && !form.querySelector('input[name="csrf_token"]')) {
      const input = document.createElement('input');
      input.type  = 'hidden';
      input.name  = 'csrf_token';
      input.value = token;
      form.appendChild(input);
    }
  });

  const originalFetch = window.fetch;
  window.fetch = function (url, options) {
    options = options || {};
    if (options.method && options.method.toUpperCase() !== 'GET') {
      options.headers = new Headers(options.headers || {});
      if (!options.headers.has('X-CSRF-Token')) {
        options.headers.set('X-CSRF-Token', token);
      }
    }
    return originalFetch.call(this, url, options);
  };
})();
</script>

</body>
</html>
