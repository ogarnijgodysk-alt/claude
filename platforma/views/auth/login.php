<div class="stack">
<?php if ($notice !== ''): ?><p class="notice"><?= e($notice) ?></p><?php endif; ?>
<?php if (!empty($flash)): ?><p class="notice okn" role="status"><?= e($flash['msg']) ?></p><?php endif; ?>
<?php $pwOnly = $mode === 'password'; $magic = !$pwOnly; $isEmail = $mode === 'email'; ?>
<?php if ($magic): ?>
<form class="panel login" method="post" action="<?= e(url($isEmail ? '/logowanie/email' : '/logowanie/link')) ?>">
  <?= App\Csrf::field() ?>
  <h1 style="font-size:22px">Zaloguj się</h1>
  <?php if ($linkError): ?><p class="notice" role="alert"><?= e($linkError) ?></p><?php endif; ?>
  <p class="muted"><?= $isEmail ? 'Wpisz adres e-mail, na który kupiłeś kurs. Hasło nie jest potrzebne.' : 'Wpisz adres e-mail, na który kupiłeś kurs. Wyślemy na niego link, który zaloguje Cię bez hasła.' ?></p>
  <div><label class="l" for="me">E-mail</label><input id="me" name="email" class="in" type="email" value="<?= $showPw ? '' : e($email) ?>" autocomplete="username" required<?= $showPw ? '' : ' autofocus' ?>></div>
  <button class="btn primary" type="submit" style="justify-content:center"><?= $isEmail ? 'Wejdź do kursów' : 'Wyślij link do logowania' ?></button>
</form>
<details class="panel login"<?= $showPw ? ' open' : '' ?>><summary class="lnk" style="cursor:pointer">Mam hasło (administratorzy)</summary>
<?php endif; ?>
<form class="<?= $magic ? 'stack' : 'panel login' ?>" method="post" action="<?= e(url('/logowanie')) ?>" style="<?= $magic ? 'margin-top:12px' : '' ?>">
  <?= App\Csrf::field() ?>
  <?php if (!$magic): ?><h1 style="font-size:22px">Zaloguj się</h1><p class="muted">Wpisz dane z e-maila z dostępem do kursów.</p><?php endif; ?>
  <?php if ($error): ?><p class="notice" role="alert"><?= e($error) ?></p><?php endif; ?>
  <div><label class="l" for="le">E-mail</label><input id="le" name="email" class="in" type="email" value="<?= e($email) ?>" autocomplete="username" required></div>
  <div><label class="l" for="lp">Hasło</label><input id="lp" name="password" class="in" type="password" autocomplete="current-password" required></div>
  <button class="btn<?= $magic ? '' : ' primary' ?>" type="submit" style="justify-content:center">Zaloguj hasłem</button>
  <?php if (!$magic): ?><a class="btn" href="<?= e(url('/haslo')) ?>" style="justify-content:center">Pierwsze logowanie / nie pamiętam hasła</a><?php endif; ?>
</form>
<?php if ($magic): ?></details><?php endif; ?>
</div>
