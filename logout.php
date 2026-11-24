<?php
/**
 * logout.php
 */

require_once __DIR__ . '/includes/autoload.php';

if (User::isLoggedIn()) {
    (new User())->destroySession();
}

setcookie(session_name(), '', time() - 42000, BASE_URL);

redirect(BASE_URL . 'login.php');
