<?php
declare(strict_types=1);

namespace App\Migration;

use App\{Db, Uploads};

/**
 * Pobiera pliki i obrazy z zewnętrznych adresów (domyślnie cdn.idealms.net) do własnego magazynu
 * i przepina linki w blokach. Wielokrotnego użytku: to, co już pobrane, jest pomijane, błędy ponawiane.
 */
final class AssetFetcher
{
    public const ALLOWED = ['pdf', 'zip', 'docx', 'xlsx', 'pptx', 'jpg', 'jpeg', 'png'];
    /** @var callable */
    private $log;
    public array $failures = [];
    public array $stats = ['found' => 0, 'downloaded' => 0, 'already' => 0, 'failed' => 0, 'skipped_ext' => 0, 'rewritten_blocks' => 0];

    /** @param string[] $hosts */
    public function __construct(private array $hosts = ['cdn.idealms.net'], private array $extra = [], ?callable $log = null)
    {
        $this->log = $log ?? static function (string $m): void {
        };
        $this->extra += ['sleep_ms' => 0, 'timeout' => 60, 'max_mb' => 200, 'ua' => 'PlatformaKursow-migracja/1.0', 'allow_ext' => []];
    }

    private function log(string $m): void
    {
        ($this->log)($m);
    }

    private function wanted(string $url): bool
    {
        $h = strtolower((string)parse_url($url, PHP_URL_HOST));
        return $h !== '' && in_array($h, $this->hosts, true) && preg_match('#^https?://#i', $url) === 1;
    }

    /** @return array<string,array<int,int>> url => lista id bloków, które go używają */
    public function collect(): array
    {
        $urls = [];
        foreach (Db::all("SELECT id, type, content, data FROM blocks WHERE type IN ('image','file','text')") as $b) {
            if ($b['type'] === 'image') {
                foreach ((json_decode((string)$b['data'], true)['images'] ?? []) as $im) {
                    if (!empty($im['src']) && $this->wanted($im['src'])) {
                        $urls[$im['src']][] = (int)$b['id'];
                    }
                }
            } elseif ($b['type'] === 'file') {
                $u = (string)(json_decode((string)$b['data'], true)['url'] ?? '');
                if ($u !== '' && $this->wanted($u)) {
                    $urls[$u][] = (int)$b['id'];
                }
            } elseif (preg_match_all('/\b(?:src|href)="(https?:\/\/[^"]+)"/i', $b['content'], $m)) {
                foreach ($m[1] as $u) {
                    $u = html_entity_decode($u);
                    if ($this->wanted($u)) {
                        $urls[$u][] = (int)$b['id'];
                    }
                }
            }
        }
        return $urls;
    }

    /** Próba na kilku plikach. @return array{ok:bool,results:array} ok=false, gdy serwer odmawia (401/403). */
    public function probe(int $n = 5): array
    {
        $urls = array_slice(array_keys($this->collect()), 0, $n);
        $res = [];
        $denied = 0;
        foreach ($urls as $u) {
            [$code, , $err] = $this->head($u);
            $res[] = ['url' => $u, 'status' => $code, 'error' => $err];
            if (in_array($code, [401, 403], true)) {
                $denied++;
            }
        }
        return ['ok' => $urls !== [] && $denied === 0 && count(array_filter($res, fn($r) => $r['status'] >= 200 && $r['status'] < 400)) > 0, 'denied' => $denied, 'results' => $res, 'total' => count($urls)];
    }

    /**
     * Pobiera adres z ręcznym śledzeniem przekierowań: tylko HTTP/HTTPS i tylko hosty z listy
     * (brak SSRF przez przekierowanie na adresy wewnętrzne).
     * @param resource|null $fh
     * @return array{0:int,1:string,2:string} kod, typ, błąd
     */
    private function fetch(string $url, $fh, bool $nobody, int $timeout): array
    {
        for ($hop = 0; $hop <= 5; $hop++) {
            $loc = '';
            $ch = curl_init($url);
            $opts = [
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_USERAGENT => $this->extra['ua'],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$loc) {
                    if (stripos($h, 'location:') === 0) {
                        $loc = trim(substr($h, 9));
                    }
                    return strlen($h);
                },
            ];
            if ($nobody) {
                $opts[CURLOPT_NOBODY] = true;
                $opts[CURLOPT_RETURNTRANSFER] = true;
            } else {
                $max = (int)$this->extra['max_mb'] * 1048576;
                $opts[CURLOPT_FILE] = $fh;
                $opts[CURLOPT_NOPROGRESS] = false;
                $opts[CURLOPT_PROGRESSFUNCTION] = fn($r, $dt, $dn) => $dn > $max ? 1 : 0;
            }
            curl_setopt_array($ch, $opts);
            if (!$nobody) {
                ftruncate($fh, 0);
                rewind($fh);
            }
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($code >= 300 && $code < 400 && $loc !== '') {
                $next = $this->absolute($url, $loc);
                if (!$this->wanted($next)) {
                    return [0, '', 'przekierowanie poza dozwolone domeny: ' . $next];
                }
                $url = $next;
                continue;
            }
            return [$code, $type, $err];
        }
        return [0, '', 'zbyt wiele przekierowań'];
    }

    private function absolute(string $base, string $loc): string
    {
        if (preg_match('#^https?://#i', $loc)) {
            return $loc;
        }
        $p = parse_url($base);
        $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($loc, '/')) {
            return $origin . $loc;
        }
        return $origin . rtrim(dirname((string)($p['path'] ?? '/')), '/') . '/' . $loc;
    }

    /** @return array{0:int,1:string,2:string} */
    private function head(string $url): array
    {
        return $this->fetch($url, null, true, 20);
    }

    public function run(): array
    {
        $map = $this->collect();
        $this->stats['found'] = count($map);
        $this->log('Znaleziono ' . count($map) . ' unikalnych adresów z domen: ' . implode(', ', $this->hosts));
        $consecutiveDenied = 0;
        $done = [];
        $i = 0;
        foreach ($map as $url => $blockIds) {
            $i++;
            $media = Db::one('SELECT id, ext FROM media WHERE source_url = ?', [$url]);
            if ($media) {
                $this->stats['already']++;
                $done[$url] = $media;
                continue;
            }
            $ext = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (!in_array($ext, array_merge(self::ALLOWED, $this->extra['allow_ext']), true)) {
                $this->stats['skipped_ext']++;
                $this->failures[] = [$url, 'pominięty', 'rozszerzenie .' . $ext . ' poza listą dozwolonych (dodaj przez --allow-ext)'];
                continue;
            }
            $r = $this->download($url, $ext);
            if (!$r['ok'] && str_starts_with($url, 'http://')) {
                // część linków w treści jest bez szyfrowania: spróbuj wersji https
                $r2 = $this->download('https://' . substr($url, 7), $ext, $url);
                $r = $r2['ok'] ? $r2 : $r;
            }
            if ($r['ok']) {
                $this->stats['downloaded']++;
                $consecutiveDenied = 0;
                $done[$url] = $r['media'];
                if ($i % 25 === 0) {
                    $this->log("Pobrano {$this->stats['downloaded']} (przetworzono $i z " . count($map) . ')');
                }
            } else {
                $this->stats['failed']++;
                $this->failures[] = [$url, (string)$r['status'], $r['error']];
                $consecutiveDenied = in_array($r['status'], [401, 403], true) ? $consecutiveDenied + 1 : 0;
                if ($consecutiveDenied >= 5 && $this->stats['downloaded'] + $this->stats['already'] === 0) {
                    $this->log('STOP: serwer odmawia dostępu (HTTP ' . $r['status'] . ') dla 5 plików z rzędu. Prawdopodobnie pliki wymagają logowania do Publigo. Pobierz je przeglądarką (zob. docs/INSTALACJA.md).');
                    $this->failures[] = ['(przerwano)', (string)$r['status'], 'Serwer odmawia dostępu; pozostałe pliki nie były pobierane'];
                    return $this->stats + ['aborted' => true];
                }
            }
            if ($this->extra['sleep_ms'] > 0) {
                usleep($this->extra['sleep_ms'] * 1000);
            }
        }
        $this->rewrite($done);
        return $this->stats + ['aborted' => false];
    }

    /** @return array{ok:bool,status:int,error:string,media?:array} */
    private function download(string $url, string $ext, ?string $sourceUrl = null): array
    {
        @mkdir(\App\Config::storage('tmp'), 0750, true);
        $tmp = tempnam(\App\Config::storage('tmp'), 'dl');
        $fh = fopen($tmp, 'w+b');
        [$code, , $err] = $this->fetch($url, $fh, false, (int)$this->extra['timeout']);
        fclose($fh);
        try {
            if ($code < 200 || $code >= 300) {
                return ['ok' => false, 'status' => $code, 'error' => $err ?: 'HTTP ' . $code];
            }
            $size = (int)filesize($tmp);
            if ($size <= 0) {
                return ['ok' => false, 'status' => $code, 'error' => 'pusty plik'];
            }
            $canon = Uploads::verify($tmp, $ext === 'jpeg' ? 'jpg' : $ext, Uploads::detect($tmp));
            if ($canon === null) {
                return ['ok' => false, 'status' => $code, 'error' => 'zawartość nie pasuje do rozszerzenia .' . $ext . ' (np. strona HTML zamiast pliku)'];
            }
            $name = urldecode(basename((string)parse_url($url, PHP_URL_PATH)));
            return ['ok' => true, 'status' => $code, 'error' => '', 'media' => Uploads::save($tmp, $name, $ext, $canon, $size, $sourceUrl ?? $url, false)];
        } finally {
            @unlink($tmp);
        }
    }

    /** Przepina linki w blokach na pobrane pliki. */
    private function rewrite(array $done): void
    {
        if (!$done) {
            return;
        }
        foreach (Db::all("SELECT id, type, content, data FROM blocks WHERE type IN ('image','file','text')") as $b) {
            $changed = false;
            $content = (string)$b['content'];
            $data = $b['data'] ? json_decode((string)$b['data'], true) : null;
            if ($b['type'] === 'image' && $data) {
                foreach ($data['images'] as &$im) {
                    if (!empty($im['src']) && isset($done[$im['src']])) {
                        $im = ['media_id' => (int)$done[$im['src']]['id'], 'alt' => $im['alt'] ?? '', 'caption' => $im['caption'] ?? ''];
                        $changed = true;
                    }
                }
                unset($im);
            } elseif ($b['type'] === 'file' && $data && !empty($data['url']) && isset($done[$data['url']])) {
                $data = ['media_id' => (int)$done[$data['url']]['id'], 'name' => $data['name'] ?? ''];
                $changed = true;
            } elseif ($b['type'] === 'text') {
                foreach ($done as $url => $m) {
                    $isImg = in_array($m['ext'], ['jpg', 'jpeg', 'png'], true);
                    foreach ([htmlspecialchars($url, ENT_QUOTES), $url] as $needle) {
                        $new = preg_replace_callback('/\b(src|href)="' . preg_quote($needle, '/') . '"/i', function ($mm) use ($m, $isImg) {
                            return $mm[1] . '="' . ($isImg && strtolower($mm[1]) === 'src' ? url('/obraz/' . $m['id']) : url('/plik/' . $m['id'])) . '"';
                        }, $content);
                        if ($new !== null && $new !== $content) {
                            $content = $new;
                            $changed = true;
                        }
                    }
                }
            }
            if ($changed) {
                Db::update('blocks', ['content' => $content, 'data' => $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $b['data']], 'id = ?', [$b['id']]);
                $this->stats['rewritten_blocks']++;
            }
        }
    }
}
