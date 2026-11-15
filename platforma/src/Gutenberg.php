<?php
declare(strict_types=1);

namespace App;

/**
 * Konwersja surowego HTML-a bloków Gutenberga (eksport Publigo/WordPress)
 * na bloki platformy: text, vimeo, youtube, image (z galerią), file, embed.
 * Kolejność bloków w lekcji jest zachowana (tekst, film, tekst, drugi film...).
 */
final class Gutenberg
{
    private const DELIM = '#<!--\s+(/)?wp:([a-z][a-z0-9_-]*(?:/[a-z][a-z0-9_-]*)?)(?:\s+(\{(?:(?!-->).)*\}))?\s*(/)?-->#s';

    /**
     * @return array{blocks:array<int,array{type:string,content:string,data:array}>,warnings:array<int,array{type:string,detail:string}>}
     */
    public static function convert(string $html): array
    {
        $warnings = [];
        $blocks = [];
        $buf = '';
        $flush = function () use (&$buf, &$blocks, &$warnings): void {
            if (trim($buf) === '') {
                $buf = '';
                return;
            }
            $raw = $buf;
            $buf = '';
            if (preg_match_all('/\[\/?trenerka[^\]]*\]/i', $raw, $m)) {
                $warnings[] = ['type' => 'shortcode_trenerka', 'detail' => count($m[0]) . '× ' . $m[0][0]];
                $raw = preg_replace('/\[\/?trenerka[^\]]*\]/i', '', $raw) ?? $raw;
            }
            $clean = Sanitizer::html($raw);
            if (!Sanitizer::isEmptyHtml($clean)) {
                $blocks[] = ['type' => 'text', 'content' => $clean, 'data' => []];
            }
        };

        foreach (self::nodes($html) as $n) {
            if ($n['kind'] === 'html') {
                $buf .= $n['html'];
                continue;
            }
            $name = $n['name'];
            $attrs = $n['attrs'];
            $inner = $n['inner'];
            switch ($name) {
                case 'embed':
                    $url = (string)($attrs['url'] ?? '');
                    if ($url === '' && preg_match('#https?://[^\s<"]+#', strip_tags($inner), $m)) {
                        $url = $m[0];
                    }
                    $b = self::fromUrl($url, $warnings);
                    if ($b) {
                        $flush();
                        $blocks[] = $b;
                    } else {
                        $buf .= '<p><a href="' . e($url) . '">' . e($url) . '</a></p>';
                    }
                    break;
                case 'html':
                    $b = self::fromHtmlBlock($inner, $warnings);
                    if ($b) {
                        $flush();
                        $blocks[] = $b;
                    } else {
                        $buf .= $inner;
                    }
                    break;
                case 'image':
                case 'gallery':
                    $imgs = self::images($inner);
                    if ($imgs) {
                        $flush();
                        $blocks[] = ['type' => 'image', 'content' => '', 'data' => ['images' => $imgs]];
                    } else {
                        $warnings[] = ['type' => 'obraz_bez_src', 'detail' => $name];
                    }
                    break;
                case 'file':
                    $href = (string)($attrs['href'] ?? '');
                    $label = '';
                    if (preg_match('#<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', $inner, $m)) {
                        $href = $href !== '' ? $href : html_entity_decode($m[1]);
                        $label = Sanitizer::text($m[2]);
                    }
                    if ($href === '') {
                        $warnings[] = ['type' => 'plik_bez_url', 'detail' => ''];
                        break;
                    }
                    $flush();
                    if ($label === '') {
                        $label = urldecode(basename((string)parse_url($href, PHP_URL_PATH)));
                    }
                    $blocks[] = ['type' => 'file', 'content' => '', 'data' => ['url' => $href, 'name' => $label]];
                    break;
                case 'buttons':
                case 'button':
                    if (preg_match_all('#<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', $inner, $ms, PREG_SET_ORDER)) {
                        foreach ($ms as $m) {
                            $buf .= '<p><a href="' . e(html_entity_decode($m[1])) . '">' . e(Sanitizer::text($m[2])) . '</a></p>';
                        }
                    }
                    break;
                default:
                    $buf .= $inner;
            }
        }
        $flush();
        return ['blocks' => $blocks, 'warnings' => $warnings];
    }

    /** Dzieli HTML na bloki najwyższego poziomu i luźny HTML pomiędzy nimi. */
    private static function nodes(string $html): array
    {
        $out = [];
        $pos = 0;
        $depth = 0;
        $start = 0;
        $cur = null;
        if (!preg_match_all(self::DELIM, $html, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return trim($html) === '' ? [] : [['kind' => 'html', 'html' => $html]];
        }
        foreach ($ms as $m) {
            $full = $m[0][0];
            $off = $m[0][1];
            $closing = ($m[1][0] ?? '') === '/';
            $selfClose = ($m[4][0] ?? '') === '/';
            if ($depth === 0) {
                if ($off > $pos) {
                    $out[] = ['kind' => 'html', 'html' => substr($html, $pos, $off - $pos)];
                }
                if ($closing) {
                    $pos = $off + strlen($full);
                    continue; // zbłąkany znacznik zamykający
                }
                $attrs = [];
                if (($m[3][0] ?? '') !== '') {
                    $j = json_decode($m[3][0], true);
                    $attrs = is_array($j) ? $j : [];
                }
                $name = $m[2][0];
                if (str_starts_with($name, 'core/')) {
                    $name = substr($name, 5);
                }
                if ($selfClose) {
                    $out[] = ['kind' => 'block', 'name' => $name, 'attrs' => $attrs, 'inner' => ''];
                    $pos = $off + strlen($full);
                    continue;
                }
                $cur = ['name' => $name, 'attrs' => $attrs];
                $depth = 1;
                $start = $off + strlen($full);
            } else {
                if ($selfClose) {
                    continue;
                }
                if ($closing) {
                    $depth--;
                    if ($depth === 0) {
                        $out[] = ['kind' => 'block', 'name' => $cur['name'], 'attrs' => $cur['attrs'], 'inner' => substr($html, $start, $off - $start)];
                        $pos = $off + strlen($full);
                        $cur = null;
                    }
                } else {
                    $depth++;
                }
            }
        }
        if ($depth > 0 && $cur) { // niedomknięty blok: weź resztę
            $out[] = ['kind' => 'block', 'name' => $cur['name'], 'attrs' => $cur['attrs'], 'inner' => substr($html, $start)];
            $pos = strlen($html);
        }
        if ($pos < strlen($html)) {
            $out[] = ['kind' => 'html', 'html' => substr($html, $pos)];
        }
        return $out;
    }

    private static function fromUrl(string $url, array &$warnings): ?array
    {
        $url = trim(html_entity_decode($url));
        if ($url === '') {
            return null;
        }
        if (stripos($url, 'vimeo.com') !== false) {
            if (Video::vimeo($url)) {
                return ['type' => 'vimeo', 'content' => $url, 'data' => []];
            }
            $warnings[] = ['type' => 'vimeo_nierozpoznane', 'detail' => $url];
            return null;
        }
        if (preg_match('#youtu(\.be|be\.com|be-nocookie\.com)#i', $url)) {
            if (Video::youtube($url)) {
                return ['type' => 'youtube', 'content' => $url, 'data' => []];
            }
            $warnings[] = ['type' => 'youtube_nierozpoznane', 'detail' => $url];
            return null;
        }
        if ($emb = Video::genericEmbed($url)) {
            $warnings[] = ['type' => 'spotify_osadzenie', 'detail' => $url];
            return ['type' => 'embed', 'content' => $emb, 'data' => ['provider' => 'spotify']];
        }
        $warnings[] = ['type' => 'osadzenie_jako_link', 'detail' => $url];
        return null;
    }

    private static function fromHtmlBlock(string $inner, array &$warnings): ?array
    {
        if (preg_match('#<iframe\b[^>]*\bsrc="([^"]+)"#i', $inner, $m)) {
            $src = html_entity_decode($m[1]);
            $b = self::fromUrl($src, $warnings);
            if ($b) {
                return $b;
            }
            $warnings[] = ['type' => 'html_iframe_pominiety', 'detail' => $src];
        }
        return null;
    }

    /** @return array<int,array{src:string,alt:string,caption:string}> */
    private static function images(string $inner): array
    {
        $inner = preg_replace('/<!--.*?-->/s', '', $inner) ?? '';
        $imgs = [];
        // figury z nagłówkiem; w razie braku figur: same <img>
        $chunks = preg_split('#(?=<figure\b)#i', $inner) ?: [$inner];
        foreach ($chunks as $ch) {
            if (!preg_match('#<img\b[^>]*>#i', $ch, $im)) {
                continue;
            }
            $src = self::attr($im[0], 'src');
            if ($src === '') {
                continue;
            }
            $cap = '';
            if (preg_match('#<figcaption[^>]*>(.*?)</figcaption>#is', $ch, $c)) {
                $cap = Sanitizer::text($c[1], 500);
            }
            $imgs[] = ['src' => $src, 'alt' => Sanitizer::text(self::attr($im[0], 'alt'), 300), 'caption' => $cap];
        }
        return $imgs;
    }

    private static function attr(string $tag, string $name): string
    {
        if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $m)) {
            return html_entity_decode($m[1] !== '' ? $m[1] : ($m[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }
}
