<?php
/** Wunschkarte in einer fremden Liste. @var array $it @var int $viewerId @var string $back */
$id = (int) $it['id'];
$giver = $it['giver_id'] === null ? null : (int) $it['giver_id'];
$mine = $giver === $viewerId;
$class = 'card card-other' . ($giver !== null ? ' is-taken' : '') . ($mine ? ' is-mine' : '');
?>
<li class="<?= $class ?>" id="e<?= $id ?>" data-id="<?= $id ?>">
  <?= view('partials/item_body', ['it' => $it]) ?>
  <div class="card-status">
    <?php if ($giver === null): ?>
      <form method="post" action="/eintrag/<?= $id ?>/reservieren" data-swap="e<?= $id ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="zurueck" value="<?= e($back) ?>">
        <button type="submit" class="btn btn-gift" data-focus><?= icon('gift') ?><span>Ich schenke das</span></button>
      </form>
    <?php elseif ($mine): ?>
      <span class="badge badge-mine"><?= check_mark() ?><span>Du schenkst das</span></span>
      <form method="post" action="/eintrag/<?= $id ?>/freigeben" data-swap="e<?= $id ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="zurueck" value="<?= e($back) ?>">
        <button type="submit" class="btn-text" data-focus>Doch nicht</button>
      </form>
    <?php else: ?>
      <span class="badge"><?= check_mark() ?><span><?= e($it['giver_name']) ?> schenkt das</span></span>
    <?php endif; ?>
  </div>
</li>
