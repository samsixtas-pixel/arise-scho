<?php
use School\Core\SchoolAuth;

$pageTitle = $pageTitle ?? 'Dashboard';
$schoolUser = $schoolUser ?? SchoolAuth::user();
$schoolRole = $schoolRole ?? school_current_role($schoolUser ?? []);
$schoolName = $schoolName ?? 'School Management';
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$isActive = static fn(array $needles): string => in_array($currentPage, $needles, true) ? 'active' : '';
$schoolInitial = initials((string) ($schoolUser['full_name'] ?? 'School User'));
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | <?= e($schoolName) ?></title>
<meta name="theme-color" content="#063328"><meta name="csrf-token" content="<?= e(SchoolAuth::csrfToken()) ?>">
<link rel="stylesheet" href="<?= SCHOOL_ASSET_URL ?>vendor/bootstrap.min.css">
<link rel="stylesheet" href="<?= SCHOOL_ASSET_URL ?>vendor/bootstrap-icons.css">
<link rel="stylesheet" href="<?= SCHOOL_ASSET_URL ?>css/style.css">
<link rel="stylesheet" href="<?= SCHOOL_ASSET_URL ?>css/lms.css">
<link rel="stylesheet" href="<?= SCHOOL_ASSET_URL ?>css/school/charts.css">
</head><body>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div><div class="wrapper">
<nav class="sidebar" id="sidebar">
  <div class="sidebar-brand"><span class="brand-logo d-flex align-items-center justify-content-center fs-3 text-warning" aria-hidden="true"><i class="bi bi-mortarboard-fill"></i></span><div class="brand-text"><div class="brand-name"><strong><?= e(mb_strtoupper(mb_substr($schoolName,0,16))) ?></strong></div><div class="brand-sub"><?= e(school_role_label($schoolRole)) ?></div></div></div>
  <ul class="sidebar-menu">
    <li class="<?= $isActive(['dashboard.php']) ?>"><a href="<?= SCHOOL_BASE_URL ?>dashboard.php"><span class="nav-icon"><i class="bi bi-grid-1x2-fill"></i></span><span>Dashboard</span></a></li>
    <li class="sidebar-divider">SCHOOL</li>
    <?php if (SchoolAuth::hasPermission('students.view')): ?><li><a href="<?= SCHOOL_BASE_URL ?>students/index.php"><span class="nav-icon"><i class="bi bi-people-fill"></i></span><span>Students</span></a></li><?php endif; ?>
    <?php if (SchoolAuth::hasPermission('students.guardian.verify') || SchoolAuth::hasPermission('portal.child')): ?><li class="<?= str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/guardians/') ? 'active' : '' ?>"><a href="<?= SCHOOL_BASE_URL ?>students/guardians.php"><span class="nav-icon"><i class="bi bi-person-hearts"></i></span><span>Guardian links</span></a></li><?php endif; ?>
    <?php if (SchoolAuth::hasPermission('school.manage')): ?><li><a href="<?= SCHOOL_BASE_URL ?>settings/academic.php"><span class="nav-icon"><i class="bi bi-calendar3"></i></span><span>Classes & terms</span></a></li><?php endif; ?>
    <?php if (SchoolAuth::hasPermission('school.manage')): ?><li class="<?= str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/settings/profile.php') ? 'active' : '' ?>"><a href="<?= SCHOOL_BASE_URL ?>settings/profile.php"><span class="nav-icon"><i class="bi bi-building"></i></span><span>School profile</span></a></li><?php endif; ?>
    <?php if (SchoolAuth::hasPermission('attendance.view')): ?><li><a href="<?= SCHOOL_BASE_URL ?>attendance/index.php"><span class="nav-icon"><i class="bi bi-calendar2-check-fill"></i></span><span>Attendance</span></a></li><?php endif; ?>
    <?php if (SchoolAuth::hasPermission('homework.manage') || SchoolAuth::hasPermission('homework.view')): ?><li class="<?= str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/homework/') ? 'active' : '' ?>"><a href="<?= SCHOOL_BASE_URL ?>homework/index.php"><span class="nav-icon"><i class="bi bi-journal-text"></i></span><span>Homework</span></a></li><?php endif; ?>
    <?php if (SchoolAuth::hasPermission('results.review') || SchoolAuth::hasPermission('results.marks.enter') || SchoolAuth::hasPermission('results.import')): ?><li class="<?= $isActive(['index.php']) && str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/academics/') ? 'active' : '' ?>"><a href="<?= SCHOOL_BASE_URL ?>academics/index.php"><span class="nav-icon"><i class="bi bi-journal-check"></i></span><span>Academics</span></a></li><?php endif; ?>
    <?php if (SchoolAuth::hasPermission('results.import')): ?><li class="<?= str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/academics/import') ? 'active' : '' ?>"><a href="<?= SCHOOL_BASE_URL ?>academics/import.php"><span class="nav-icon"><i class="bi bi-file-earmark-arrow-up"></i></span><span>Import results</span></a></li>
    <li class="<?= str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/academics/entry.php') ? 'active' : '' ?>"><a href="<?= SCHOOL_BASE_URL ?>academics/entry.php"><span class="nav-icon"><i class="bi bi-grid-3x3-gap"></i></span><span>Result entry sheet</span></a></li><?php endif; ?>
    <?php if (SchoolAuth::hasPermission('finance.view')): ?><li class="<?= str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/finance/') ? 'active' : '' ?>"><a href="<?= SCHOOL_BASE_URL ?>finance/index.php"><span class="nav-icon"><i class="bi bi-receipt"></i></span><span>Fees</span></a></li><?php endif; ?>
    <?php if (SchoolAuth::hasPermission('staff.manage') || SchoolAuth::hasPermission('assignments.manage')): ?><li class="<?= str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/staff/') ? 'active' : '' ?>"><a href="<?= SCHOOL_BASE_URL ?>staff/index.php"><span class="nav-icon"><i class="bi bi-person-workspace"></i></span><span>Staff & assignments</span></a></li><?php endif; ?>
    <?php if (SchoolAuth::hasPermission('portal.child')): ?><li class="<?= str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/portal/') ? 'active' : '' ?>"><a href="<?= SCHOOL_BASE_URL ?>portal/children.php"><span class="nav-icon"><i class="bi bi-people"></i></span><span>My children</span></a></li><?php endif; ?>
    <li class="sidebar-divider">INSIGHTS</li>
    <?php if (SchoolAuth::hasPermission('analytics.school') || SchoolAuth::hasPermission('analytics.academic') || SchoolAuth::hasPermission('analytics.finance') || SchoolAuth::hasPermission('analytics.assigned') || SchoolAuth::hasPermission('reports.view')): ?><li class="<?= str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/insights/') ? 'active' : '' ?>"><a href="<?= SCHOOL_BASE_URL ?>insights/index.php"><span class="nav-icon"><i class="bi bi-bar-chart-fill"></i></span><span>Insights &amp; analytics</span></a></li><?php endif; ?>
    <?php if (SchoolAuth::hasPermission('documents.view') || SchoolAuth::hasPermission('reports.view')): ?><li class="<?= str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/reports/') ? 'active' : '' ?>"><a href="<?= SCHOOL_BASE_URL ?>reports/index.php"><span class="nav-icon"><i class="bi bi-file-earmark-pdf"></i></span><span>Reports &amp; documents</span></a></li><?php endif; ?>
    <li class="sidebar-divider">ACCOUNT</li>
    <li class="<?= str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/account/') ? 'active' : '' ?>"><a href="<?= SCHOOL_BASE_URL ?>account/password.php"><span class="nav-icon"><i class="bi bi-key"></i></span><span>Change password</span></a></li>
    
  </ul>
  <form method="post" action="<?= SCHOOL_BASE_URL ?>logout.php" class="sidebar-footer"><?= SchoolAuth::csrfField() ?><button type="submit"><span class="nav-icon"><i class="bi bi-box-arrow-right"></i></span><span>Log out</span></button></form>
</nav>
<div class="main"><header class="topbar">
  <button class="btn-toggle" id="sidebarToggle" aria-label="Menu"><i class="bi bi-list"></i></button>
  <h5 class="topbar-title"><?= e($pageTitle) ?></h5><div id="networkStatus" class="network-status"></div>
  <div class="today-chip d-none d-lg-flex"><i class="bi bi-calendar3"></i><span><?= e(date('d/m/Y')) ?></span><small><?= e(date('l')) ?></small></div>
  <div class="dropdown notif-wrapper"><button class="btn-toggle position-relative" data-bs-toggle="dropdown" aria-expanded="false" title="Alerts"><i class="bi bi-bell-fill"></i></button><ul class="dropdown-menu dropdown-menu-end notif-menu"><li class="notif-menu-head"><strong>School notifications</strong></li><li class="notif-empty"><i class="bi bi-check2-circle text-success"></i>No new notifications.</li></ul></div>
  <div class="dropdown"><button class="topbar-user dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><div class="user-avatar"><?= e($schoolInitial) ?></div><div class="user-info"><span class="user-name"><?= e($schoolUser['full_name'] ?? '') ?></span><span class="user-role"><?= e(school_role_label($schoolRole)) ?></span></div></button><ul class="dropdown-menu dropdown-menu-end"><li class="dropdown-item-text small text-muted"><?= e($schoolUser['username'] ?? $schoolUser['email'] ?? '') ?></li><?php if (!empty($schoolUser['must_change_password'])): ?><li class="dropdown-item-text"><span class="badge bg-warning-subtle text-warning">Change your initial password</span></li><?php endif; ?><li><a class="dropdown-item" href="<?= SCHOOL_BASE_URL ?>account/password.php"><i class="bi bi-key me-2"></i>Change password</a></li><li><hr class="dropdown-divider"></li><li><form method="post" action="<?= SCHOOL_BASE_URL ?>logout.php"><?= SchoolAuth::csrfField() ?><button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Log out</button></form></li></ul></div>
</header><main class="content">
