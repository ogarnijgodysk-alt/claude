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

$o = getopt('', ['dir:', 'admin-email::', 'replace-content', 'report-only', 'help', 'yes', 'only::']);
if (isset($o['help']) || empty($o['dir'])) {
    echo "Użycie: php bin/import-publigo.php --dir=KATALOG_PACZKI [--admin-email=EMAIL] [--replace-content] [--report-only]\n"
        . "  --dir              katalog z kursy.json, quizy.json, kursanci.csv, dostepy.csv\n"
        . "  --admin-email      konto, które dostaje rolę administratora (domyślnie latuszekmarek@gmail.com)\n"
        . "  --replace-content  nadpisz treść kursów, które już są w bazie (domyślnie są pomijane)\n"
        . "  --only=courses     tylko kursy i quizy (wymaga kursy.json, quizy.json), bez danych osobowych\n"
        . "  --only=users       tylko konta i przypisania (wymaga kursanci.csv, dostepy.csv; kursy muszą już być w bazie)\n"
        . "  --report-only      nie importuj, tylko wygeneruj raport zgodności źródło vs baza\n";
    exit(isset($o['help']) ? 0 : 1);
}
if (isset($o['replace-content']) && !isset($o['yes'])) {
    exit("--replace-content USUWA i odtwarza treść kursów już w bazie (przepada postęp kursantów w lekcjach, podpięte quizy i przepięte linki do plików).\nJeśli na pewno tego chcesz, dodaj --yes. Zwykły ponowny import (bez tej opcji) niczego nie nadpisuje.\n");
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
        'only' => in_array($o['only'] ?? 'all', ['all', 'courses', 'users'], true) ? ($o['only'] ?? 'all') : 'all',
    ], $log);
    $res = $imp->run();
    $issues = $res['issues'];
    $log('Import zakończony w ' . $res['stats']['seconds'] . ' s.');
}
if (!is_file($dir . '/kursy.json') || !is_file($dir . '/quizy.json')) {
    $log('Raport zgodności pominięty: potrzebuje też kursy.json i quizy.json w tym samym katalogu (nie zawierają danych osobowych). Skopiuj je tu i uruchom: php bin/import-publigo.php --dir=' . $dir . ' --report-only');
    exit(0);
}
$log('Buduję raport zgodności…');
$files = Report::generate($dir, $issues, Config::storage('reports'));
foreach ($files as $f) {
    $log('Zapisano: ' . $f);
}
