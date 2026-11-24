<?php
/**
 * index.php — entry point.
 */

require_once __DIR__ . '/includes/autoload.php';

redirect(User::isLoggedIn() ? BASE_URL . 'dashboard.php' : BASE_URL . 'login.php');
