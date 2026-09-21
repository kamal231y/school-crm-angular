<?php
/**
 * Token based authentication (auth_tokens table).
 * Login par random 64-char token milta hai, logout par delete ho jaata hai.
 */
class Auth
{
    private static ?array $user = null;

    public static function attempt(string $email, string $password): ?array
    {
        $user = Database::one('SELECT * FROM users WHERE email = ? AND status = 1', [$email]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }
        unset($user['password_hash']);
        return $user;
    }

    public static function issueToken(int $userId): array
    {
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + (int) Config::get('token_ttl'));

        Database::insert('auth_tokens', [
            'user_id'    => $userId,
            'token'      => $token,
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'ip'         => Request::ip(),
            'expires_at' => $expires,
        ]);

        Database::run('DELETE FROM auth_tokens WHERE expires_at < NOW()');
        Database::run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$userId]);

        return ['token' => $token, 'expires_at' => $expires];
    }

    public static function revoke(?string $token): void
    {
        if ($token) {
            Database::run('DELETE FROM auth_tokens WHERE token = ?', [$token]);
        }
    }

    /** Logged-in user ya null. */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $token = Request::bearerToken();
        if (!$token) {
            return null;
        }
        $row = Database::one(
            'SELECT u.id, u.name, u.email, u.role, u.phone
               FROM auth_tokens t
               JOIN users u ON u.id = t.user_id
              WHERE t.token = ? AND t.expires_at > NOW() AND u.status = 1',
            [$token]
        );
        self::$user = $row;
        return $row;
    }

    /** Route guard: login zaroori. */
    public static function required(): array
    {
        $u = self::user();
        if (!$u) {
            Response::error('Session expired. Please sign in again.', 401);
        }
        return $u;
    }

    /** Role guard, e.g. Auth::can('admin','accountant') */
    public static function can(string ...$roles): array
    {
        $u = self::required();
        if (!in_array($u['role'], $roles, true)) {
            Response::error('You do not have permission for this action.', 403);
        }
        return $u;
    }

    public static function log(string $action, string $detail = ''): void
    {
        $u = self::user();
        Database::insert('activity_log', [
            'user_id' => $u['id'] ?? null,
            'action'  => $action,
            'detail'  => substr($detail, 0, 255),
            'ip'      => Request::ip(),
        ]);
    }
}
