<?php
declare(strict_types=1);
/**
 * Zadania w tle. Uruchamiaj co minutę z crona:
 *   * * * * * /usr/bin/php /sciezka/do/platforma/bin/cron.php >> /sciezka/do/platforma/storage/logs/cron.log 2>&1
 * Robi: (1) wysyłkę kolejki e-maili w tempie z ustawień, (2) kolejne porcje importów klientów,
 * (3) sprzątanie starych limitów prób, tokenów i dziennika.
 */
if (PHP_SAPI !== 'cli') {
    exit("Tylko z linii poleceń.\n");
}
require __DIR__ . '/../src/bootstrap.php';

use App\{Db, Security, Settings};
use App\Jobs\ClientImport;
use App\Mail\MailQueue;

@mkdir(App\Config::storage('tmp'), 0750, true);
$lockFile = @fopen(App\Config::storage('tmp') . '/cron.lock', 'c');
if (!$lockFile) {
    fwrite(STDERR, "BŁĄD: nie można utworzyć blokady w storage/tmp (uprawnienia?)\n");
    exit(1);
}
if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
    exit(0); // poprzedni przebieg jeszcze trwa
}
$t0 = time();
try {
    // 1. e-maile: nie więcej niż mail_per_minute na przebieg
    $per = max(1, min(600, (int)Settings::get('mail_per_minute', '60')));
    $r = MailQueue::flush($per);
    if ($r['sent'] || $r['failed']) {
        echo date('c') . " e-mail: wysłano {$r['sent']}, błędów {$r['failed']}\n";
    }
    // 2. importy klientów
    foreach (Db::all("SELECT id FROM jobs WHERE type = 'clients' AND status = 'running' ORDER BY id") as $j) {
        $left = 50 - (time() - $t0);
        if ($left <= 3) {
            break;
        }
        $s = ClientImport::process((int)$j['id'], min(40.0, $left - 2));
        echo date('c') . " import #{$j['id']}: {$s['done']}/{$s['total']}\n";
    }
    // 3. sprzątanie raz na godzinę
    if (time() - (int)Settings::get('last_purge', '0') > 3600) {
        Security::purge();
        Settings::set('last_purge', (string)time());
    }
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' BŁĄD: ' . $e->getMessage() . "\n");
    exit(1);
}
