<?php
/**
 * header.php — application shell (sidebar + topbar).
 * Pages set $pageTitle (and optionally $pageSubtitle) before including.
 */

$pageTitle    = $pageTitle    ?? t('Dashboard');
$pageSubtitle = $pageSubtitle ?? '';
$currentPage  = basename($_SERVER['PHP_SELF'] ?? '');
$currentUri   = $_SERVER['REQUEST_URI'] ?? '';
$userInitial  = initials($_SESSION['full_name'] ?? 'U');
$isAdmin      = User::isAdmin();
$lang         = current_lang();

$overdueCount    = 0;
$incompleteCount = 0;
$todayTotal      = 0;

try {
    $overdueCount = (int) Database::getConnection()
        ->query("SELECT COUNT(*) FROM v_overdue_loans")->fetchColumn();
    $incompleteCount = (new Customer())->countIncomplete();
    $todayTotal = (new Payment())->todayTotal()['total'] ?? 0;
} catch (Throwable $e) {
    // Header must never break a page.
}

$isActive = static function (array $needles) use ($currentUri, $currentPage): string {
    foreach ($needles as $needle) {
        if ($needle !== '' && (str_contains($currentUri, $needle) || $currentPage === $needle)) {
            return 'active';
        }
    }
    return '';
};
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | <?= e(Settings::businessName()) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/loan-manager-icon.svg">
<link rel="manifest" href="<?= BASE_URL ?>manifest.json">
<meta name="theme-color" content="#0D6E5D">
<meta name="csrf-token" content="<?= e(Security::csrfToken()) ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap-icons.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/lms.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/lms.css') ?>">
</head>
<body>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="wrapper">

  <!-- ═══ SIDEBAR ═══ -->
  <nav class="sidebar" id="sidebar">

    <div class="sidebar-brand">
      <span class="brand-logo d-flex align-items-center justify-content-center fs-3 text-warning" aria-hidden="true">
        <i class="bi bi-cash-coin"></i>
      </span>
      <div class="brand-text">
        <div class="brand-name"><strong><?= e(mb_strtoupper(mb_substr(Settings::businessName(), 0, 14))) ?></strong></div>
        <div class="brand-sub"><?= $isAdmin ? t('Administrator') : t('Loan Officer') ?></div>
      </div>
    </div>

    <ul class="sidebar-menu">

      <li class="<?= $isActive(['dashboard.php', '/dashboard']) ?>">
        <a href="<?= BASE_URL ?>dashboard.php">
          <span class="nav-icon"><i class="bi bi-grid-1x2-fill"></i></span>
          <span><?= t('Dashboard') ?></span>
        </a>
      </li>

      <li class="sidebar-divider"><?= t('Lending') ?></li>

      <li class="<?= $isActive(['/customers']) ?>">
        <a href="<?= BASE_URL ?>views/customers/index.php">
          <span class="nav-icon"><i class="bi bi-people-fill"></i></span>
          <span><?= t('Customers') ?></span>
        </a>
      </li>

      <li class="<?= $isActive(['/loans']) ?>">
        <a href="<?= BASE_URL ?>views/loans/index.php">
          <span class="nav-icon"><i class="bi bi-cash-coin"></i></span>
          <span><?= t('Loans') ?></span>
        </a>
      </li>

      <li class="<?= $isActive(['/payments']) ?>">
        <a href="<?= BASE_URL ?>views/payments/index.php">
          <span class="nav-icon"><i class="bi bi-receipt"></i></span>
          <span><?= t('Payments') ?></span>
        </a>
      </li>

      <li class="<?= $isActive(['/overdue']) ?>">
        <a href="<?= BASE_URL ?>views/overdue/index.php">
          <span class="nav-icon"><i class="bi bi-exclamation-triangle-fill"></i></span>
          <span><?= t('Overdue') ?></span>
          <?php if ($overdueCount > 0): ?>
            <span class="badge bg-danger ms-auto"><?= $overdueCount ?></span>
          <?php endif; ?>
        </a>
      </li>

      <li class="sidebar-divider"><?= t('Insights') ?></li>

      <li class="<?= $isActive(['/reports']) ?>">
        <a href="<?= BASE_URL ?>views/reports/index.php">
          <span class="nav-icon"><i class="bi bi-bar-chart-fill"></i></span>
          <span><?= t('Reports') ?></span>
        </a>
      </li>

      <li class="<?= $isActive(['/statement']) ?>">
        <a href="<?= BASE_URL ?>views/reports/statement.php">
          <span class="nav-icon"><i class="bi bi-file-earmark-text-fill"></i></span>
          <span><?= t('Statements') ?></span>
        </a>
      </li>

      <li class="<?= $isActive(['export.php']) ?>">
        <a href="<?= BASE_URL ?>export.php">
          <span class="nav-icon"><i class="bi bi-file-earmark-excel-fill"></i></span>
          <span><?= t('Workbook export') ?></span>
        </a>
      </li>

      <?php if ($isAdmin): ?>
      <li class="sidebar-divider"><?= t('Administration') ?></li>

      <li class="<?= $isActive(['/users']) ?>">
        <a href="<?= BASE_URL ?>views/users/index.php">
          <span class="nav-icon"><i class="bi bi-person-badge-fill"></i></span>
          <span><?= t('Users') ?></span>
        </a>
      </li>

      <li class="<?= $isActive(['/settings']) ?>">
        <a href="<?= BASE_URL ?>views/settings/index.php">
          <span class="nav-icon"><i class="bi bi-gear-fill"></i></span>
          <span><?= t('Settings') ?></span>
        </a>
      </li>

      <li class="<?= $isActive(['/audit']) ?>">
        <a href="<?= BASE_URL ?>views/audit/index.php">
          <span class="nav-icon"><i class="bi bi-shield-lock-fill"></i></span>
          <span><?= t('Audit log') ?></span>
        </a>
      </li>
      <?php endif; ?>

    </ul>

    <div class="sidebar-footer">
      <a href="<?= BASE_URL ?>logout.php">
        <span class="nav-icon"><i class="bi bi-box-arrow-right"></i></span>
        <span><?= t('Log out') ?></span>
      </a>
    </div>

  </nav>

  <!-- ═══ MAIN ═══ -->
  <div class="main">

    <header class="topbar">
      <button class="btn-toggle" id="sidebarToggle" aria-label="<?= t('Menu') ?>">
        <i class="bi bi-list"></i>
      </button>

      <h5 class="topbar-title"><?= e($pageTitle) ?></h5>
      <div id="networkStatus" class="network-status"></div>

      <form class="topbar-search d-none d-md-flex" method="GET" action="<?= BASE_URL ?>search.php">
        <i class="bi bi-search"></i>
        <input type="text" name="q" placeholder="<?= t('Search customers, loans, receipts…') ?>" autocomplete="off"
               value="<?= isset($_GET['q']) && $currentPage === 'search.php' ? e((string) $_GET['q']) : '' ?>">
      </form>

      <div class="today-chip d-none d-lg-flex">
        <i class="bi bi-cash-stack"></i>
        <span><?= e(money($todayTotal)) ?></span>
        <small><?= t('collected today') ?></small>
      </div>

      <div class="dropdown notif-wrapper">
        <button class="btn-toggle position-relative" data-bs-toggle="dropdown" aria-expanded="false" title="<?= t('Alerts') ?>">
          <i class="bi bi-bell-fill"></i>
          <?php if ($overdueCount + $incompleteCount > 0): ?>
            <span class="notif-badge"><?= min(9, $overdueCount + $incompleteCount) ?><?= ($overdueCount + $incompleteCount) > 9 ? '+' : '' ?></span>
          <?php endif; ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end notif-menu">
          <li class="notif-menu-head"><strong><?= t('Needs attention') ?></strong></li>

          <?php if ($overdueCount === 0 && $incompleteCount === 0): ?>
            <li class="notif-empty">
              <i class="bi bi-check2-circle text-success"></i>
              <?= t('Nothing needs your attention right now.') ?>
            </li>
          <?php endif; ?>

          <?php if ($overdueCount > 0): ?>
            <li>
              <a class="dropdown-item notif-item notif-danger" href="<?= BASE_URL ?>views/overdue/index.php">
                <i class="bi bi-exclamation-triangle-fill notif-icon"></i>
                <span><?= t(':n loans are overdue', ['n' => $overdueCount]) ?></span>
              </a>
            </li>
          <?php endif; ?>

          <?php if ($incompleteCount > 0): ?>
            <li>
              <a class="dropdown-item notif-item notif-warning" href="<?= BASE_URL ?>views/customers/index.php?data_status=incomplete">
                <i class="bi bi-person-exclamation notif-icon"></i>
                <span><?= t(':n customer records are incomplete', ['n' => $incompleteCount]) ?></span>
              </a>
            </li>
          <?php endif; ?>
        </ul>
      </div>

      <div class="dropdown">
        <button class="btn-toggle lang-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="<?= t('Language') ?>">
          <i class="bi bi-translate"></i>
          <span class="lang-code"><?= strtoupper($lang) ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <?php foreach (available_languages() as $code => $label): ?>
            <li>
              <a class="dropdown-item <?= $code === $lang ? 'active' : '' ?>" href="<?= e(langSwitchUrl($code)) ?>">
                <?= e($label) ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="dropdown">
        <button class="topbar-user dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
          <div class="user-avatar"><?= e($userInitial) ?></div>
          <div class="user-info">
            <span class="user-name"><?= e($_SESSION['full_name'] ?? '') ?></span>
            <span class="user-role"><?= e(t(ucfirst($_SESSION['role'] ?? ''))) ?></span>
          </div>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li>
            <a class="dropdown-item" href="<?= BASE_URL ?>views/profile.php">
              <i class="bi bi-person-circle me-2"></i><?= t('My profile') ?>
            </a>
          </li>
          <li><hr class="dropdown-divider"></li>
          <li>
            <a class="dropdown-item text-danger" href="<?= BASE_URL ?>logout.php">
              <i class="bi bi-box-arrow-right me-2"></i><?= t('Log out') ?>
            </a>
          </li>
        </ul>
      </div>
    </header>

    <main class="content">
      <?php if ($pageSubtitle !== ''): ?>
        <p class="page-subtitle"><?= e($pageSubtitle) ?></p>
      <?php endif; ?>
