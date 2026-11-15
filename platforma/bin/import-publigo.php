<?php
declare(strict_types=1);
/**
 * Import paczki migracyjnej Publigo + raport zgodności.
 *
 *   php bin/import-publigo.php --dir=/sciezka/do/paczki [--admin-email=adres] [--replace-content] [--report-only]
 */
if (PHP_SAPI !== 'cli') {
    exit("Tylko z linii poleceń.\n");
}
require __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Migration\PubligoImporter;
use App\Migration\Report;

$o = getopt('', ['dir:', 'admin-email::', 'replace-content', 'report-only', 'help']);
if (isset($o['help']) || empty($o['dir'])) {
    echo "Użycie: php bin/import-publigo.php --dir=KATALOG_PACZKI [--admin-email=EMAIL] [--replace-content] [--report-only]\n"
        . "  --dir              katalog z kursy.json, quizy.json, kursanci.csv, dostepy.csv\n"
        . "  --admin-email      konto, które dostaje rolę administratora (domyślnie latuszekmarek@gmail.com)\n"
        . "  --replace-content  nadpisz treść kursów, które już są w bazie (domyślnie są pomijane)\n"
        . "  --report-only      nie importuj, tylko wygeneruj raport zgodności źródło vs baza\n";
    exit(isset($o['help']) ? 0 : 1);
}
$dir = rtrim((string)$o['dir'], '/');
$log = static function (string $m): void {
    fwrite(STDOUT, '[' . date('H:i:s') . '] ' . $m . "\n");
};
$issues = [];
if (!isset($o['report-only'])) {
    $imp = new PubligoImporter($dir, [
        'admin_email' => $o['admin-email'] ?? 'latuszekmarek@gmail.com',
        'replace_content' => isset($o['replace-content']),
    ], $log);
    $res = $imp->run();
    $issues = $res['issues'];
    $log('Import zakończony w ' . $res['stats']['seconds'] . ' s.');
}
$log('Buduję raport zgodności…');
$files = Report::generate($dir, $issues, Config::storage('reports'));
foreach ($files as $f) {
    $log('Zapisano: ' . $f);
}
