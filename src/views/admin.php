<?php /** @var array $accounts */ ?>
<header class="section-head section-head-row">
  <div>
    <h1>Verwaltung</h1>
    <p class="sub"><?= e(count_label(count($accounts), 'Konto', 'Konten')) ?>. Reservierungen sind hier bewusst nicht zu sehen.</p>
  </div>
  <form method="post" action="<?= e(base_path()) ?>/verwaltung/abmelden">
    <?= Csrf::field() ?>
    <button type="submit" class="btn btn-ghost"><?= icon('logout') ?><span>Verwaltung verlassen</span></button>
  </form>
</header>

<?php $problems = count(array_filter($checks, static fn(array $c): bool => $c[1] !== 'ok')); ?>
<details class="panel checks"<?= $problems ? ' open' : '' ?>>
  <summary class="panel-title">Server-Prüfung <span class="opt"><?= $problems ? e(count_label($problems, 'Hinweis', 'Hinweise')) : 'alles in Ordnung' ?></span></summary>
  <ul class="check-list">
    <?php foreach ($checks as [$label, $status, $detail]): ?>
      <li class="check-<?= e($status) ?>"><span class="check-dot" aria-hidden="true"></span><span class="check-label"><?= e($label) ?></span><span class="check-detail"><?= e($detail) ?></span></li>
    <?php endforeach; ?>
    <li class="check-warn" data-probe="<?= e(url('/data/schutztest.txt')) ?>" data-probe-marker="SCHUTZTEST-OFFEN">
      <span class="check-dot" aria-hidden="true"></span><span class="check-label">Datenbank vor Abruf geschützt</span><span class="check-detail">wird geprüft …</span>
    </li>
  </ul>
</details>

<div class="panel">
  <h2 class="panel-title">Neues Konto</h2>
  <form method="post" action="<?= e(base_path()) ?>/verwaltung/konto" class="inline-form">
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
        <form method="post" action="<?= e(base_path()) ?>/verwaltung/konto/<?= $id ?>" class="inline-form account-rename">
          <?= Csrf::field() ?>
          <label class="field field-grow">
            <span class="visually-hidden">Name</span>
            <input type="text" name="name" required maxlength="40" value="<?= e($a['name']) ?>">
          </label>
          <button type="submit" class="btn btn-ghost">Umbenennen</button>
        </form>
        <span class="account-meta"><?= e(count_label((int) $a['items'], 'Wunsch', 'Wünsche')) ?></span>
        <form method="post" action="<?= e(base_path()) ?>/verwaltung/konto/<?= $id ?>/loeschen" data-confirm="Konto „<?= e($a['name']) ?>“ mit allen Wünschen löschen? Das lässt sich nicht rückgängig machen.">
          <?= Csrf::field() ?>
          <button type="submit" class="btn-icon btn-danger" title="Löschen" aria-label="Konto „<?= e($a['name']) ?>“ löschen"><?= icon('trash') ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
