<?php
declare(strict_types=1);

namespace App;

/** Wyświetlanie bloków lekcji w widoku klienta. Tekst jest oczyszczony przy zapisie; reszta escapowana. */
final class BlockRenderer
{
    /** @param array<int,array> $blocks */
    public static function render(array $blocks, array $ctx): string
    {
        $out = '';
        $nVideo = 0;
        foreach ($blocks as $b) {
            $data = $b['data'] ? (json_decode((string)$b['data'], true) ?: []) : [];
            switch ($b['type']) {
                case 'text':
                    $out .= '<div class="prose max65">' . $b['content'] . '</div>';
                    break;
                case 'vimeo':
                    $v = Video::vimeo((string)$b['content']);
                    if ($v) {
                        $out .= self::frame(Video::vimeoEmbed($v), 'Film Vimeo ' . (++$nVideo), 'video');
                    }
                    break;
                case 'youtube':
                    $id = Video::youtube((string)$b['content']);
                    if ($id) {
                        $out .= self::frame(Video::youtubeEmbed($id), 'Film YouTube ' . (++$nVideo), 'video');
                    }
                    break;
                case 'embed':
                    $u = Video::genericEmbed((string)$b['content']);
                    if ($u) {
                        $out .= self::frame($u, 'Odtwarzacz Spotify', 'audio');
                    }
                    break;
                case 'image':
                    $out .= self::images($data['images'] ?? []);
                    break;
                case 'file':
                    $out .= self::file($data);
                    break;
                case 'quiz':
                    $out .= \App\View::partial('student/quiz', ['block' => $b, 'quizId' => (int)($data['quiz_id'] ?? 0)] + $ctx);
                    break;
            }
        }
        return $out;
    }

    private static function frame(string $src, string $title, string $kind): string
    {
        $style = $kind === 'audio' ? ' style="aspect-ratio:auto;height:232px"' : '';
        return '<div class="video ' . $kind . '"' . $style . '><iframe src="' . e($src) . '" title="' . e($title) . '" loading="lazy" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" allow="autoplay; fullscreen; picture-in-picture; encrypted-media; clipboard-write"></iframe></div>';
    }

    private static function images(array $images): string
    {
        $items = '';
        foreach ($images as $im) {
            $src = !empty($im['media_id']) ? url('/obraz/' . (int)$im['media_id']) : (string)($im['src'] ?? '');
            if ($src === '' || (!str_starts_with($src, '/') && !preg_match('#^https?://#i', $src))) {
                continue;
            }
            $items .= '<figure class="fig"><img class="imgprev" src="' . e($src) . '" alt="' . e($im['alt'] ?? '') . '" loading="lazy">'
                . (!empty($im['caption']) ? '<figcaption>' . e($im['caption']) . '</figcaption>' : '') . '</figure>';
        }
        if ($items === '') {
            return '';
        }
        return '<div class="gallery' . (substr_count($items, '<figure') > 1 ? ' multi' : '') . '">' . $items . '</div>';
    }

    private static function file(array $d): string
    {
        $name = (string)($d['name'] ?? 'Plik');
        $ext = strtoupper(substr((string)pathinfo($name, PATHINFO_EXTENSION), 0, 4));
        $meta = '';
        if (!empty($d['media_id'])) {
            $m = Db::one('SELECT ext, size FROM media WHERE id = ?', [(int)$d['media_id']]);
            if (!$m) {
                return '';
            }
            $ext = strtoupper($m['ext']);
            $meta = fmt_size((int)$m['size']);
            $href = url('/plik/' . (int)$d['media_id']);
            $attr = '';
        } elseif (!empty($d['url'])) {
            $href = (string)$d['url'];
            if (!preg_match('#^https?://#i', $href)) {
                return '';
            }
            $attr = ' target="_blank" rel="noopener noreferrer"';
            if ($ext === '') {
                $ext = strtoupper(substr((string)pathinfo((string)parse_url($href, PHP_URL_PATH), PATHINFO_EXTENSION), 0, 4));
            }
        } else {
            return '';
        }
        return '<div class="fcard max65"><span class="fi">' . e($ext ?: 'PLIK') . '</span><div class="t"><b>' . e($name) . '</b>' . ($meta ? '<span class="small muted">' . e($meta) . '</span>' : '') . '</div><a class="btn sm" href="' . e($href) . '"' . $attr . '>Pobierz</a></div>';
    }
}
