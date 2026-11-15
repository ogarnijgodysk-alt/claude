<form class="panel login" method="post" novalidate>
  <?= App\Csrf::field() ?>
  <h1 style="font-size:22px">Ustaw nowe hasło</h1>
  <p class="muted">Konto: <b><?= e($email) ?></b></p>
  <div><label class="l" for="p1">Nowe hasło</label><input id="p1" name="password" class="in" type="password" autocomplete="new-password" minlength="10" required autofocus><p class="small muted" style="margin-top:4px">Co najmniej 10 znaków.</p></div>
  <div><label class="l" for="p2">Powtórz hasło</label><input id="p2" name="password2" class="in" type="password" autocomplete="new-password" required></div>
  <?php if ($error): ?><div class="bad small" role="alert"><?= e($error) ?></div><?php endif; ?>
  <button class="btn primary" type="submit" style="justify-content:center">Zapisz hasło</button>
</form>
