<?php
/** @var string $action @var array $values @var string $submit @var ?array $item */
$autoPrice = $item !== null && !$item['price_manual'] && $item['price_cents'] !== null;
?>
<form method="post" action="<?= e($action) ?>" class="item-form stack">
  <?= Csrf::field() ?>
  <label class="field">
    <span class="label">Was wünschst du dir?</span>
    <input type="text" name="titel" required maxlength="150" value="<?= e($values['titel'] ?? '') ?>" placeholder="z. B. Wanderschuhe">
  </label>
  <label class="field">
    <span class="label">Link <span class="opt">optional</span></span>
    <input type="url" name="link" maxlength="2000" inputmode="url" autocapitalize="off" spellcheck="false" value="<?= e($values['link'] ?? '') ?>" placeholder="https://…">
  </label>
  <div class="field-row">
    <label class="field field-grow">
      <span class="label">Notiz <span class="opt">optional</span></span>
      <input type="text" name="notiz" maxlength="300" value="<?= e($values['notiz'] ?? '') ?>" placeholder="Größe, Farbe …">
    </label>
    <label class="field field-price">
      <span class="label">Preis <span class="opt">optional</span></span>
      <input type="text" name="preis" maxlength="20" inputmode="decimal" value="<?= e($values['preis'] ?? '') ?>" placeholder="aus Link">
    </label>
  </div>
  <p class="hint">Ohne Preisangabe versuche ich, den Preis aus dem Link zu lesen. Das klappt nicht bei jedem Shop.</p>
  <?php if ($autoPrice): ?>
    <label class="check-line">
      <input type="checkbox" name="preis_neu" value="1">
      <span>Preis neu aus dem Link lesen (zuletzt erkannt: <?= e(format_price((int) $item['price_cents'], $item['price_currency'])) ?>)</span>
    </label>
  <?php endif; ?>
  <div class="form-actions">
    <button type="submit" class="btn btn-primary" data-busy="Speichere …"><?= e($submit) ?></button>
    <?php if ($item !== null): ?>
      <a class="btn btn-ghost" href="/#e<?= (int) $item['id'] ?>">Abbrechen</a>
    <?php endif; ?>
  </div>
</form>
