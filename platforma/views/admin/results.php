<div class="head"><div><h1>Wyniki quizów</h1><p class="sub"><?= $pending ? '<b class="warnc">' . $pending . '</b> ' . pl($pending, 'podejście czeka', 'podejścia czekają', 'podejść czeka') . ' na ocenę ręczną' : 'Nic nie czeka na ocenę ręczną' ?></p></div>
  <div class="seg"><a class="btn sm" href="<?= e(url('/admin/wyniki')) ?>" style="<?= $only ? '' : 'font-weight:700' ?>">Wszystkie</a><a class="btn sm" href="<?= e(url('/admin/wyniki?f=review')) ?>" style="<?= $only ? 'font-weight:700' : '' ?>">Do oceny</a></div></div>
<?php if (!$rows): ?><div class="panel empty"><b>Brak podejść</b></div><?php endif; ?>
<div class="stack">
<?php foreach ($rows as $a): $given = $a['ans']['given'] ?? []; ?>
  <article class="panel pane">
    <div class="row"><b><?= e($a['full_name'] ?: $a['email']) ?></b><span class="muted small"><?= e($a['email']) ?></span><span class="sp"></span><span class="mono muted"><?= e(fmt_date($a['created_at'])) ?></span></div>
    <div class="row"><span><?= e($a['qtitle']) ?></span><span class="chip"><?= (int)$a['score_percent'] ?>%</span><?= $a['passed'] ? '<span class="ok">zaliczony</span>' : '<span class="muted">niezaliczony</span>' ?><?= $a['needs_review'] ? ($a['reviewed_at'] ? ' <span class="muted small">oceniony ręcznie</span>' : ' <b class="warnc">do oceny ręcznej</b>') : '' ?></div>
    <?php if ($a['needs_review'] && $a['quiz']): foreach ($a['quiz']['questions'] as $q): if (App\Quiz::isAuto($q)) { continue; } $g = $given[$q['id']] ?? []; ?>
      <div class="qq"><b class="small"><?= e($q['text']) ?></b>
      <?php if ($q['type'] === 'text'): ?><div style="white-space:pre-wrap"><?= e($g['text'] ?? '') ?: '<span class="muted">brak odpowiedzi</span>' ?></div>
      <?php else: ?><?= !empty($g['media_id']) ? '<a class="btn sm" href="' . e(url('/plik/' . (int)$g['media_id'])) . '">Pobierz: ' . e($g['name'] ?? 'plik') . '</a>' : '<span class="muted">brak pliku</span>' ?><?php endif; ?></div>
    <?php endforeach; ?>
      <?php if (!$a['reviewed_at']): ?><form class="row" method="post" action="<?= e(url('/admin/wyniki/' . $a['id'] . '/ocena')) ?>"><?= App\Csrf::field() ?><button class="btn sm primary" name="decision" value="pass">Zalicz</button><button class="btn sm" name="decision" value="fail">Nie zaliczaj</button><button class="btn sm ghost" name="decision" value="keep">Zostaw wynik automatyczny</button></form><?php endif; ?>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
</div>
