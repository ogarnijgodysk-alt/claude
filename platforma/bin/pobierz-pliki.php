<?php
declare(strict_types=1);
/**
 * Pobiera pliki i obrazy z treści lekcji (domyślnie z cdn.idealms.net) na własny serwer i przepina linki.
 *
 *   php bin/pobierz-pliki.php --probe             # próba na 5 plikach: czy serwer je wydaje (403 = wymagają logowania)
 *   php bin/pobierz-pliki.php                     # pobiera wszystko, pomija już pobrane, ponawia błędy
 *   php bin/pobierz-pliki.php --hosts=cdn.idealms.net,obiblog.pl --allow-ext=gif,webp --sleep-ms=200
 */
if (PHP_SAPI !== 'cli') {
    exit("Tylko z linii poleceń.\n");
}
require __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Migration\AssetFetcher;

$o = getopt('', ['probe', 'hosts::', 'allow-ext::', 'sleep-ms::', 'timeout::', 'help']);
if (isset($o['help'])) {
    echo "Użycie: php bin/pobierz-pliki.php [--probe] [--hosts=a,b] [--allow-ext=gif,webp] [--sleep-ms=N] [--timeout=S]\n";
    exit(0);
}
$hosts = array_filter(array_map('trim', explode(',', (string)($o['hosts'] ?? 'cdn.idealms.net'))));
$extra = [
    'allow_ext' => array_filter(array_map('trim', explode(',', (string)($o['allow-ext'] ?? '')))),
    'sleep_ms' => (int)($o['sleep-ms'] ?? 0), 'timeout' => (int)($o['timeout'] ?? 60),
];
$f = new AssetFetcher($hosts, $extra, static function (string $m): void {
    echo '[' . date('H:i:s') . '] ' . $m . "\n";
});

if (isset($o['probe'])) {
    $r = $f->probe(5);
    foreach ($r['results'] as $x) {
        echo sprintf("HTTP %-3d %s%s\n", $x['status'], $x['url'], $x['error'] ? '  (' . $x['error'] . ')' : '');
    }
    if ($r['total'] === 0) {
        echo "Brak adresów z podanych domen w bazie (czy import już się wykonał?).\n";
        exit(1);
    }
    if (!$r['ok']) {
        echo "\nSTOP: serwer nie wydaje plików (HTTP 401/403 lub błąd połączenia). Prawdopodobnie wymagają logowania do Publigo.\n"
            . "Nie uruchamiaj pełnego pobierania. Pobierz pliki przeglądarką po zalogowaniu (zob. docs/INSTALACJA.md, rozdział „Pliki z CDN”).\n";
        exit(2);
    }
    echo "\nOK: serwer wydaje pliki. Uruchom pełne pobieranie bez --probe.\n";
    exit(0);
}
$stats = $f->run();
$dir = Config::storage('reports');
@mkdir($dir, 0750, true);
$file = $dir . '/pobieranie-bledy-' . date('Ymd-His') . '.csv';
$fh = fopen($file, 'wb');
fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, ['url', 'status', 'problem'], ',', '"', '');
foreach ($f->failures as $row) {
    fputcsv($fh, $row, ',', '"', '');
}
fclose($fh);
echo "\nPodsumowanie: znaleziono {$stats['found']}, pobrano {$stats['downloaded']}, już były {$stats['already']}, błędy {$stats['failed']}, pominięte (rozszerzenie) {$stats['skipped_ext']}, przepięte bloki {$stats['rewritten_blocks']}\n";
echo "Raport błędów: $file\n";
exit(!empty($stats['aborted']) ? 2 : ($stats['failed'] ? 1 : 0));
