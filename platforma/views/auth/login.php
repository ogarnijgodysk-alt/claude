<form class="panel login" method="post" action="<?= e(url('/logowanie')) ?>">
  <?= App\Csrf::field() ?>
  <h1 style="font-size:22px">Zaloguj się</h1>
  <?php if ($notice !== ''): ?><p class="notice"><?= e($notice) ?></p><?php endif; ?>
  <?php if (!empty($flash)): ?><p class="notice okn" role="status"><?= e($flash['msg']) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="notice" role="alert"><?= e($error) ?></p><?php endif; ?>
  <p class="muted">Wpisz dane z e-maila z dostępem do kursów.</p>
  <div><label class="l" for="le">E-mail</label><input id="le" name="email" class="in" type="email" value="<?= e($email) ?>" autocomplete="username" required autofocus></div>
  <div><label class="l" for="lp">Hasło</label><input id="lp" name="password" class="in" type="password" autocomplete="current-password" required></div>
  <button class="btn primary" type="submit" style="justify-content:center">Zaloguj</button>
  <a class="btn" href="<?= e(url('/haslo')) ?>" style="justify-content:center">Pierwsze logowanie / nie pamiętam hasła</a>
</form>
