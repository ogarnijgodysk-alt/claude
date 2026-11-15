<?php
declare(strict_types=1);

// serwer wbudowany PHP (tylko do testów): pliki statyczne podawaj bezpośrednio
if (PHP_SAPI === 'cli-server') {
    $f = __DIR__ . (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($f) && basename($f) !== 'index.php') {
        return false;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Http\{Request, Response};

set_exception_handler(static function (Throwable $e): void {
    $dir = App\Config::storage('logs');
    @mkdir($dir, 0750, true);
    @file_put_contents($dir . '/error.log', '[' . date('c') . '] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
    if (App\Config::get('debug', false)) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(500);
        echo $e;
        exit;
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Błąd</title><p style="font:16px system-ui;margin:40px">Coś poszło nie tak. Spróbuj ponownie za chwilę. Jeśli problem się powtarza, powiadom administratora.</p>';
    exit;
});

if (!is_file(APP_ROOT . '/config/config.php') && !getenv('KURSY_CONFIG')) {
    header('Location: ' . url('/instalator.php'));
    exit;
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-src https://player.vimeo.com https://www.youtube-nocookie.com https://open.spotify.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
if (App\Config::get('hsts', false)) {
    header('Strict-Transport-Security: max-age=31536000');
}

$req = Request::fromGlobals();

// stare adresy z Publigo (np. /?p=123) -> 301 na nowe
if ($req->path === '/' && isset($req->query['p'])) {
    $row = App\Db::one('SELECT * FROM redirects WHERE old_path = ?', ['/?p=' . (int)$req->query['p']]);
    if ($row) {
        Response::redirect($row['target_type'] === 'lesson' ? '/lekcja-przekierowanie/' . $row['target_id'] : '/kurs/' . $row['target_id'], 301);
    }
}
if ($req->method === 'GET' && $req->path !== '/') {
    $row = App\Db::one('SELECT * FROM redirects WHERE old_path = ?', [$req->path]);
    if ($row) {
        Response::redirect($row['target_type'] === 'lesson' ? '/lekcja-przekierowanie/' . $row['target_id'] : '/kurs/' . $row['target_id'], 301);
    }
}

$router = new App\Router();
(require APP_ROOT . '/src/routes.php')($router);
$router->get('/lekcja-przekierowanie/{lid}', [App\Controllers\StudentController::class, 'lessonRedirect']);
$router->dispatch($req);
