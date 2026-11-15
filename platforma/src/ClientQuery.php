<?php
declare(strict_types=1);

namespace App;

/** Filtry listy klientów (wyszukiwanie, kurs, wygasły dostęp, bez kursu) jako parametryzowane SQL. */
final class ClientQuery
{
    /** @return array{0:string,1:array} fragment WHERE (bez słowa WHERE) i parametry */
    public static function where(string $q, string $cf): array
    {
        $w = ['1=1'];
        $p = [];
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $w[] = '(u.email LIKE ? OR u.full_name LIKE ?)';
            array_push($p, $like, $like);
        }
        if ($cf === 'expired') {
            $w[] = 'EXISTS (SELECT 1 FROM enrollments e WHERE e.user_id = u.id AND e.expires_at IS NOT NULL AND e.expires_at < CURDATE())';
        } elseif ($cf === 'none') {
            $w[] = 'NOT EXISTS (SELECT 1 FROM enrollments e WHERE e.user_id = u.id)';
        } elseif (ctype_digit($cf) && (int)$cf > 0) {
            $w[] = 'EXISTS (SELECT 1 FROM enrollments e WHERE e.user_id = u.id AND e.course_id = ?)';
            $p[] = (int)$cf;
        }
        return [implode(' AND ', $w), $p];
    }

    public static function count(string $q, string $cf): int
    {
        [$w, $p] = self::where($q, $cf);
        return (int)Db::val('SELECT COUNT(*) FROM users u WHERE ' . $w, $p);
    }

    public static function ids(string $q, string $cf): array
    {
        [$w, $p] = self::where($q, $cf);
        return array_map('intval', array_column(Db::all('SELECT u.id FROM users u WHERE ' . $w, $p), 'id'));
    }
}
