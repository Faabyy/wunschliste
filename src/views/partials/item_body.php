<?php
/**
 * Inhalt einer Wunschkarte. Bekommt nur die Wunschdaten, nie Reservierungen.
 * @var array $it
 */
$hasPrice = $it['price_cents'] !== null;
?>
<div class="card-body">
  <h3 class="card-title"><?= e($it['title']) ?></h3>
  <?php if ($it['note'] !== null && $it['note'] !== ''): ?>
    <p class="card-note"><?= e($it['note']) ?></p>
  <?php endif; ?>
  <?php if ($it['url'] !== null || $hasPrice): ?>
    <p class="card-meta">
      <?php if ($it['url'] !== null): ?>
        <a class="chip chip-link" href="<?= e($it['url']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('external') ?><span><?= e(domain_of($it['url'])) ?></span></a>
      <?php endif; ?>
      <?php if ($hasPrice): ?>
        <span class="chip chip-price"><?= e(format_price((int) $it['price_cents'], $it['price_currency'], !$it['price_manual'])) ?></span>
      <?php endif; ?>
    </p>
  <?php endif; ?>
</div>
