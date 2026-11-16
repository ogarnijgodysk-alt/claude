<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\{Request, Response};
use App\{Auth, Db, Security, Session, Settings};

final class AuthController extends Base
{
    private function loginView(array $extra = []): never
    {
        $notice = Settings::get('login_notice_on') === '1' ? trim(Settings::get('login_notice')) : '';
        $this->page('auth/login', $extra + ['notice' => $notice, 'email' => '', 'error' => null], 'auth');
    }

    public function loginForm(Request $r): void
    {
        if (Session::user()) {
            Response::redirect('/');
        }
        $this->loginView();
    }

    public function login(Request $r): void
    {
        $res = Auth::attempt($r, $r->str('email'), is_string($r->post['password'] ?? null) ? $r->post['password'] : '');
        if (!$res['ok']) {
            http_response_code(401);
            $this->loginView(['error' => $res['error'], 'email' => $r->str('email')]);
        }
        Response::redirect($res['user']['role'] === 'admin' ? '/admin' : '/moje-kursy');
    }

    public function logout(Request $r): void
    {
        Session::destroy();
        Response::redirect('/logowanie');
    }

    public function forgotForm(Request $r): void
    {
        $this->page('auth/forgot', ['email' => $r->str('email'), 'error' => null, 'sent' => false, 'ttl' => (int)Settings::get('reset_ttl', '60')], 'auth');
    }

    public function forgot(Request $r): void
    {
        $email = $r->str('email');
        if (!valid_email($email)) {
            $this->page('auth/forgot', ['email' => $email, 'error' => 'Wpisz poprawny adres e-mail.', 'sent' => false, 'ttl' => (int)Settings::get('reset_ttl', '60')], 'auth');
        }
        $t0 = microtime(true);
        Auth::requestReset($r, $email);
        // stały minimalny czas odpowiedzi: nie zdradza, czy konto istnieje
        usleep((int)max(0, 350000 - (microtime(true) - $t0) * 1e6));
        // zawsze ta sama odpowiedź, niezależnie od tego, czy adres jest w bazie
        $this->page('auth/forgot', ['email' => $email, 'error' => null, 'sent' => true, 'ttl' => (int)Settings::get('reset_ttl', '60')], 'auth');
    }

    public function resetForm(Request $r, string $token): void
    {
        $row = Auth::findReset($token);
        if (!$row) {
            http_response_code(410);
            $this->page('auth/reset_invalid', [], 'auth');
        }
        $this->page('auth/reset', ['email' => $row['email'], 'error' => null], 'auth');
    }

    public function reset(Request $r, string $token): void
    {
        $row = Auth::findReset($token);
        if (!$row) {
            http_response_code(410);
            $this->page('auth/reset_invalid', [], 'auth');
        }
        $err = Auth::passwordError(is_string($r->post['password'] ?? null) ? $r->post['password'] : '', is_string($r->post['password2'] ?? null) ? $r->post['password2'] : '');
        if ($err) {
            http_response_code(422);
            $this->page('auth/reset', ['email' => $row['email'], 'error' => $err], 'auth');
        }
        Auth::setPassword((int)$row['user_id'], $r->post['password']);
        Security::log('password_set', (int)$row['user_id'], $row['email'], $r->ip());
        Session::destroy();
        Session::start();
        Session::flash('Hasło zostało ustawione. Zaloguj się nowym hasłem.');
        Response::redirect('/logowanie');
    }
}
