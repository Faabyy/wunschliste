<?php /** @var array $notices @var string $back */ ?>
<div id="notices" class="notices">
  <?php foreach ($notices as $n): ?>
    <div class="notice">
      <?= icon('bell', 'notice-icon') ?>
      <p>
        <?php if ($n['kind'] === 'removed'): ?>
          Ein von dir reservierter Wunsch von <?= e($n['owner_name']) ?> wurde entfernt: <strong><?= e($n['item_title']) ?></strong>
        <?php else: ?>
          <?= e($n['owner_name']) ?> hat einen Wunsch geändert, den du schenken willst:
          <?php if ($n['item_id'] !== null && $n['owner_id'] !== null): ?>
            <a href="<?= e(base_path()) ?>/liste/<?= (int) $n['owner_id'] ?>#e<?= (int) $n['item_id'] ?>"><strong><?= e($n['item_title']) ?></strong></a>.
          <?php else: ?>
            <strong><?= e($n['item_title']) ?></strong>.
          <?php endif; ?>
          Schau kurz, ob dein Geschenk noch passt.
        <?php endif; ?>
      </p>
      <form method="post" action="<?= e(base_path()) ?>/hinweis/<?= (int) $n['id'] ?>/ok" data-swap="notices">
        <?= Csrf::field() ?>
        <input type="hidden" name="zurueck" value="<?= e($back) ?>">
        <button type="submit" class="btn-icon" title="Ausblenden" aria-label="Hinweis ausblenden"><?= icon('x') ?></button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
