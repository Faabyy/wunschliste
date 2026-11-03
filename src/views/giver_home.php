<?php /** Startseite für Konten ohne eigene Wunschliste. @var array $people @var array $notices */ ?>
<?= view('partials/notices', ['notices' => $notices, 'back' => '/']) ?>
<header class="section-head">
  <h1>Wunschlisten</h1>
  <p class="sub">Wähle eine Person, um ihre Wünsche zu sehen und etwas zum Verschenken auszusuchen.</p>
</header>
<?= view('partials/people', ['people' => $people]) ?>
