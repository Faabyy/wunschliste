<?php /** @var bool $needCode @var array $old */ ?>
<section class="login">
  <div class="login-card">
    <div class="login-mark"><?= icon('gift') ?></div>
    <h1>Wunschliste</h1>
    <p class="sub">Wünsche aufschreiben, heimlich verschenken.</p>
    <form method="post" action="/anmelden" class="stack">
      <?= Csrf::field() ?>
      <?php if ($needCode): ?>
        <label class="field">
          <span class="label">Zugangscode</span>
          <input type="password" name="code" required autocomplete="current-password" autocapitalize="off" spellcheck="false">
          <span class="hint">Den Code hast du von der Person bekommen, die die Seite betreibt.</span>
        </label>
      <?php endif; ?>
      <label class="field">
        <span class="label">Dein Name</span>
        <input type="text" name="name" required maxlength="40" autocomplete="username" value="<?= e($old['name'] ?? '') ?>"<?= $needCode ? '' : ' autofocus' ?>>
      </label>
      <button type="submit" class="btn btn-primary btn-block">Weiter</button>
    </form>
  </div>
</section>
