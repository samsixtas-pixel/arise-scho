<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use School\Core\SchoolAuth;
header('Location: ' . SCHOOL_BASE_URL . (SchoolAuth::userId() ? 'dashboard.php' : 'login.php'));
exit;
