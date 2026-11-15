<div class="head"><div><h1>Kursy</h1><p class="sub"><?= count($courses) ?> <?= pl(count($courses), 'kurs', 'kursy', 'kursów') ?> · <?= nf($nClients) ?> klientów w bazie</p></div>
  <button class="btn primary" type="button" data-open="new-course"><?= icon('plus') ?>Nowy kurs</button></div>
<dialog id="new-course" class="modal-d"><form method="post" action="<?= e(url('/admin/kursy')) ?>" class="stack"><?= App\Csrf::field() ?>
  <h2>Nowy kurs</h2><div><label class="l" for="nct">Nazwa kursu</label><input id="nct" name="title" class="in" placeholder="np. Copywriting dla początkujących" required maxlength="300"></div>
  <div class="row end"><button class="btn" type="button" data-close>Anuluj</button><button class="btn primary" type="submit">Utwórz kurs</button></div></form></dialog>
<div class="cards">
<?php foreach ($courses as $c): ?>
  <article class="panel card">
    <div><h3><?= e($c['title']) ?></h3><p class="sub"><?= e($c['description'] ?: 'Bez opisu') ?></p></div>
    <div class="stats"><div><b><?= (int)$c['n_modules'] ?></b><?= pl((int)$c['n_modules'], 'moduł', 'moduły', 'modułów') ?></div><div><b><?= (int)$c['n_lessons'] ?></b><?= pl((int)$c['n_lessons'], 'lekcja', 'lekcje', 'lekcji') ?></div><div><b><?= nf((int)$c['n_students']) ?></b>uczniów</div></div>
    <p class="small muted acc-l"><?= e(App\Access::label($c)) ?></p>
    <div class="row"><a class="btn primary" href="<?= e(url('/admin/kurs/' . $c['id'])) ?>">Edytuj kurs</a><a class="btn" href="<?= e(url('/kurs/' . $c['id'])) ?>">Podgląd ucznia</a></div>
  </article>
<?php endforeach; ?>
</div>
