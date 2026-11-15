<?php
declare(strict_types=1);

namespace App;

/** Czas dostępu: obliczanie dat, status przypisania, drzewo kursu. */
final class Access
{
    /** Data wygaśnięcia dla nowego przypisania wg domyślnego czasu kursu (null = bezterminowo). */
    public static function defaultExpiry(array $course, ?string $from = null): ?string
    {
        $n = (int)($course['access_amount'] ?? 0);
        if ($n <= 0) {
            return null;
        }
        $d = new \DateTimeImmutable($from ?? today());
        $d = $d->modify('+' . $n . ' ' . (($course['access_unit'] ?? 'months') === 'years' ? 'years' : 'months'));
        return $d->format('Y-m-d');
    }

    public static function label(array $course): string
    {
        $n = (int)($course['access_amount'] ?? 0);
        if ($n <= 0) {
            return 'Dostęp bezterminowy';
        }
        $y = ($course['access_unit'] ?? 'months') === 'years';
        return 'Dostęp: ' . $n . ' ' . ($y ? pl($n, 'rok', 'lata', 'lat') : pl($n, 'miesiąc', 'miesiące', 'miesięcy'));
    }

    /**
     * @return array{state:string,days:?int,text:string,cls:string,expired:bool}
     * state: unlimited | active | soon (<= 30 dni) | expired
     */
    public static function status(?string $expiresAt): array
    {
        if ($expiresAt === null || $expiresAt === '') {
            return ['state' => 'unlimited', 'days' => null, 'text' => 'Dostęp bezterminowy', 'cls' => 'muted', 'expired' => false];
        }
        $days = (int)floor((strtotime($expiresAt . ' 12:00:00') - strtotime(today() . ' 12:00:00')) / 86400);
        if ($days < 0) {
            return ['state' => 'expired', 'days' => $days, 'text' => 'Dostęp wygasł ' . fmt_date($expiresAt), 'cls' => 'bad', 'expired' => true];
        }
        if ($days <= 30) {
            return ['state' => 'soon', 'days' => $days, 'text' => 'Dostęp do ' . fmt_date($expiresAt) . ' (zostało ' . $days . ' ' . pl($days, 'dzień', 'dni', 'dni') . ')', 'cls' => 'warnc', 'expired' => false];
        }
        return ['state' => 'active', 'days' => $days, 'text' => 'Dostęp do ' . fmt_date($expiresAt), 'cls' => 'muted', 'expired' => false];
    }

    public static function enrollment(int $userId, int $courseId): ?array
    {
        return Db::one('SELECT * FROM enrollments WHERE user_id = ? AND course_id = ?', [$userId, $courseId]);
    }

    /** Czy użytkownik może wchodzić do lekcji kursu (administrator zawsze, jako podgląd). */
    public static function canEnter(array $user, int $courseId): bool
    {
        if ($user['role'] === 'admin') {
            return true;
        }
        $e = self::enrollment((int)$user['id'], $courseId);
        return $e !== null && !self::status($e['expires_at'])['expired'];
    }

    /** Drzewo kursu: moduły z lekcjami, w kolejności. */
    public static function tree(int $courseId): array
    {
        $mods = Db::all('SELECT id, title, sort_order FROM modules WHERE course_id = ? ORDER BY sort_order, id', [$courseId]);
        $les = Db::all('SELECT l.id, l.module_id, l.title, l.sort_order FROM lessons l JOIN modules m ON m.id = l.module_id WHERE m.course_id = ? ORDER BY l.sort_order, l.id', [$courseId]);
        $by = [];
        foreach ($les as $l) {
            $by[$l['module_id']][] = $l;
        }
        foreach ($mods as &$m) {
            $m['lessons'] = $by[$m['id']] ?? [];
        }
        return $mods;
    }

    /** Płaska lista lekcji w kolejności kursu. */
    public static function flat(array $tree): array
    {
        $out = [];
        foreach ($tree as $m) {
            foreach ($m['lessons'] as $l) {
                $out[] = $l + ['module_title' => $m['title']];
            }
        }
        return $out;
    }
}
