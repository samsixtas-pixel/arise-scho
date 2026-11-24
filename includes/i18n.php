<?php
/**
 * i18n.php — English / Kiswahili switching.
 * -------------------------------------------------------
 * English is the source language and the fallback: t('Save')
 * returns "Save" until a Swahili translation exists in
 * includes/lang/sw.php. Placeholders use :name syntax.
 * -------------------------------------------------------
 */

function available_languages(): array
{
    return ['en' => 'English', 'sw' => 'Kiswahili'];
}

function current_lang(): string
{
    $lang = $_SESSION['lang'] ?? null;

    if ($lang === null && !empty($_SESSION['preferred_language'])) {
        $lang = $_SESSION['preferred_language'];
    }

    $lang = is_string($lang) ? strtolower($lang) : 'en';

    return array_key_exists($lang, available_languages()) ? $lang : 'en';
}

function set_lang(string $lang): void
{
    if (array_key_exists($lang, available_languages())) {
        $_SESSION['lang'] = $lang;
    }
}

/** Loaded translation table (English => target language). */
function translations(): array
{
    static $cache = [];

    $lang = current_lang();

    if (!isset($cache[$lang])) {
        $file = __DIR__ . '/lang/' . $lang . '.php';
        $cache[$lang] = is_file($file) ? (require $file) : [];
    }

    return $cache[$lang];
}

/**
 * Translate a phrase. Unknown phrases fall back to the English
 * source string, so a missing translation never breaks a page.
 *
 * @param string $text   English phrase
 * @param array  $params :placeholder replacements
 */
function t(string $text, array $params = []): string
{
    if (current_lang() === 'en') {
        $out = $text;
    } else {
        $map = translations();
        $out = $map[$text] ?? $text;
    }

    foreach ($params as $key => $value) {
        $out = str_replace(':' . $key, (string) $value, $out);
    }

    return $out;
}

/** Language switch URL for the current page. */
function langSwitchUrl(string $lang): string
{
    $params = array_merge($_GET, ['lang' => $lang]);
    return strtok($_SERVER['REQUEST_URI'] ?? BASE_URL, '?') . '?' . http_build_query($params);
}
