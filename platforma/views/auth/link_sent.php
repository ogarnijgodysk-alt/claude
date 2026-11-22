<div class="panel login">
  <h1 style="font-size:22px">Sprawdź skrzynkę</h1>
  <p class="muted">Jeśli adres <b><?= e($email) ?></b> jest w naszej bazie, wysłaliśmy na niego wiadomość z linkiem do logowania. Link jest ważny <?= (int)$ttl ?> minut i działa tylko raz.</p>
  <p class="small muted">Nie widzisz wiadomości? Zajrzyj do folderu Spam. Nowy link możesz zamówić po minucie.</p>
  <a class="lnk" href="<?= e(url('/logowanie')) ?>">Wróć do logowania</a>
</div>
