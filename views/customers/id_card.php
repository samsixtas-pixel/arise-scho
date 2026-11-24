<?php
/**
 * views/customers/id_card.php — printable customer identification card.
 * -------------------------------------------------------
 * The QR code contains only the authenticated profile URL, so
 * scanning it never reveals the NIDA number, phone or balance.
 */

require_once __DIR__ . '/../../includes/autoload.php';
User::requireLogin();

$id       = (int) ($_GET['id'] ?? 0);
$customer = (new Customer())->find($id);

if (!$customer) {
    flash('error', t('Customer not found.'));
    redirect(BASE_URL . 'views/customers/index.php');
}

$photo      = PhotoStorage::url($customer['photo_path'] ?? null);
$company    = Settings::businessName();
$scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host       = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$profileUrl = $scheme . '://' . $host . BASE_URL . 'views/customers/view.php?id=' . $id;
?>
<!DOCTYPE html>
<html lang="<?= current_lang() === 'sw' ? 'sw' : 'en' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($customer['reference']) ?> · <?= t('Customer profile') ?></title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/loan-manager-icon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap-icons.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/../../assets/css/style.css') ?>">
<style>
  body { background: #EEF2F0; padding: 1.5rem; }
  .card-toolbar { max-width: 720px; margin: 0 auto 1rem; display: flex; gap: .5rem; flex-wrap: wrap; }
  .idcard {
    width: 92mm;
    min-height: 58mm;
    margin: 0 auto;
    background: #fff;
    border-radius: 3.5mm;
    box-shadow: 0 10px 30px rgba(6, 51, 40, .18);
    overflow: hidden;
    border: 1px solid #DDE8E2;
  }
  .idcard-band {
    background: #063328;
    color: #fff;
    padding: 2.2mm 3.5mm;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .idcard-band strong { font-size: 3.1mm; letter-spacing: .04em; text-transform: uppercase; }
  .idcard-band span { font-size: 2.4mm; letter-spacing: .12em; color: #A8E6C8; text-transform: uppercase; }
  .idcard-body { display: flex; gap: 3mm; padding: 3.5mm; align-items: flex-start; }
  .idcard-photo {
    width: 22mm; height: 27mm; flex: 0 0 22mm;
    border-radius: 1.6mm; overflow: hidden; background: #EDF9F3;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid #D4F4E4;
  }
  .idcard-photo img { width: 100%; height: 100%; object-fit: cover; }
  .idcard-initials { font-size: 7mm; font-weight: 800; color: #118057; }
  .idcard-main { flex: 1 1 auto; min-width: 0; }
  .idcard-label { font-size: 2.2mm; letter-spacing: .1em; text-transform: uppercase; color: #5C7068; margin-bottom: .6mm; }
  .idcard-name { font-size: 3.5mm; font-weight: 800; line-height: 1.2; color: #111A16; margin-bottom: 2mm; }
  .idcard-ref { font-size: 3.6mm; font-weight: 800; letter-spacing: .04em; color: #0A4A38; font-variant-numeric: tabular-nums; }
  .idcard-meta { font-size: 2.4mm; color: #5C7068; margin-top: 1.6mm; }
  .idcard-qr { width: 21mm; flex: 0 0 21mm; text-align: center; }
  .idcard-qr svg { width: 21mm; height: 21mm; display: block; }
  .idcard-qr small { font-size: 1.9mm; color: #5C7068; display: block; margin-top: .6mm; }
  .idcard-foot {
    border-top: 1px dashed #DDE8E2;
    padding: 1.6mm 3.5mm;
    font-size: 2.2mm;
    color: #5C7068;
    display: flex;
    justify-content: space-between;
    gap: 2mm;
  }

  @media print {
    @page { size: A4; margin: 12mm; }
    body { background: #fff; padding: 0; }
    .card-toolbar { display: none; }
    .idcard { box-shadow: none; margin: 0; }
  }
</style>
</head>
<body>

<div class="card-toolbar">
  <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>views/customers/view.php?id=<?= $id ?>">
    <i class="bi bi-arrow-left me-1"></i><?= t('Customer profile') ?>
  </a>
  <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">
    <i class="bi bi-printer me-1"></i><?= t('Print') ?>
  </button>
</div>

<div class="idcard">
  <div class="idcard-band">
    <strong><?= e($company) ?></strong>
    <span><?= t('Customer') ?> ID</span>
  </div>

  <div class="idcard-body">
    <div class="idcard-photo">
      <?php if ($photo): ?>
        <img src="<?= e($photo) ?>" alt="<?= e($customer['full_name']) ?>">
      <?php else: ?>
        <span class="idcard-initials"><?= e(initials($customer['full_name'])) ?></span>
      <?php endif; ?>
    </div>

    <div class="idcard-main">
      <div class="idcard-label"><?= t('Full name') ?></div>
      <div class="idcard-name"><?= e($customer['full_name']) ?></div>

      <div class="idcard-label"><?= t('Customer') ?> ID</div>
      <div class="idcard-ref"><?= e($customer['reference']) ?></div>

      <div class="idcard-meta"><?= t('Registered :date', ['date' => formatDate($customer['registered_at'])]) ?></div>
    </div>

    <div class="idcard-qr">
      <div id="qr"></div>
      <small><?= t('Scan to open') ?></small>
    </div>
  </div>

  <div class="idcard-foot">
    <span><?= e($company) ?></span>
    <span><?= e(Settings::businessPhone()) ?></span>
  </div>
</div>

<script src="<?= BASE_URL ?>assets/vendor/qrcode.js"></script>
<script>
  (function () {
    var target = document.getElementById('qr');
    if (!target || typeof qrcode !== 'function') return;

    try {
      var qr = qrcode(0, 'M');
      qr.addData(<?= json_encode($profileUrl, JSON_UNESCAPED_SLASHES) ?>);
      qr.make();
      target.innerHTML = qr.createSvgTag(3, 0);
    } catch (e) {
      target.innerHTML = '<i class="bi bi-qr-code" style="font-size:12mm;color:#C4D8CE"></i>';
    }
  })();
</script>

</body>
</html>
