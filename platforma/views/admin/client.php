<div class="head"><div><a class="btn ghost sm" href="<?= e(url('/admin/klienci')) ?>"><?= icon('back') ?>Klienci</a><h1 style="margin-top:6px"><?= e($c['full_name'] ?: $c['email']) ?></h1><p class="sub"><?= e($c['email']) ?> · konto od <?= e(fmt_date($c['created_at'])) ?><?= $c['last_login_at'] ? ' · ostatnie logowanie ' . e(fmt_date($c['last_login_at'])) : ' · jeszcze się nie logował' ?><?= $c['password_hash'] ? '' : ' · hasło nieustawione' ?></p></div>
  <form method="post" action="<?= e(url('/admin/klienci/' . $c['id'] . '/zaproszenie')) ?>"><?= App\Csrf::field() ?><button class="btn" type="submit"><?= icon('mail') ?>Wyślij link do ustawienia hasła</button></form></div>
<form class="panel pane" method="post" action="<?= e(url('/admin/klienci/' . $c['id'])) ?>">
  <?= App\Csrf::field() ?>
  <div class="row2"><div><label class="l" for="f1">Imię</label><input id="f1" class="in" name="first_name" value="<?= e($c['first_name']) ?>"></div><div><label class="l" for="f2">Nazwisko</label><input id="f2" class="in" name="last_name" value="<?= e($c['last_name']) ?>"></div></div>
  <div class="row2"><div><label class="l" for="f3">E-mail</label><input id="f3" class="in" type="email" name="email" value="<?= e($c['email']) ?>" required></div>
    <div><label class="l" for="f4">Rola</label><select id="f4" class="in" name="role"><option value="client"<?= $c['role'] === 'client' ? ' selected' : '' ?>>klient</option><option value="admin"<?= $c['role'] === 'admin' ? ' selected' : '' ?>>administrator</option></select></div></div>
  <div><h3 style="margin-bottom:10px">Kursy i daty dostępu</h3><?= App\View::partial('admin/enroll_rows', ['courses' => $courses, 'enr' => $enr]) ?></div>
  <?php $legacy = array_filter($enr, fn($e) => $e['legacy_progress'] !== null); if ($legacy): ?>
    <details><summary>Postęp z poprzedniej platformy (informacyjnie)</summary><ul class="small muted"><?php foreach ($legacy as $cid => $e): foreach ($courses as $cc) { if ((int)$cc['id'] === $cid) { echo '<li>' . e($cc['title']) . ': ' . (int)$e['legacy_progress'] . '% (' . (int)$e['legacy_done'] . ' z ' . (int)$e['legacy_total'] . ' lekcji)</li>'; } } endforeach; ?></ul></details>
  <?php endif; ?>
  <div class="row end"><button class="btn primary" type="submit">Zapisz</button></div>
</form>
<?php if ($attempts): ?>
<div class="panel" style="margin-top:16px"><div class="panel-h"><b>Podejścia do quizów</b></div><div class="tablewrap"><table><thead><tr><th>Quiz</th><th>Wynik</th><th>Status</th><th>Data</th></tr></thead><tbody>
<?php foreach ($attempts as $a): ?><tr><td><?= e($a['title']) ?></td><td><?= $a['score_percent'] === null ? '—' : (int)$a['score_percent'] . '%' ?></td><td><?= $a['passed'] ? '<span class="ok">zaliczony</span>' : 'niezaliczony' ?><?= $a['needs_review'] ? ' · <b class="warnc">do oceny</b>' : '' ?></td><td class="mono"><?= e(fmt_date($a['created_at'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
