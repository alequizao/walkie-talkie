<?php
namespace WalkieTalkie;

/**
 * Settings - pares chave/valor persistidos no banco (ex.: chaves VAPID).
 */
class Settings
{
    private static array $cache = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) return self::$cache[$key];

        $value = Database::value('SELECT `value` FROM settings WHERE `key` = :k', ['k' => $key]);
        return self::$cache[$key] = ($value !== null ? (string) $value : $default);
    }

    public static function set(string $key, string $value): void
    {
        Database::query(
            'INSERT INTO settings (`key`, `value`) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE `value` = :v2',
            ['k' => $key, 'v' => $value, 'v2' => $value]
        );
        self::$cache[$key] = $value;
    }

    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
