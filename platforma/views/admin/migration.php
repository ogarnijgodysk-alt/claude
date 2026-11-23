<div class="head"><div><h1>Migracja z Publigo</h1><p class="sub">Raport zgodności (źródło a nowa baza), pliki z CDN i polecenia.</p></div></div>
<div class="panel pane" style="margin-bottom:16px">
  <h3>Import paczki z Publigo</h3>
  <p class="small muted" style="margin:0">W bazie: <b><?= nf($users) ?></b> kont i <b><?= nf($enroll) ?></b> przypisań. Etap 1 to kursy i quizy (<code>kursy.json</code>, <code>quizy.json</code>), etap 2 to kursanci (<code>kursanci.csv</code>, <code>dostepy.csv</code>). Raport zgodności potrzebuje wszystkich czterech plików obecnych naraz.</p>
  <form method="post" action="<?= e(url('/admin/migracja/pliki')) ?>" enctype="multipart/form-data" class="stack"><?= App\Csrf::field() ?>
    <?php foreach ($present as $nm => $sz): ?>
      <div class="row"><label class="l" style="margin:0;min-width:150px" for="f-<?= e($nm) ?>"><?= e($nm) ?></label><input id="f-<?= e($nm) ?>" type="file" name="<?= e(str_replace('.', '_', $nm)) ?>"><span class="small <?= $sz === null ? 'muted' : 'ok' ?>"><?= $sz === null ? 'brak na serwerze' : 'jest (' . e(fmt_size((int)$sz)) . ')' ?></span></div>
    <?php endforeach; ?>
    <div class="row"><button class="btn" type="submit">Wgraj wybrane pliki</button></div>
  </form>
  <form method="post" action="<?= e(url('/admin/migracja/import')) ?>" class="row"><?= App\Csrf::field() ?>
    <button class="btn primary" name="only" value="courses" <?= ($present['kursy.json'] && $present['quizy.json']) ? '' : 'disabled' ?>>Importuj kursy i quizy (etap 1)</button>
    <button class="btn primary" name="only" value="users" <?= ($present['kursanci.csv'] && $present['dostepy.csv']) ? '' : 'disabled' ?>>Importuj kursantów i dostępy (etap 2)</button>
  </form>
  <form method="post" action="<?= e(url('/admin/migracja/usun')) ?>" class="row"><?= App\Csrf::field() ?><button class="btn" type="submit" data-arm title="Usuń pliki paczki z serwera">Usuń pliki paczki z serwera</button></form>
  <p class="small muted" style="margin:0">Import jest bezpieczny do powtórzenia: istniejące kursy są pomijane, a konta i przypisania uzupełniane (daty dostępu już istniejących przypisań nie są nadpisywane). Po zakończeniu usuń pliki paczki.</p>
</div>
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
