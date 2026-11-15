<div class="head"><div><a class="btn ghost sm" href="<?= e(url('/admin')) ?>"><?= icon('back') ?>Kursy</a>
  <h1 style="margin-top:6px"><input class="h1in" id="c-title" value="<?= e($course['title']) ?>" aria-label="Tytuł kursu" maxlength="300"></h1></div>
  <div class="row"><span class="saved" id="saved"><?= icon('check') ?>Zapisano</span><a class="btn" href="<?= e(url('/kurs/' . $course['id'])) ?>" target="_blank" rel="noopener">Podgląd ucznia</a>
  <button class="icb" type="button" id="c-del" title="Usuń kurs" aria-label="Usuń kurs"><?= icon('trash') ?></button></div></div>
<div class="panel acc" id="acc"></div>
<div id="editor" class="editor">
  <section class="panel"><div class="panel-h"><b>Struktura kursu</b><button class="btn sm" type="button" id="add-module"><?= icon('plus') ?>Moduł</button></div><div id="tree"></div><p class="hint">Przeciągnij za uchwyt, żeby zmienić kolejność modułów i lekcji. Lekcję możesz przenieść do innego modułu.</p></section>
  <section class="panel pane" id="pane"></section>
</div>
<script type="application/json" id="boot"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
