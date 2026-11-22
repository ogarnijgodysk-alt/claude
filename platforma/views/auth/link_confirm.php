<form class="panel login" method="post">
  <?= App\Csrf::field() ?>
  <h1 style="font-size:22px">Zalogować jako <?= e($email) ?>?</h1>
  <p class="muted">Kliknij przycisk, żeby wejść do swoich kursów.</p>
  <button class="btn primary" type="submit" style="justify-content:center">Zaloguj się</button>
</form>
