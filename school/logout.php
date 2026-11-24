<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use School\Core\SchoolAuth;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !SchoolAuth::verifyCsrf()) { http_response_code(405); exit('Invalid request.'); }
SchoolAuth::logout();
header('Location: ' . SCHOOL_BASE_URL . 'login.php');
