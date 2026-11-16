<?php
declare(strict_types=1);
/**
 * Wczytuje stare hashe haseł z WordPressa/Publigo, żeby klienci mogli logować się dotychczasowym hasłem.
 * Po pierwszym poprawnym logowaniu hasło jest przeliczane na Argon2id (bcrypt) i stary hash znika.
 *
 *   php bin/import-hasel.php --csv=wp_users.csv        # kolumny: user_email,user_pass (nagłówek w 1. wierszu)
 *   php bin/import-hasel.php --csv=... --dry-run
 *
 * Eksport z phpMyAdmin: tabela wp_users -> Eksport -> CSV, kolumny user_email i user_pass.
 * Obsługiwane hashe: phpass ($P$, $H$), bcrypt ($2y$) i WordPress 6.8+ ($wp$2y$). Konta bez hasha lub z innym formatem są pomijane.
 */
if (PHP_SAPI !== 'cli') {
    exit("Tylko z linii poleceń.\n");
}
require __DIR__ . '/../src/bootstrap.php';

use App\Db;
use App\Migration\PubligoImporter;

$o = getopt('', ['csv:', 'dry-run']);
if (empty($o['csv']) || !is_file((string)$o['csv'])) {
    exit("Użycie: php bin/import-hasel.php --csv=PLIK.csv [--dry-run]\n");
}
$fh = fopen((string)$o['csv'], 'rb');
$head = PubligoImporter::csvRow($fh);
$head = array_map(fn($h) => strtolower(trim(ltrim((string)$h, "\xEF\xBB\xBF"))), $head);
$ie = array_search('user_email', $head, true);
$ip = array_search('user_pass', $head, true);
if ($ie === false || $ip === false) {
    exit("Brak kolumn user_email i user_pass w nagłówku.\n");
}
$st = ['rows' => 0, 'set' => 0, 'no_account' => 0, 'has_password' => 0, 'bad_format' => 0];
while (($r = PubligoImporter::csvRow($fh)) !== null) {
    $st['rows']++;
    $email = mb_strtolower(trim((string)($r[$ie] ?? '')));
    $hash = trim((string)($r[$ip] ?? ''));
    if (!preg_match('/^(\$P\$|\$H\$|\$2[yab]\$|\$wp\$2y\$)/', $hash)) {
        $st['bad_format']++;
        continue;
    }
    $u = Db::one('SELECT id, password_hash FROM users WHERE email = ?', [$email]);
    if (!$u) {
        $st['no_account']++;
        continue;
    }
    if (!empty($u['password_hash'])) {
        $st['has_password']++; // konto ma już nowe hasło (np. administrator): nie ruszamy
        continue;
    }
    if (!isset($o['dry-run'])) {
        Db::exec('UPDATE users SET legacy_hash = ? WHERE id = ?', [$hash, $u['id']]);
    }
    $st['set']++;
}
fclose($fh);
echo "Wierszy: {$st['rows']}, ustawiono stare hasło: {$st['set']}, brak konta w bazie: {$st['no_account']}, konto ma już nowe hasło: {$st['has_password']}, nieobsługiwany format: {$st['bad_format']}" . (isset($o['dry-run']) ? " (próba, nic nie zapisano)\n" : "\n");
