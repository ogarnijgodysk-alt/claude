<?php
declare(strict_types=1);

namespace App\Migration;

use App\Db;
use App\Gutenberg;
use App\Sanitizer;

/**
 * Import paczki migracyjnej Publigo (kursy.json, quizy.json, kursanci.csv, dostepy.csv).
 * Idempotentny: ponowne uruchomienie dopisuje brakujące konta/przypisania i pomija kursy,
 * które już są w bazie (chyba że $opts['replace_content']).
 * Wszystkie identyfikatory porównywane jako tekst.
 */
final class PubligoImporter
{
    /** @var array<string,mixed> */
    public array $stats = [];
    /** @var array<int,array<string,mixed>> */
    public array $issues = [];
    /** @var callable */
    private $log;
    private array $opts;

    public function __construct(private string $dir, array $opts = [], ?callable $log = null)
    {
        $this->opts = $opts + [
            'admin_email' => 'latuszekmarek@gmail.com',
            'replace_content' => false,
            'skip_users' => false,
        ];
        $this->log = $log ?? static function (string $m): void {
        };
    }

    private function log(string $m): void
    {
        ($this->log)($m);
    }

    private function issue(string $type, array $info): void
    {
        $this->issues[] = ['type' => $type] + $info;
    }

    public static function requiredFiles(): array
    {
        return ['kursy.json', 'quizy.json', 'kursanci.csv', 'dostepy.csv'];
    }

    public function run(): array
    {
        foreach (self::requiredFiles() as $f) {
            if (!is_file($this->dir . '/' . $f)) {
                throw new \RuntimeException('Brak pliku w paczce: ' . $f);
            }
        }
        $t0 = microtime(true);
        $courseMap = $this->importCourses();
        $this->importQuizzes();
        $userMap = $this->opts['skip_users'] ? [] : $this->importUsers();
        if (!$this->opts['skip_users']) {
            $this->importEnrollments($courseMap, $userMap);
        }
        $this->stats['seconds'] = round(microtime(true) - $t0, 1);
        return ['stats' => $this->stats, 'issues' => $this->issues];
    }

    /** @return array<string,int> publigo_course_id => course.id */
    private function importCourses(): array
    {
        $data = json_decode((string)file_get_contents($this->dir . '/kursy.json'), true);
        if (!is_array($data) || !isset($data['courses'])) {
            throw new \RuntimeException('kursy.json: nieprawidłowy format');
        }
        $map = [];
        $st = ['courses_new' => 0, 'courses_existing' => 0, 'modules' => 0, 'lessons' => 0, 'blocks' => 0, 'loose_modules' => 0];
        $order = 0;
        foreach ($data['courses'] as $c) {
            $order++;
            $pid = (string)$c['course_post_id'];
            $existing = Db::one('SELECT id FROM courses WHERE publigo_course_id = ?', [$pid]);
            if ($existing && !$this->opts['replace_content']) {
                $map[$pid] = (int)$existing['id'];
                $st['courses_existing']++;
                continue;
            }
            Db::tx(function () use ($c, $pid, $existing, $order, &$map, &$st): void {
                if ($existing) {
                    Db::exec('DELETE FROM modules WHERE course_id = ?', [$existing['id']]);
                }
                $am = $c['default_access']['amount'] ?? null;
                $unit = $c['default_access']['unit'] ?? null;
                $amount = ($am !== null && $am !== '' && (int)$am > 0) ? (int)$am : null;
                $unitDb = $unit === 'years' ? 'years' : 'months';
                if ($amount !== null && !in_array($unit, ['months', 'years'], true)) {
                    $this->issue('czas_dostepu_nieznana_jednostka', ['kurs' => $c['title'], 'jednostka' => (string)$unit]);
                    $amount = null;
                }
                if ($amount === null && $unit === 'minutes') {
                    $this->issue('czas_dostepu_minuty_bez_wartosci', ['kurs' => $c['title']]);
                }
                $row = [
                    'title' => Sanitizer::text((string)$c['title']),
                    'short_title' => mb_substr(Sanitizer::text((string)$c['title']), 0, 60),
                    'access_amount' => $amount,
                    'access_unit' => $unitDb,
                    'sort_order' => $order,
                    'publigo_course_id' => $pid,
                    'publigo_product_id' => isset($c['product_id']) ? (string)$c['product_id'] : null,
                ];
                if ($existing) {
                    $cid = (int)$existing['id'];
                    Db::update('courses', $row, 'id = ?', [$cid]);
                } else {
                    $cid = Db::insert('courses', $row + ['created_at' => $this->createdAt($c['created'] ?? null)]);
                }
                $map[$pid] = $cid;
                $st['courses_new']++;
                $this->importModules($cid, $c, $st);
            });
        }
        $this->stats += $st;
        $this->log(sprintf('Kursy: %d nowych, %d już w bazie; moduły %d, lekcje %d, bloki %d', $st['courses_new'], $st['courses_existing'], $st['modules'], $st['lessons'], $st['blocks']));
        return $map;
    }

    private function createdAt(?string $d): string
    {
        $t = $d ? strtotime($d) : false;
        return $t ? date('Y-m-d 00:00:00', $t) : now();
    }

    private function importModules(int $cid, array $c, array &$st): void
    {
        $sort = 0;
        $looseId = null;
        $lessonRows = [];
        foreach ($c['modules'] as $m) {
            $mid = $m['module_id'] ?? null;
            if ($mid === null || $mid === '') {
                // lekcje luzem -> jeden moduł „Lekcje” w kursie (na pozycji pierwszej takiej lekcji)
                if ($looseId === null) {
                    $looseId = Db::insert('modules', ['course_id' => $cid, 'title' => 'Lekcje', 'sort_order' => ++$sort, 'publigo_id' => null]);
                    $st['modules']++;
                    $st['loose_modules']++;
                }
                $moduleDbId = $looseId;
            } else {
                $moduleDbId = Db::insert('modules', ['course_id' => $cid, 'title' => Sanitizer::text((string)($m['title'] ?? '')) ?: 'Moduł', 'sort_order' => ++$sort, 'publigo_id' => (string)$mid]);
                $st['modules']++;
            }
            foreach ($m['lessons'] as $l) {
                $lessonRows[] = [$moduleDbId, $l];
            }
        }
        $perModule = [];
        foreach ($lessonRows as [$moduleDbId, $l]) {
            $perModule[$moduleDbId] = ($perModule[$moduleDbId] ?? 0) + 1;
            $lid = Db::insert('lessons', [
                'module_id' => $moduleDbId,
                'title' => Sanitizer::text((string)($l['title'] ?? '')) ?: 'Bez tytułu',
                'sort_order' => $perModule[$moduleDbId],
                'publigo_id' => (string)$l['lesson_id'],
            ]);
            $st['lessons']++;
            $conv = Gutenberg::convert((string)($l['html'] ?? ''));
            $blockRows = [];
            foreach ($conv['blocks'] as $i => $b) {
                $blockRows[] = [$lid, $b['type'], $i + 1, $b['content'], $b['data'] ? json_encode($b['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null];
            }
            if ($blockRows) {
                Db::insertMany('blocks', ['lesson_id', 'type', 'sort_order', 'content', 'data'], $blockRows, 100);
            }
            $st['blocks'] += count($blockRows);
            $ctx = ['kurs' => (string)$c['title'], 'kurs_id' => (string)$c['course_post_id'], 'lekcja' => (string)($l['title'] ?? ''), 'lekcja_id' => (string)$l['lesson_id'], 'lekcja_db_id' => $lid];
            if (!$blockRows) {
                $this->issue('pusta_lekcja', $ctx);
            }
            foreach ($conv['warnings'] as $w) {
                $this->issue($w['type'], $ctx + ['szczegoly' => $w['detail']]);
            }
            // stare adresy ?p=ID -> 301
            Db::exec('INSERT IGNORE INTO redirects (old_path, target_type, target_id) VALUES (?, ?, ?)', ['/?p=' . $l['lesson_id'], 'lesson', $lid]);
        }
        Db::exec('INSERT IGNORE INTO redirects (old_path, target_type, target_id) VALUES (?, ?, ?)', ['/?p=' . $c['course_post_id'], 'course', $cid]);
    }

    private function importQuizzes(): void
    {
        $quizzes = json_decode((string)file_get_contents($this->dir . '/quizy.json'), true);
        if (!is_array($quizzes)) {
            throw new \RuntimeException('quizy.json: nieprawidłowy format');
        }
        $typeMap = ['single_radio' => 'single', 'multiple' => 'multi', 'text' => 'text', 'file' => 'file'];
        $st = ['quizzes_new' => 0, 'quizzes_existing' => 0, 'questions' => 0, 'answers' => 0];
        foreach ($quizzes as $q) {
            $qid = (string)$q['quiz_id'];
            if (Db::one('SELECT id FROM quizzes WHERE publigo_quiz_id = ?', [$qid])) {
                $st['quizzes_existing']++;
                continue;
            }
            Db::tx(function () use ($q, $qid, $typeMap, &$st): void {
                $id = Db::insert('quizzes', ['title' => Sanitizer::text((string)$q['title']) ?: 'Quiz', 'pass_percent' => 70, 'max_attempts' => 0, 'publigo_quiz_id' => $qid, 'created_at' => now()]);
                $st['quizzes_new']++;
                $n = 0;
                foreach ($q['questions'] as $qq) {
                    $srcType = (string)($qq['type'] ?? '');
                    $type = $typeMap[$srcType] ?? 'text';
                    if (!isset($typeMap[$srcType])) {
                        $this->issue('quiz_pytanie_typ_na_opisowe', ['quiz' => $q['title'], 'quiz_id' => $qid, 'pytanie' => mb_substr((string)$qq['title'], 0, 80), 'typ_zrodlowy' => $srcType]);
                    }
                    $qdb = Db::insert('quiz_questions', ['quiz_id' => $id, 'type' => $type, 'text' => Sanitizer::text((string)$qq['title'], 2000), 'sort_order' => ++$n]);
                    $st['questions']++;
                    if ($type === 'single' || $type === 'multi') {
                        $rows = [];
                        $k = 0;
                        $correct = 0;
                        foreach ($qq['answers'] ?? [] as $a) {
                            $p = trim((string)($a['points'] ?? ''));
                            $ok = ($p !== '' && $p !== '0') ? 1 : 0;
                            $correct += $ok;
                            $rows[] = [$qdb, Sanitizer::text((string)$a['title'], 1000), $ok, ++$k];
                        }
                        if ($rows) {
                            Db::insertMany('quiz_answers', ['question_id', 'text', 'is_correct', 'sort_order'], $rows);
                        }
                        $st['answers'] += count($rows);
                        if ($correct === 0 || ($type === 'single' && $correct > 1)) {
                            $this->issue('quiz_pytanie_bez_poprawnej_lub_wiele', ['quiz' => $q['title'], 'quiz_id' => $qid, 'pytanie' => mb_substr((string)$qq['title'], 0, 80), 'poprawnych' => $correct]);
                        }
                    }
                }
            });
        }
        $this->stats += $st;
        $this->log(sprintf('Quizy: %d nowych, %d już w bazie; pytań %d, odpowiedzi %d', $st['quizzes_new'], $st['quizzes_existing'], $st['questions'], $st['answers']));
    }

    /** @return array<string,int> email(lower) => user.id */
    private function importUsers(): array
    {
        $fh = fopen($this->dir . '/kursanci.csv', 'rb');
        $head = self::csvRow($fh);
        $head[0] = ltrim((string)$head[0], "\xEF\xBB\xBF");
        $rows = [];
        $seen = [];
        $st = ['users_source_rows' => 0, 'users_invalid_email' => 0, 'users_duplicate_email' => 0, 'users_non_subscriber' => 0];
        while (($r = self::csvRow($fh)) !== null) {
            if (count($r) < count($head)) {
                $r = array_pad($r, count($head), '');
            }
            $r = array_combine($head, array_slice($r, 0, count($head)));
            $st['users_source_rows']++;
            $email = mb_strtolower(trim((string)$r['email']));
            if (!valid_email($email)) {
                $st['users_invalid_email']++;
                $this->issue('konto_bledny_email', ['email' => $email, 'id_publigo' => $r['user_id_publigo']]);
                continue;
            }
            if (isset($seen[$email])) {
                $st['users_duplicate_email']++;
                $this->issue('konto_duplikat_email', ['email' => $email, 'id_publigo' => $r['user_id_publigo']]);
                continue;
            }
            $seen[$email] = true;
            $role = trim((string)$r['rola']);
            $isAdmin = $email === mb_strtolower((string)$this->opts['admin_email']);
            if ($role !== 'Subskrybent') {
                $st['users_non_subscriber']++;
                $this->issue('konto_inna_rola', ['email' => $email, 'rola_publigo' => $role, 'nazwa' => (string)$r['imie_nazwisko'], 'nadano' => $isAdmin ? 'administrator (decyzja właściciela)' : 'klient']);
            }
            [$fn, $ln, $full] = self::splitName((string)$r['imie_nazwisko'], $email);
            $rows[] = [$email, $fn, $ln, $full, $isAdmin ? 'admin' : 'client', (string)$r['user_id_publigo'], $role, now()];
        }
        fclose($fh);
        // administrator z opcji, nawet jeśli nie ma go w CSV
        Db::insertMany(
            'users',
            ['email', 'first_name', 'last_name', 'full_name', 'role', 'publigo_id', 'publigo_role', 'created_at'],
            $rows,
            500,
            'INSERT',
            "ON DUPLICATE KEY UPDATE publigo_id = VALUES(publigo_id), publigo_role = VALUES(publigo_role), role = IF(users.role = 'admin', 'admin', VALUES(role)), first_name = IF(users.first_name = '', VALUES(first_name), users.first_name), last_name = IF(users.last_name = '', VALUES(last_name), users.last_name), full_name = IF(users.full_name = '', VALUES(full_name), users.full_name)"
        );
        $this->stats += $st;
        $map = [];
        foreach (Db::all('SELECT id, email FROM users') as $u) {
            $map[mb_strtolower($u['email'])] = (int)$u['id'];
        }
        $this->log(sprintf('Konta: %d wierszy w źródle, %d odrzuconych (błędny e-mail), %d duplikatów; w bazie razem %d', $st['users_source_rows'], $st['users_invalid_email'], $st['users_duplicate_email'], count($map)));
        return $map;
    }

    private function importEnrollments(array $courseMap, array $userMap): void
    {
        $fh = fopen($this->dir . '/dostepy.csv', 'rb');
        $head = self::csvRow($fh);
        $head[0] = ltrim((string)$head[0], "\xEF\xBB\xBF");
        $rows = [];
        $seen = [];
        $st = ['enroll_source_rows' => 0, 'enroll_no_user' => 0, 'enroll_no_course' => 0, 'enroll_duplicate' => 0, 'enroll_unlimited' => 0, 'enroll_limited' => 0, 'enroll_expired' => 0];
        $today = today();
        while (($r = self::csvRow($fh)) !== null) {
            $r = array_combine($head, array_slice(array_pad($r, count($head), ''), 0, count($head)));
            $st['enroll_source_rows']++;
            $email = mb_strtolower(trim((string)$r['email']));
            $uid = $userMap[$email] ?? null;
            $cid = $courseMap[(string)$r['course_post_id']] ?? null;
            if ($uid === null) {
                $st['enroll_no_user']++;
                $this->issue('przypisanie_brak_konta', ['email' => $email, 'kurs' => $r['kurs']]);
                continue;
            }
            if ($cid === null) {
                $st['enroll_no_course']++;
                $this->issue('przypisanie_brak_kursu', ['email' => $email, 'course_post_id' => $r['course_post_id'], 'kurs' => $r['kurs']]);
                continue;
            }
            $key = $uid . ':' . $cid;
            if (isset($seen[$key])) {
                $st['enroll_duplicate']++;
                continue;
            }
            $seen[$key] = true;
            $exp = null;
            $raw = trim((string)($r['dostep_do_lokalnie'] !== '' ? $r['dostep_do_lokalnie'] : $r['dostep_do_utc']));
            if ($raw !== '') {
                $ts = strtotime($raw);
                if ($ts) {
                    $exp = date('Y-m-d', $ts);
                } else {
                    $this->issue('przypisanie_zla_data', ['email' => $email, 'data' => $raw]);
                }
            }
            if ($exp === null) {
                $st['enroll_unlimited']++;
            } else {
                $st['enroll_limited']++;
                if ($exp < $today) {
                    $st['enroll_expired']++;
                }
            }
            $rows[] = [$uid, $cid, $exp, self::intOrNull($r['postep_proc'], 100), self::intOrNull($r['lekcji_ukonczonych'], 65000), self::intOrNull($r['lekcji_razem'], 65000), now()];
        }
        fclose($fh);
        Db::insertMany(
            'enrollments',
            ['user_id', 'course_id', 'expires_at', 'legacy_progress', 'legacy_done', 'legacy_total', 'created_at'],
            $rows,
            500,
            'INSERT',
            'ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at), legacy_progress = VALUES(legacy_progress), legacy_done = VALUES(legacy_done), legacy_total = VALUES(legacy_total)'
        );
        $this->stats += $st;
        $this->log(sprintf('Przypisania: %d wierszy w źródle, %d zapisanych (bezterminowych %d, z limitem %d, w tym wygasłych %d)', $st['enroll_source_rows'], count($rows), $st['enroll_unlimited'], $st['enroll_limited'], $st['enroll_expired']));
    }

    private static function intOrNull(mixed $v, int $max): ?int
    {
        $v = trim((string)$v);
        return $v === '' || !is_numeric($v) ? null : max(0, min($max, (int)round((float)$v)));
    }

    /** @return array{0:string,1:string,2:string} */
    public static function splitName(string $name, string $email): array
    {
        $name = Sanitizer::text($name, 300);
        if ($name === '' || mb_strtolower($name) === mb_strtolower($email) || str_contains($name, '@')) {
            return ['', '', ''];
        }
        $p = preg_split('/\s+/u', $name, 2);
        return [mb_substr($p[0], 0, 120), mb_substr($p[1] ?? '', 0, 160), $name];
    }

    /** fgetcsv z jawnymi parametrami (kompatybilnie z PHP 8.4). */
    public static function csvRow($fh): ?array
    {
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($r !== [null]) {
                return $r;
            }
        }
        return null;
    }
}
