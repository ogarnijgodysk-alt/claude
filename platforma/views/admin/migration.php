<div class="head"><div><h1>Migracja z Publigo</h1><p class="sub">Raport zgodności (źródło a nowa baza), pliki z CDN i polecenia.</p></div></div>
<div class="panel pane">
  <h3>Raport zgodności</h3>
  <?php if (!$reports): ?><p class="muted">Nie ma jeszcze raportu. Uruchom import z linii poleceń (poniżej).</p>
  <?php else: ?><div class="row"><a class="btn primary" href="<?= e(url('/admin/migracja/raport')) ?>" target="_blank" rel="noopener">Otwórz najnowszy raport</a>
    <a class="btn" href="<?= e(url('/admin/migracja/raport?plik=' . rawurlencode(str_replace('.html', '.json', $reports[0])))) ?>">JSON</a>
    <a class="btn" href="<?= e(url('/admin/migracja/raport?plik=' . rawurlencode(str_replace('raport-zgodnosci', 'pliki-do-pobrania', str_replace('.html', '.csv', $reports[0]))))) ?>">Lista plików (CSV)</a></div>
    <p class="small muted">Dostępne raporty: <?= e(implode(', ', $reports)) ?></p><?php endif; ?>
</div>
<div class="panel pane" style="margin-top:16px">
  <h3>Pliki z cdn.idealms.net i innych domen</h3>
  <p>Przepięte na własny serwer: <b class="ok"><?= nf($local) ?></b> · nadal zdalne (do pobrania): <b class="<?= $remote ? 'bad' : 'ok' ?>"><?= nf($remote) ?></b></p>
  <p class="small muted">Pobierz pliki <b>przed wyłączeniem Publigo</b> skryptem <code>php bin/pobierz-pliki.php</code> (szczegóły w instrukcji instalacji).</p>
</div>
<div class="panel pane" style="margin-top:16px">
  <h3>Polecenia (na serwerze)</h3>
<pre class="mono" style="white-space:pre-wrap;margin:0">php bin/import-publigo.php --dir=/sciezka/do/paczki
php bin/import-publigo.php --dir=/sciezka/do/paczki --report-only
php bin/pobierz-pliki.php --probe
php bin/pobierz-pliki.php</pre>
</div>
