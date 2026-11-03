<?php /** @var array $people */ ?>
<header class="section-head">
  <h1>Andere Listen</h1>
  <p class="sub">Wähle eine Person, um ihre Wünsche zu sehen.</p>
</header>
<?= view('partials/people', ['people' => $people]) ?>
