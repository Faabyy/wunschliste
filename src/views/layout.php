<?php
/** @var string $content @var string $title @var string $active @var ?array $user @var array $flashes */
$current = static fn(string $key): string => $active === $key ? ' aria-current="page"' : '';
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f3efe7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#171513" media="(prefers-color-scheme: dark)">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · Wunschliste</title>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<script src="<?= e(asset('app.js')) ?>" defer></script>
</head>
<body class="<?= $user ? 'has-user' : 'no-user' ?>">
<a class="skip" href="#main">Zum Inhalt springen</a>
<header class="topbar">
  <div class="wrap topbar-inner">
    <a class="brand" href="/"><?= icon('gift', 'brand-mark') ?><span>Wunschliste</span></a>
    <?php if ($user): ?>
      <nav class="mainnav" aria-label="Hauptmenü">
        <a href="/"<?= $current('home') ?>>Meine Liste</a>
        <a href="/listen"<?= $current('people') ?>>Andere Listen</a>
        <a href="/schenken"<?= $current('gifts') ?>>Was ich schenke</a>
      </nav>
      <form class="account" method="post" action="/abmelden">
        <?= Csrf::field() ?>
        <span class="account-name"><?= avatar($user['name'], 'avatar-sm') ?><span><?= e($user['name']) ?></span></span>
        <button type="submit" class="btn-icon" title="Abmelden" aria-label="Abmelden"><?= icon('logout') ?></button>
      </form>
    <?php endif; ?>
  </div>
</header>

<main id="main" class="wrap main">
  <div id="flashes" class="flashes" aria-live="polite">
    <?php foreach ($flashes as $f): ?>
      <p class="flash flash-<?= $f['type'] === 'error' ? 'error' : 'success' ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($f['message']) ?></p>
    <?php endforeach; ?>
  </div>
  <?= $content ?>
</main>

<?php if ($user): ?>
<nav class="tabbar" aria-label="Hauptmenü">
  <a href="/"<?= $current('home') ?>><?= icon('list') ?><span>Meine Liste</span></a>
  <a href="/listen"<?= $current('people') ?>><?= icon('users') ?><span>Andere</span></a>
  <a href="/schenken"<?= $current('gifts') ?>><?= icon('gift') ?><span>Ich schenke</span></a>
</nav>
<?php endif; ?>
</body>
</html>
