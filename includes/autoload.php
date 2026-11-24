<?php
/**
 * autoload.php — bootstrap for every page.
 * -------------------------------------------------------
 * Loads configuration, helpers, translation layer and a
 * PSR-style class autoloader for /classes.
 * -------------------------------------------------------
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/functions.php';

// Composer (PhpSpreadsheet) — only needed for exports, but harmless here.
$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

// Language switch (?lang=sw) works on any page, before output.
if (isset($_GET['lang'])) {
    set_lang((string) $_GET['lang']);
    if (!empty($_SESSION['user_id'])) {
        try {
            (new User())->saveLanguage((int) $_SESSION['user_id'], current_lang());
        } catch (Throwable $e) {
            // Non-fatal: the session preference already applies.
        }
        $_SESSION['preferred_language'] = current_lang();
    }
}

spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/../classes/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
