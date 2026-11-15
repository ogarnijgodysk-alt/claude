<?php
declare(strict_types=1);

namespace App;

use App\Http\Request;

final class Csrf
{
    public static function token(): string
    {
        Session::start();
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function valid(Request $r): bool
    {
        $sent = (string)($r->post['_csrf'] ?? $r->server['HTTP_X_CSRF_TOKEN'] ?? '');
        Session::start();
        return $sent !== '' && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $sent);
    }

    public static function check(Request $r): void
    {
        if (!self::valid($r)) {
            \App\Http\Response::error(419, 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.');
        }
    }
}
