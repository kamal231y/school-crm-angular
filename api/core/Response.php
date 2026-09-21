<?php
class Response
{
    public static function json($data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok($data = null, string $message = 'OK'): void
    {
        self::json(['success' => true, 'message' => $message, 'data' => $data]);
    }

    public static function error(string $message, int $code = 400, $errors = null): void
    {
        self::json(['success' => false, 'message' => $message, 'errors' => $errors], $code);
    }

    /** Paginated list payload. */
    public static function paginated(array $rows, int $total, int $page, int $perPage): void
    {
        self::json([
            'success' => true,
            'data'    => $rows,
            'meta'    => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
            ],
        ]);
    }
}
