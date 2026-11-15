<div class="head"><div><h1>Quizy</h1><p class="sub">Biblioteka quizów. Quiz podpinasz w lekcji blokiem „Quiz” (w edytorze kursu).</p></div></div>
<div class="panel"><div class="tablewrap"><table><thead><tr><th>#</th><th>Quiz</th><th>Pytań</th><th>Próg</th><th>Podejścia</th><th>Użyty w lekcjach</th><th>Wyniki</th></tr></thead><tbody>
<?php foreach ($rows as $z): ?>
  <tr><td class="mono"><?= (int)$z['id'] ?></td><td><b><?= e($z['title']) ?></b><?= $z['publigo_quiz_id'] ? ' <span class="tag2">z Publigo #' . e($z['publigo_quiz_id']) . '</span>' : '' ?></td>
  <td><?= (int)$z['n'] ?></td><td><?= (int)$z['pass_percent'] ?>%</td><td><?= $z['max_attempts'] ? (int)$z['max_attempts'] : 'bez limitu' ?></td>
  <td><?php if (!$z['lessons']): ?><span class="chip none">niepodpięty</span><?php else: foreach ($z['lessons'] as $l): ?><a class="chip" href="<?= e(url('/admin/lekcja/' . $l['lid'])) ?>" style="text-decoration:none"><?= e($l['ctitle']) ?> › <?= e($l['title']) ?></a><?php endforeach; endif; ?></td>
  <td><?= (int)$z['attempts'] ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="empty">Brak quizów.</td></tr><?php endif; ?></tbody></table></div></div>
