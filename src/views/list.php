<?php /** @var array $owner @var array $items @var int $viewerId */
$free = count(array_filter($items, static fn(array $it): bool => $it['giver_id'] === null));
$back = '/liste/' . (int) $owner['id'];
?>
<a class="back" href="<?= e(base_path()) ?>/listen"><?= icon('back') ?><span>Alle Listen</span></a>
<header class="section-head list-head">
  <?= avatar($owner['name'], 'avatar-lg') ?>
  <div>
    <h1>Wünsche von <?= e($owner['name']) ?></h1>
    <p class="sub">
      <?php if ($items): ?>
        <?= e(count_label(count($items), 'Wunsch', 'Wünsche')) ?> · <?= $free === 0 ? 'alle vergeben' : e($free . "\u{00A0}frei") ?>
      <?php else: ?>
        Noch keine Wünsche eingetragen
      <?php endif; ?>
    </p>
  </div>
</header>

<?php if (!$items): ?>
  <div class="empty">
    <?= icon('gift') ?>
    <p><?= e($owner['name']) ?> hat noch nichts auf die Liste geschrieben. Schau später noch mal vorbei.</p>
  </div>
<?php else: ?>
  <p class="secret-hint"><?= icon('eye-off') ?><span><?= e($owner['name']) ?> sieht nicht, was reserviert ist und von wem.</span></p>
  <ol class="cards">
    <?php foreach ($items as $it): ?>
      <?= view('partials/other_card', ['it' => $it, 'viewerId' => $viewerId, 'back' => $back]) ?>
    <?php endforeach; ?>
  </ol>
<?php endif; ?>
