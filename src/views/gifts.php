<?php
/** @var array $groups @var array $notices */
$count = 0;
$bought = 0;
$sums = [];
foreach ($groups as $g) {
    foreach ($g['items'] as $it) {
        $count++;
        $bought += (int) $it['purchased'];
        if ($it['price_cents'] !== null) {
            $cur = $it['price_currency'] ?: 'EUR';
            $sums[$cur] = ($sums[$cur] ?? 0) + (int) $it['price_cents'];
        }
    }
}
?>
<?= view('partials/notices', ['notices' => $notices, 'back' => '/schenken']) ?>
<header class="section-head">
  <h1>Was ich schenke</h1>
  <p class="sub">Deine Zusagen über alle Listen. Den Haken „gekauft“ siehst nur du.</p>
</header>

<div id="gifts">
  <?php if (!$groups): ?>
    <div class="empty">
      <?= icon('gift') ?>
      <p>Du hast noch nichts reserviert. Schau in die <a href="/listen">Listen der anderen</a>.</p>
    </div>
  <?php else: ?>
    <dl class="stats">
      <div><dt>Geschenke</dt><dd><?= $count ?></dd></div>
      <div><dt>Gekauft</dt><dd><?= $bought ?> von <?= $count ?></dd></div>
      <?php foreach ($sums as $cur => $sum): ?>
        <div><dt>Summe ca.</dt><dd><?= e(format_price($sum, $cur)) ?></dd></div>
      <?php endforeach; ?>
    </dl>

    <?php foreach ($groups as $ownerId => $g): ?>
      <section class="gift-group">
        <h2 class="group-title"><?= avatar($g['name'], 'avatar-sm') ?><a href="/liste/<?= (int) $ownerId ?>"><?= e($g['name']) ?></a></h2>
        <ol class="cards">
          <?php foreach ($g['items'] as $it): $id = (int) $it['id']; $isBought = (bool) $it['purchased']; ?>
            <li class="card card-gift<?= $isBought ? ' is-bought' : '' ?>" id="e<?= $id ?>" data-id="<?= $id ?>">
              <?= view('partials/item_body', ['it' => $it]) ?>
              <div class="card-status">
                <form method="post" action="/eintrag/<?= $id ?>/gekauft" data-swap="gifts">
                  <?= Csrf::field() ?>
                  <button type="submit" class="toggle" aria-pressed="<?= $isBought ? 'true' : 'false' ?>">
                    <span class="toggle-box"><?= check_mark() ?></span><span>Gekauft</span>
                  </button>
                </form>
                <form method="post" action="/eintrag/<?= $id ?>/freigeben" data-swap="gifts" data-confirm="Reservierung für „<?= e($it['title']) ?>“ aufheben?">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="zurueck" value="/schenken">
                  <button type="submit" class="btn-text">Doch nicht</button>
                </form>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
