<?php /** @var array $user @var array $items @var array $people @var array $notices @var array $old */ ?>
<?= view('partials/notices', ['notices' => $notices, 'back' => '/']) ?>
<div class="home-grid<?= $people ? '' : ' home-single' ?>">
  <section class="col-own" aria-labelledby="h-own">
    <header class="section-head">
      <h1 id="h-own">Meine Wunschliste</h1>
      <p class="sub"><?= count($items) ? e(count_label(count($items), 'Wunsch', 'Wünsche')) : 'Noch leer' ?></p>
    </header>

    <details class="adder" id="neu"<?= !$items || !empty($old['open']) ? ' open' : '' ?>>
      <summary><?= icon('plus') ?><span>Wunsch hinzufügen</span></summary>
      <?= view('partials/item_form', [
          'action' => '/eintrag',
          'values' => $old,
          'submit' => 'Hinzufügen',
          'item' => null,
      ]) ?>
    </details>

    <?= view('partials/own_list', ['items' => $items]) ?>
  </section>

  <?php if ($people): ?>
    <aside class="col-others" aria-labelledby="h-others">
      <h2 id="h-others" class="aside-title">Andere Listen</h2>
      <?= view('partials/people', ['people' => $people]) ?>
    </aside>
  <?php endif; ?>
</div>
