<?php /** @var bool $enabled */ ?>
<section class="login">
  <div class="login-card">
    <div class="login-mark"><?= icon('key') ?></div>
    <h1>Verwaltung</h1>
    <?php if (!$enabled): ?>
      <p class="sub">Die Web-Verwaltung ist ausgeschaltet. Setze <code>WUNSCHLISTE_ADMIN_PASSWORT</code>
        oder lege Konten mit <code>php bin/konten.php</code> an.</p>
    <?php else: ?>
      <p class="sub">Konten anlegen, umbenennen und löschen.</p>
      <form method="post" action="/verwaltung/anmelden" class="stack">
        <?= Csrf::field() ?>
        <label class="field">
          <span class="label">Passwort</span>
          <input type="password" name="passwort" required autocomplete="current-password" autofocus>
        </label>
        <button type="submit" class="btn btn-primary btn-block">Anmelden</button>
      </form>
    <?php endif; ?>
  </div>
</section>
