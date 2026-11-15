<div class="head"><div><h1>Cześć<?= $user['first_name'] ? ', ' . e($user['first_name']) : '' ?></h1><p class="sub">Twoje kursy</p></div></div>
<?php if (!$courses): ?>
  <div class="panel empty"><b>Nie masz jeszcze przypisanych kursów</b><p>Jeśli kupiłeś kurs, a nie widzisz go tutaj, napisz do nas.</p></div>
<?php else: ?>
<div class="cards">
<?php foreach ($courses as $c): $ac = $c['acc']; ?>
  <article class="panel card<?= $ac['expired'] ? ' lock' : '' ?>">
    <div><h3><?= e($c['title']) ?></h3><?php if ($c['description']): ?><p class="sub"><?= e($c['description']) ?></p><?php endif; ?></div>
    <div>
      <div class="pbar" role="progressbar" aria-valuenow="<?= (int)$c['pct'] ?>" aria-valuemin="0" aria-valuemax="100"><i style="width:<?= (int)$c['pct'] ?>%"></i></div>
      <p class="small muted" style="margin-top:6px">Ukończono <?= (int)$c['done'] ?> z <?= (int)$c['total'] ?> <?= pl((int)$c['total'], 'lekcji', 'lekcji', 'lekcji') ?> · <?= (int)$c['pct'] ?>%</p>
      <?php if ((int)$c['done'] === 0 && $c['legacy_progress'] !== null && (int)$c['legacy_progress'] > 0): ?>
        <p class="small muted" style="margin-top:4px">Postęp z poprzedniej platformy: <?= (int)$c['legacy_progress'] ?>%<?= $c['legacy_total'] ? ' (' . (int)$c['legacy_done'] . ' z ' . (int)$c['legacy_total'] . ' lekcji)' : '' ?></p>
      <?php endif; ?>
      <p class="small <?= e($ac['cls']) ?> acc-l" style="margin-top:6px"><?= e($ac['text']) ?></p>
    </div>
    <div class="row">
      <?php if ($ac['expired']): ?>
        <?php if ($extendUrl !== ''): ?><a class="btn" href="<?= e($extendUrl) ?>" target="_blank" rel="noopener noreferrer">Przedłuż dostęp</a>
        <?php else: ?><a class="btn" href="mailto:<?= e(App\Mail\MailQueue::from()[0]) ?>?subject=<?= rawurlencode('Przedłużenie dostępu: ' . $c['title']) ?>">Przedłuż dostęp</a><?php endif; ?>
      <?php else: ?>
        <a class="btn primary" href="<?= e(url('/kurs/' . $c['id'])) ?>"><?= (int)$c['done'] ? 'Kontynuuj' : 'Zacznij kurs' ?></a>
        <?php if ($ac['state'] === 'soon' && $extendUrl !== ''): ?><a class="btn" href="<?= e($extendUrl) ?>" target="_blank" rel="noopener noreferrer">Przedłuż dostęp</a><?php endif; ?>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?php endif; ?>
