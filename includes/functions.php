<?php
/**
 * functions.php — shared helpers used by every page.
 * -------------------------------------------------------
 * Output escaping, money/date formatting, flash messages,
 * Tanzanian phone/NIDA validation and small view helpers.
 * -------------------------------------------------------
 */

/** HTML-escape any value for safe output. */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Format an amount as Tanzanian shillings: TZS 1,234,567 */
function money($amount, bool $decimals = false): string
{
    return 'TZS ' . number_format((float) $amount, $decimals ? 2 : 0, '.', ',');
}

/** Short money without the currency prefix (for tables/charts). */
function num($amount, bool $decimals = false): string
{
    return number_format((float) $amount, $decimals ? 2 : 0, '.', ',');
}

/** Format a stored date (Y-m-d) as DD/MM/YYYY. */
function formatDate(?string $date, string $fallback = '—'): string
{
    if (empty($date) || $date === '0000-00-00') {
        return $fallback;
    }

    $ts = strtotime($date);
    return $ts ? date(DISPLAY_DATE_FORMAT, $ts) : $fallback;
}

/** Format a datetime as DD/MM/YYYY HH:MM. */
function formatDateTime(?string $datetime, string $fallback = '—'): string
{
    if (empty($datetime)) {
        return $fallback;
    }

    $ts = strtotime($datetime);
    return $ts ? date(DISPLAY_DATE_FORMAT . ' H:i', $ts) : $fallback;
}

/** ISO date for <input type="date"> and SQL. */
function isoDate(?string $date): string
{
    if (empty($date)) {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date('Y-m-d', $ts) : '';
}

/** Add days to a Y-m-d date. */
function addDays(string $date, int $days): string
{
    return date('Y-m-d', strtotime($date . ' ' . ($days >= 0 ? '+' : '') . $days . ' days'));
}

/** Difference in whole days between two dates (b - a). */
function daysBetween(string $from, string $to): int
{
    return (int) floor((strtotime($to) - strtotime($from)) / 86400);
}

/** Badge markup for a loan status. */
function loanBadge(?string $status): string
{
    $map = [
        'PAID'        => 'success',
        'ACTIVE'      => 'primary',
        'DUE'         => 'warning',
        'OVERDUE'     => 'danger',
        'OVERPAID'    => 'info',
        'WRITTEN OFF' => 'secondary',
    ];

    $status = strtoupper(trim((string) $status));
    $class  = $map[$status] ?? 'secondary';
    $label  = t(ucwords(strtolower($status)));

    return '<span class="badge bg-' . $class . '">' . e($label) . '</span>';
}

/** Badge markup for a generic state (active/inactive/blacklisted/...). */
function statusBadge(?string $status): string
{
    $map = [
        'active'     => 'success',
        'inactive'   => 'secondary',
        'blacklisted'=> 'danger',
        'complete'   => 'success',
        'incomplete' => 'warning',
        'confirmed'  => 'success',
        'pending'    => 'warning',
        'reversed'   => 'danger',
        'paid'       => 'success',
        'partial'    => 'warning',
        'missed'     => 'danger',
        'approved'   => 'success',
        'rejected'   => 'danger',
        'submitted'  => 'info',
        'draft'      => 'secondary',
    ];

    $key   = strtolower(trim((string) $status));
    $class = $map[$key] ?? 'secondary';

    return '<span class="badge bg-' . $class . '">' . e(t(ucfirst($key))) . '</span>';
}

/** Client IP address (proxy-aware but not proxy-trusting). */
function clientIp(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** True when the current request is a POST. */
function isPost(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Redirect and stop. */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** Absolute application URL for a relative path. */
function url(string $path = ''): string
{
    return BASE_URL . ltrim($path, '/');
}

/** Remember a one-off message for the next request. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Pull and clear all queued flash messages. */
function takeFlashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/** Keep submitted form values for redisplay after a validation error. */
function old(string $key, $default = '')
{
    return $_SESSION['old'][$key] ?? $default;
}

function setOld(array $values): void
{
    unset($values['csrf_token'], $values['password'], $values['password_confirm']);
    $_SESSION['old'] = $values;
}

function clearOld(): void
{
    unset($_SESSION['old']);
}

/** First letter(s) for avatars. */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $out   = '';

    foreach (array_slice($parts, 0, 2) as $part) {
        if ($part !== '') {
            $out .= mb_strtoupper(mb_substr($part, 0, 1));
        }
    }

    return $out !== '' ? $out : '?';
}

/** Normalise a Tanzanian phone number to 0XXXXXXXXX. */
function normalisePhone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    if (str_starts_with($digits, '255') && strlen($digits) === 12) {
        $digits = '0' . substr($digits, 3);
    }

    if (str_starts_with($digits, '0') && strlen($digits) === 10) {
        return $digits;
    }

    // Nine digits without a leading zero (e.g. 744909605)
    if (strlen($digits) === 9) {
        return '0' . $digits;
    }

    return $digits;
}

/** Validate a Tanzanian mobile number. */
function isValidTzPhone(string $phone): bool
{
    $p = normalisePhone($phone);
    return (bool) preg_match('/^0[67][0-9]{8}$/', $p);
}

/** Pretty phone for display: 0744 909 605 */
function formatPhone(?string $phone): string
{
    $p = normalisePhone((string) $phone);

    if (strlen($p) === 10) {
        return substr($p, 0, 4) . ' ' . substr($p, 4, 3) . ' ' . substr($p, 7, 3);
    }

    return (string) ($phone ?? '');
}

/** Validate a NIDA number (20 digits in Tanzania). */
function isValidNida(string $nida): bool
{
    return (bool) preg_match('/^[0-9]{20}$/', preg_replace('/\s+/', '', $nida) ?? '');
}

/** Percentage of a total, safe against division by zero. */
function percent($part, $total): float
{
    $total = (float) $total;
    return $total > 0 ? round(((float) $part / $total) * 100, 1) : 0.0;
}

/** Query-string builder that keeps existing filters. */
function queryWith(array $overrides): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        }
    }
    return $params ? '?' . http_build_query($params) : '';
}

/** Spell a whole number in English (used on receipts). */
function numberToWords(int $number): string
{
    $ones = [
        '', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine',
        'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen',
        'seventeen', 'eighteen', 'nineteen',
    ];

    $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

    if ($number < 0) {
        return 'minus ' . numberToWords(-$number);
    }

    if ($number < 20) {
        return $ones[$number];
    }

    if ($number < 100) {
        return $tens[intdiv($number, 10)] . ($number % 10 ? '-' . $ones[$number % 10] : '');
    }

    if ($number < 1000) {
        return $ones[intdiv($number, 100)] . ' hundred'
            . ($number % 100 ? ' and ' . numberToWords($number % 100) : '');
    }

    foreach ([1_000_000_000 => 'billion', 1_000_000 => 'million', 1_000 => 'thousand'] as $value => $label) {
        if ($number >= $value) {
            return numberToWords(intdiv($number, $value)) . ' ' . $label
                . ($number % $value ? ' ' . numberToWords($number % $value) : '');
        }
    }

    return (string) $number;
}

/** Amount in words for a receipt: "One hundred thousand shillings only". */
function amountInWords($amount): string
{
    $whole = (int) round((float) $amount);

    if ($whole === 0) {
        return t('Zero shillings only');
    }

    return ucfirst(numberToWords($whole)) . ' ' . t('shillings only');
}

/** Human "3 days ago" style label. */
function timeAgo(?string $datetime): string{
    if (empty($datetime)) {
        return '—';
    }

    $ts   = strtotime($datetime);
    $diff = time() - $ts;

    if ($diff < 60)     return t('just now');
    if ($diff < 3600)   return t(':n min ago', ['n' => (int) floor($diff / 60)]);
    if ($diff < 86400)  return t(':n hours ago', ['n' => (int) floor($diff / 3600)]);
    if ($diff < 604800) return t(':n days ago', ['n' => (int) floor($diff / 86400)]);

    return formatDate($datetime);
}

/** Abbreviated money for tight spaces: 1.2M / 340K */
function compactMoney($amount): string
{
    $amount = (float) $amount;

    if (abs($amount) >= 1_000_000) {
        return number_format($amount / 1_000_000, 1) . 'M';
    }
    if (abs($amount) >= 1_000) {
        return number_format($amount / 1_000, 0) . 'K';
    }

    return number_format($amount, 0);
}

/** True when the logged-in user is the administrator. */
function isAdmin(): bool
{
    return class_exists('User') && User::isAdmin();
}

/** Current user id (0 when not logged in). */
function currentUserId(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

/** Bootstrap pagination markup that preserves the current filters. */
function pagination(int $page, int $pages, string $path): string
{
    if ($pages <= 1) {
        return '';
    }

    $page = max(1, min($page, $pages));

    $url = static function (int $target) use ($path): string {
        return $path . queryWith(['page' => $target]);
    };

    $out = '<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center flex-wrap">';

    $out .= '<li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '">'
          . '<a class="page-link" href="' . e($url(max(1, $page - 1))) . '">&laquo;</a></li>';

    $start = max(1, $page - 2);
    $end   = min($pages, $start + 4);
    $start = max(1, $end - 4);

    for ($i = $start; $i <= $end; $i++) {
        $out .= '<li class="page-item ' . ($i === $page ? 'active' : '') . '">'
              . '<a class="page-link" href="' . e($url($i)) . '">' . $i . '</a></li>';
    }

    $out .= '<li class="page-item ' . ($page >= $pages ? 'disabled' : '') . '">'
          . '<a class="page-link" href="' . e($url(min($pages, $page + 1))) . '">&raquo;</a></li>';

    $out .= '</ul></nav>';

    return $out;
}

/** Inline validation error list for a form. */
function errorList(array $errors): string
{
    if (!$errors) {
        return '';
    }

    $out = '<div class="alert alert-danger"><ul class="mb-0 ps-3">';
    foreach ($errors as $error) {
        $out .= '<li>' . e($error) . '</li>';
    }
    $out .= '</ul></div>';

    return $out;
}
