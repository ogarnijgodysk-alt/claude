<?php
declare(strict_types=1);

namespace App;

use App\Http\Request;
use App\Mail\MailQueue;

final class Auth
{
    public const MIN_PASSWORD = 10;

    public static function hash(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }

    private static function needsRehash(string $hash): bool
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_needs_rehash($hash, $algo);
    }

    /** @return array{ok:bool,user:?array,error:?string} */
    public static function attempt(Request $r, string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        $ip = $r->ip();
        if (Security::tooMany('login_email', $email, 8, 900) || Security::tooMany('login_ip', $ip, 40, 900)) {
            Security::log('login_throttled', null, $email, $ip);
            return ['ok' => false, 'user' => null, 'error' => 'Zbyt wiele prób logowania. Spróbuj ponownie za kilkanaście minut.'];
        }
        $u = $email !== '' ? Db::one('SELECT * FROM users WHERE email = ?', [$email]) : null;
        $ok = false;
        if ($u && !empty($u['password_hash'])) {
            $ok = password_verify($password, $u['password_hash']);
            if ($ok && self::needsRehash($u['password_hash'])) {
                Db::update('users', ['password_hash' => self::hash($password)], 'id = ?', [$u['id']]);
            }
        } elseif ($u && !empty($u['legacy_hash'])) {
            // opcjonalnie: hasło z WordPressa (phpass), po poprawnym logowaniu przeliczane na Argon2id/bcrypt
            $ok = Phpass::check($password, (string)$u['legacy_hash']);
            if ($ok) {
                Db::update('users', ['password_hash' => self::hash($password), 'legacy_hash' => null], 'id = ?', [$u['id']]);
            }
        } else {
            // wyrównanie czasu odpowiedzi, gdy konto nie istnieje
            password_verify($password, '$2y$10$abcdefghijklmnopqrstuuMJ5tVfV6sH1bqzZq1b1m0YQ1xLwB0aS');
        }
        if (!$ok) {
            Security::hit('login_email', $email);
            Security::hit('login_ip', $ip);
            Security::log('login_fail', $u ? (int)$u['id'] : null, $email, $ip);
            return ['ok' => false, 'user' => null, 'error' => 'Nieprawidłowy e-mail lub hasło.'];
        }
        Security::clear('login_email', $email);
        Db::update('users', ['last_login_at' => now()], 'id = ?', [$u['id']]);
        Security::log('login_ok', (int)$u['id'], $email, $ip);
        $u = Db::one('SELECT * FROM users WHERE id = ?', [$u['id']]);
        Session::login($u);
        return ['ok' => true, 'user' => $u, 'error' => null];
    }

    /**
     * Prośba o link do ustawienia hasła. Zawsze zwraca true (ta sama odpowiedź dla każdego adresu).
     * Limity: per adres (max 3/godz., min. 60 s odstępu) i per IP (max 10/godz.).
     */
    public static function requestReset(Request $r, string $email): void
    {
        $email = mb_strtolower(trim($email));
        $ip = $r->ip();
        if (!valid_email($email)) {
            return;
        }
        if (Security::tooMany('reset_ip', $ip, 10, 3600)) {
            Security::log('reset_throttled_ip', null, $email, $ip);
            return;
        }
        Security::hit('reset_ip', $ip);
        if (Security::tooMany('reset_email', $email, 3, 3600) || Security::count('reset_email', $email, 60) >= 1) {
            Security::log('reset_throttled_email', null, $email, $ip);
            return;
        }
        Security::hit('reset_email', $email);
        $u = Db::one('SELECT id, email, first_name FROM users WHERE email = ?', [$email]);
        if (!$u) {
            Security::log('reset_unknown_email', null, $email, $ip);
            return;
        }
        self::sendInvite((int)$u['id'], 'reset');
        Security::log('reset_requested', (int)$u['id'], $email, $ip);
    }

    /** Tworzy jednorazowy token (w bazie tylko skrót SHA-256) i zwraca wartość do linku. */
    public static function issueToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $ttl = max(10, (int)Settings::get('reset_ttl', '60'));
        Db::insert('password_resets', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl * 60),
            'created_at' => now(),
        ]);
        return $token;
    }

    /** Kolejkuje e-mail z linkiem. Dla zaproszeń token powstaje dopiero przy wysyłce (patrz MailQueue::flush). */
    public static function sendInvite(int $userId, string $kind = 'reset', bool $inline = true): void
    {
        $u = Db::one('SELECT id, email, first_name FROM users WHERE id = ?', [$userId]);
        if (!$u) {
            return;
        }
        $ttl = max(10, (int)Settings::get('reset_ttl', '60'));
        $link = $kind === 'invite' ? '{{LINK}}' : abs_url('/haslo/' . self::issueToken($userId));
        [$subject, $text, $html] = Mail\Templates::passwordLink($u, $link, $ttl, $kind);
        MailQueue::enqueue($userId, $u['email'], $kind, $subject, $text, $html);
        if ($inline) {
            MailQueue::flush(1, true);
        }
    }

    public static function findReset(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return Db::one('SELECT pr.*, u.email FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > ?', [hash('sha256', $token), now()]);
    }

    public static function passwordError(string $p1, string $p2): ?string
    {
        if (mb_strlen($p1) < self::MIN_PASSWORD) {
            return 'Hasło musi mieć co najmniej ' . self::MIN_PASSWORD . ' znaków.';
        }
        if ($p1 !== $p2) {
            return 'Hasła nie są takie same.';
        }
        return null;
    }

    /** Ustawia hasło; unieważnia sesje i wszystkie linki tego konta. */
    public static function setPassword(int $userId, string $password): void
    {
        Db::tx(function () use ($userId, $password): void {
            Db::exec('UPDATE users SET password_hash = ?, legacy_hash = NULL, session_version = session_version + 1 WHERE id = ?', [self::hash($password), $userId]);
            Db::exec('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now(), $userId]);
        });
    }

    public static function requireLogin(): array
    {
        $u = Session::user();
        if (!$u) {
            Http\Response::redirect('/logowanie');
        }
        return $u;
    }

    public static function requireAdmin(): array
    {
        $u = Session::user();
        if (!$u) {
            Http\Response::redirect('/logowanie');
        }
        if ($u['role'] !== 'admin') {
            Http\Response::error(403, 'Ta strona jest dostępna tylko dla administratora.');
        }
        return $u;
    }
}
