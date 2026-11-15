<div class="panel login">
  <h1 style="font-size:22px"><?= e($title) ?></h1>
  <?php if ($message): ?><p class="muted"><?= e($message) ?></p><?php endif; ?>
  <p><a class="btn" href="<?= e(url('/')) ?>">Wróć na stronę główną</a></p>
</div>
