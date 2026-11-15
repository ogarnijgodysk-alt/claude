<?php include __DIR__ . '/head.php'; ?>
<body>
<div class="wrap">
  <div class="stu">
    <div class="sbar"><div class="brand"><span class="logo"><?= e(mb_strtoupper(mb_substr($siteName, 0, 1))) ?></span><?= e($siteName) ?></div></div>
    <?= $content ?>
  </div>
</div>
</body>
</html>
