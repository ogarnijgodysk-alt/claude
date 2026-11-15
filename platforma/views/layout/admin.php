<?php
include __DIR__ . '/head.php';
$items = [['/admin', 'Kursy', 'book', 'kursy'], ['/admin/klienci', 'Klienci', 'users', 'klienci'], ['/admin/quizy', 'Quizy', 'quiz', 'quizy'], ['/admin/wyniki', 'Wyniki', 'check', 'wyniki'], ['/admin/import', 'Import', 'upload', 'import'], ['/admin/migracja', 'Migracja', 'clock', 'migracja'], ['/admin/ustawienia', 'Ustawienia', 'gear', 'ustawienia']];
?>
<body>
<div class="wrap" style="padding-top:16px">
  <div class="shell">
    <nav class="side" aria-label="Menu">
      <div class="brand"><span class="logo"><?= e(mb_strtoupper(mb_substr($siteName, 0, 1))) ?></span><?= e($siteName) ?></div>
      <?php foreach ($items as [$href, $label, $ic, $key]): ?>
        <a class="nav" href="<?= e(url($href)) ?>"<?= ($active ?? '') === $key ? ' aria-current="page"' : '' ?>><?= icon($ic) ?><?= e($label) ?></a>
      <?php endforeach; ?>
      <a class="nav" href="<?= e(url('/moje-kursy')) ?>"><?= icon('play') ?>Widok klienta</a>
      <form method="post" action="<?= e(url('/wyloguj')) ?>"><?= App\Csrf::field() ?><button class="nav" type="submit" style="width:100%"><?= icon('back') ?>Wyloguj</button></form>
    </nav>
    <main>
      <?php if (!empty($flash)): ?><p class="notice <?= $flash['type'] === 'ok' ? 'okn' : '' ?>" role="status"><?= e($flash['msg']) ?></p><?php endif; ?>
      <?= $content ?>
    </main>
  </div>
</div>
<script src="<?= e(asset('sortable.min.js')) ?>"></script>
<script src="<?= e(asset('app.js')) ?>" defer></script>
<?php foreach (($scripts ?? []) as $sc): ?><script src="<?= e(asset($sc)) ?>" defer></script><?php endforeach; ?>
</body>
</html>
