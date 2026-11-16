<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\{Request, Response};
use App\{Access, Auth, BlockRenderer, Db, Quiz, Security, Session, Settings, Uploads};

final class StudentController extends Base
{
    public function courses(Request $r): void
    {
        $u = Auth::requireLogin();
        $rows = Db::all(
            'SELECT c.id, c.title, c.description, e.expires_at, e.legacy_progress, e.legacy_done, e.legacy_total,
                    (SELECT COUNT(*) FROM lessons l JOIN modules m ON m.id = l.module_id WHERE m.course_id = c.id) AS total,
                    (SELECT COUNT(*) FROM lesson_progress p JOIN lessons l ON l.id = p.lesson_id JOIN modules m ON m.id = l.module_id WHERE m.course_id = c.id AND p.user_id = e.user_id) AS done
             FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE e.user_id = ? ORDER BY c.sort_order, c.title',
            [$u['id']]
        );
        foreach ($rows as &$c) {
            $c['acc'] = Access::status($c['expires_at']);
            $c['pct'] = (int)$c['total'] > 0 ? (int)round($c['done'] / $c['total'] * 100) : 0;
        }
        $this->page('student/courses', ['courses' => $rows, 'extendUrl' => trim(Settings::get('extend_url'))], 'student');
    }

    private function loadCourse(array $user, int $id): array
    {
        $c = Db::one('SELECT * FROM courses WHERE id = ?', [$id]);
        if (!$c) {
            Response::error(404);
        }
        if (!Access::canEnter($user, $id)) {
            if (Access::enrollment((int)$user['id'], $id)) {
                Session::flash('Dostęp do tego kursu wygasł. Użyj przycisku „Przedłuż dostęp”.', 'warn');
                Response::redirect('/moje-kursy');
            }
            Response::error(403, 'Nie masz dostępu do tego kursu.');
        }
        return $c;
    }

    public function course(Request $r, string $id): void
    {
        $u = Auth::requireLogin();
        $c = $this->loadCourse($u, (int)$id);
        $flat = Access::flat(Access::tree((int)$c['id']));
        if (!$flat) {
            $this->page('student/lesson', ['course' => $c, 'tree' => [], 'lesson' => null, 'blocks' => '', 'idx' => 0, 'n' => 0, 'done' => [], 'prev' => null, 'next' => null], 'student');
        }
        $done = $this->doneSet((int)$u['id'], array_column($flat, 'id'));
        $target = $flat[0];
        foreach ($flat as $l) {
            if (!isset($done[$l['id']])) {
                $target = $l;
                break;
            }
        }
        Response::redirect('/kurs/' . $c['id'] . '/lekcja/' . $target['id']);
    }

    public function lessonRedirect(Request $r, string $lid): void
    {
        $l = Db::one('SELECT l.id, m.course_id FROM lessons l JOIN modules m ON m.id = l.module_id WHERE l.id = ?', [(int)$lid]);
        if (!$l) {
            Response::error(404);
        }
        Response::redirect('/kurs/' . $l['course_id'] . '/lekcja/' . $l['id'], 301);
    }

    private function doneSet(int $uid, array $lessonIds): array
    {
        if (!$lessonIds) {
            return [];
        }
        $rows = Db::all('SELECT lesson_id FROM lesson_progress WHERE user_id = ? AND lesson_id IN (' . implode(',', array_map('intval', $lessonIds)) . ')', [$uid]);
        return array_flip(array_column($rows, 'lesson_id'));
    }

    public function lesson(Request $r, string $id, string $lid): void
    {
        $u = Auth::requireLogin();
        $c = $this->loadCourse($u, (int)$id);
        $tree = Access::tree((int)$c['id']);
        $flat = Access::flat($tree);
        $idx = null;
        foreach ($flat as $i => $l) {
            if ((int)$l['id'] === (int)$lid) {
                $idx = $i;
            }
        }
        if ($idx === null) {
            Response::error(404);
        }
        $lesson = $flat[$idx];
        $blocks = Db::all('SELECT * FROM blocks WHERE lesson_id = ? ORDER BY sort_order, id', [$lesson['id']]);
        $done = $this->doneSet((int)$u['id'], array_column($flat, 'id'));
        $html = BlockRenderer::render($blocks, ['user' => $u, 'course' => $c, 'lesson' => $lesson]);
        $this->page('student/lesson', [
            'course' => $c, 'tree' => $tree, 'lesson' => $lesson, 'blocks' => $html, 'idx' => $idx, 'n' => count($flat), 'done' => $done,
            'prev' => $flat[$idx - 1] ?? null, 'next' => $flat[$idx + 1] ?? null, 'isEmpty' => !$blocks,
        ], 'student');
    }

    public function toggleDone(Request $r, string $lid): void
    {
        $u = Auth::requireLogin();
        $l = Db::one('SELECT l.id, m.course_id FROM lessons l JOIN modules m ON m.id = l.module_id WHERE l.id = ?', [(int)$lid]);
        if (!$l || !Access::canEnter($u, (int)$l['course_id'])) {
            Response::error(403);
        }
        $next = null;
        if (Db::val('SELECT 1 FROM lesson_progress WHERE user_id = ? AND lesson_id = ?', [$u['id'], $l['id']])) {
            Db::exec('DELETE FROM lesson_progress WHERE user_id = ? AND lesson_id = ?', [$u['id'], $l['id']]);
        } else {
            Db::exec('INSERT IGNORE INTO lesson_progress (user_id, lesson_id, completed_at) VALUES (?, ?, ?)', [$u['id'], $l['id'], now()]);
            $flat = Access::flat(Access::tree((int)$l['course_id']));
            foreach ($flat as $i => $x) {
                if ((int)$x['id'] === (int)$l['id']) {
                    $next = $flat[$i + 1]['id'] ?? null;
                }
            }
            Session::flash('Lekcja ukończona');
        }
        Response::redirect('/kurs/' . $l['course_id'] . '/lekcja/' . ($next ?? $l['id']));
    }

    public function quizSubmit(Request $r, string $bid): void
    {
        $u = Auth::requireLogin();
        $b = Db::one("SELECT b.*, m.course_id FROM blocks b JOIN lessons l ON l.id = b.lesson_id JOIN modules m ON m.id = l.module_id WHERE b.id = ? AND b.type = 'quiz'", [(int)$bid]);
        if (!$b || !Access::canEnter($u, (int)$b['course_id'])) {
            Response::error(403);
        }
        $back = '/kurs/' . $b['course_id'] . '/lekcja/' . $b['lesson_id'] . '#quiz-' . $b['id'];
        $quizId = (int)((json_decode((string)$b['data'], true) ?: [])['quiz_id'] ?? 0);
        $quiz = Quiz::load($quizId);
        if (!$quiz) {
            Response::error(404);
        }
        if (!Quiz::canAttempt($quiz, (int)$u['id'])) {
            $this->flashRedirect($back, 'Wykorzystano limit podejść do tego quizu.', 'warn');
        }
        $given = [];
        $needsReview = false;
        $fileQs = [];
        foreach ($quiz['questions'] as $q) {
            $qid = (int)$q['id'];
            if (Quiz::isAuto($q)) {
                $sel = $r->post['q'][$qid] ?? [];
                $sel = is_array($sel) ? $sel : [$sel];
                $valid = array_map('intval', array_column($q['answers'], 'id'));
                $given[$qid] = array_values(array_filter(array_map('intval', array_filter($sel, 'is_scalar')), fn($x) => in_array($x, $valid, true)));
                if ($q['type'] === 'single') {
                    $given[$qid] = array_slice($given[$qid], 0, 1);
                }
                if (!$given[$qid]) {
                    $this->flashRedirect($back, 'Odpowiedz na wszystkie pytania zamknięte.', 'warn');
                }
            } elseif ($q['type'] === 'text') {
                $t = $r->post['q'][$qid] ?? '';
                $given[$qid] = ['text' => mb_substr(is_string($t) ? $t : '', 0, 5000)];
                $needsReview = true;
            } else {
                $needsReview = true;
                $fileQs[] = $qid;
            }
        }
        // pliki zapisujemy dopiero po sprawdzeniu reszty, z limitem na użytkownika
        $stored = [];
        foreach ($fileQs as $qid) {
            $f = $_FILES['qf']['name'][$qid] ?? null;
            $given[$qid] = ['media_id' => null, 'name' => ''];
            if ($f === null || $f === '' || (int)($_FILES['qf']['error'][$qid] ?? 4) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (Security::tooMany('quiz_upload', (string)$u['id'], 10, 86400)) {
                foreach ($stored as $mid) {
                    Uploads::remove($mid);
                }
                $this->flashRedirect($back, 'Przekroczono dzienny limit wysyłanych plików. Spróbuj jutro.', 'warn');
            }
            $one = ['name' => $_FILES['qf']['name'][$qid], 'tmp_name' => $_FILES['qf']['tmp_name'][$qid], 'error' => $_FILES['qf']['error'][$qid], 'size' => $_FILES['qf']['size'][$qid]];
            $res = Uploads::store($one, 'quiz');
            if (!$res['ok']) {
                foreach ($stored as $mid) {
                    Uploads::remove($mid);
                }
                $this->flashRedirect($back, $res['error'], 'warn');
            }
            Security::hit('quiz_upload', (string)$u['id']);
            $stored[] = (int)$res['media']['id'];
            $given[$qid] = ['media_id' => (int)$res['media']['id'], 'name' => $res['media']['original_name']];
        }
        $g = Quiz::grade($quiz, $given);
        // quiz bez pytań zamkniętych nie zalicza się sam: czeka na ocenę ręczną
        $manualOnly = $g['total'] === 0 && $needsReview;
        Db::insert('quiz_attempts', [
            'quiz_id' => $quizId, 'user_id' => $u['id'], 'score_percent' => $manualOnly ? null : $g['pct'], 'passed' => ($g['pass'] && !$manualOnly) ? 1 : 0,
            'needs_review' => $needsReview ? 1 : 0, 'answers' => json_encode(['given' => $given, 'det' => $g['det']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
        Response::redirect($back);
    }
}
