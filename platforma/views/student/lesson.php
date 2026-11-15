<a class="btn ghost sm" href="<?= e(url('/moje-kursy')) ?>"><?= icon('back') ?>Moje kursy</a>
<?php if (!$lesson): ?>
  <div class="panel empty" style="margin-top:12px"><b>Ten kurs nie ma jeszcze lekcji</b></div>
<?php else:
  $doneCount = count(array_filter($tree ? array_merge(...array_map(fn($m) => $m['lessons'], $tree)) : [], fn($l) => isset($done[$l['id']])));
  $isDone = isset($done[$lesson['id']]);
?>
<div class="player">
  <main>
    <div><h1><?= e($lesson['title']) ?></h1><p class="sub"><?= e($course['title']) ?> · lekcja <?= $idx + 1 ?> z <?= (int)$n ?></p></div>
    <?= $blocks ?>
    <?php if (!empty($isEmpty)): ?><div class="panel empty">Ta lekcja nie ma jeszcze treści.</div><?php endif; ?>
    <div class="row">
      <?php if ($prev): ?><a class="btn" href="<?= e(url('/kurs/' . $course['id'] . '/lekcja/' . $prev['id'])) ?>"><?= icon('back') ?>Poprzednia</a><?php else: ?><span class="btn" aria-disabled="true" style="opacity:.45"><?= icon('back') ?>Poprzednia</span><?php endif; ?>
      <form method="post" action="<?= e(url('/lekcja/' . $lesson['id'] . '/ukoncz')) ?>"><?= App\Csrf::field() ?>
        <button class="btn<?= $isDone ? '' : ' primary' ?>" type="submit"><?= $isDone ? icon('check') . 'Ukończona (cofnij)' : 'Oznacz jako ukończoną' ?></button></form>
      <?php if ($next): ?><a class="btn" href="<?= e(url('/kurs/' . $course['id'] . '/lekcja/' . $next['id'])) ?>">Następna<?= icon('next') ?></a><?php else: ?><span class="btn" aria-disabled="true" style="opacity:.45">Następna<?= icon('next') ?></span><?php endif; ?>
    </div>
  </main>
  <aside class="panel toc">
    <div class="panel-h"><b>Program kursu</b><span class="mono muted"><?= $doneCount ?>/<?= (int)$n ?></span></div>
    <?php foreach ($tree as $m): ?>
      <div class="m"><div class="mt"><?= e($m['title']) ?></div>
      <?php foreach ($m['lessons'] as $x): $d = isset($done[$x['id']]); ?>
        <a class="t<?= (int)$x['id'] === (int)$lesson['id'] ? ' cur' : '' ?>" href="<?= e(url('/kurs/' . $course['id'] . '/lekcja/' . $x['id'])) ?>" style="text-decoration:none;color:inherit"><span class="tick<?= $d ? ' done' : '' ?>"><?= $d ? icon('check') : '' ?></span><span><?= e($x['title']) ?></span></a>
      <?php endforeach; ?></div>
    <?php endforeach; ?>
  </aside>
</div>
<?php endif; ?>
