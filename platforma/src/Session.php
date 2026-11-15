<?php
declare(strict_types=1);

namespace App;

use App\Http\Request;

final class Session
{
    private static bool $started = false;
    private static ?array $user = null;
    private static bool $userLoaded = false;

    public static function start(): void
    {
        if (self::$started || PHP_SAPI === 'cli') {
            self::$started = true;
            if (PHP_SAPI === 'cli' && !isset($_SESSION)) {
                $_SESSION = [];
            }
            return;
        }
        $dir = Config::storage('sessions');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        $https = Request::fromGlobals()->isHttps();
        session_name('kurs_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string)(14 * 86400));
        session_start();
        self::$started = true;
    }

    public static function user(): ?array
    {
        self::start();
        if (self::$userLoaded) {
            return self::$user;
        }
        self::$userLoaded = true;
        $uid = (int)($_SESSION['uid'] ?? 0);
        if ($uid > 0) {
            $u = Db::one('SELECT * FROM users WHERE id = ?', [$uid]);
            // sesja traci ważność po zmianie hasła (session_version)
            if ($u && (int)$u['session_version'] === (int)($_SESSION['sv'] ?? 0)) {
                self::$user = $u;
            } else {
                self::destroy();
            }
        }
        return self::$user;
    }

    public static function login(array $user): void
    {
        self::start();
        $csrf = null;
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['uid'] = (int)$user['id'];
        $_SESSION['sv'] = (int)$user['session_version'];
        self::$user = $user;
        self::$userLoaded = true;
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
            }
            session_destroy();
        }
        self::$user = null;
        self::$userLoaded = true;
        self::$started = false;
    }

    public static function flash(?string $msg = null, string $type = 'ok'): ?array
    {
        self::start();
        if ($msg !== null) {
            $_SESSION['_flash'] = ['msg' => $msg, 'type' => $type];
            return null;
        }
        $f = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);
        return $f;
    }
}
