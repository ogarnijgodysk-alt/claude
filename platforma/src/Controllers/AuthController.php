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
        $this->page('auth/login', $extra + ['notice' => $notice, 'email' => '', 'error' => null, 'linkError' => null, 'mode' => Settings::get('login_mode'), 'magic' => Settings::get('login_mode') === 'magic', 'showPw' => isset($_GET['haslo'])], 'auth');
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
            $_GET['haslo'] = '1';
            $this->loginView(['error' => $res['error'], 'email' => $r->str('email')]);
        }
        Response::redirect($res['user']['role'] === 'admin' ? '/admin' : '/moje-kursy');
    }

    /** Logowanie samym adresem e-mail (tryb „email”). Konta administratorów wymagają hasła. */
    public function emailLogin(Request $r): void
    {
        $email = mb_strtolower($r->str('email'));
        $ip = $r->ip();
        if (Settings::get('login_mode') !== 'email') {
            Response::redirect('/logowanie');
        }
        if (Security::tooMany('login_ip', $ip, 25, 900)) {
            Security::log('login_throttled', null, $email, $ip);
            http_response_code(429);
            $this->loginView(['linkError' => 'Zbyt wiele prób. Spróbuj ponownie za kilkanaście minut.', 'email' => $email]);
        }
        $u = valid_email($email) ? Db::one('SELECT * FROM users WHERE email = ?', [$email]) : null;
        if (!$u || $u['role'] === 'admin') {
            Security::hit('login_ip', $ip);
            Security::log($u ? 'login_email_admin_blocked' : 'login_email_unknown', $u ? (int)$u['id'] : null, $email, $ip);
            http_response_code(401);
            $msg = $u ? 'To konto administratora. Zaloguj się hasłem (link „Mam hasło” poniżej).' : 'Nie znaleźliśmy tego adresu. Wpisz adres e-mail, na który kupiłeś kurs.';
            $this->loginView(['linkError' => $msg, 'email' => $email, 'showPw' => (bool)$u]);
        }
        Db::update('users', ['last_login_at' => now()], 'id = ?', [$u['id']]);
        Security::log('login_ok_email', (int)$u['id'], $email, $ip);
        Session::login($u);
        Response::redirect('/moje-kursy');
    }

    /** Prośba o link do logowania (tryb „magic”). Odpowiedź zawsze taka sama. */
    public function linkRequest(Request $r): void
    {
        $email = $r->str('email');
        if (!valid_email($email)) {
            $this->loginView(['linkError' => 'Wpisz poprawny adres e-mail.', 'email' => $email]);
        }
        $t0 = microtime(true);
        Auth::requestLoginLink($r, $email);
        usleep((int)max(0, 350000 - (microtime(true) - $t0) * 1e6));
        $this->page('auth/link_sent', ['email' => $email, 'ttl' => (int)Settings::get('reset_ttl', '60')], 'auth');
    }

    /** Krok pośredni: sam GET nie loguje (skanery poczty otwierają linki), loguje dopiero kliknięcie przycisku. */
    public function linkConfirmForm(Request $r, string $token): void
    {
        $row = Auth::findReset($token);
        if (!$row || Settings::get('login_mode') !== 'magic') {
            http_response_code(410);
            $this->page('auth/link_invalid', [], 'auth');
        }
        $this->page('auth/link_confirm', ['email' => $row['email']], 'auth');
    }

    public function linkConfirm(Request $r, string $token): void
    {
        $row = Auth::findReset($token);
        if (!$row || Settings::get('login_mode') !== 'magic') {
            http_response_code(410);
            $this->page('auth/link_invalid', [], 'auth');
        }
        // zużyj token atomowo: tylko jedno żądanie może się zalogować
        if (Db::exec('UPDATE password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL', [now(), $row['id']]) !== 1) {
            http_response_code(410);
            $this->page('auth/link_invalid', [], 'auth');
        }
        $u = Db::one('SELECT * FROM users WHERE id = ?', [$row['user_id']]);
        if (!$u || $u['role'] === 'admin') {
            http_response_code(410);
            $this->page('auth/link_invalid', [], 'auth');
        }
        Db::update('users', ['last_login_at' => now()], 'id = ?', [$u['id']]);
        Security::log('login_ok_link', (int)$u['id'], $u['email'], $r->ip());
        Session::login($u);
        Response::redirect('/moje-kursy');
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
