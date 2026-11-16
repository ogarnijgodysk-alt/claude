<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\{Request, Response};
use App\{Access, Db, Sanitizer, Settings, Uploads, Video};

final class AdminCourses extends Base
{
    public function index(Request $r): void
    {
        $this->admin();
        $courses = Db::all(
            'SELECT c.*,
               (SELECT COUNT(*) FROM modules m WHERE m.course_id = c.id) AS n_modules,
               (SELECT COUNT(*) FROM lessons l JOIN modules m ON m.id = l.module_id WHERE m.course_id = c.id) AS n_lessons,
               (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS n_students
             FROM courses c ORDER BY c.sort_order, c.id'
        );
        $this->page('admin/courses', ['courses' => $courses, 'active' => 'kursy', 'nClients' => (int)Db::val('SELECT COUNT(*) FROM users WHERE role = "client"'), 'title' => 'Kursy'], 'admin');
    }

    public function create(Request $r): void
    {
        $this->admin();
        $title = Sanitizer::text($r->str('title'));
        if ($title === '') {
            $this->flashRedirect('/admin', 'Wpisz nazwę kursu.', 'warn');
        }
        $max = (int)Db::val('SELECT COALESCE(MAX(sort_order), 0) FROM courses');
        $id = Db::insert('courses', ['title' => $title, 'short_title' => mb_substr($title, 0, 60), 'sort_order' => $max + 1, 'created_at' => now()]);
        Db::insert('modules', ['course_id' => $id, 'title' => 'Moduł 1', 'sort_order' => 1]);
        Response::redirect('/admin/kurs/' . $id);
    }

    public function lessonRedirect(Request $r, string $id): void
    {
        $this->admin();
        $l = Db::one('SELECT l.id, m.course_id FROM lessons l JOIN modules m ON m.id = l.module_id WHERE l.id = ?', [(int)$id]);
        if (!$l) {
            Response::error(404);
        }
        Response::redirect('/admin/kurs/' . $l['course_id'] . '?lekcja=' . $l['id']);
    }

    public function editor(Request $r, string $id): void
    {
        $this->admin();
        $c = Db::one('SELECT * FROM courses WHERE id = ?', [(int)$id]);
        if (!$c) {
            Response::error(404);
        }
        $boot = [
            'course' => ['id' => (int)$c['id'], 'title' => $c['title'], 'description' => (string)$c['description'], 'access_amount' => (int)($c['access_amount'] ?? 0), 'access_unit' => $c['access_unit']],
            'tree' => $this->tree((int)$c['id']),
            'open' => $r->int('lekcja'),
            'limits' => ['image_mb' => 20, 'file_mb' => (int)round(Uploads::maxBytes('file') / 1048576), 'image_ext' => Uploads::IMAGE_EXT, 'file_ext' => Uploads::FILE_EXT],
            'csrf' => \App\Csrf::token(),
            'base' => rtrim((string)\App\Config::get('base_path', ''), '/'),
        ];
        $this->page('admin/editor', ['course' => $c, 'boot' => $boot, 'active' => 'kursy', 'title' => $c['title'], 'scripts' => ['editor.js']], 'admin');
    }

    private function tree(int $courseId): array
    {
        $out = [];
        $stats = [];
        foreach (Db::all("SELECT b.lesson_id, SUM(b.type IN ('vimeo','youtube')) v, SUM(b.type = 'quiz') q FROM blocks b JOIN lessons l ON l.id = b.lesson_id JOIN modules m ON m.id = l.module_id WHERE m.course_id = ? GROUP BY b.lesson_id", [$courseId]) as $s) {
            $stats[$s['lesson_id']] = $s;
        }
        foreach (Access::tree($courseId) as $m) {
            $out[] = ['id' => (int)$m['id'], 'title' => $m['title'], 'lessons' => array_map(fn($l) => ['id' => (int)$l['id'], 'title' => $l['title'], 'videos' => (int)($stats[$l['id']]['v'] ?? 0), 'quizzes' => (int)($stats[$l['id']]['q'] ?? 0)], $m['lessons'])];
        }
        return $out;
    }

    public function upload(Request $r): void
    {
        $this->admin();
        $kind = $r->str('kind') === 'image' ? 'image' : 'file';
        $res = Uploads::store($_FILES['file'] ?? [], $kind);
        if (!$res['ok']) {
            Response::json(['ok' => false, 'error' => $res['error']], 422);
        }
        $m = $res['media'];
        Response::json(['ok' => true, 'media' => ['id' => (int)$m['id'], 'name' => $m['original_name'], 'size' => fmt_size((int)$m['size']), 'ext' => $m['ext']]]);
    }

    // ------------------------------------------------------------------ API JSON
    public function api(Request $r, string $action): void
    {
        $this->admin();
        $in = $r->json();
        try {
            $out = match ($action) {
                'course.update' => $this->courseUpdate($in),
                'course.delete' => $this->courseDelete($in),
                'module.add' => $this->moduleAdd($in),
                'module.update' => $this->moduleUpdate($in),
                'module.delete' => $this->moduleDelete($in),
                'module.reorder' => $this->moduleReorder($in),
                'lesson.add' => $this->lessonAdd($in),
                'lesson.update' => $this->lessonUpdate($in),
                'lesson.delete' => $this->lessonDelete($in),
                'lesson.layout' => $this->lessonLayout($in),
                'lesson.get' => $this->lessonGet($in),
                'block.add' => $this->blockAdd($in),
                'block.update' => $this->blockUpdate($in),
                'block.delete' => $this->blockDelete($in),
                'block.reorder' => $this->blockReorder($in),
                'quiz.get' => $this->quizGet($in),
                'quiz.save' => $this->quizSave($in),
                'quiz.list' => $this->quizList(),
                'quiz.attach' => $this->quizAttach($in),
                'quiz.new' => $this->quizNew($in),
                default => throw new \InvalidArgumentException('Nieznana akcja'),
            };
            Response::json(['ok' => true] + $out);
        } catch (\InvalidArgumentException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    private function courseUpdate(array $in): array
    {
        $id = (int)($in['id'] ?? 0);
        $set = [];
        if (isset($in['title'])) {
            $t = Sanitizer::text((string)$in['title']);
            if ($t === '') {
                throw new \InvalidArgumentException('Tytuł kursu nie może być pusty.');
            }
            $set['title'] = $t;
        }
        if (isset($in['description'])) {
            $set['description'] = Sanitizer::text((string)$in['description'], 2000);
        }
        if (array_key_exists('access_amount', $in)) {
            $n = (int)$in['access_amount'];
            $set['access_amount'] = $n > 0 ? min(99, $n) : null;
            $set['access_unit'] = ($in['access_unit'] ?? 'months') === 'years' ? 'years' : 'months';
        }
        if ($set) {
            Db::update('courses', $set, 'id = ?', [$id]);
        }
        return [];
    }

    private function courseDelete(array $in): array
    {
        Db::exec('DELETE FROM courses WHERE id = ?', [(int)($in['id'] ?? 0)]);
        return [];
    }

    private function moduleAdd(array $in): array
    {
        $cid = (int)($in['course_id'] ?? 0);
        if (!Db::val('SELECT 1 FROM courses WHERE id = ?', [$cid])) {
            throw new \InvalidArgumentException('Brak kursu.');
        }
        $sort = (int)Db::val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM modules WHERE course_id = ?', [$cid]);
        $id = Db::insert('modules', ['course_id' => $cid, 'title' => 'Nowy moduł', 'sort_order' => $sort]);
        return ['module' => ['id' => $id, 'title' => 'Nowy moduł', 'lessons' => []]];
    }

    private function moduleUpdate(array $in): array
    {
        Db::update('modules', ['title' => mb_substr(Sanitizer::text((string)($in['title'] ?? '')), 0, 300)], 'id = ?', [(int)($in['id'] ?? 0)]);
        return [];
    }

    private function moduleDelete(array $in): array
    {
        Db::exec('DELETE FROM modules WHERE id = ?', [(int)($in['id'] ?? 0)]);
        return [];
    }

    private function moduleReorder(array $in): array
    {
        $cid = (int)($in['course_id'] ?? 0);
        $i = 0;
        foreach ((array)($in['order'] ?? []) as $mid) {
            Db::exec('UPDATE modules SET sort_order = ? WHERE id = ? AND course_id = ?', [++$i, (int)$mid, $cid]);
        }
        return [];
    }

    private function lessonAdd(array $in): array
    {
        $mid = (int)($in['module_id'] ?? 0);
        if (!Db::val('SELECT 1 FROM modules WHERE id = ?', [$mid])) {
            throw new \InvalidArgumentException('Brak modułu.');
        }
        $sort = (int)Db::val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM lessons WHERE module_id = ?', [$mid]);
        $id = Db::insert('lessons', ['module_id' => $mid, 'title' => 'Nowa lekcja', 'sort_order' => $sort]);
        Db::insert('blocks', ['lesson_id' => $id, 'type' => 'text', 'sort_order' => 1, 'content' => '']);
        return ['lesson' => ['id' => $id, 'title' => 'Nowa lekcja', 'videos' => 0, 'quizzes' => 0]];
    }

    private function lessonUpdate(array $in): array
    {
        Db::update('lessons', ['title' => mb_substr(Sanitizer::text((string)($in['title'] ?? '')), 0, 300)], 'id = ?', [(int)($in['id'] ?? 0)]);
        return [];
    }

    private function lessonDelete(array $in): array
    {
        Db::exec('DELETE FROM lessons WHERE id = ?', [(int)($in['id'] ?? 0)]);
        return [];
    }

    /** Pełny układ po przeciągnięciu: {"course_id":1,"layout":{"<module_id>":[lesson_ids...]}} */
    private function lessonLayout(array $in): array
    {
        $cid = (int)($in['course_id'] ?? 0);
        foreach ((array)($in['layout'] ?? []) as $mid => $ids) {
            if (!Db::val('SELECT 1 FROM modules WHERE id = ? AND course_id = ?', [(int)$mid, $cid])) {
                continue;
            }
            $i = 0;
            foreach ((array)$ids as $lid) {
                Db::exec('UPDATE lessons SET module_id = ?, sort_order = ? WHERE id = ? AND module_id IN (SELECT id FROM (SELECT id FROM modules WHERE course_id = ?) x)', [(int)$mid, ++$i, (int)$lid, $cid]);
            }
        }
        return [];
    }

    private function blockOut(array $b): array
    {
        $data = $b['data'] ? (json_decode((string)$b['data'], true) ?: []) : [];
        $o = ['id' => (int)$b['id'], 'type' => $b['type'], 'content' => (string)$b['content'], 'data' => $data ?: new \stdClass()];
        if ($b['type'] === 'file' && !empty($data['media_id'])) {
            $m = Db::one('SELECT size, ext FROM media WHERE id = ?', [(int)$data['media_id']]);
            $o['meta'] = $m ? ['size' => fmt_size((int)$m['size']), 'ext' => $m['ext']] : null;
        }
        if ($b['type'] === 'image' && !empty($data['images'])) {
            $o['urls'] = array_map(fn($im) => !empty($im['media_id']) ? url('/obraz/' . (int)$im['media_id']) : (string)($im['src'] ?? ''), $data['images']);
        }
        return $o;
    }

    private function lessonGet(array $in): array
    {
        $id = (int)($in['id'] ?? 0);
        $l = Db::one('SELECT l.id, l.title, m.course_id FROM lessons l JOIN modules m ON m.id = l.module_id WHERE l.id = ?', [$id]);
        if (!$l) {
            throw new \InvalidArgumentException('Nie znaleziono lekcji.');
        }
        $blocks = array_map(fn($b) => $this->blockOut($b), Db::all('SELECT * FROM blocks WHERE lesson_id = ? ORDER BY sort_order, id', [$id]));
        return ['lesson' => ['id' => (int)$l['id'], 'title' => $l['title']], 'blocks' => $blocks];
    }

    private function blockAdd(array $in): array
    {
        $lid = (int)($in['lesson_id'] ?? 0);
        $type = (string)($in['type'] ?? '');
        if (!in_array($type, ['text', 'vimeo', 'youtube', 'image', 'file', 'quiz'], true) || !Db::val('SELECT 1 FROM lessons WHERE id = ?', [$lid])) {
            throw new \InvalidArgumentException('Nieprawidłowy blok.');
        }
        $sort = (int)Db::val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM blocks WHERE lesson_id = ?', [$lid]);
        $data = null;
        if ($type === 'quiz') {
            $data = json_encode(['quiz_id' => $this->createQuiz()]);
        }
        $id = Db::insert('blocks', ['lesson_id' => $lid, 'type' => $type, 'sort_order' => $sort, 'content' => '', 'data' => $data]);
        return ['block' => $this->blockOut(Db::one('SELECT * FROM blocks WHERE id = ?', [$id]))];
    }

    private function blockUpdate(array $in): array
    {
        $id = (int)($in['id'] ?? 0);
        $b = Db::one('SELECT * FROM blocks WHERE id = ?', [$id]);
        if (!$b) {
            throw new \InvalidArgumentException('Nie znaleziono bloku.');
        }
        $set = [];
        $extra = [];
        switch ($b['type']) {
            case 'text':
                $set['content'] = Sanitizer::html((string)($in['content'] ?? ''));
                break;
            case 'vimeo':
                $u = mb_substr(trim((string)($in['content'] ?? '')), 0, 500);
                $set['content'] = $u;
                $v = Video::vimeo($u);
                $extra['recognized'] = $v ? ('ID ' . $v['id'] . ($v['hash'] ? ' (link prywatny)' : '')) : null;
                break;
            case 'youtube':
                $u = mb_substr(trim((string)($in['content'] ?? '')), 0, 500);
                $set['content'] = $u;
                $y = Video::youtube($u);
                $extra['recognized'] = $y ? 'ID ' . $y : null;
                break;
            case 'image':
                $imgs = [];
                foreach ((array)($in['data']['images'] ?? []) as $im) {
                    $row = ['alt' => Sanitizer::text((string)($im['alt'] ?? ''), 300), 'caption' => Sanitizer::text((string)($im['caption'] ?? ''), 500)];
                    if (!empty($im['media_id']) && Db::val('SELECT 1 FROM media WHERE id = ?', [(int)$im['media_id']])) {
                        $row['media_id'] = (int)$im['media_id'];
                    } elseif (!empty($im['src']) && ($s = Sanitizer::safeUrl((string)$im['src'], ['http', 'https'])) !== null) {
                        $row['src'] = $s;
                    } else {
                        continue;
                    }
                    $imgs[] = $row;
                }
                $set['data'] = json_encode(['images' => $imgs], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;
            case 'file':
                $d = ['name' => Sanitizer::text((string)($in['data']['name'] ?? ''), 200)];
                if (!empty($in['data']['media_id']) && Db::val('SELECT 1 FROM media WHERE id = ?', [(int)$in['data']['media_id']])) {
                    $d['media_id'] = (int)$in['data']['media_id'];
                } elseif (!empty($in['data']['url']) && ($s = Sanitizer::safeUrl((string)$in['data']['url'], ['http', 'https'])) !== null) {
                    $d['url'] = $s;
                }
                $set['data'] = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;
        }
        if ($set) {
            Db::update('blocks', $set, 'id = ?', [$id]);
        }
        $out = ['block' => $this->blockOut(Db::one('SELECT * FROM blocks WHERE id = ?', [$id]))] + $extra;
        if ($set && isset($set['content']) && $b['type'] === 'text') {
            $out['sanitized'] = $set['content'];
        }
        return $out;
    }

    private function blockDelete(array $in): array
    {
        $id = (int)($in['id'] ?? 0);
        $b = Db::one('SELECT * FROM blocks WHERE id = ?', [$id]);
        Db::exec('DELETE FROM blocks WHERE id = ?', [$id]);
        if ($b && $b['data']) {
            $d = json_decode($b['data'], true) ?: [];
            foreach ((array)($d['images'] ?? [$d]) as $x) {
                $mid = (int)($x['media_id'] ?? 0);
                if ($mid && !Uploads::usage($mid)['blocks']) {
                    Uploads::remove($mid);
                }
            }
        }
        return [];
    }

    private function blockReorder(array $in): array
    {
        $lid = (int)($in['lesson_id'] ?? 0);
        $i = 0;
        foreach ((array)($in['order'] ?? []) as $bid) {
            Db::exec('UPDATE blocks SET sort_order = ? WHERE id = ? AND lesson_id = ?', [++$i, (int)$bid, $lid]);
        }
        return [];
    }

    // ------------------------------------------------------------------ quizy
    private function createQuiz(string $title = 'Nowy quiz'): int
    {
        $qid = Db::insert('quizzes', ['title' => $title, 'pass_percent' => 70, 'max_attempts' => 0, 'created_at' => now()]);
        $q = Db::insert('quiz_questions', ['quiz_id' => $qid, 'type' => 'single', 'text' => '', 'sort_order' => 1]);
        Db::insertMany('quiz_answers', ['question_id', 'text', 'is_correct', 'sort_order'], [[$q, '', 1, 1], [$q, '', 0, 2]]);
        return $qid;
    }

    private function quizGet(array $in): array
    {
        $q = \App\Quiz::load((int)($in['id'] ?? 0), true);
        if (!$q) {
            throw new \InvalidArgumentException('Nie znaleziono quizu.');
        }
        return ['quiz' => [
            'id' => (int)$q['id'], 'title' => $q['title'], 'pass' => (int)$q['pass_percent'], 'tries' => (int)$q['max_attempts'],
            'questions' => array_map(fn($x) => ['id' => (int)$x['id'], 'type' => $x['type'], 'text' => $x['text'], 'answers' => array_map(fn($a) => ['id' => (int)$a['id'], 'text' => $a['text'], 'correct' => (bool)$a['is_correct']], $x['answers'])], $q['questions']),
        ]];
    }

    private function quizSave(array $in): array
    {
        $qid = (int)($in['id'] ?? 0);
        if (!Db::val('SELECT 1 FROM quizzes WHERE id = ?', [$qid])) {
            throw new \InvalidArgumentException('Nie znaleziono quizu.');
        }
        $tries = (int)($in['tries'] ?? 0);
        Db::tx(function () use ($qid, $in, $tries): void {
            Db::update('quizzes', [
                'title' => mb_substr(Sanitizer::text((string)($in['title'] ?? '')), 0, 300) ?: 'Quiz',
                'pass_percent' => max(0, min(100, (int)($in['pass'] ?? 70))),
                'max_attempts' => in_array($tries, [0, 1, 3], true) ? $tries : 0,
            ], 'id = ?', [$qid]);
            $keepQ = [];
            $sq = 0;
            foreach ((array)($in['questions'] ?? []) as $q) {
                $type = in_array($q['type'] ?? '', ['single', 'multi', 'text', 'file'], true) ? $q['type'] : 'single';
                $row = ['type' => $type, 'text' => Sanitizer::text((string)($q['text'] ?? ''), 2000), 'sort_order' => ++$sq];
                $id = (int)($q['id'] ?? 0);
                if ($id && Db::val('SELECT 1 FROM quiz_questions WHERE id = ? AND quiz_id = ?', [$id, $qid])) {
                    Db::update('quiz_questions', $row, 'id = ?', [$id]);
                } else {
                    $id = Db::insert('quiz_questions', $row + ['quiz_id' => $qid]);
                }
                $keepQ[] = $id;
                $keepA = [];
                $sa = 0;
                if ($type === 'single' || $type === 'multi') {
                    $seenCorrect = false;
                    foreach ((array)($q['answers'] ?? []) as $a) {
                        $ok = !empty($a['correct']);
                        if ($type === 'single') {
                            $ok = $ok && !$seenCorrect;
                            $seenCorrect = $seenCorrect || $ok;
                        }
                        $arow = ['text' => Sanitizer::text((string)($a['text'] ?? ''), 1000), 'is_correct' => $ok ? 1 : 0, 'sort_order' => ++$sa];
                        $aid = (int)($a['id'] ?? 0);
                        if ($aid && Db::val('SELECT 1 FROM quiz_answers WHERE id = ? AND question_id = ?', [$aid, $id])) {
                            Db::update('quiz_answers', $arow, 'id = ?', [$aid]);
                        } else {
                            $aid = Db::insert('quiz_answers', $arow + ['question_id' => $id]);
                        }
                        $keepA[] = $aid;
                    }
                }
                $in2 = $keepA ? ' AND id NOT IN (' . implode(',', $keepA) . ')' : '';
                Db::exec('DELETE FROM quiz_answers WHERE question_id = ?' . $in2, [$id]);
            }
            $in2 = $keepQ ? ' AND id NOT IN (' . implode(',', $keepQ) . ')' : '';
            Db::exec('DELETE FROM quiz_questions WHERE quiz_id = ?' . $in2, [$qid]);
        });
        return $this->quizGet(['id' => $qid]);
    }

    private function quizList(): array
    {
        $rows = Db::all("SELECT z.id, z.title, (SELECT COUNT(*) FROM quiz_questions q WHERE q.quiz_id = z.id) n,
            (SELECT COUNT(*) FROM blocks b WHERE b.type = 'quiz' AND b.data LIKE CONCAT('%\"quiz_id\":', z.id, '}%')) used FROM quizzes z ORDER BY z.id");
        return ['quizzes' => array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'questions' => (int)$r['n'], 'used' => (int)$r['used']], $rows)];
    }

    private function quizAttach(array $in): array
    {
        $bid = (int)($in['block_id'] ?? 0);
        $qid = (int)($in['quiz_id'] ?? 0);
        if (!Db::val("SELECT 1 FROM blocks WHERE id = ? AND type = 'quiz'", [$bid]) || !Db::val('SELECT 1 FROM quizzes WHERE id = ?', [$qid])) {
            throw new \InvalidArgumentException('Nieprawidłowy quiz.');
        }
        Db::update('blocks', ['data' => json_encode(['quiz_id' => $qid])], 'id = ?', [$bid]);
        return ['block' => $this->blockOut(Db::one('SELECT * FROM blocks WHERE id = ?', [$bid]))];
    }

    private function quizNew(array $in): array
    {
        $bid = (int)($in['block_id'] ?? 0);
        if (!Db::val("SELECT 1 FROM blocks WHERE id = ? AND type = 'quiz'", [$bid])) {
            throw new \InvalidArgumentException('Nieprawidłowy blok.');
        }
        Db::update('blocks', ['data' => json_encode(['quiz_id' => $this->createQuiz()])], 'id = ?', [$bid]);
        return ['block' => $this->blockOut(Db::one('SELECT * FROM blocks WHERE id = ?', [$bid]))];
    }
}
