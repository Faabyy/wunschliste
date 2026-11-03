<?php
/** @var array $item @var array $old */
$values = $old ?: [
    'titel' => $item['title'],
    'link' => $item['url'] ?? '',
    'notiz' => $item['note'] ?? '',
    'preis' => $item['price_manual'] && $item['price_cents'] !== null
        ? number_format($item['price_cents'] / 100, 2, ',', '')
        : '',
];
?>
<a class="back" href="<?= e(base_path()) ?>/#e<?= (int) $item['id'] ?>"><?= icon('back') ?><span>Meine Liste</span></a>
<header class="section-head">
  <h1>Wunsch bearbeiten</h1>
</header>
<div class="panel">
  <?= view('partials/item_form', [
      'action' => '/eintrag/' . (int) $item['id'],
      'values' => $values,
      'submit' => 'Speichern',
      'item' => $item,
  ]) ?>
</div>
