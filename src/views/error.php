<?php /** @var string $message */ ?>
<div class="empty empty-error">
  <?= icon('x') ?>
  <p><?= e($message) ?></p>
  <p><a class="btn btn-ghost" href="<?= e(base_path()) ?>/">Zur Startseite</a></p>
</div>
