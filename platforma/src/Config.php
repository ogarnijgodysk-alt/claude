<?php
declare(strict_types=1);

namespace App;

final class Config
{
    private static array $c = [];

    public static function load(array $c): void
    {
        self::$c = $c;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $v = self::$c;
        foreach (explode('.', $key) as $p) {
            if (!is_array($v) || !array_key_exists($p, $v)) {
                return $default;
            }
            $v = $v[$p];
        }
        return $v;
    }

    public static function storage(string $sub = ''): string
    {
        $base = rtrim((string)self::get('storage_path', APP_ROOT . '/storage'), '/');
        return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
    }
}
