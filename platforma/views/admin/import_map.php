<?php $hdr = $p['header']; $fields = App\Jobs\ClientImport::FIELDS; ?>
<div class="head"><div><h1>Import klientów</h1><p class="sub"><?= e($p['orig'] ?? '') ?> · <?= nf((int)$job['total']) ?> wierszy · pierwszy wiersz to nagłówki</p></div></div>
<div class="steps"><div class="step done"><i>✓</i>Plik</div><div class="step on"><i>2</i>Kolumny</div><div class="step"><i>3</i>Import</div></div>
<form class="panel pane" method="post" action="<?= e(url('/admin/import/' . $job['id'])) ?>" id="map-form">
  <div><h3 style="margin-bottom:10px">Dopasuj kolumny</h3>
  <div class="map"><?php foreach ($fields as $f => $label): ?><span><?= e($label) ?></span>
    <select class="in" name="map[<?= e($f) ?>]" aria-label="Kolumna: <?= e($label) ?>" data-autosubmit>
      <option value="-1"<?= $map[$f] === -1 ? ' selected' : '' ?>>— pomiń —</option>
      <?php foreach ($hdr as $i => $h): ?><option value="<?= $i ?>"<?= $map[$f] === $i ? ' selected' : '' ?>><?= e($h !== '' ? $h : 'kolumna ' . ($i + 1)) ?></option><?php endforeach; ?>
    </select><?php endforeach; ?></div></div>
  <div><h3 style="margin-bottom:10px">Podgląd pierwszych wierszy</h3><div class="tablewrap"><table style="min-width:560px"><thead><tr><?php foreach ($hdr as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($preview as $row): ?><tr><?php foreach ($row as $v): ?><td><?= $v !== '' ? e($v) : '<span class="muted">—</span>' ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div></div>
  <div><h3 style="margin-bottom:10px">Wynik sprawdzenia</h3>
  <?php if (!$analysis): ?><p class="notice">Wskaż kolumnę z adresem e-mail, żeby sprawdzić plik.</p>
  <?php else: ?><ul class="res">
    <li><span class="ok"><?= icon('check') ?></span><b><?= nf($analysis['new']) ?></b> nowych klientów do dodania</li>
    <li><b class="mono" style="color:var(--warn)"><?= nf($analysis['existing'] + $analysis['dups']) ?></b> już jest w bazie lub powtarza się w pliku, zostaną pominięci</li>
    <li><b class="mono bad"><?= nf(count($analysis['invalid'])) ?></b> błędnych adresów e-mail<?php if ($analysis['invalid']): ?> (np. wiersz <?= (int)$analysis['invalid'][0][0] ?>: <?= e($analysis['invalid'][0][1] ?: 'pusty') ?>)<?php endif; ?></li>
    <?php if ($analysis['unknown_courses']): ?><li><b class="mono" style="color:var(--warn)"><?= nf(count($analysis['unknown_courses'])) ?></b> nazw kursów nie pasuje do żadnego kursu, np. „<?= e((string)array_key_first($analysis['unknown_courses'])) ?>” (te kursy nie zostaną przypisane)</li><?php endif; ?>
  </ul><?php endif; ?></div>
  <div><h3 style="margin-bottom:10px">Przypisz kursy wszystkim importowanym</h3><div class="checks">
    <?php foreach ($courses as $c): ?><label><input type="checkbox" name="courses[]" value="<?= (int)$c['id'] ?>"<?= in_array((int)$c['id'], $sel, true) ? ' checked' : '' ?>> <?= e($c['title']) ?></label><?php endforeach; ?></div>
    <p class="small muted" style="margin:8px 0 0">Kursy z kolumny „kurs” w pliku zostaną dopasowane po nazwie. Pusta data w kolumnie „dostep_do” oznacza czas dostępu z ustawień kursu.</p></div>
  <label class="inl"><input type="checkbox" name="mail" value="1"<?= $mail ? ' checked' : '' ?>> Wyślij każdemu e-mail z linkiem do ustawienia hasła</label>
  <p class="small muted" style="margin:0">Wiadomości są wysyłane w tle, w paczkach (ustawienie „E-maile na minutę” w Ustawieniach), przez cron.</p>
  <div class="row end"><a class="btn" href="<?= e(url('/admin/import')) ?>">Zmień plik</a><button class="btn" type="submit">Sprawdź ponownie</button>
    <button class="btn primary" type="submit" formaction="<?= e(url('/admin/import/start')) ?>" <?= (!$analysis || $analysis['new'] === 0) ? 'disabled' : '' ?>>Importuj <?= $analysis ? nf($analysis['new']) : '' ?> klientów</button></div>
  <input type="hidden" name="id" value="<?= (int)$job['id'] ?>"><?= App\Csrf::field() ?>
</form>
