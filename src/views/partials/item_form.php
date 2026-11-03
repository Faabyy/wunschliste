<?php
/** @var string $action @var array $values @var string $submit @var ?array $item */
$hasLink = $item !== null && $item['url'] !== null;
$image = $item['image'] ?? null;
?>
<form method="post" action="<?= e(url($action)) ?>" class="item-form stack" enctype="multipart/form-data">
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

  <div class="field image-field">
    <span class="label">Bild <span class="opt">optional</span></span>
    <div class="image-picker">
      <span class="image-preview<?= $image ? '' : ' is-empty' ?>" data-preview>
        <?php if ($image): ?><img src="<?= e(Images::url($image)) ?>" alt=""><?php else: ?><?= icon('image') ?><?php endif; ?>
      </span>
      <label class="btn btn-ghost btn-file">
        <?= icon('upload') ?><span><?= $image ? 'Anderes Bild' : 'Bild wählen' ?></span>
        <input type="file" name="bild" accept="image/jpeg,image/png,image/gif,image/webp" data-resize>
      </label>
    </div>
    <?php if ($image): ?>
      <label class="check-line">
        <input type="checkbox" name="bild_entfernen" value="1">
        <span>Bild entfernen</span>
      </label>
    <?php endif; ?>
  </div>

  <p class="hint">Ohne Preis oder Bild versuche ich, beides aus dem Link zu lesen. Das klappt nicht bei
    jedem Shop. Amazon und eBay geben Preise nicht heraus.</p>
  <?php if ($hasLink): ?>
    <label class="check-line">
      <input type="checkbox" name="neu_lesen" value="1">
      <span>Preis und Bild neu aus dem Link lesen<?= !$item['price_manual'] && $item['price_cents'] !== null ? ' (zuletzt erkannt: ' . e(format_price((int) $item['price_cents'], $item['price_currency'])) . ')' : '' ?></span>
    </label>
  <?php endif; ?>
  <div class="form-actions">
    <button type="submit" class="btn btn-primary" data-busy="Speichere …"><?= e($submit) ?></button>
    <?php if ($item !== null): ?>
      <a class="btn btn-ghost" href="<?= e(base_path()) ?>/#e<?= (int) $item['id'] ?>">Abbrechen</a>
    <?php endif; ?>
  </div>
</form>
