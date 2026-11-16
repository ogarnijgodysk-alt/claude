<?php
declare(strict_types=1);
/**
 * Eksportuje pobrane pliki do zwykłego folderu z oryginalnymi nazwami i strukturą z adresu źródłowego
 * (np. EKSPORT/cdn.idealms.net/2018/2021/02/plik.pdf), plus indeks.csv (adres źródłowy -> plik).
 *   php bin/eksport-plikow.php --dir=/sciezka/EKSPORT [--zip]
 */
if (PHP_SAPI !== 'cli') {
    exit("Tylko z linii poleceń.\n");
}
require __DIR__ . '/../src/bootstrap.php';

use App\{Db, Uploads};

$o = getopt('', ['dir:', 'zip']);
if (empty($o['dir'])) {
    exit("Użycie: php bin/eksport-plikow.php --dir=FOLDER [--zip]\n");
}
$dir = rtrim((string)$o['dir'], '/');
@mkdir($dir, 0750, true);
$idx = fopen($dir . '/indeks.csv', 'wb');
fwrite($idx, "\xEF\xBB\xBF");
fputcsv($idx, ['adres_zrodlowy', 'plik', 'rozmiar_bajtow', 'sha256'], ',', '"', '');
$n = 0;
$bytes = 0;
foreach (Db::all('SELECT * FROM media WHERE source_url IS NOT NULL ORDER BY id') as $m) {
    $u = parse_url((string)$m['source_url']);
    $rel = ($u['host'] ?? 'inne') . '/' . implode('/', array_map('rawurldecode', explode('/', trim((string)($u['path'] ?? $m['original_name']), '/'))));
    $rel = preg_replace('#\.\./#', '', $rel);
    $dest = $dir . '/' . $rel;
    @mkdir(dirname($dest), 0750, true);
    if (!copy(Uploads::path($m), $dest)) {
        fwrite(STDERR, "Nie udało się skopiować: {$m['source_url']}\n");
        continue;
    }
    fputcsv($idx, [$m['source_url'], $rel, $m['size'], $m['sha256']], ',', '"', '');
    $n++;
    $bytes += (int)$m['size'];
}
fclose($idx);
echo "Wyeksportowano $n plików (" . fmt_size($bytes) . ") do $dir\n";
if (isset($o['zip'])) {
    $zip = $dir . '.zip';
    passthru('cd ' . escapeshellarg(dirname($dir)) . ' && zip -qr0 ' . escapeshellarg($zip) . ' ' . escapeshellarg(basename($dir)), $rc);
    echo $rc === 0 ? "Zip: $zip\n" : "Nie udało się utworzyć zip (brak polecenia zip?)\n";
}
