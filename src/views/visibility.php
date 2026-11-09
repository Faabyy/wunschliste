<?php /** Wer die eigene Liste sehen darf. @var bool $restricted @var array $choices */ ?>
<a class="back" href="<?= e(base_path()) ?>/"><?= icon('back') ?><span>Meine Liste</span></a>
<header class="section-head">
  <h1>Wer sieht meine Liste?</h1>
  <p class="sub">Wer deine Liste nicht sehen darf, findet sie weder in der Übersicht noch über einen Link.</p>
</header>
<form method="post" action="<?= e(url('/sichtbarkeit')) ?>" class="panel stack visibility-form">
  <?= Csrf::field() ?>
  <label class="choice">
    <input type="radio" name="sichtbar" value="alle"<?= $restricted ? '' : ' checked' ?>>
    <span><strong>Alle</strong><span class="hint">Jedes Konto, auch später angelegte.</span></span>
  </label>
  <label class="choice">
    <input type="radio" name="sichtbar" value="auswahl"<?= $restricted ? ' checked' : '' ?> data-pick-radio>
    <span><strong>Nur ausgewählte Personen</strong><span class="hint">Neue Konten sehen die Liste erst, wenn du sie hier anhakst.</span></span>
  </label>
  <?php if ($choices): ?>
    <fieldset class="pick">
      <legend class="visually-hidden">Personen, die meine Liste sehen dürfen</legend>
      <?php foreach ($choices as $c): ?>
        <label class="pick-person">
          <input type="checkbox" name="personen[]" value="<?= (int) $c['id'] ?>"<?= $c['allowed'] ? ' checked' : '' ?> data-pick>
          <?= avatar($c['name'], 'avatar-sm') ?>
          <span><?= e($c['name']) ?></span>
        </label>
      <?php endforeach; ?>
    </fieldset>
  <?php else: ?>
    <p class="hint">Es gibt noch keine anderen Konten.</p>
  <?php endif; ?>
  <p class="hint">Hat jemand schon etwas von deiner Liste zugesagt, behält er diese Zusage, auch wenn du ihn hier
    herausnimmst. Neues kann er dann nicht mehr sehen.</p>
  <div class="form-actions">
    <button type="submit" class="btn btn-primary" data-busy="Speichere …">Speichern</button>
    <a class="btn btn-ghost" href="<?= e(base_path()) ?>/">Abbrechen</a>
  </div>
</form>
