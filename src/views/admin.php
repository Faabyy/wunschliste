<?php /** @var array $accounts */ ?>
<header class="section-head section-head-row">
  <div>
    <h1>Verwaltung</h1>
    <p class="sub"><?= e(count_label(count($accounts), 'Konto', 'Konten')) ?>. Reservierungen sind hier bewusst nicht zu sehen.</p>
  </div>
  <form method="post" action="/verwaltung/abmelden">
    <?= Csrf::field() ?>
    <button type="submit" class="btn btn-ghost"><?= icon('logout') ?><span>Verwaltung verlassen</span></button>
  </form>
</header>

<div class="panel">
  <h2 class="panel-title">Neues Konto</h2>
  <form method="post" action="/verwaltung/konto" class="inline-form">
    <?= Csrf::field() ?>
    <label class="field field-grow">
      <span class="label">Name</span>
      <input type="text" name="name" required maxlength="40" autocomplete="off" placeholder="z. B. Oma Gerda">
    </label>
    <button type="submit" class="btn btn-primary"><?= icon('plus') ?><span>Anlegen</span></button>
  </form>
</div>

<?php if ($accounts): ?>
  <ul class="accounts">
    <?php foreach ($accounts as $a): $id = (int) $a['id']; ?>
      <li class="account-row">
        <?= avatar($a['name']) ?>
        <form method="post" action="/verwaltung/konto/<?= $id ?>" class="inline-form account-rename">
          <?= Csrf::field() ?>
          <label class="field field-grow">
            <span class="visually-hidden">Name</span>
            <input type="text" name="name" required maxlength="40" value="<?= e($a['name']) ?>">
          </label>
          <button type="submit" class="btn btn-ghost">Umbenennen</button>
        </form>
        <span class="account-meta"><?= e(count_label((int) $a['items'], 'Wunsch', 'Wünsche')) ?></span>
        <form method="post" action="/verwaltung/konto/<?= $id ?>/loeschen" data-confirm="Konto „<?= e($a['name']) ?>“ mit allen Wünschen löschen? Das lässt sich nicht rückgängig machen.">
          <?= Csrf::field() ?>
          <button type="submit" class="btn-icon btn-danger" title="Löschen" aria-label="Konto „<?= e($a['name']) ?>“ löschen"><?= icon('trash') ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
