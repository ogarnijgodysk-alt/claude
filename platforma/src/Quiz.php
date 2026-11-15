<?php
declare(strict_types=1);

namespace App;

final class Quiz
{
    public static function load(int $quizId, bool $withCorrect = true): ?array
    {
        $q = Db::one('SELECT * FROM quizzes WHERE id = ?', [$quizId]);
        if (!$q) {
            return null;
        }
        $qs = Db::all('SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY sort_order, id', [$quizId]);
        $ids = array_column($qs, 'id');
        $ans = [];
        if ($ids) {
            foreach (Db::all('SELECT * FROM quiz_answers WHERE question_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY sort_order, id') as $a) {
                if (!$withCorrect) {
                    unset($a['is_correct']);
                }
                $ans[$a['question_id']][] = $a;
            }
        }
        foreach ($qs as &$x) {
            $x['answers'] = $ans[$x['id']] ?? [];
        }
        $q['questions'] = $qs;
        return $q;
    }

    public static function isAuto(array $q): bool
    {
        return $q['type'] === 'single' || $q['type'] === 'multi';
    }

    /**
     * Ocena pytań zamkniętych. $given: question_id => [answer_id...] lub string (opisowe) lub ['file'=>nazwa].
     * @return array{pct:int,good:int,total:int,pass:bool,det:array<int,bool>,manual:int}
     */
    public static function grade(array $quiz, array $given): array
    {
        $good = 0;
        $total = 0;
        $manual = 0;
        $det = [];
        foreach ($quiz['questions'] as $q) {
            if (!self::isAuto($q)) {
                $manual++;
                continue;
            }
            $total++;
            $sel = array_map('intval', (array)($given[$q['id']] ?? []));
            sort($sel);
            $cor = [];
            foreach ($q['answers'] as $a) {
                if ((int)$a['is_correct'] === 1) {
                    $cor[] = (int)$a['id'];
                }
            }
            sort($cor);
            $ok = $sel === $cor && $cor !== [];
            $det[(int)$q['id']] = $ok;
            if ($ok) {
                $good++;
            }
        }
        $pct = $total > 0 ? (int)round($good / $total * 100) : 100;
        return ['pct' => $pct, 'good' => $good, 'total' => $total, 'pass' => $pct >= (int)$quiz['pass_percent'], 'det' => $det, 'manual' => $manual];
    }

    public static function attempts(int $userId, int $quizId): int
    {
        return (int)Db::val('SELECT COUNT(*) FROM quiz_attempts WHERE user_id = ? AND quiz_id = ?', [$userId, $quizId]);
    }

    public static function lastAttempt(int $userId, int $quizId): ?array
    {
        return Db::one('SELECT * FROM quiz_attempts WHERE user_id = ? AND quiz_id = ? ORDER BY id DESC LIMIT 1', [$userId, $quizId]);
    }

    public static function canAttempt(array $quiz, int $userId): bool
    {
        $max = (int)$quiz['max_attempts'];
        return $max === 0 || self::attempts($userId, (int)$quiz['id']) < $max;
    }
}
