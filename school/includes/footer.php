</main><footer class="app-footer"><span><?= e($schoolName ?? 'School Management') ?> · School Management System</span><span class="d-none d-sm-inline"><?= e(date('d/m/Y')) ?> · TZS</span></footer>
</div></div>
<script>window.BASE_URL=<?= json_encode(SCHOOL_BASE_URL) ?>;</script>
<script src="<?= SCHOOL_ASSET_URL ?>vendor/bootstrap.bundle.min.js"></script><script src="<?= SCHOOL_ASSET_URL ?>vendor/sweetalert2.min.js"></script><script src="<?= SCHOOL_ASSET_URL ?>js/app.js"></script>
<script>if('serviceWorker'in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('/sw.js',{scope:'/'}).catch(()=>{}));}</script>
</body></html>
