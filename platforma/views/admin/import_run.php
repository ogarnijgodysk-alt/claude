<?php $running = !$state['finished']; $pct = $state['total'] ? (int)floor($state['done'] / $state['total'] * 100) : 0; ?>
<div class="head"><div><h1>Import klientów</h1><p class="sub"><?= e($p['orig'] ?? '') ?></p></div></div>
<div class="steps"><div class="step done"><i>✓</i>Plik</div><div class="step done"><i>✓</i>Kolumny</div><div class="step <?= $running ? 'on' : 'done' ?>"><i><?= $running ? 3 : '✓' ?></i>Import</div></div>
<div class="panel pane" id="run" data-tick="<?= e(url('/admin/import/' . $job['id'] . '/tick')) ?>" data-csrf="<?= e(App\Csrf::token()) ?>" data-running="<?= $running ? '1' : '0' ?>">
  <div id="run-head"><h3><?= $running ? 'Trwa import…' : 'Gotowe' ?></h3>
  <p class="muted" id="run-text" style="margin:6px 0 0"><?= $running ? 'Przetworzono ' . nf($state['done']) . ' z ' . nf($state['total']) : 'Dodano ' . nf($state['added']) . ' klientów, pominięto ' . nf($state['skipped']) . ' (już w bazie), błędnych wierszy: ' . nf($state['errors']) . '.' ?></p></div>
  <div class="progress" role="progressbar" aria-label="Postęp importu" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><i id="run-bar" style="width:<?= $running ? $pct : 100 ?>%"></i></div>
  <p class="small muted" id="run-note"><?= $running ? 'Możesz zostawić tę kartę otwartą. Jeśli ją zamkniesz, import dokończy się w tle (cron).' : (!empty($p['mail']) ? 'E-maile z linkiem do ustawienia hasła trafiły do kolejki wysyłki.' : '') ?></p>
  <div class="row" id="run-actions" <?= $running ? 'hidden' : '' ?>>
    <a class="btn primary" href="<?= e(url('/admin/klienci')) ?>">Zobacz klientów</a><a class="btn" href="<?= e(url('/admin/import')) ?>">Importuj kolejny plik</a>
    <?php if ($state['errors'] || $state['warnings']): ?><a class="btn" href="<?= e(url('/admin/import/' . $job['id'] . '/bledy')) ?>">Pobierz listę błędów (<?= nf($state['errors'] + $state['warnings']) ?>)</a><?php endif; ?></div>
</div>
