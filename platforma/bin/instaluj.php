<?php
declare(strict_types=1);
/**
 * Instalacja z linii poleceń:
 *   php bin/instaluj.php --db-host=localhost --db-name=kursy --db-user=u --db-pass=h --app-url=https://nowa.example.pl \
 *       --admin-email=a@b.pl --admin-name="Imię Nazwisko" [--admin-password=...] [--site-name="Twoja Akademia"] \
 *       [--smtp-host= --smtp-port=587 --smtp-enc=tls --smtp-user= --smtp-pass= --mail-from=]
 * Gdy nie podasz --admin-password, skrypt zapyta o nie (ukryte wpisywanie).
 */
if (PHP_SAPI !== 'cli') {
    exit("Tylko z linii poleceń.\n");
}
require __DIR__ . '/../src/bootstrap.php';

use App\Installer;

if (is_file(APP_ROOT . '/config/config.php') && !in_array('--force', $argv, true)) {
    fwrite(STDERR, "Już zainstalowano (config/config.php istnieje). Użyj --force, aby nadpisać konfigurację (baza nie jest kasowana).\n");
    exit(1);
}
$o = getopt('', ['db-host:', 'db-port::', 'db-name:', 'db-user:', 'db-pass::', 'app-url:', 'admin-email:', 'admin-name::', 'admin-password::', 'site-name::', 'smtp-host::', 'smtp-port::', 'smtp-enc::', 'smtp-user::', 'smtp-pass::', 'mail-from::', 'mail-transport::', 'force']);
foreach (['db-host', 'db-name', 'db-user', 'app-url', 'admin-email'] as $req) {
    if (empty($o[$req])) {
        fwrite(STDERR, "Brak parametru --$req (zobacz komentarz na początku pliku)\n");
        exit(1);
    }
}
$pass = $o['admin-password'] ?? '';
if ($pass === '') {
    fwrite(STDOUT, 'Hasło administratora (min. 10 znaków): ');
    if (DIRECTORY_SEPARATOR === '/') {
        system('stty -echo');
    }
    $pass = trim((string)fgets(STDIN));
    if (DIRECTORY_SEPARATOR === '/') {
        system('stty echo');
    }
    fwrite(STDOUT, "\n");
}
if (mb_strlen($pass) < 10) {
    fwrite(STDERR, "Hasło musi mieć co najmniej 10 znaków.\n");
    exit(1);
}
foreach (Installer::requirements() as $r) {
    if (!$r['ok']) {
        fwrite(STDERR, 'Wymaganie niespełnione: ' . $r['label'] . ' (' . $r['detail'] . ")\n");
        exit(1);
    }
}
try {
    $log = Installer::run([
        'db_host' => $o['db-host'], 'db_port' => $o['db-port'] ?? 3306, 'db_name' => $o['db-name'], 'db_user' => $o['db-user'], 'db_pass' => $o['db-pass'] ?? '',
        'app_url' => $o['app-url'], 'admin_email' => $o['admin-email'], 'admin_name' => $o['admin-name'] ?? '', 'admin_password' => $pass,
        'site_name' => $o['site-name'] ?? 'Twoja Akademia', 'mail_transport' => $o['mail-transport'] ?? 'smtp',
        'smtp_host' => $o['smtp-host'] ?? '', 'smtp_port' => $o['smtp-port'] ?? 587, 'smtp_enc' => $o['smtp-enc'] ?? 'tls',
        'smtp_user' => $o['smtp-user'] ?? '', 'smtp_pass' => $o['smtp-pass'] ?? '', 'mail_from' => $o['mail-from'] ?? '',
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Błąd instalacji: ' . $e->getMessage() . "\n");
    exit(1);
}
foreach ($log as $l) {
    echo $l, "\n";
}
echo "Gotowe. Dodaj do crona:  * * * * * php " . APP_ROOT . "/bin/cron.php\n";
