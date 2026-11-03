<?php /** @var bool $enabled */ ?>
<section class="login">
  <div class="login-card">
    <div class="login-mark"><?= icon('key') ?></div>
    <h1>Verwaltung</h1>
    <?php if (!$enabled): ?>
      <p class="sub">Die Verwaltung ist ausgeschaltet. Trag in <code>config.php</code> ein
        <code>admin_passwort</code> ein (Vorlage: <code>config.sample.php</code>).</p>
    <?php else: ?>
      <p class="sub">Konten anlegen, umbenennen und löschen.</p>
      <form method="post" action="<?= e(base_path()) ?>/verwaltung/anmelden" class="stack">
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
