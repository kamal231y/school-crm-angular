<?php
class Request
{
    private static ?array $body = null;

    /** JSON body ya form-data, dono handle karta hai. */
    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        self::$body = is_array($json) ? $json : ($_POST ?: []);
        return self::$body;
    }

    public static function input(string $key, $default = null)
    {
        $b = self::body();
        $v = $b[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    public static function query(string $key, $default = null)
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    public static function bearerToken(): ?string
    {
        $hdrs = function_exists('getallheaders') ? getallheaders() : [];
        $auth = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
            return $m[1];
        }
        return $_GET['token'] ?? null;
    }
}
