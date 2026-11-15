<?php include __DIR__ . '/head.php'; ?>
<body>
<div class="wrap">
  <div class="stu">
    <div class="sbar">
      <a class="brand" href="<?= e(url('/moje-kursy')) ?>" style="text-decoration:none;color:inherit"><span class="logo"><?= e(mb_strtoupper(mb_substr($siteName, 0, 1))) ?></span><?= e($siteName) ?></a>
      <div class="row">
        <?php if (($user['role'] ?? '') === 'admin'): ?><a class="btn sm" href="<?= e(url('/admin')) ?>">Panel admina</a><?php endif; ?>
        <span class="muted hide-s"><?= e($user['full_name'] ?: $user['email']) ?></span>
        <form method="post" action="<?= e(url('/wyloguj')) ?>"><?= App\Csrf::field() ?><button class="btn sm" type="submit">Wyloguj</button></form>
      </div>
    </div>
    <?php if (!empty($flash)): ?><p class="notice <?= $flash['type'] === 'ok' ? 'okn' : '' ?>" role="status"><?= e($flash['msg']) ?></p><?php endif; ?>
    <?= $content ?>
  </div>
</div>
<script src="<?= e(asset('app.js')) ?>" defer></script>
</body>
</html>
