<?php
declare(strict_types=1);

namespace App\Http;

final class Response
{
    public static function redirect(string $to, int $code = 302): never
    {
        if (!preg_match('#^https?://#', $to)) {
            $to = url($to);
        }
        header('Location: ' . $to, true, $code);
        exit;
    }

    public static function json(mixed $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function html(string $html, ?int $code = null): never
    {
        if ($code !== null) {
            http_response_code($code);
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo $html;
        exit;
    }

    public static function error(int $code, string $msg = ''): never
    {
        $titles = [403 => 'Brak dostępu', 404 => 'Nie znaleziono strony', 419 => 'Sesja wygasła', 429 => 'Zbyt wiele prób', 500 => 'Błąd serwera'];
        $title = $titles[$code] ?? 'Błąd';
        $req = Request::fromGlobals();
        if ($req->wantsJson()) {
            self::json(['ok' => false, 'error' => $msg ?: $title], $code);
        }
        self::html(\App\View::render('error', ['title' => $title, 'message' => $msg, 'code' => $code, 'siteName' => \App\Settings::get('site_name', 'Twoja Akademia')], 'auth'), $code);
    }

    /** Wysyła plik z dysku (X-Sendfile niepotrzebny; strumieniowo). */
    public static function file(string $path, string $name, string $mime, bool $attachment): never
    {
        if (!is_file($path)) {
            self::error(404);
        }
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($path));
        $safe = preg_replace('/[^\w.\- ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/u', '_', $name) ?: 'plik';
        header('Content-Disposition: ' . ($attachment ? 'attachment' : 'inline') . '; filename="' . addcslashes($safe, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Cache-Control: private, max-age=3600');
        if ($attachment) {
            header("Content-Security-Policy: default-src 'none'; sandbox");
        }
        readfile($path);
        exit;
    }
}
