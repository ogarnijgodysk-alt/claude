<?php
use App\Quiz;
$quiz = $quizId ? Quiz::load($quizId, true) : null;
if (!$quiz || !$quiz['questions']) { return; }
$last = Quiz::lastAttempt((int)$user['id'], (int)$quiz['id']);
$canRetry = Quiz::canAttempt($quiz, (int)$user['id']);
$retry = isset($_GET['retry']) && (int)$_GET['retry'] === (int)$block['id'] && $canRetry;
$showResult = $last && !$retry;
$ans = $showResult ? (json_decode((string)$last['answers'], true) ?: []) : [];
$given = $ans['given'] ?? [];
$det = $ans['det'] ?? [];
$nq = count($quiz['questions']);
$hints = ['single' => 'wybierz jedną', 'multi' => 'wybierz wszystkie poprawne', 'text' => 'odpowiedź opisowa', 'file' => 'prześlij plik'];
?>
<section class="panel quiz max65" id="quiz-<?= (int)$block['id'] ?>">
  <div class="panel-h"><b><?= icon('quiz', 'style="vertical-align:-3px;margin-right:6px"') ?><?= e($quiz['title']) ?></b><span class="mono muted"><?= $nq ?> <?= pl($nq, 'pytanie', 'pytania', 'pytań') ?> · próg <?= (int)$quiz['pass_percent'] ?>%</span></div>
  <form class="qbody" method="post" action="<?= e(url('/quiz/' . $block['id'] . '/wyslij')) ?>" enctype="multipart/form-data">
    <?= App\Csrf::field() ?>
    <?php foreach ($quiz['questions'] as $i => $q): $qid = (int)$q['id']; $auto = Quiz::isAuto($q);
      $cls = $showResult && $auto ? (!empty($det[$qid]) ? ' good' : ' wrong') : ''; ?>
      <fieldset class="qs<?= $cls ?>"><legend><?= $i + 1 ?>. <?= e($q['text']) ?> <small>(<?= e($hints[$q['type']] ?? '') ?>)</small></legend>
      <?php if ($auto): $sel = array_map('intval', (array)($given[$qid] ?? []));
        foreach ($q['answers'] as $a): ?>
        <label><input type="<?= $q['type'] === 'single' ? 'radio' : 'checkbox' ?>" name="q[<?= $qid ?>][]" value="<?= (int)$a['id'] ?>"<?= in_array((int)$a['id'], $sel, true) ? ' checked' : '' ?><?= $showResult ? ' disabled' : ' required' ?>><span><?= e($a['text']) ?></span></label>
      <?php endforeach;
        if ($showResult): ?>
          <?php if (!empty($det[$qid])): ?><div class="ok small"><?= icon('check') ?> Dobrze</div>
          <?php else: ?><div class="bad small">Niepoprawnie. Poprawna odpowiedź: <?= e(implode('; ', array_map(fn($a) => $a['text'], array_filter($q['answers'], fn($a) => (int)$a['is_correct'] === 1)))) ?></div><?php endif; ?>
        <?php endif;
      elseif ($q['type'] === 'text'): ?>
        <textarea class="in" rows="3" name="q[<?= $qid ?>]" aria-label="Twoja odpowiedź"<?= $showResult ? ' disabled' : '' ?>><?= e($given[$qid]['text'] ?? '') ?></textarea>
        <?php if ($showResult): ?><div class="small muted">Ta odpowiedź trafiła do oceny ręcznej.</div><?php endif; ?>
      <?php else: ?>
        <?php if (!$showResult): ?><input type="file" name="qf[<?= $qid ?>]" aria-label="Wybierz plik" accept=".pdf,.zip,.docx,.xlsx,.pptx,.jpg,.jpeg,.png,.mp4">
        <?php else: ?><span class="small muted"><?= !empty($given[$qid]['name']) ? 'Przesłano: ' . e($given[$qid]['name']) : 'Brak pliku' ?></span><div class="small muted">Ta odpowiedź trafiła do oceny ręcznej.</div><?php endif; ?>
      <?php endif; ?>
      </fieldset>
    <?php endforeach; ?>
    <?php if ($showResult):
      $total = 0; foreach ($quiz['questions'] as $q) { if (Quiz::isAuto($q)) { $total++; } }
      $good = count(array_filter($det)); $manual = $nq - $total; ?>
      <div class="qres <?= $last['passed'] ? 'pass' : 'fail' ?>" role="status">Wynik: <?= (int)$last['score_percent'] ?>%<?= $total ? ' (' . $good . ' z ' . $total . ' pytań zamkniętych)' : '' ?>. <?= $last['passed'] ? 'Quiz zaliczony.' : 'Próg zaliczenia to ' . (int)$quiz['pass_percent'] . '%.' . ($canRetry ? '' : ' Wykorzystano limit podejść.') ?><?= $manual ? ' Odpowiedzi do ręcznej oceny: ' . $manual . '.' : '' ?></div>
      <?php if (!$last['passed'] && $canRetry): ?><div class="row"><a class="btn" href="?retry=<?= (int)$block['id'] ?>#quiz-<?= (int)$block['id'] ?>">Spróbuj ponownie</a></div><?php endif; ?>
    <?php elseif ($canRetry): ?>
      <div class="row"><button class="btn primary" type="submit">Sprawdź odpowiedzi</button></div>
    <?php else: ?><p class="muted">Wykorzystano limit podejść.</p><?php endif; ?>
  </form>
</section>
