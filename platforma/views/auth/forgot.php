<?php if ($sent): ?>
<div class="panel login">
  <h1 style="font-size:22px">Sprawdź skrzynkę</h1>
  <p class="muted">Jeśli adres <b><?= e($email) ?></b> jest w naszej bazie, wysłaliśmy na niego wiadomość z linkiem do ustawienia hasła. Link jest ważny <?= (int)$ttl ?> minut i działa tylko raz.</p>
  <p class="small muted">Nie widzisz wiadomości? Zajrzyj do folderu Spam. Nowy link możesz zamówić po minucie.</p>
  <a class="lnk" href="<?= e(url('/logowanie')) ?>">Wróć do logowania</a>
</div>
<?php else: ?>
<form class="panel login" method="post" action="<?= e(url('/haslo')) ?>" novalidate>
  <?= App\Csrf::field() ?>
  <h1 style="font-size:22px">Pierwsze logowanie lub nowe hasło</h1>
  <p class="muted">Wpisz adres e-mail, na który kupiłeś kurs. Wyślemy na niego link do ustawienia hasła.</p>
  <div><label class="l" for="fe">E-mail</label><input id="fe" name="email" class="in" type="email" value="<?= e($email) ?>" autocomplete="username" required autofocus></div>
  <?php if ($error): ?><div class="bad small" role="alert"><?= e($error) ?></div><?php endif; ?>
  <button class="btn primary" type="submit" style="justify-content:center">Wyślij link</button>
  <a class="lnk" href="<?= e(url('/logowanie')) ?>">Wróć do logowania</a>
</form>
<?php endif; ?>
