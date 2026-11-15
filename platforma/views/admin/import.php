<div class="head"><div><h1>Import klientów</h1><p class="sub">Wgraj plik CSV lub Excel (XLSX). Konta powstaną automatycznie, a wybrane kursy zostaną przypisane.</p></div></div>
<div class="steps"><div class="step on"><i>1</i>Plik</div><div class="step"><i>2</i>Kolumny</div><div class="step"><i>3</i>Import</div></div>
<form method="post" action="<?= e(url('/admin/import/plik')) ?>" enctype="multipart/form-data" id="upload-form"><?= App\Csrf::field() ?>
  <div class="drop" id="drop"><?= icon('upload', 'class="i big"') ?><b>Przeciągnij plik tutaj</b><p class="muted">CSV, TSV lub XLSX, do <?= nf(App\Spreadsheet::MAX_ROWS) ?> wierszy. Kolumny: email, imie, nazwisko, kurs, dostep_do.</p>
  <label class="btn primary" for="file">Wybierz plik</label><input id="file" name="file" type="file" accept=".csv,.tsv,.txt,.xlsx" hidden></div>
</form>
<p class="muted small">Plik XLS (stary Excel) zapisz najpierw jako XLSX lub CSV. Kolumna „kurs” może zawierać kilka nazw po przecinku (dopasowanie po nazwie). Puste „dostep_do” oznacza czas dostępu z ustawień kursu.</p>
<?php if ($jobs): ?>
<div class="panel" style="margin-top:20px"><div class="panel-h"><b>Ostatnie importy</b></div><div class="tablewrap"><table><thead><tr><th>#</th><th>Plik</th><th>Wiersze</th><th>Status</th><th>Data</th><th></th></tr></thead><tbody>
<?php foreach ($jobs as $j): $p = json_decode((string)$j['payload'], true) ?: []; ?>
  <tr><td><?= (int)$j['id'] ?></td><td><?= e($p['orig'] ?? '') ?></td><td><?= nf((int)$j['total']) ?></td><td><?= e(['new' => 'czeka na ustawienia', 'running' => 'w toku', 'done' => 'zakończony', 'failed' => 'błąd'][$j['status']] ?? $j['status']) ?></td><td class="mono"><?= e(fmt_date($j['created_at'])) ?></td><td><a class="btn sm" href="<?= e(url('/admin/import/' . $j['id'])) ?>">Otwórz</a></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>
