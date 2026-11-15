<?php
$qs = fn(array $extra = []) => http_build_query(array_filter(['q' => $q, 'kurs' => $cf] + $extra, fn($v) => $v !== '' && $v !== null));
?>
<div class="head"><div><h1>Klienci</h1><p class="sub"><?= nf($all) ?> klientów w bazie<?= $total !== $all ? ' · pasuje ' . nf($total) : '' ?></p></div>
  <div class="row"><a class="btn" href="<?= e(url('/admin/import')) ?>"><?= icon('upload') ?>Importuj z pliku</a><button class="btn primary" type="button" data-open="add-client"><?= icon('plus') ?>Dodaj klienta</button></div></div>

<dialog id="add-client" class="modal-d"><form method="post" action="<?= e(url('/admin/klienci/dodaj')) ?>" class="stack"><?= App\Csrf::field() ?>
  <h2>Dodaj klienta</h2>
  <div class="row2"><div><label class="l" for="nf">Imię</label><input id="nf" name="first_name" class="in" required></div><div><label class="l" for="nl">Nazwisko</label><input id="nl" name="last_name" class="in" required></div></div>
  <div><label class="l" for="ne">E-mail</label><input id="ne" name="email" class="in" type="email" placeholder="klient@example.com" required></div>
  <div><span class="l">Kursy do przypisania</span><?= App\View::partial('admin/enroll_rows', ['courses' => App\Db::all('SELECT id, title, access_amount, access_unit FROM courses ORDER BY sort_order, id'), 'enr' => []]) ?></div>
  <label class="inl"><input type="checkbox" name="send_mail" value="1" checked> Wyślij e-mail z linkiem do ustawienia hasła</label>
  <div class="row end"><button class="btn" type="button" data-close>Anuluj</button><button class="btn primary" type="submit">Dodaj klienta</button></div></form></dialog>

<div class="panel">
  <form class="bar" method="get" action="<?= e(url('/admin/klienci')) ?>">
    <div class="searchw"><?= icon('search') ?><input class="in" name="q" type="search" placeholder="Szukaj po imieniu, nazwisku lub e-mailu" value="<?= e($q) ?>" aria-label="Szukaj klienta"></div>
    <select class="in sel-w" name="kurs" aria-label="Filtr kursu" data-autosubmit>
      <option value="">Wszystkie kursy</option>
      <?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $cf === (string)$c['id'] ? ' selected' : '' ?>><?= e($c['title']) ?></option><?php endforeach; ?>
      <option value="expired"<?= $cf === 'expired' ? ' selected' : '' ?>>Wygasły dostęp</option>
      <option value="none"<?= $cf === 'none' ? ' selected' : '' ?>>Bez kursu</option>
    </select>
    <button class="btn" type="submit">Szukaj</button>
  </form>
  <form method="post" action="<?= e(url('/admin/klienci-hurt')) ?>" id="bulk-form" data-total="<?= (int)$total ?>">
    <?= App\Csrf::field() ?>
    <input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="kurs" value="<?= e($cf) ?>"><input type="hidden" name="all" value="0" id="bulk-all">
    <div class="bulk" id="bulk-bar" hidden>
      <b id="bulk-count">Zaznaczono 0</b>
      <button class="lnk" type="button" id="bulk-select-all" data-total="<?= (int)$total ?>">Zaznacz wszystkich pasujących (<?= nf($total) ?>)</button>
      <span class="sp"></span>
      <select class="in sel-w" name="course_id" aria-label="Kurs do przypisania"><?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['title']) ?></option><?php endforeach; ?></select>
      <button class="btn sm primary" type="submit" name="op" value="add">Przypisz kurs</button>
      <button class="btn sm" type="submit" name="op" value="del" data-arm title="Odbierz kurs zaznaczonym">Odbierz kurs</button>
      <button class="btn sm ghost" type="button" id="bulk-clear">Wyczyść</button>
    </div>
    <div class="tablewrap"><table>
      <thead><tr><th style="width:44px"><input type="checkbox" id="sel-page" aria-label="Zaznacz całą stronę"></th><th>Klient</th><th>Kursy</th><th>Dodano</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $u): ?>
        <tr><td><input type="checkbox" name="ids[]" value="<?= (int)$u['id'] ?>" class="sel-row" aria-label="Zaznacz: <?= e($u['full_name'] ?: $u['email']) ?>"></td>
          <td class="who"><b><?= e($u['full_name'] ?: '—') ?><?= $u['role'] === 'admin' ? ' <span class="tag2">admin</span>' : '' ?></b><span><?= e($u['email']) ?></span></td>
          <td><?php if (empty($enr[$u['id']])): ?><span class="chip none">brak</span><?php else: foreach ($enr[$u['id']] as $e): $st = App\Access::status($e['expires_at']); ?>
            <span class="chip<?= $st['expired'] ? ' exp' : '' ?>" title="<?= e($st['text']) ?>"><?= e($e['short_title'] ?: $e['title']) ?><?= $e['expires_at'] ? '<small> · ' . ($st['expired'] ? 'wygasł' : 'do ' . e(fmt_date($e['expires_at']))) . '</small>' : '' ?></span>
          <?php endforeach; endif; ?></td>
          <td class="mono"><?= e(fmt_date($u['created_at'])) ?></td>
          <td><a class="btn sm" href="<?= e(url('/admin/klienci/' . $u['id'])) ?>">Kursy</a></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="5" class="empty">Brak klientów pasujących do filtrów.</td></tr><?php endif; ?>
      </tbody></table></div>
  </form>
  <div class="pager"><span class="muted"><?= $total ? nf(($page - 1) * 20 + 1) . '–' . nf(min($total, $page * 20)) . ' z ' . nf($total) : '0' ?></span>
    <span class="row"><a class="btn sm" href="?<?= e($qs(['p' => $page - 1])) ?>"<?= $page <= 1 ? ' aria-disabled="true"' : '' ?>>Wstecz</a><span class="muted">Strona <?= $page ?> z <?= $pages ?></span><a class="btn sm" href="?<?= e($qs(['p' => $page + 1])) ?>"<?= $page >= $pages ? ' aria-disabled="true"' : '' ?>>Dalej</a></span></div>
</div>
