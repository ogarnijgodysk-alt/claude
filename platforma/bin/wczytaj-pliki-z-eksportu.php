<?php
declare(strict_types=1);
/**
 * Wczytuje pliki z folderu utworzonego wcześniej (pliki-publigo.zip: katalogi + indeks.csv), gdy CDN Publigo już nie działa,
 * a potem przepina linki w lekcjach na własny serwer.
 *   php bin/wczytaj-pliki-z-eksportu.php --dir=/sciezka/pliki-publigo
 */
if (PHP_SAPI !== 'cli') {
    exit("Tylko z linii poleceń.\n");
}
require __DIR__ . '/../src/bootstrap.php';

use App\{Db, Uploads};
use App\Migration\{AssetFetcher, PubligoImporter};

$o = getopt('', ['dir:']);
$dir = rtrim((string)($o['dir'] ?? ''), '/');
if ($dir === '' || !is_file($dir . '/indeks.csv')) {
    exit("Użycie: php bin/wczytaj-pliki-z-eksportu.php --dir=FOLDER (w folderze ma być indeks.csv)\n");
}
$fh = fopen($dir . '/indeks.csv', 'rb');
$head = PubligoImporter::csvRow($fh);
$n = 0;
$skip = 0;
$bad = 0;
while (($r = PubligoImporter::csvRow($fh)) !== null) {
    [$url, $rel] = [$r[0] ?? '', $r[1] ?? ''];
    if ($url === '' || Db::one('SELECT id FROM media WHERE source_url = ?', [$url])) {
        $skip++;
        continue;
    }
    $path = realpath($dir . '/' . $rel);
    if (!$path || !str_starts_with($path, realpath($dir) . '/') || !is_file($path)) {
        echo "Brak pliku: $rel\n";
        $bad++;
        continue;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $canon = Uploads::verify($path, $ext === 'jpeg' ? 'jpg' : $ext, Uploads::detect($path));
    if ($canon === null) {
        echo "Odrzucony (zawartość nie pasuje do .$ext): $rel\n";
        $bad++;
        continue;
    }
    Uploads::save($path, urldecode(basename($path)), $ext, $canon, (int)filesize($path), $url, false);
    $n++;
}
fclose($fh);
echo "Wczytano $n plików, pominięto $skip (już były), błędy $bad. Przepinam linki w lekcjach…\n";
// run() nie pobiera z sieci tego, co już jest w media (dopasowanie po adresie źródłowym), tylko przepina linki
$hosts = array_values(array_unique(array_filter(array_map(fn($x) => (string)parse_url((string)$x['source_url'], PHP_URL_HOST), Db::all('SELECT DISTINCT source_url FROM media WHERE source_url IS NOT NULL')))));
$f = new AssetFetcher($hosts ?: ['cdn.idealms.net'], ['timeout' => 15], static function (string $m): void {
    echo $m . "\n";
});
$s = $f->run();
echo "Przepięte bloki: {$s['rewritten_blocks']}. Niepobrane: {$s['failed']}\n";
