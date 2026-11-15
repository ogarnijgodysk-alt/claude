<?php
declare(strict_types=1);

namespace App;

final class Video
{
    /** @return array{id:string,hash:?string}|null */
    public static function vimeo(string $url): ?array
    {
        $url = trim($url);
        if (!preg_match('#(?:^|//)(?:www\.|player\.)?vimeo\.com/(.*)$#i', $url, $m)) {
            return null;
        }
        $path = $m[1];
        $query = '';
        if (str_contains($path, '?')) {
            [$path, $query] = explode('?', $path, 2);
        }
        $path = preg_replace('/#.*$/', '', $path) ?? '';
        $hash = null;
        if ($query !== '') {
            parse_str(html_entity_decode($query), $qs);
            if (!empty($qs['h']) && is_string($qs['h']) && preg_match('/^[a-f0-9]{6,16}$/i', $qs['h'])) {
                $hash = $qs['h'];
            }
        }
        $parts = array_values(array_filter(explode('/', $path), fn($p) => $p !== ''));
        $id = null;
        foreach ($parts as $i => $p) {
            if (ctype_digit($p) && strlen($p) >= 6) {
                $id = $p;
                $next = $parts[$i + 1] ?? null;
                if ($hash === null && $next !== null && preg_match('/^[a-f0-9]{6,16}$/i', $next)) {
                    $hash = $next;
                }
                break;
            }
        }
        return $id === null ? null : ['id' => $id, 'hash' => $hash];
    }

    public static function youtube(string $url): ?string
    {
        if (preg_match('#(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:[^\#]*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#i', trim($url), $m)) {
            return $m[1];
        }
        return null;
    }

    public static function vimeoEmbed(array $v): string
    {
        $u = 'https://player.vimeo.com/video/' . $v['id'] . '?dnt=1';
        if (!empty($v['hash'])) {
            $u .= '&h=' . $v['hash'];
        }
        return $u;
    }

    public static function youtubeEmbed(string $id): string
    {
        return 'https://www.youtube-nocookie.com/embed/' . $id . '?rel=0';
    }

    /** Dozwolone osadzenia inne niż wideo (np. Spotify). Zwraca adres do iframe albo null. */
    public static function genericEmbed(string $url): ?string
    {
        $url = trim(html_entity_decode($url));
        if (preg_match('#^https://open\.spotify\.com/(?:embed/)?(episode|show|track|playlist|album)/([A-Za-z0-9]{10,30})#i', $url, $m)) {
            return 'https://open.spotify.com/embed/' . strtolower($m[1]) . '/' . $m[2];
        }
        return null;
    }
}
