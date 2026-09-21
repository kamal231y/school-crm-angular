<?php
class AuthController
{
    /** POST /auth/login */
    public function login(): void
    {
        $data = Request::body();
        (new Validator($data))->check([
            'email'    => 'required|email',
            'password' => 'required|min:4',
        ])->stopOnFail();

        $user = Auth::attempt($data['email'], $data['password']);
        if (!$user) {
            Response::error('Email or password is incorrect', 401);
        }

        $token = Auth::issueToken((int) $user['id']);
        Auth::log('login', $user['email']);

        Response::ok([
            'user'       => $user,
            'token'      => $token['token'],
            'expires_at' => $token['expires_at'],
            'school'     => Config::setting('school_name'),
            'session'    => Config::setting('session'),
        ], 'Signed in');
    }

    /** POST /auth/logout */
    public function logout(): void
    {
        Auth::required();
        Auth::log('logout');
        Auth::revoke(Request::bearerToken());
        Response::ok(null, 'Signed out');
    }

    /** GET /auth/me */
    public function me(): void
    {
        $u = Auth::required();
        Response::ok([
            'user'    => $u,
            'school'  => Config::setting('school_name'),
            'session' => Config::setting('session'),
        ]);
    }

    /** POST /auth/change-password */
    public function changePassword(): void
    {
        $u = Auth::required();
        $d = Request::body();
        (new Validator($d))->check([
            'current_password' => 'required',
            'new_password'     => 'required|min:6|max:60',
        ])->stopOnFail();

        $row = Database::one('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
        if (!password_verify($d['current_password'], $row['password_hash'])) {
            Response::error('Current password is incorrect', 422, ['current_password' => 'Current password is incorrect']);
        }

        Database::update('users', (int) $u['id'], [
            'password_hash' => password_hash($d['new_password'], PASSWORD_BCRYPT),
        ]);
        Database::run('DELETE FROM auth_tokens WHERE user_id = ?', [$u['id']]);
        Auth::log('password_change');

        Response::ok(null, 'Password changed. Please sign in again.');
    }
}
