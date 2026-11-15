<?php /** @var array $courses @var array $enr */ ?>
<div class="checks">
<?php foreach ($courses as $c):
  $on = isset($enr[(int)$c['id']]); $exp = $on ? ($enr[(int)$c['id']]['expires_at'] ?? '') : '';
  $def = App\Access::defaultExpiry($c) ?? ''; ?>
  <div class="arow" data-enroll-row>
    <label><input type="checkbox" name="courses[]" value="<?= (int)$c['id'] ?>" data-default="<?= e($def) ?>"<?= $on ? ' checked' : '' ?>> <?= e($c['title']) ?></label>
    <span class="adate"><span class="small muted">dostęp do</span><input class="in" type="date" name="exp[<?= (int)$c['id'] ?>]" value="<?= e($exp) ?>" aria-label="Dostęp do: <?= e($c['title']) ?>"></span>
  </div>
<?php endforeach; ?>
</div>
<p class="small muted" style="margin:6px 0 0">Puste pole daty oznacza dostęp bezterminowy. Po zaznaczeniu kursu data uzupełnia się według jego ustawień.</p>
