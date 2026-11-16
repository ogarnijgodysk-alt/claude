<?php
declare(strict_types=1);

namespace App;

/**
 * Wgrywanie plików: typ sprawdzany po ZAWARTOŚCI (finfo + struktura ZIP dla Office),
 * zapis poza katalogiem publicznym pod losową nazwą bez rozszerzenia, pobieranie zawsze przez PHP.
 */
final class Uploads
{
    public const IMAGE_EXT = ['jpg', 'jpeg', 'png'];
    public const FILE_EXT = ['pdf', 'zip', 'docx', 'xlsx', 'pptx'];
    public const QUIZ_EXT = ['pdf', 'zip', 'docx', 'xlsx', 'pptx', 'jpg', 'jpeg', 'png', 'mp4'];
    private const OFFICE = ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'];

    public static function maxBytes(string $kind): int
    {
        $mb = $kind === 'image' ? 20 : max(1, (int)Settings::get('upload_max_mb', '100'));
        $php = self::iniBytes((string)ini_get('upload_max_filesize'));
        $post = self::iniBytes((string)ini_get('post_max_size'));
        $limit = $mb * 1048576;
        foreach ([$php, $post] as $p) {
            if ($p > 0) {
                $limit = min($limit, $p);
            }
        }
        return $limit;
    }

    private static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '') {
            return 0;
        }
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }

    public static function allowed(string $kind): array
    {
        return match ($kind) {
            'image' => self::IMAGE_EXT,
            'quiz' => self::QUIZ_EXT,
            default => self::FILE_EXT,
        };
    }

    /** @return array{ok:bool,error?:string,media?:array} */
    public static function store(array $f, string $kind): array
    {
        $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Plik jest za duży (limit serwera: ' . fmt_size(self::maxBytes($kind)) . ').',
                UPLOAD_ERR_NO_FILE => 'Nie wybrano pliku.',
                default => 'Nie udało się wgrać pliku (kod ' . $err . ').',
            }];
        }
        $tmp = (string)$f['tmp_name'];
        if (!is_uploaded_file($tmp) && !(PHP_SAPI === 'cli' && is_file($tmp))) {
            return ['ok' => false, 'error' => 'Nieprawidłowy plik.'];
        }
        $size = (int)filesize($tmp);
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'Plik jest pusty.'];
        }
        if ($size > self::maxBytes($kind)) {
            return ['ok' => false, 'error' => 'Plik jest za duży. Maksymalny rozmiar to ' . fmt_size(self::maxBytes($kind)) . '.'];
        }
        $name = Sanitizer::text((string)($f['name'] ?? 'plik'), 200) ?: 'plik';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = self::allowed($kind);
        if (!in_array($ext, $allowed, true)) {
            return ['ok' => false, 'error' => 'Ten typ pliku nie jest obsługiwany. Dozwolone: ' . strtoupper(implode(', ', $allowed)) . '.'];
        }
        $mime = self::detect($tmp);
        $canon = self::verify($tmp, $ext, $mime);
        if ($canon === null) {
            return ['ok' => false, 'error' => 'Zawartość pliku nie zgadza się z jego rozszerzeniem (.' . $ext . ').'];
        }
        return ['ok' => true, 'media' => self::save($tmp, $name, $ext, $canon, $size, null)];
    }

    public static function detect(string $path): string
    {
        $fi = new \finfo(FILEINFO_MIME_TYPE);
        return (string)$fi->file($path);
    }

    /** Zwraca kanoniczny MIME, gdy zawartość pasuje do rozszerzenia; inaczej null. */
    public static function verify(string $path, string $ext, string $mime): ?string
    {
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                return $mime === 'image/jpeg' && @getimagesize($path) ? 'image/jpeg' : null;
            case 'png':
                return $mime === 'image/png' && @getimagesize($path) ? 'image/png' : null;
            case 'webp':
                return $mime === 'image/webp' ? 'image/webp' : null;
            case 'gif':
                return $mime === 'image/gif' ? 'image/gif' : null;
            case 'pdf':
                return $mime === 'application/pdf' ? 'application/pdf' : null;
            case 'mp4':
                return in_array($mime, ['video/mp4', 'video/quicktime'], true) ? 'video/mp4' : null;
            case 'zip':
                return $mime === 'application/zip' ? 'application/zip' : null;
            case 'docx':
            case 'xlsx':
            case 'pptx':
                if (!in_array($mime, ['application/zip', 'application/octet-stream', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'], true)) {
                    return null;
                }
                $z = new \ZipArchive();
                if ($z->open($path) !== true) {
                    return null;
                }
                $hasCt = $z->locateName('[Content_Types].xml') !== false;
                $dirOk = false;
                for ($i = 0; $i < $z->numFiles; $i++) {
                    if (str_starts_with((string)$z->getNameIndex($i), self::OFFICE[$ext])) {
                        $dirOk = true;
                        break;
                    }
                }
                $z->close();
                if (!$hasCt || !$dirOk) {
                    return null;
                }
                return match ($ext) {
                    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    default => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                };
        }
        return null;
    }

    public static function save(string $tmp, string $originalName, string $ext, string $mime, int $size, ?string $sourceUrl, bool $move = true): array
    {
        $stored = bin2hex(random_bytes(16));
        $dir = Config::storage('uploads/' . substr($stored, 0, 2));
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nie można utworzyć katalogu plików.');
        }
        $dest = $dir . '/' . $stored;
        $ok = $move && is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dest) : copy($tmp, $dest);
        if (!$ok) {
            throw new \RuntimeException('Nie można zapisać pliku.');
        }
        @chmod($dest, 0640);
        $row = [
            'original_name' => mb_substr($originalName, 0, 300), 'stored_name' => $stored, 'mime' => $mime, 'ext' => $ext,
            'size' => $size, 'sha256' => hash_file('sha256', $dest), 'source_url' => $sourceUrl, 'created_at' => now(),
        ];
        $row['id'] = Db::insert('media', $row);
        return $row;
    }

    /**
     * Gdzie plik jest użyty: id kursów i bloków. Dopasowanie z granicą liczby (5 != 57).
     * @return array{courses:int[],blocks:int[]}
     */
    public static function usage(int $mediaId): array
    {
        $rows = Db::all(
            "SELECT b.id, b.type, b.content, b.data, m.course_id FROM blocks b JOIN lessons l ON l.id = b.lesson_id JOIN modules m ON m.id = l.module_id
             WHERE (b.type IN ('file','image') AND b.data LIKE ?) OR (b.type = 'text' AND (b.content LIKE ? OR b.content LIKE ?))",
            ['%"media_id":' . $mediaId . '%', '%/plik/' . $mediaId . '"%', '%/obraz/' . $mediaId . '"%']
        );
        $courses = [];
        $blocks = [];
        foreach ($rows as $r) {
            $hit = $r['type'] === 'text'
                ? preg_match('#/(plik|obraz)/' . $mediaId . '"#', (string)$r['content']) === 1
                : preg_match('/"media_id":' . $mediaId . '(?!\d)/', (string)$r['data']) === 1;
            if ($hit) {
                $courses[(int)$r['course_id']] = (int)$r['course_id'];
                $blocks[(int)$r['id']] = (int)$r['id'];
            }
        }
        return ['courses' => array_values($courses), 'blocks' => array_values($blocks)];
    }

    public static function path(array $media): string
    {
        return Config::storage('uploads/' . substr($media['stored_name'], 0, 2) . '/' . $media['stored_name']);
    }

    public static function remove(int $mediaId): void
    {
        $m = Db::one('SELECT * FROM media WHERE id = ?', [$mediaId]);
        if ($m) {
            @unlink(self::path($m));
            Db::exec('DELETE FROM media WHERE id = ?', [$mediaId]);
        }
    }
}
