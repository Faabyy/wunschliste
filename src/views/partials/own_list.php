<?php
/**
 * Die eigene Liste. Daten kommen aus Wishlist::ownItems() und enthalten keine Reservierungen.
 * @var array $items
 */
$last = count($items) - 1;
?>
<div id="own-list" class="own-list">
  <?php if (!$items): ?>
    <div class="empty">
      <?= icon('gift') ?>
      <p>Deine Liste ist noch leer. Trag oben deinen ersten Wunsch ein.</p>
    </div>
  <?php else: ?>
    <ol class="cards">
      <?php foreach ($items as $n => $it): $id = (int) $it['id']; ?>
        <li class="card card-own" id="e<?= $id ?>" data-id="<?= $id ?>">
          <?= view('partials/item_body', ['it' => $it]) ?>
          <div class="card-actions">
            <form method="post" action="<?= e(base_path()) ?>/eintrag/<?= $id ?>/hoch" data-swap="own-list">
              <?= Csrf::field() ?>
              <button type="submit" class="btn-icon" title="Nach oben" aria-label="„<?= e($it['title']) ?>“ nach oben"<?= $n === 0 ? ' disabled' : '' ?>><?= icon('up') ?></button>
            </form>
            <form method="post" action="<?= e(base_path()) ?>/eintrag/<?= $id ?>/runter" data-swap="own-list">
              <?= Csrf::field() ?>
              <button type="submit" class="btn-icon" title="Nach unten" aria-label="„<?= e($it['title']) ?>“ nach unten"<?= $n === $last ? ' disabled' : '' ?>><?= icon('down') ?></button>
            </form>
            <a class="btn-icon" href="<?= e(base_path()) ?>/eintrag/<?= $id ?>" title="Bearbeiten" aria-label="„<?= e($it['title']) ?>“ bearbeiten"><?= icon('edit') ?></a>
            <form method="post" action="<?= e(base_path()) ?>/eintrag/<?= $id ?>/loeschen" data-swap="own-list" data-confirm="„<?= e($it['title']) ?>“ wirklich löschen?">
              <?= Csrf::field() ?>
              <button type="submit" class="btn-icon btn-danger" title="Löschen" aria-label="„<?= e($it['title']) ?>“ löschen"><?= icon('trash') ?></button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</div>
