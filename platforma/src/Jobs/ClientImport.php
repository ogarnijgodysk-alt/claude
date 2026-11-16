<?php
declare(strict_types=1);

namespace App\Jobs;

use App\{Access, Auth, Db, Sanitizer, Spreadsheet};

/**
 * Import klientów z pliku (CSV/XLSX) jako zadanie w tle. Przetwarza porcjami i wznawia od kursora,
 * więc nie przekracza limitu czasu serwera: kolejne porcje wywołuje pasek postępu (AJAX) lub cron.
 */
final class ClientImport
{
    public const FIELDS = ['email' => 'E-mail (wymagane)', 'imie' => 'Imię', 'nazwisko' => 'Nazwisko', 'kurs' => 'Kurs (opcjonalnie)', 'dostep_do' => 'Dostęp do (opcjonalnie)'];

    public static function dir(): string
    {
        $d = \App\Config::storage('imports');
        @mkdir($d, 0750, true);
        return $d;
    }

    public static function csvPath(array $payload): string
    {
        return self::dir() . '/' . basename((string)$payload['file']);
    }

    public static function guessMap(array $header): array
    {
        $norm = fn($s) => preg_replace('/[^a-z0-9]/', '', strtolower(strtr(mb_strtolower($s), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'])));
        $alias = [
            'email' => ['email', 'mail', 'adresemail', 'emailadres', 'adres'],
            'imie' => ['imie', 'firstname', 'name'],
            'nazwisko' => ['nazwisko', 'lastname', 'surname'],
            'kurs' => ['kurs', 'kursy', 'course', 'courses'],
            'dostep_do' => ['dostepdo', 'dostep', 'wygasa', 'expires', 'dostepdodata'],
        ];
        $map = array_fill_keys(array_keys(self::FIELDS), -1);
        foreach ($header as $i => $h) {
            $n = $norm($h);
            foreach ($alias as $f => $as) {
                if ($map[$f] === -1 && in_array($n, $as, true)) {
                    $map[$f] = $i;
                }
            }
        }
        return $map;
    }

    /** Mapa nazw kursów (małe litery) => lista id. */
    private static function titleMap(): array
    {
        $m = [];
        foreach (Db::all('SELECT id, title, short_title FROM courses') as $c) {
            foreach (array_unique([$c['title'], $c['short_title']]) as $t) {
                $k = mb_strtolower(trim($t));
                if ($k !== '') {
                    $m[$k][(int)$c['id']] = (int)$c['id'];
                }
            }
        }
        return $m;
    }

    /** Dopasowanie nazw kursów po przecinku, także gdy sama nazwa zawiera przecinek. @return array{0:int[],1:string[]} */
    public static function parseCourses(string $cell, array $titles): array
    {
        $ids = [];
        $unknown = [];
        $cell = trim($cell);
        if ($cell === '') {
            return [[], []];
        }
        $tok = array_map('trim', explode(',', $cell));
        $n = count($tok);
        $i = 0;
        while ($i < $n) {
            $hit = false;
            for ($j = $n; $j > $i; $j--) {
                $cand = mb_strtolower(trim(implode(', ', array_slice($tok, $i, $j - $i))));
                $cand2 = mb_strtolower(trim(implode(',', array_slice($tok, $i, $j - $i))));
                foreach ([$cand, $cand2] as $cd) {
                    if ($cd !== '' && isset($titles[$cd])) {
                        if (count($titles[$cd]) === 1) {
                            $ids[] = (int)reset($titles[$cd]);
                        } else {
                            $unknown[] = $tok[$i] . ' (niejednoznaczna nazwa: kilka kursów)';
                        }
                        $i = $j;
                        $hit = true;
                        break 2;
                    }
                }
            }
            if (!$hit) {
                if ($tok[$i] !== '') {
                    $unknown[] = $tok[$i];
                }
                $i++;
            }
        }
        return [array_values(array_unique($ids)), $unknown];
    }

    /** Sprawdzenie przed importem: ile nowych, ilu w bazie, błędne adresy. */
    public static function analyze(array $payload, array $map): array
    {
        $rows = Spreadsheet::csv(self::csvPath($payload));
        array_shift($rows);
        $emails = [];
        foreach ($rows as $r) {
            $e = mb_strtolower(trim((string)($r[$map['email']] ?? '')));
            if ($e !== '') {
                $emails[] = $e;
            }
        }
        $existing = [];
        foreach (array_chunk(array_unique($emails), 1000) as $chunk) {
            foreach (Db::all('SELECT email FROM users WHERE email IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $x) {
                $existing[mb_strtolower($x['email'])] = true;
            }
        }
        $new = 0;
        $exist = 0;
        $dups = 0;
        $invalid = [];
        $seen = [];
        $unknownCourses = [];
        $titles = self::titleMap();
        foreach ($rows as $i => $r) {
            $e = mb_strtolower(trim((string)($r[$map['email']] ?? '')));
            if (!valid_email($e)) {
                $invalid[] = [$i + 2, $e, $e === '' ? 'Pusty adres e-mail' : 'Błędny adres e-mail'];
                continue;
            }
            if (isset($existing[$e])) {
                $exist++;
            } elseif (isset($seen[$e])) {
                $dups++;
            } else {
                $new++;
            }
            $seen[$e] = true;
            if ($map['kurs'] >= 0) {
                foreach (self::parseCourses((string)($r[$map['kurs']] ?? ''), $titles)[1] as $u) {
                    $unknownCourses[$u] = ($unknownCourses[$u] ?? 0) + 1;
                }
            }
        }
        return ['rows' => count($rows), 'new' => $new, 'existing' => $exist, 'dups' => $dups, 'invalid' => $invalid, 'unknown_courses' => $unknownCourses];
    }

    /**
     * Przetwarza wiersze od kursora przez $budget sekund. Zwraca stan zadania.
     * @return array{done:int,total:int,added:int,skipped:int,errors:int,finished:bool}
     */
    public static function process(int $jobId, float $budget = 8.0): array
    {
        $lock = 'kursy_' . substr(md5((string)\App\Config::get('db.name', '') . __DIR__), 0, 12) . '_job_' . $jobId;
        if ((int)Db::val('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            return self::state($jobId); // ktoś inny już przetwarza
        }
        try {
            $job = Db::one('SELECT * FROM jobs WHERE id = ?', [$jobId]);
            if (!$job || $job['status'] !== 'running') {
                return self::state($jobId);
            }
            $p = json_decode((string)$job['payload'], true);
            $res = json_decode((string)($job['result'] ?: '{}'), true) + ['added' => 0, 'skipped' => 0, 'errors' => [], 'warnings' => []];
            $rows = Spreadsheet::csv(self::csvPath($p));
            array_shift($rows);
            $map = $p['map'];
            $titles = self::titleMap();
            $globalCourses = array_map('intval', $p['courses'] ?? []);
            $courseRows = [];
            foreach ($globalCourses as $cid) {
                $c = Db::one('SELECT * FROM courses WHERE id = ?', [$cid]);
                if ($c) {
                    $courseRows[$cid] = $c;
                }
            }
            $t0 = microtime(true);
            $i = (int)$job['cursor_pos'];
            $total = count($rows);
            $get = fn(array $r, string $f): string => $map[$f] >= 0 ? trim((string)($r[$map[$f]] ?? '')) : '';
            for (; $i < $total; $i++) {
                if (microtime(true) - $t0 > $budget) {
                    break;
                }
                $r = $rows[$i];
                $email = mb_strtolower($get($r, 'email'));
                $line = $i + 2;
                if (!valid_email($email)) {
                    $res['errors'][] = [$line, $email, $email === '' ? 'Pusty adres e-mail' : 'Błędny adres e-mail'];
                    continue;
                }
                if (Db::val('SELECT 1 FROM users WHERE email = ?', [$email])) {
                    $res['skipped']++;
                    continue;
                }
                $first = Sanitizer::text($get($r, 'imie'), 120);
                $last = Sanitizer::text($get($r, 'nazwisko'), 160);
                $uid = Db::insert('users', ['email' => $email, 'first_name' => $first, 'last_name' => $last, 'full_name' => trim($first . ' ' . $last), 'role' => 'client', 'created_at' => now()]);
                $res['added']++;
                $rowExp = Spreadsheet::parseDate($get($r, 'dostep_do'));
                if ($get($r, 'dostep_do') !== '' && $rowExp === null) {
                    $res['warnings'][] = [$line, $email, 'Nierozpoznana data „' . $get($r, 'dostep_do') . '”, użyto czasu dostępu z ustawień kursu'];
                }
                $assign = [];
                foreach ($globalCourses as $cid) {
                    $assign[$cid] = true;
                }
                if ($map['kurs'] >= 0) {
                    [$ids, $unknown] = self::parseCourses($get($r, 'kurs'), $titles);
                    foreach ($ids as $cid) {
                        $assign[$cid] = true;
                    }
                    foreach ($unknown as $u) {
                        $res['warnings'][] = [$line, $email, 'Nieznany kurs: ' . $u];
                    }
                }
                foreach (array_keys($assign) as $cid) {
                    $c = $courseRows[$cid] ?? ($courseRows[$cid] = Db::one('SELECT * FROM courses WHERE id = ?', [$cid]));
                    if (!$c) {
                        continue;
                    }
                    Db::exec('INSERT IGNORE INTO enrollments (user_id, course_id, expires_at, created_at) VALUES (?, ?, ?, ?)', [$uid, $cid, $rowExp ?? Access::defaultExpiry($c), now()]);
                }
                if (!empty($p['mail'])) {
                    Auth::sendInvite($uid, 'invite', false);
                }
            }
            $finished = $i >= $total;
            $res['errors'] = array_slice($res['errors'], 0, 20000);
            $res['warnings'] = array_slice($res['warnings'], 0, 20000);
            Db::update('jobs', ['cursor_pos' => $i, 'total' => $total, 'result' => json_encode($res, JSON_UNESCAPED_UNICODE), 'status' => $finished ? 'done' : 'running', 'updated_at' => now()], 'id = ?', [$jobId]);
            return self::state($jobId);
        } finally {
            Db::q('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    public static function state(int $jobId): array
    {
        $job = Db::one('SELECT * FROM jobs WHERE id = ?', [$jobId]);
        $res = json_decode((string)($job['result'] ?? '{}'), true) ?: [];
        return [
            'done' => (int)($job['cursor_pos'] ?? 0), 'total' => (int)($job['total'] ?? 0), 'added' => (int)($res['added'] ?? 0),
            'skipped' => (int)($res['skipped'] ?? 0), 'errors' => count($res['errors'] ?? []), 'warnings' => count($res['warnings'] ?? []),
            'finished' => ($job['status'] ?? '') === 'done',
        ];
    }
}
