<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Installer;

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

$done = is_file(APP_ROOT . '/config/config.php');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$out = '';
$errors = [];
$v = $_POST + ['db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_pass' => '', 'app_url' => '', 'site_name' => 'Twoja Akademia', 'admin_email' => '', 'admin_name' => '', 'admin_password' => '', 'mail_transport' => 'smtp', 'smtp_host' => '', 'smtp_port' => '587', 'smtp_enc' => 'tls', 'smtp_user' => '', 'smtp_pass' => '', 'mail_from' => ''];
if (!$v['app_url']) {
    $v['app_url'] = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
$log = [];
if (!$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // prosty token przeciw CSRF (instalator działa bez sesji z bazy)
    session_name('kurs_inst');
    session_start();
    if (empty($_POST['_t']) || !hash_equals($_SESSION['t'] ?? '', (string)$_POST['_t'])) {
        $errors[] = 'Formularz wygasł. Odśwież stronę.';
    } else {
        if (!valid_email((string)$v['admin_email'])) {
            $errors[] = 'Podaj poprawny e-mail administratora.';
        }
        if (mb_strlen((string)$v['admin_password']) < 10) {
            $errors[] = 'Hasło administratora musi mieć co najmniej 10 znaków.';
        }
        if ($v['admin_password'] !== ($_POST['admin_password2'] ?? '')) {
            $errors[] = 'Hasła administratora nie są takie same.';
        }
        if (!preg_match('#^https?://#', (string)$v['app_url'])) {
            $errors[] = 'Adres strony musi zaczynać się od http:// lub https://';
        }
        if (!$errors) {
            try {
                $log = Installer::run($v);
                $done = true;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('kurs_inst');
    session_start();
}
$_SESSION['t'] = $_SESSION['t'] ?? bin2hex(random_bytes(16));
$css = '/assets/app.css';
?><!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Instalator platformy kursów</title><link rel="stylesheet" href="<?= $h($css) ?>"></head>
<body><div class="wrap"><div class="stu" style="max-width:720px">
<div class="sbar"><div class="brand"><span class="logo">A</span>Instalator platformy kursów</div></div>
<?php if ($done && $log): ?>
  <div class="panel pane"><h1 style="font-size:22px">Instalacja zakończona</h1>
  <ul><?php foreach ($log as $l): ?><li><?= $h($l) ?></li><?php endforeach; ?></ul>
  <p class="notice"><b>Ważne:</b> usuń plik <code>public/instalator.php</code> z serwera, a potem dodaj zadanie cron z instrukcji (plik <code>docs/INSTALACJA.md</code>).</p>
  <a class="btn primary" href="/logowanie" style="justify-content:center">Przejdź do logowania</a></div>
<?php elseif ($done): ?>
  <div class="panel pane"><h1 style="font-size:22px">Platforma jest już zainstalowana</h1><p class="muted">Plik <code>config/config.php</code> istnieje. Usuń plik <code>public/instalator.php</code> z serwera.</p><a class="btn" href="/logowanie">Przejdź do logowania</a></div>
<?php else: ?>
  <form class="panel pane" method="post" autocomplete="off">
    <input type="hidden" name="_t" value="<?= $h($_SESSION['t']) ?>">
    <h1 style="font-size:22px">Instalacja</h1>
    <?php foreach ($errors as $er): ?><p class="notice" role="alert"><?= $h($er) ?></p><?php endforeach; ?>
    <h3>Sprawdzenie serwera</h3>
    <ul class="res"><?php foreach (Installer::requirements() as $r): ?><li><span class="<?= $r['ok'] ? 'ok' : 'bad' ?>"><?= $r['ok'] ? '✓' : '✗' ?></span> <?= $h($r['label']) ?> <span class="muted small"><?= $h($r['detail']) ?></span></li><?php endforeach; ?></ul>
    <h3>Baza danych MySQL/MariaDB</h3>
    <div class="row2"><div><label class="l">Serwer</label><input class="in" name="db_host" value="<?= $h($v['db_host']) ?>" required></div><div><label class="l">Port</label><input class="in" name="db_port" value="<?= $h($v['db_port']) ?>"></div></div>
    <div class="row2"><div><label class="l">Nazwa bazy</label><input class="in" name="db_name" value="<?= $h($v['db_name']) ?>" required></div><div><label class="l">Użytkownik</label><input class="in" name="db_user" value="<?= $h($v['db_user']) ?>" required></div></div>
    <div><label class="l">Hasło do bazy</label><input class="in" type="password" name="db_pass" value="<?= $h($v['db_pass']) ?>"></div>
    <h3>Strona</h3>
    <div><label class="l">Adres platformy (bez końcowego /)</label><input class="in" name="app_url" value="<?= $h($v['app_url']) ?>" required></div>
    <div><label class="l">Nazwa platformy</label><input class="in" name="site_name" value="<?= $h($v['site_name']) ?>"></div>
    <h3>Konto administratora</h3>
    <div class="row2"><div><label class="l">Imię i nazwisko</label><input class="in" name="admin_name" value="<?= $h($v['admin_name']) ?>"></div><div><label class="l">E-mail (login)</label><input class="in" type="email" name="admin_email" value="<?= $h($v['admin_email']) ?>" required></div></div>
    <div class="row2"><div><label class="l">Hasło (min. 10 znaków)</label><input class="in" type="password" name="admin_password" minlength="10" required></div><div><label class="l">Powtórz hasło</label><input class="in" type="password" name="admin_password2" required></div></div>
    <h3>Wysyłka e-maili (SMTP)</h3>
    <p class="small muted">Można uzupełnić później w pliku <code>config/config.php</code>. Do testów wybierz „zapis do pliku logu”.</p>
    <div><label class="l">Sposób wysyłki</label><select class="in" name="mail_transport"><option value="smtp"<?= $v['mail_transport'] === 'smtp' ? ' selected' : '' ?>>SMTP (zalecane)</option><option value="log"<?= $v['mail_transport'] === 'log' ? ' selected' : '' ?>>tylko zapis do pliku logu (testy)</option></select></div>
    <div class="row2"><div><label class="l">Serwer SMTP</label><input class="in" name="smtp_host" value="<?= $h($v['smtp_host']) ?>"></div><div><label class="l">Port</label><input class="in" name="smtp_port" value="<?= $h($v['smtp_port']) ?>"></div></div>
    <div class="row2"><div><label class="l">Szyfrowanie</label><select class="in" name="smtp_enc"><option value="tls"<?= $v['smtp_enc'] === 'tls' ? ' selected' : '' ?>>STARTTLS (587)</option><option value="ssl"<?= $v['smtp_enc'] === 'ssl' ? ' selected' : '' ?>>SSL (465)</option><option value="none"<?= $v['smtp_enc'] === 'none' ? ' selected' : '' ?>>brak</option></select></div><div><label class="l">Adres nadawcy</label><input class="in" type="email" name="mail_from" value="<?= $h($v['mail_from']) ?>" placeholder="kursy@twojadomena.pl"></div></div>
    <div class="row2"><div><label class="l">Login SMTP</label><input class="in" name="smtp_user" value="<?= $h($v['smtp_user']) ?>"></div><div><label class="l">Hasło SMTP</label><input class="in" type="password" name="smtp_pass"></div></div>
    <button class="btn primary" type="submit" style="justify-content:center">Zainstaluj</button>
  </form>
<?php endif; ?>
</div></div></body></html>
