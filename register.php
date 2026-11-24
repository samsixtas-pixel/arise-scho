<?php
/**
 * register.php — retired public self-registration.
 * -------------------------------------------------------
 * Accounts are created by an Administrator from
 * views/users/add.php. This entry point is kept only so old
 * bookmarks do not 404; it sends visitors to the sign-in page.
 */

require_once __DIR__ . '/includes/autoload.php';

flash('info', t('Accounts are created by an administrator. Please sign in.'));
redirect(BASE_URL . (User::isLoggedIn() ? 'dashboard.php' : 'login.php'));
