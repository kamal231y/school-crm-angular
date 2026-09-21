<?php
class Config
{
    private static array $data = [];

    public static function load(string $file): void
    {
        self::$data = require $file;
    }

    public static function get(string $key, $default = null)
    {
        return self::$data[$key] ?? $default;
    }

    /** Setting stored in the DB settings table. */
    public static function setting(string $key, $default = ''): string
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            foreach (Database::all('SELECT skey, svalue FROM settings') as $r) {
                $cache[$r['skey']] = $r['svalue'];
            }
        }
        return $cache[$key] ?? $default;
    }
}
