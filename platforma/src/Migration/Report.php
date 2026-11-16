<?php
declare(strict_types=1);

namespace App\Migration;

use App\Db;
use App\Gutenberg;

/**
 * Raport zgodności migracji: liczby w źródle (liczone niezależnie z surowych plików)
 * kontra liczby w nowej bazie, różnice, pominięte elementy, inwentaryzacja plików
 * i losowa próba 20 lekcji do ręcznego porównania.
 */
final class Report
{
    private const FILE_EXT_RE = 'pdf|docx?|xlsx?|pptx?|zip|rar|7z|jpe?g|png|gif|webp|svg|mp3|mp4|mov|m4a|wav|txt|csv|odt|ods';
    private const ALLOWED_EXT = ['pdf', 'zip', 'docx', 'xlsx', 'pptx', 'jpg', 'jpeg', 'png'];

    /** @return array<int,string> ścieżki zapisanych plików */
    public static function generate(string $dir, array $importIssues, string $outDir): array
    {
        if (!is_dir($outDir)) {
            mkdir($outDir, 0750, true);
        }
        $r = self::build($dir, $importIssues);
        $stamp = date('Ymd-His');
        $files = [];
        $files[] = $f = $outDir . "/raport-zgodnosci-$stamp.html";
        file_put_contents($f, self::html($r));
        $files[] = $f = $outDir . "/raport-zgodnosci-$stamp.json";
        file_put_contents($f, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $files[] = $f = $outDir . "/pliki-do-pobrania-$stamp.csv";
        $fh = fopen($f, 'wb');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['url', 'host', 'rozszerzenie', 'rodzaj', 'dozwolone', 'kurs', 'lekcja_id_db'], ',', '"', '');
        foreach ($r['inventory']['rows'] as $row) {
            fputcsv($fh, $row, ',', '"', '');
        }
        fclose($fh);
        return $files;
    }

    public static function latest(string $outDir): ?string
    {
        $g = glob($outDir . '/raport-zgodnosci-*.html') ?: [];
        sort($g);
        return $g ? end($g) : null;
    }

    public static function build(string $dir, array $importIssues = []): array
    {
        $src = json_decode((string)file_get_contents($dir . '/kursy.json'), true)['courses'];
        $quizzes = json_decode((string)file_get_contents($dir . '/quizy.json'), true);
        $srcIds = array_map(fn($c) => (string)$c['course_post_id'], $src);

        // --- liczby w źródle (niezależnie od konwertera) ---
        $S = ['courses' => count($src), 'modules' => 0, 'lessons' => 0, 'vimeo' => 0, 'youtube' => 0, 'spotify' => 0, 'images' => 0, 'files' => 0];
        $perCourse = [];
        $contentIssues = [];
        $lessonIndex = []; // publigo lesson id => [course, module, title, src counts]
        foreach ($src as $c) {
            $cid = (string)$c['course_post_id'];
            $pc = ['title' => $c['title'], 'modules' => 0, 'lessons' => 0, 'vimeo' => 0, 'youtube' => 0];
            $loose = false;
            foreach ($c['modules'] as $m) {
                if ($m['module_id'] === null || $m['module_id'] === '') {
                    $loose = true;
                } else {
                    $pc['modules']++;
                }
                foreach ($m['lessons'] as $l) {
                    $h = (string)$l['html'];
                    $pc['lessons']++;
                    $cnt = self::sourceCounts($h);
                    foreach (['vimeo', 'youtube'] as $k) {
                        $pc[$k] += $cnt[$k];
                    }
                    foreach (['vimeo', 'youtube', 'spotify', 'images', 'files'] as $k) {
                        $S[$k] += $cnt[$k];
                    }
                    $lessonIndex[(string)$l['lesson_id']] = ['course' => $c['title'], 'module' => $m['title'] ?? 'Lekcje', 'title' => $l['title'], 'src' => $cnt, 'textlen' => $cnt['textlen']];
                    $conv = Gutenberg::convert($h);
                    if (!$conv['blocks']) {
                        $contentIssues[] = ['type' => 'pusta_lekcja', 'kurs' => $c['title'], 'modul' => $m['title'] ?? 'Lekcje', 'lekcja' => $l['title'], 'lekcja_id' => (string)$l['lesson_id']];
                    }
                    foreach ($conv['warnings'] as $w) {
                        $contentIssues[] = ['type' => $w['type'], 'kurs' => $c['title'], 'modul' => $m['title'] ?? 'Lekcje', 'lekcja' => $l['title'], 'lekcja_id' => (string)$l['lesson_id'], 'szczegoly' => $w['detail']];
                    }
                }
            }
            if ($loose) {
                $pc['modules']++;
            }
            $S['modules'] += $pc['modules'];
            $S['lessons'] += $pc['lessons'];
            $perCourse[$cid] = $pc;
        }

        // --- liczby w bazie ---
        $in = implode(',', array_fill(0, count($srcIds), '?'));
        $T = [];
        $T['courses'] = (int)Db::val("SELECT COUNT(*) FROM courses WHERE publigo_course_id IN ($in)", $srcIds);
        $T['modules'] = (int)Db::val("SELECT COUNT(*) FROM modules m JOIN courses c ON c.id=m.course_id WHERE c.publigo_course_id IN ($in)", $srcIds);
        $T['lessons'] = (int)Db::val("SELECT COUNT(*) FROM lessons l JOIN modules m ON m.id=l.module_id JOIN courses c ON c.id=m.course_id WHERE c.publigo_course_id IN ($in)", $srcIds);
        $blockCounts = Db::all("SELECT b.type, COUNT(*) n FROM blocks b JOIN lessons l ON l.id=b.lesson_id JOIN modules m ON m.id=l.module_id JOIN courses c ON c.id=m.course_id WHERE c.publigo_course_id IN ($in) GROUP BY b.type", $srcIds);
        $bt = array_column($blockCounts, 'n', 'type');
        $T['vimeo'] = (int)($bt['vimeo'] ?? 0);
        $T['youtube'] = (int)($bt['youtube'] ?? 0);
        $T['spotify'] = (int)($bt['embed'] ?? 0);
        $T['files'] = (int)($bt['file'] ?? 0);
        $T['images'] = 0;
        foreach (Db::all("SELECT b.data FROM blocks b JOIN lessons l ON l.id=b.lesson_id JOIN modules m ON m.id=l.module_id JOIN courses c ON c.id=m.course_id WHERE b.type='image' AND c.publigo_course_id IN ($in)", $srcIds) as $r) {
            $d = json_decode((string)$r['data'], true);
            $T['images'] += count($d['images'] ?? []);
        }
        $T['text_blocks'] = (int)($bt['text'] ?? 0);
        $T['blocks_total'] = array_sum(array_map('intval', $bt));

        // --- quizy ---
        $qs = ['quizzes' => count($quizzes), 'questions' => 0, 'answers' => 0];
        foreach ($quizzes as $q) {
            $qs['questions'] += count($q['questions']);
            foreach ($q['questions'] as $qq) {
                if (in_array($qq['type'] ?? '', ['single_radio', 'multiple'], true)) {
                    $qs['answers'] += count($qq['answers'] ?? []);
                }
            }
        }
        $qin = implode(',', array_fill(0, max(1, count($quizzes)), '?'));
        $qids = array_map(fn($q) => (string)$q['quiz_id'], $quizzes) ?: [''];
        $qt = [
            'quizzes' => (int)Db::val("SELECT COUNT(*) FROM quizzes WHERE publigo_quiz_id IN ($qin)", $qids),
            'questions' => (int)Db::val("SELECT COUNT(*) FROM quiz_questions qq JOIN quizzes z ON z.id=qq.quiz_id WHERE z.publigo_quiz_id IN ($qin)", $qids),
            'answers' => (int)Db::val("SELECT COUNT(*) FROM quiz_answers a JOIN quiz_questions qq ON qq.id=a.question_id JOIN quizzes z ON z.id=qq.quiz_id WHERE z.publigo_quiz_id IN ($qin)", $qids),
        ];

        // --- konta ---
        $users = self::csv($dir . '/kursanci.csv');
        $srcEmails = [];
        $nonSub = [];
        $badEmail = 0;
        foreach ($users as $u) {
            $e = mb_strtolower(trim($u['email']));
            if (!valid_email($e)) {
                $badEmail++;
                continue;
            }
            $srcEmails[$e] = true;
            if (trim($u['rola']) !== 'Subskrybent') {
                $nonSub[] = ['email' => $e, 'rola_publigo' => $u['rola'], 'nazwa' => $u['imie_nazwisko']];
            }
        }
        $dbEmails = [];
        foreach (Db::all('SELECT email, role FROM users') as $r) {
            $dbEmails[mb_strtolower($r['email'])] = $r['role'];
        }
        $missingUsers = array_values(array_diff_key($srcEmails, $dbEmails));
        $nonSubOut = array_map(fn($x) => $x + ['rola_w_bazie' => $dbEmails[$x['email']] ?? 'brak'], $nonSub);

        // --- przypisania (porównanie zbiorów email+kurs, plus daty) ---
        $acc = self::csv($dir . '/dostepy.csv');
        $srcPairs = [];
        $srcPerCourse = [];
        $expUnlimited = 0;
        $expLimited = 0;
        $expExpired = 0;
        foreach ($acc as $a) {
            $e = mb_strtolower(trim($a['email']));
            $raw = trim($a['dostep_do_lokalnie'] !== '' ? $a['dostep_do_lokalnie'] : $a['dostep_do_utc']);
            $d = $raw !== '' && ($t = strtotime($raw)) ? date('Y-m-d', $t) : null;
            $srcPairs[$e . '|' . $a['course_post_id']] = $d;
            $srcPerCourse[(string)$a['course_post_id']] = ($srcPerCourse[(string)$a['course_post_id']] ?? 0) + 1;
            if ($d === null) {
                $expUnlimited++;
            } else {
                $expLimited++;
                if ($d < today()) {
                    $expExpired++;
                }
            }
        }
        $dbPairs = [];
        $dbPerCourse = [];
        foreach (Db::all("SELECT LOWER(u.email) e, c.publigo_course_id pc, en.expires_at FROM enrollments en JOIN users u ON u.id=en.user_id JOIN courses c ON c.id=en.course_id WHERE c.publigo_course_id IS NOT NULL") as $r) {
            $dbPairs[$r['e'] . '|' . $r['pc']] = $r['expires_at'];
            $dbPerCourse[$r['pc']] = ($dbPerCourse[$r['pc']] ?? 0) + 1;
        }
        $missingPairs = array_diff_key($srcPairs, $dbPairs);
        $extraPairs = array_diff_key($dbPairs, $srcPairs);
        $dateMismatch = 0;
        $dateMismatchEx = [];
        foreach ($srcPairs as $k => $d) {
            if (array_key_exists($k, $dbPairs) && $dbPairs[$k] !== $d) {
                $dateMismatch++;
                if (count($dateMismatchEx) < 20) {
                    $dateMismatchEx[] = [$k, $d, $dbPairs[$k]];
                }
            }
        }
        $dbLimited = Db::val('SELECT COUNT(*) FROM enrollments en JOIN courses c ON c.id=en.course_id WHERE c.publigo_course_id IS NOT NULL AND en.expires_at IS NOT NULL');
        $dbExpired = Db::val('SELECT COUNT(*) FROM enrollments en JOIN courses c ON c.id=en.course_id WHERE c.publigo_course_id IS NOT NULL AND en.expires_at < ?', [today()]);

        // --- tabela główna ---
        $rows = [];
        $add = function (string $label, $s, $t, string $note = '') use (&$rows): void {
            $rows[] = ['label' => $label, 'source' => $s, 'target' => $t, 'diff' => (is_numeric($s) && is_numeric($t)) ? (int)$t - (int)$s : null, 'note' => $note];
        };
        $looseCourses = 0;
        foreach ($src as $c) {
            foreach ($c['modules'] as $m) {
                if ($m['module_id'] === null || $m['module_id'] === '') {
                    $looseCourses++;
                    break;
                }
            }
        }
        $add('Kursy', $S['courses'], $T['courses'], 'Każdy z 85 kursów przeniesiony osobno (po course_post_id).');
        $add('Moduły', $S['modules'], $T['modules'], "W źródle 508 modułów + $looseCourses kursów z lekcjami luzem, które trafiły do modułu „Lekcje”.");
        $add('Lekcje', $S['lessons'], $T['lessons'], 'Lekcje luzem (module_id = null) są w module „Lekcje”.');
        $add('Wideo Vimeo (bloki)', $S['vimeo'], $T['vimeo'], 'Źródło: osadzenia Vimeo (wp:embed) i ramki iframe w wp:html.');
        $add('Wideo YouTube (bloki)', $S['youtube'], $T['youtube']);
        $add('Osadzenia Spotify', $S['spotify'], $T['spotify'], 'Zostawione jako osadzenie (blok „embed”).');
        $add('Obrazy (w tym z galerii)', $S['images'], $T['images'], 'Źródło: tagi <img> w blokach wp:image i galeriach; cel: obrazy w blokach obrazu.');
        $add('Pliki do pobrania (bloki)', $S['files'], $T['files'], 'Różnica = puste bloki „plik” bez adresu (patrz lista pominiętych).');
        $add('Quizy', $qs['quizzes'], $qt['quizzes'], 'Quizy czekają na podpięcie w lekcjach (źródło nie wiąże ich z lekcjami).');
        $add('Pytania quizów', $qs['questions'], $qt['questions']);
        $add('Odpowiedzi quizów (pytania zamknięte)', $qs['answers'], $qt['answers']);
        $add('Konta (poprawne, unikalne adresy)', count($srcEmails), count(array_intersect_key($srcEmails, $dbEmails)), "Wierszy w kursanci.csv: " . count($users) . ", błędnych adresów: $badEmail. W bazie jest razem " . count($dbEmails) . ' kont.');
        $add('Przypisania kursów', count($srcPairs), count(array_intersect_key($srcPairs, $dbPairs)), 'Porównanie par (e-mail, kurs).');
        $add('Przypisania bezterminowe', $expUnlimited, count($srcPairs) - (int)$dbLimited >= 0 ? (int)Db::val('SELECT COUNT(*) FROM enrollments en JOIN courses c ON c.id=en.course_id WHERE c.publigo_course_id IS NOT NULL AND en.expires_at IS NULL') : 0);
        $add('Przypisania z limitem czasu', $expLimited, (int)$dbLimited);
        $add('…w tym już wygasłe (na dziś)', $expExpired, (int)$dbExpired);

        // --- per kurs ---
        $dbCourse = [];
        foreach (Db::all("SELECT c.publigo_course_id pc, c.id,
              (SELECT COUNT(*) FROM modules m WHERE m.course_id=c.id) modules,
              (SELECT COUNT(*) FROM lessons l JOIN modules m ON m.id=l.module_id WHERE m.course_id=c.id) lessons,
              (SELECT COUNT(*) FROM blocks b JOIN lessons l ON l.id=b.lesson_id JOIN modules m ON m.id=l.module_id WHERE m.course_id=c.id AND b.type='vimeo') vimeo,
              (SELECT COUNT(*) FROM blocks b JOIN lessons l ON l.id=b.lesson_id JOIN modules m ON m.id=l.module_id WHERE m.course_id=c.id AND b.type='youtube') youtube
            FROM courses c WHERE c.publigo_course_id IS NOT NULL") as $r) {
            $dbCourse[$r['pc']] = $r;
        }
        $courseRows = [];
        foreach ($perCourse as $pcid => $pc) {
            $d = $dbCourse[$pcid] ?? null;
            $courseRows[] = [
                'publigo_id' => $pcid, 'title' => $pc['title'], 'db_id' => $d['id'] ?? null,
                'modules' => [$pc['modules'], (int)($d['modules'] ?? 0)],
                'lessons' => [$pc['lessons'], (int)($d['lessons'] ?? 0)],
                'vimeo' => [$pc['vimeo'], (int)($d['vimeo'] ?? 0)],
                'youtube' => [$pc['youtube'], (int)($d['youtube'] ?? 0)],
                'enroll' => [$srcPerCourse[$pcid] ?? 0, $dbPerCourse[$pcid] ?? 0],
            ];
        }

        // --- kursy bez kursantów, konta bez kursu ---
        $noStudents = [];
        foreach ($perCourse as $pcid => $pc) {
            if (empty($srcPerCourse[$pcid])) {
                $noStudents[] = ['kurs' => $pc['title'], 'course_post_id' => $pcid];
            }
        }
        $usersWithAcc = [];
        foreach ($acc as $a) {
            $usersWithAcc[mb_strtolower(trim($a['email']))] = true;
        }
        $noCourseUsers = count(array_diff_key($srcEmails, $usersWithAcc));

        // --- losowa próba 20 lekcji ---
        $sample = self::sample($lessonIndex, 20);

        $inv = self::inventory($in, $srcIds);

        $otherIssues = array_values(array_filter($importIssues, fn($i) => !in_array($i['type'], ['pusta_lekcja', 'shortcode_trenerka', 'spotify_osadzenie', 'osadzenie_jako_link', 'obraz_bez_src', 'plik_bez_url', 'html_iframe_pominiety', 'vimeo_nierozpoznane', 'youtube_nierozpoznane'], true)));

        $diffCount = 0;
        foreach ($rows as $r) {
            if ($r['diff'] !== null && $r['diff'] !== 0) {
                $diffCount++;
            }
        }
        return [
            'generated' => now(),
            'source' => ['dir' => basename($dir), 'kursy_json' => 'Publigo WXR export'],
            'summary' => $rows,
            'diff_rows' => $diffCount,
            'per_course' => $courseRows,
            'enroll_diff' => [
                'missing_in_db' => count($missingPairs), 'missing_ex' => array_slice(array_keys($missingPairs), 0, 30),
                'extra_in_db' => count($extraPairs), 'extra_ex' => array_slice(array_keys($extraPairs), 0, 30),
                'date_mismatch' => $dateMismatch, 'date_ex' => $dateMismatchEx,
            ],
            'missing_users' => array_slice($missingUsers, 0, 50),
            'missing_users_count' => count($missingUsers),
            'content_issues' => $contentIssues,
            'other_issues' => $otherIssues,
            'non_subscribers' => $nonSubOut,
            'courses_without_students' => $noStudents,
            'users_without_course' => $noCourseUsers,
            'sample' => $sample,
            'inventory' => $inv,
            'blocks_target' => $bt,
        ];
    }

    /** Niezależne liczenie elementów w surowym HTML-u lekcji. */
    private static function sourceCounts(string $h): array
    {
        $c = ['vimeo' => 0, 'youtube' => 0, 'spotify' => 0, 'images' => 0, 'files' => 0, 'textlen' => 0];
        if (preg_match_all('/<!-- wp:embed (\{.*?\}) -->/s', $h, $m)) {
            foreach ($m[1] as $j) {
                $u = (string)(json_decode($j, true)['url'] ?? '');
                if (stripos($u, 'vimeo.com') !== false) {
                    $c['vimeo']++;
                } elseif (preg_match('#youtu(\.be|be\.com)#i', $u)) {
                    $c['youtube']++;
                } elseif (stripos($u, 'open.spotify.com') !== false) {
                    $c['spotify']++;
                }
            }
        }
        if (preg_match_all('#<!-- wp:html -->(.*?)<!-- /wp:html -->#s', $h, $m)) {
            foreach ($m[1] as $x) {
                if (preg_match('#<iframe[^>]+src="[^"]*vimeo\.com#i', $x)) {
                    $c['vimeo']++;
                } elseif (preg_match('#<iframe[^>]+src="[^"]*youtu#i', $x)) {
                    $c['youtube']++;
                } elseif (preg_match('#<iframe[^>]+src="[^"]*open\.spotify\.com#i', $x)) {
                    $c['spotify']++;
                }
            }
        }
        if (preg_match_all('#<!-- wp:image\b.*?<!-- /wp:image -->#s', $h, $m)) {
            foreach ($m[0] as $x) {
                $c['images'] += preg_match_all('/<img\b[^>]*\bsrc="[^"]+"/i', $x);
            }
        }
        $c['files'] = preg_match_all('#<!-- wp:file\s*\{[^}]*"href"\s*:\s*"[^"]+"#', $h) ?: 0;
        $c['textlen'] = mb_strlen(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('/<!--.*?-->/s', '', $h) ?? ''))) ?? ''));
        return $c;
    }

    private static function sample(array $lessonIndex, int $n): array
    {
        $cands = [];
        foreach ($lessonIndex as $pid => $x) {
            if ($x['textlen'] > 0 || $x['src']['vimeo'] + $x['src']['youtube'] > 0) {
                $cands[] = $pid;
            }
        }
        $out = [];
        $pick = [];
        $keys = $cands;
        $max = min($n, count($keys));
        while (count($pick) < $max) {
            $k = $keys[random_int(0, count($keys) - 1)];
            $pick[$k] = true;
        }
        foreach (array_keys($pick) as $pid) {
            $x = $lessonIndex[(string)$pid];
            $l = Db::one('SELECT l.id, l.title, m.title AS module FROM lessons l JOIN modules m ON m.id=l.module_id WHERE l.publigo_id = ? LIMIT 1', [(string)$pid]);
            $t = ['vimeo' => 0, 'youtube' => 0, 'images' => 0, 'files' => 0, 'textlen' => 0, 'blocks' => 0];
            if ($l) {
                foreach (Db::all('SELECT type, content, data FROM blocks WHERE lesson_id = ? ORDER BY sort_order', [$l['id']]) as $b) {
                    $t['blocks']++;
                    if ($b['type'] === 'vimeo') {
                        $t['vimeo']++;
                    } elseif ($b['type'] === 'youtube') {
                        $t['youtube']++;
                    } elseif ($b['type'] === 'file') {
                        $t['files']++;
                    } elseif ($b['type'] === 'image') {
                        $t['images'] += count((json_decode((string)$b['data'], true)['images'] ?? []));
                    } elseif ($b['type'] === 'text') {
                        $t['textlen'] += mb_strlen(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($b['content']))) ?? ''));
                    }
                }
            }
            $out[] = [
                'publigo_lesson_id' => (string)$pid, 'db_id' => $l['id'] ?? null, 'course' => $x['course'], 'module' => $x['module'], 'title' => $x['title'],
                'source' => $x['src'], 'target' => $t,
                'ok' => $l && $x['src']['vimeo'] === $t['vimeo'] && $x['src']['youtube'] === $t['youtube'] && $x['src']['images'] === $t['images'] && $x['src']['files'] === $t['files'],
            ];
        }
        return $out;
    }

    /** Inwentaryzacja plików/obrazów odwoływanych z treści (co trzeba pobrać z CDN). */
    private static function inventory(string $in, array $srcIds): array
    {
        $rows = [];
        $byHost = [];
        $byExt = [];
        $seen = [];
        $add = function (string $url, string $kind, string $course, int $lessonId) use (&$rows, &$byHost, &$byExt, &$seen): void {
            if (!preg_match('#^https?://#i', $url)) {
                return;
            }
            $host = strtolower((string)parse_url($url, PHP_URL_HOST));
            $path = (string)parse_url($url, PHP_URL_PATH);
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $key = $url;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $byHost[$host] = ($byHost[$host] ?? 0) + 1;
            $byExt[$ext === '' ? '(brak)' : $ext] = ($byExt[$ext === '' ? '(brak)' : $ext] ?? 0) + 1;
            $rows[] = [$url, $host, $ext, $kind, in_array($ext, self::ALLOWED_EXT, true) ? 'tak' : 'NIE', $course, $lessonId];
        };
        $q = Db::all("SELECT b.type, b.content, b.data, l.id lid, c.title ctitle FROM blocks b JOIN lessons l ON l.id=b.lesson_id JOIN modules m ON m.id=l.module_id JOIN courses c ON c.id=m.course_id WHERE b.type IN ('image','file','text') AND c.publigo_course_id IN ($in)", $srcIds);
        foreach ($q as $b) {
            if ($b['type'] === 'image') {
                foreach ((json_decode((string)$b['data'], true)['images'] ?? []) as $im) {
                    if (!empty($im['src'])) {
                        $add($im['src'], 'blok obrazu', $b['ctitle'], (int)$b['lid']);
                    }
                }
            } elseif ($b['type'] === 'file') {
                $u = (string)(json_decode((string)$b['data'], true)['url'] ?? '');
                if ($u !== '') {
                    $add($u, 'blok pliku', $b['ctitle'], (int)$b['lid']);
                }
            } else {
                if (preg_match_all('/<img\b[^>]*\bsrc="([^"]+)"/i', $b['content'], $m)) {
                    foreach ($m[1] as $u) {
                        $add(html_entity_decode($u), 'obraz w tekście', $b['ctitle'], (int)$b['lid']);
                    }
                }
                if (preg_match_all('/<a\b[^>]*\bhref="([^"]+)"/i', $b['content'], $m)) {
                    foreach ($m[1] as $u) {
                        $u = html_entity_decode($u);
                        $p = (string)parse_url($u, PHP_URL_PATH);
                        if (preg_match('/\.(' . self::FILE_EXT_RE . ')$/i', $p) || stripos($u, 'cdn.idealms.net') !== false) {
                            $add($u, 'link w tekście', $b['ctitle'], (int)$b['lid']);
                        }
                    }
                }
            }
        }
        arsort($byHost);
        arsort($byExt);
        $notAllowed = array_values(array_filter(array_keys($byExt), fn($e) => !in_array($e, self::ALLOWED_EXT, true)));
        return ['rows' => $rows, 'total' => count($rows), 'by_host' => $byHost, 'by_ext' => $byExt, 'not_allowed_ext' => $notAllowed, 'allowed' => self::ALLOWED_EXT];
    }

    private static function csv(string $file): array
    {
        $fh = fopen($file, 'rb');
        $head = PubligoImporter::csvRow($fh);
        $head[0] = ltrim((string)$head[0], "\xEF\xBB\xBF");
        $out = [];
        while (($r = PubligoImporter::csvRow($fh)) !== null) {
            $out[] = array_combine($head, array_slice(array_pad($r, count($head), ''), 0, count($head)));
        }
        fclose($fh);
        return $out;
    }

    // ------------------------------------------------------------------ HTML
    private const LABELS = [
        'pusta_lekcja' => 'Puste lekcje (bez treści w źródle, zachowane jako puste)',
        'shortcode_trenerka' => 'Shortcode [trenerka] usunięty z treści (do decyzji z Markiem)',
        'spotify_osadzenie' => 'Osadzenia Spotify (zostawione jako osadzenie)',
        'osadzenie_jako_link' => 'Osadzenia innych serwisów zamienione na link w tekście',
        'obraz_bez_src' => 'Pusty blok obrazu/galerii w źródle (bez obrazów)',
        'plik_bez_url' => 'Pusty blok pliku w źródle (bez adresu)',
        'html_iframe_pominiety' => 'Ramka iframe w wp:html pominięta (nieznany serwis)',
        'vimeo_nierozpoznane' => 'Adres Vimeo nierozpoznany',
        'youtube_nierozpoznane' => 'Adres YouTube nierozpoznany',
    ];

    public static function html(array $r): string
    {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ok = $r['diff_rows'] === 0;
        $o = '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Raport zgodności migracji</title><style>'
            . ':root{--bg:#F2F5F4;--s:#fff;--ink:#13201C;--mut:#586964;--line:#DCE3E0;--ok:#1B7A3E;--bad:#B3261E;--warn:#8A5200;--wsoft:#FBEFD5;--acc:#DDEFE9}'
            . '@media(prefers-color-scheme:dark){:root{--bg:#0E1513;--s:#151F1C;--ink:#E6EEEB;--mut:#94A59F;--line:#243430;--ok:#5CC98A;--bad:#F08A82;--warn:#E5A83B;--wsoft:#33270F;--acc:#15332D}}'
            . 'body{font:15px/1.5 system-ui,sans-serif;background:var(--bg);color:var(--ink);margin:0;padding:16px}main{max-width:1100px;margin:0 auto}'
            . 'h1{font-size:26px;margin:8px 0}h2{font-size:19px;margin:30px 0 8px}table{border-collapse:collapse;width:100%;background:var(--s);font-size:14px}th,td{border-bottom:1px solid var(--line);padding:7px 10px;text-align:left;vertical-align:top}th{font-size:12px;text-transform:uppercase;color:var(--mut)}'
            . '.w{overflow-x:auto;border:1px solid var(--line);border-radius:10px}.n{text-align:right;font-variant-numeric:tabular-nums}.ok{color:var(--ok);font-weight:600}.bad{color:var(--bad);font-weight:600}.mut{color:var(--mut)}'
            . '.banner{padding:14px 16px;border-radius:10px;font-weight:600;background:var(--acc)}.banner.bad{background:var(--wsoft);color:var(--warn)}code{font-size:12px}details{margin:6px 0}summary{cursor:pointer}'
            . '</style></head><body><main>';
        $o .= '<h1>Raport zgodności migracji Publigo → nowa platforma</h1><p class="mut">Wygenerowano: ' . $h($r['generated']) . '. Liczby „źródło” są liczone niezależnie z plików paczki, liczby „baza” z nowej bazy.</p>';
        $o .= '<div class="banner' . ($ok ? '' : ' bad') . '">' . ($ok ? 'Wszystkie porównania liczbowe zgodne (różnica 0).' : 'Różnice w ' . $r['diff_rows'] . ' wierszach tabeli poniżej. Zobacz kolumnę „Uwagi” i listę pominiętych elementów.') . '</div>';

        $o .= '<h2>1. Źródło a nowa baza</h2><div class="w"><table><thead><tr><th>Element</th><th class="n">Źródło</th><th class="n">Baza</th><th class="n">Różnica</th><th>Uwagi</th></tr></thead><tbody>';
        foreach ($r['summary'] as $x) {
            $d = $x['diff'];
            $o .= '<tr><td>' . $h($x['label']) . '</td><td class="n">' . $h(is_numeric($x['source']) ? nf((int)$x['source']) : $x['source']) . '</td><td class="n">' . $h(is_numeric($x['target']) ? nf((int)$x['target']) : $x['target']) . '</td><td class="n ' . ($d === 0 ? 'ok' : ($d === null ? '' : 'bad')) . '">' . ($d === null ? '' : ($d === 0 ? '0' : sprintf('%+d', $d))) . '</td><td class="mut">' . $h($x['note']) . '</td></tr>';
        }
        $o .= '</tbody></table></div>';
        $e = $r['enroll_diff'];
        $o .= '<p>Przypisania: brakuje w bazie <b class="' . ($e['missing_in_db'] ? 'bad' : 'ok') . '">' . nf($e['missing_in_db']) . '</b>, dodatkowych w bazie <b>' . nf($e['extra_in_db']) . '</b>, różnych dat dostępu <b class="' . ($e['date_mismatch'] ? 'bad' : 'ok') . '">' . nf($e['date_mismatch']) . '</b>. Kont ze źródła, których brak w bazie: <b class="' . ($r['missing_users_count'] ? 'bad' : 'ok') . '">' . nf($r['missing_users_count']) . '</b>.</p>';
        foreach (['missing_ex' => 'Przykłady brakujących przypisań', 'extra_ex' => 'Przykłady dodatkowych przypisań'] as $k => $t) {
            if ($e[$k]) {
                $o .= '<details><summary>' . $t . '</summary><code>' . $h(implode("\n", $e[$k])) . '</code></details>';
            }
        }

        $o .= '<h2>2. Pominięte, zmienione i wymagające decyzji</h2>';
        $groups = [];
        foreach ($r['content_issues'] as $i) {
            $groups[$i['type']][] = $i;
        }
        foreach (self::LABELS as $type => $label) {
            $list = $groups[$type] ?? [];
            if (!$list) {
                continue;
            }
            $o .= '<details' . (count($list) <= 15 ? ' open' : '') . '><summary><b>' . $h($label) . '</b>: ' . count($list) . '</summary><div class="w"><table><thead><tr><th>Kurs</th><th>Moduł</th><th>Lekcja</th><th>ID lekcji (Publigo)</th><th>Szczegóły</th></tr></thead><tbody>';
            foreach ($list as $i) {
                $o .= '<tr><td>' . $h($i['kurs']) . '</td><td>' . $h($i['modul']) . '</td><td>' . $h($i['lekcja']) . '</td><td>' . $h($i['lekcja_id']) . '</td><td>' . $h($i['szczegoly'] ?? '') . '</td></tr>';
            }
            $o .= '</tbody></table></div></details>';
        }
        $o .= '<details open><summary><b>Konta o roli innej niż „Subskrybent”</b> (do weryfikacji z Markiem): ' . count($r['non_subscribers']) . '</summary><div class="w"><table><thead><tr><th>E-mail</th><th>Rola w Publigo</th><th>Nazwa</th><th>Rola w nowej bazie</th></tr></thead><tbody>';
        foreach ($r['non_subscribers'] as $x) {
            $o .= '<tr><td>' . $h($x['email']) . '</td><td>' . $h($x['rola_publigo']) . '</td><td>' . $h($x['nazwa']) . '</td><td>' . $h($x['rola_w_bazie']) . '</td></tr>';
        }
        $o .= '</tbody></table></div></details>';
        $o .= '<details><summary><b>Kursy bez żadnego kursanta w paczce</b>: ' . count($r['courses_without_students']) . '</summary><ul>';
        foreach ($r['courses_without_students'] as $x) {
            $o .= '<li>' . $h($x['kurs']) . ' <span class="mut">(' . $h($x['course_post_id']) . ')</span></li>';
        }
        $o .= '</ul></details>';
        $o .= '<p>Konta bez żadnego przypisanego kursu: <b>' . nf($r['users_without_course']) . '</b>. Quizy (6) zaimportowane jako samodzielne, do podpięcia w lekcjach; historii podejść nie przenosimy.</p>';
        if ($r['other_issues']) {
            $o .= '<details><summary><b>Inne uwagi z importu</b>: ' . count($r['other_issues']) . '</summary><ul>';
            foreach (array_slice($r['other_issues'], 0, 200) as $i) {
                $o .= '<li><code>' . $h($i['type']) . '</code> ' . $h(json_encode(array_diff_key($i, ['type' => 1]), JSON_UNESCAPED_UNICODE)) . '</li>';
            }
            $o .= '</ul></details>';
        }

        $o .= '<h2>3. Losowa próba ' . count($r['sample']) . ' lekcji do ręcznego porównania z Publigo</h2><p class="mut">Otwórz lekcję w Publigo i w nowej platformie (kolumna „Nowa platforma”) i porównaj treść, filmy i obrazy.</p><div class="w"><table><thead><tr><th>Kurs / moduł / lekcja</th><th>ID Publigo</th><th>Źródło: wideo / obrazy / pliki / znaki</th><th>Baza: wideo / obrazy / pliki / znaki</th><th>Zgodne</th><th>Nowa platforma</th></tr></thead><tbody>';
        foreach ($r['sample'] as $s) {
            $a = $s['source'];
            $b = $s['target'];
            $o .= '<tr><td>' . $h($s['course']) . '<br><span class="mut">' . $h($s['module']) . ' › </span>' . $h($s['title']) . '</td><td>' . $h($s['publigo_lesson_id']) . '</td><td>' . ($a['vimeo'] + $a['youtube']) . ' / ' . $a['images'] . ' / ' . $a['files'] . ' / ' . $a['textlen'] . '</td><td>' . ($b['vimeo'] + $b['youtube']) . ' / ' . $b['images'] . ' / ' . $b['files'] . ' / ' . $b['textlen'] . '</td><td class="' . ($s['ok'] ? 'ok' : 'bad') . '">' . ($s['ok'] ? 'tak' : 'NIE') . '</td><td>' . ($s['db_id'] ? '<code>/admin/lekcja/' . (int)$s['db_id'] . '</code>' : '—') . '</td></tr>';
        }
        $o .= '</tbody></table></div><p class="mut">Liczba znaków tekstu w bazie może się nieznacznie różnić od źródła (oczyszczanie HTML, usunięte shortcode’y, pominięte puste znaczniki).</p>';

        $inv = $r['inventory'];
        $o .= '<h2>4. Inwentaryzacja plików i obrazów z treści lekcji</h2><p>Unikalnych adresów: <b>' . nf($inv['total']) . '</b>. Pełna lista: plik CSV <code>pliki-do-pobrania-…csv</code>. Pobierz je skryptem <code>bin/pobierz-pliki.php</code> przed wyłączeniem Publigo.</p>';
        $o .= '<div class="w"><table><thead><tr><th>Domena</th><th class="n">Adresów</th></tr></thead><tbody>';
        foreach (array_slice($inv['by_host'], 0, 15, true) as $k => $v) {
            $o .= '<tr><td>' . $h($k) . '</td><td class="n">' . nf($v) . '</td></tr>';
        }
        $o .= '</tbody></table></div><br><div class="w"><table><thead><tr><th>Rozszerzenie</th><th class="n">Adresów</th><th>Na liście dozwolonych?</th></tr></thead><tbody>';
        foreach ($inv['by_ext'] as $k => $v) {
            $al = in_array($k, $inv['allowed'], true);
            $o .= '<tr><td>' . $h($k) . '</td><td class="n">' . nf($v) . '</td><td class="' . ($al ? 'ok' : 'bad') . '">' . ($al ? 'tak' : 'nie, do decyzji') . '</td></tr>';
        }
        $o .= '</tbody></table></div>';

        $o .= '<h2>5. Zgodność per kurs</h2><div class="w"><table><thead><tr><th>Kurs</th><th>ID Publigo</th><th class="n">Moduły ź/b</th><th class="n">Lekcje ź/b</th><th class="n">Vimeo ź/b</th><th class="n">YouTube ź/b</th><th class="n">Przypisania ź/b</th></tr></thead><tbody>';
        foreach ($r['per_course'] as $c) {
            $cell = fn($p) => '<td class="n ' . ($p[0] === $p[1] ? '' : 'bad') . '">' . $p[0] . ' / ' . $p[1] . '</td>';
            $o .= '<tr><td>' . $h($c['title']) . '</td><td>' . $h($c['publigo_id']) . '</td>' . $cell($c['modules']) . $cell($c['lessons']) . $cell($c['vimeo']) . $cell($c['youtube']) . $cell($c['enroll']) . '</tr>';
        }
        $o .= '</tbody></table></div></main></body></html>';
        return $o;
    }
}
