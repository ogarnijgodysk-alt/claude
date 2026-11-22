<div class="head"><div><h1>Ustawienia</h1><p class="sub">Ekran logowania, wiadomości e-mail i limity.</p></div></div>
<form method="post" action="<?= e(url('/admin/ustawienia')) ?>"><?= App\Csrf::field() ?>
<div class="panel pane">
  <div><h3>Komunikat na ekranie logowania</h3><p class="small muted" style="margin:4px 0 0">Wyświetla się nad formularzem. Po okresie przejściowym możesz go wyłączyć.</p></div>
  <div><label class="l" for="lm">Sposób logowania klientów</label><select id="lm" name="login_mode" class="in"><option value="email"<?= $s['login_mode'] === 'email' ? ' selected' : '' ?>>Tylko adres e-mail, bez hasła i bez wysyłki (najmniej bezpieczne)</option><option value="magic"<?= $s['login_mode'] === 'magic' ? ' selected' : '' ?>>Link z e-maila, bez hasła (klient podaje tylko adres)</option><option value="password"<?= $s['login_mode'] === 'password' ? ' selected' : '' ?>>E-mail i hasło</option></select><p class="small muted" style="margin:4px 0 0">Administratorzy zawsze logują się hasłem. Link działa jeden raz i wygasa (ustawienie niżej).</p></div>
  <label class="inl"><input type="checkbox" name="login_notice_on" value="1"<?= $s['login_notice_on'] === '1' ? ' checked' : '' ?>> Pokaż komunikat</label>
  <div><label class="l" for="sn">Treść komunikatu</label><textarea id="sn" name="login_notice" class="in" rows="4"><?= e($s['login_notice']) ?></textarea></div>
  <div class="row"><a class="btn" href="<?= e(url('/logowanie')) ?>" target="_blank" rel="noopener">Zobacz ekran logowania</a></div>
</div>
<div class="panel pane" style="margin-top:16px">
  <div><h3>Hasła i e-mail</h3></div>
  <div class="row2"><div><label class="l" for="snd">Adres nadawcy wiadomości</label><input id="snd" name="mail_from" type="email" class="in" value="<?= e($s['mail_from']) ?>" placeholder="<?= e($from[0]) ?>"></div>
    <div><label class="l" for="sndn">Nazwa nadawcy</label><input id="sndn" name="mail_from_name" class="in" value="<?= e($s['mail_from_name']) ?>"></div></div>
  <div class="row2"><div><label class="l" for="ttl">Ważność linku do ustawienia hasła</label><select id="ttl" name="reset_ttl" class="in"><?php foreach ([30, 60, 120] as $v): ?><option value="<?= $v ?>"<?= (int)$s['reset_ttl'] === $v ? ' selected' : '' ?>><?= $v ?> minut</option><?php endforeach; ?></select></div>
    <div><label class="l" for="pm">E-maile na minutę (kolejka z crona)</label><input id="pm" name="mail_per_minute" type="number" min="1" max="600" class="in" value="<?= (int)$s['mail_per_minute'] ?>"></div></div>
  <p class="small muted" style="margin:0">Link działa jeden raz. Hasło musi mieć co najmniej 10 znaków. Wysyłka przez SMTP: <b><?= e($mail['transport'] === 'smtp' ? ($mail['host'] ?: 'nieskonfigurowany') : (string)$mail['transport']) ?></b> (konfiguracja w pliku <code>config/config.php</code>). Wymaga domeny nadawcy z rekordami SPF, DKIM i DMARC. Kolejka: oczekuje <?= (int)$queue['pending'] ?>, wysłano <?= nf($queue['sent']) ?>, błędów <?= (int)$queue['failed'] ?>.</p>
</div>
<div class="panel pane" style="margin-top:16px">
  <div><h3>Platforma</h3></div>
  <div class="row2"><div><label class="l" for="sitn">Nazwa platformy</label><input id="sitn" name="site_name" class="in" value="<?= e($s['site_name']) ?>"></div>
    <div><label class="l" for="up">Limit wgrywanego pliku (MB)</label><input id="up" name="upload_max_mb" type="number" min="1" max="2048" class="in" value="<?= (int)$s['upload_max_mb'] ?>"></div></div>
  <p class="small muted" style="margin:0">Faktyczny limit zależy też od serwera (PHP): teraz <?= e(fmt_size($maxUpload)) ?>.</p>
  <div><label class="l" for="ext">Adres przycisku „Przedłuż dostęp”</label><input id="ext" name="extend_url" class="in" value="<?= e($s['extend_url']) ?>" placeholder="https://… lub mailto:kontakt@…"><p class="small muted" style="margin:4px 0 0">Gdy puste, przycisk otwiera e-mail do adresu nadawcy.</p></div>
</div>
<div class="row end" style="margin-top:16px"><button class="btn primary" type="submit">Zapisz ustawienia</button></div>
</form>
<form class="panel pane" id="haslo" method="post" action="<?= e(url('/admin/haslo')) ?>" style="margin-top:24px"><?= App\Csrf::field() ?>
  <div><h3>Moje hasło (administrator)</h3><p class="small muted" style="margin:4px 0 0">Konto: <?= e($user['email']) ?>. Minimum 10 znaków. Po zmianie zostaniesz zalogowany, a inne sesje tego konta wygasną.</p></div>
  <div><label class="l" for="pc">Obecne hasło</label><input id="pc" name="current" class="in" type="password" autocomplete="current-password" required></div>
  <div class="row2"><div><label class="l" for="pn">Nowe hasło</label><input id="pn" name="password" class="in" type="password" autocomplete="new-password" minlength="10" required></div><div><label class="l" for="pn2">Powtórz nowe hasło</label><input id="pn2" name="password2" class="in" type="password" autocomplete="new-password" required></div></div>
  <div class="row end"><button class="btn primary" type="submit">Zmień hasło</button></div>
</form>
