<?php
declare(strict_types=1);

namespace App;

/** Odczyt plików importu (CSV/TSV/TXT i XLSX) do tablicy wierszy. Bez bibliotek zewnętrznych. */
final class Spreadsheet
{
    public const MAX_ROWS = 10000;

    /** @return array<int,array<int,string>> wszystkie wiersze (z nagłówkiem), zawsze UTF-8 */
    public static function read(string $path, string $ext): array
    {
        $ext = strtolower($ext);
        if ($ext === 'xlsx') {
            return self::xlsx($path);
        }
        if (in_array($ext, ['csv', 'tsv', 'txt'], true)) {
            return self::csv($path);
        }
        throw new \InvalidArgumentException('Ten format nie jest obsługiwany. Zapisz plik jako CSV lub XLSX.');
    }

    public static function csv(string $path): array
    {
        $raw = (string)file_get_contents($path);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        } elseif (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $conv = function_exists('iconv') ? @iconv('CP1250', 'UTF-8//IGNORE', $raw) : false;
            $raw = $conv !== false ? $conv : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-2');
        }
        $first = strtok($raw, "\n") ?: '';
        $delims = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($delims);
        $d = (string)array_key_first($delims);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $rows = [];
        while (($r = fgetcsv($fh, 0, $d, '"', '')) !== false) {
            if ($r === [null]) {
                continue;
            }
            $rows[] = array_map(fn($v) => trim((string)$v), $r);
            if (count($rows) > self::MAX_ROWS + 1) {
                break;
            }
        }
        fclose($fh);
        return $rows;
    }

    public static function xlsx(string $path): array
    {
        $z = new \ZipArchive();
        if ($z->open($path) !== true) {
            throw new \InvalidArgumentException('Nie można otworzyć pliku XLSX.');
        }
        $shared = [];
        if (($ss = $z->getFromName('xl/sharedStrings.xml')) !== false) {
            $x = new \XMLReader();
            $x->XML($ss, null, LIBXML_NONET);
            $cur = null;
            while ($x->read()) {
                if ($x->nodeType === \XMLReader::ELEMENT && $x->name === 'si') {
                    $cur = '';
                } elseif ($x->nodeType === \XMLReader::ELEMENT && $x->name === 't' && $cur !== null) {
                    $cur .= $x->readString();
                } elseif ($x->nodeType === \XMLReader::END_ELEMENT && $x->name === 'si') {
                    $shared[] = $cur;
                    $cur = null;
                }
            }
        }
        $sheet = $z->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet === false) {
            for ($i = 0; $i < $z->numFiles; $i++) {
                $n = (string)$z->getNameIndex($i);
                if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $n)) {
                    $sheet = $z->getFromName($n);
                    break;
                }
            }
        }
        $z->close();
        if ($sheet === false) {
            throw new \InvalidArgumentException('W pliku XLSX nie znaleziono arkusza.');
        }
        $rows = [];
        $x = new \XMLReader();
        $x->XML($sheet, null, LIBXML_NONET);
        $row = null;
        $col = 0;
        $type = '';
        $val = '';
        $inCell = false;
        while ($x->read()) {
            if ($x->nodeType === \XMLReader::ELEMENT) {
                if ($x->name === 'row') {
                    $row = [];
                } elseif ($x->name === 'c' && $row !== null) {
                    $ref = $x->getAttribute('r') ?? '';
                    $type = (string)$x->getAttribute('t');
                    $col = self::colIndex($ref);
                    $val = '';
                    $inCell = true;
                    if ($x->isEmptyElement) {
                        $row[$col] = '';
                        $inCell = false;
                    }
                } elseif ($inCell && ($x->name === 'v' || $x->name === 't')) {
                    $val .= $x->readString();
                }
            } elseif ($x->nodeType === \XMLReader::END_ELEMENT) {
                if ($x->name === 'c' && $inCell) {
                    $row[$col] = $type === 's' ? ($shared[(int)$val] ?? '') : trim($val);
                    $inCell = false;
                } elseif ($x->name === 'row' && $row !== null) {
                    $max = $row ? max(array_keys($row)) : -1;
                    $out = [];
                    for ($i = 0; $i <= $max; $i++) {
                        $out[] = trim((string)($row[$i] ?? ''));
                    }
                    if (array_filter($out, fn($v) => $v !== '') !== []) {
                        $rows[] = $out;
                    }
                    $row = null;
                    if (count($rows) > self::MAX_ROWS + 1) {
                        break;
                    }
                }
            }
        }
        return $rows;
    }

    private static function colIndex(string $ref): int
    {
        if (!preg_match('/^([A-Z]+)/', $ref, $m)) {
            return 0;
        }
        $n = 0;
        foreach (str_split($m[1]) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    /** Liczba z Excela (dni od 1900) lub tekst daty -> Y-m-d albo null. */
    public static function parseDate(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{5}(\.\d+)?$/', $v) && (float)$v > 20000 && (float)$v < 80000) {
            return gmdate('Y-m-d', (int)(((float)$v - 25569) * 86400));
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $v, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        return null;
    }

    /** Zapisuje wiersze jako znormalizowany CSV (UTF-8, przecinek). */
    public static function writeCsv(string $path, array $rows): void
    {
        $fh = fopen($path, 'wb');
        foreach ($rows as $r) {
            fputcsv($fh, $r, ',', '"', '');
        }
        fclose($fh);
    }
}
