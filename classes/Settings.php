<?php
/**
 * Settings.php
 * -------------------------------------------------------
 * Key/value application settings (mirrors the workbook's
 * SETTINGS sheet). Cached for the life of the request.
 * -------------------------------------------------------
 */

class Settings extends Model
{
    private static ?array $cache = null;

    protected function getTable(): string
    {
        return 'app_settings';
    }

    public static function allValues(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [];

        try {
            $db   = Database::getConnection();
            $rows = $db->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll();

            foreach ($rows as $row) {
                self::$cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            self::$cache = [];
        }

        return self::$cache;
    }

    public static function get(string $key, $default = null)
    {
        $all = self::allValues();
        return array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== ''
            ? $all[$key]
            : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value === null ? $default : (int) $value;
    }

    public static function set(string $key, $value, ?string $notes = null): void
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            "INSERT INTO app_settings (setting_key, setting_value, notes, updated_by)
             VALUES (:k, :v, :n, :u)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                     notes = COALESCE(VALUES(notes), notes),
                                     updated_by = VALUES(updated_by)"
        );

        $stmt->execute([
            'k' => $key,
            'v' => $value === null ? null : (string) $value,
            'n' => $notes,
            'u' => currentUserId() ?: null,
        ]);

        self::$cache = null;

        AuditLog::record('settings.update', 'settings', null, 'Setting "' . $key . '" changed');
    }

    public static function businessName(): string
    {
        return (string) self::get('business_name', APP_NAME);
    }

    public static function businessPhone(): string
    {
        return (string) self::get('business_phone', '');
    }

    public static function currency(): string
    {
        return (string) self::get('currency', DEFAULT_CURRENCY);
    }

    public static function dateFormat(): string
    {
        return (string) self::get('date_format', DISPLAY_DATE_FORMAT);
    }

    public static function defaultWeeks(): int
    {
        return self::int('default_weeks', DEFAULT_WEEKS);
    }

    public static function defaultWeeklyAmount(): float
    {
        return (float) self::get('default_weekly_amount', DEFAULT_WEEKLY_AMOUNT);
    }

    public static function graceDays(): int
    {
        return self::int('overdue_grace_days', OVERDUE_GRACE_DAYS);
    }

    public static function defaultLanguage(): string
    {
        return (string) self::get('default_language', 'en');
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
