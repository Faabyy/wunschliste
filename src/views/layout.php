<?php
/** @var string $content @var string $title @var string $active @var ?array $user @var int $otherLists @var array $flashes */
$current = static fn(string $key): string => $active === $key ? ' aria-current="page"' : '';

// Menüpunkte: [Ziel, aktiv-Schlüssel, Text oben, Text Tableiste, Icon]. Nur, was es gerade gibt.
$nav = [];
if ($user) {
    if ($user['can_wish']) {
        $nav[] = ['/', 'home', 'Meine Liste', 'Meine Liste', 'list'];
        if ($otherLists > 0) {
            $nav[] = ['/listen', 'people', 'Andere Listen', 'Andere', 'users'];
        }
    } elseif ($otherLists === 1) {
        $nav[] = ['/', 'people', 'Wunschliste', 'Wunschliste', 'list'];
    } else {
        $nav[] = ['/', 'people', 'Wunschlisten', 'Wunschlisten', 'users'];
    }
    if ($otherLists > 0) {
        $nav[] = ['/schenken', 'gifts', 'Was ich schenke', 'Ich schenke', 'gift'];
    }
}
$tabbar = count($nav) > 1;
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
<body class="<?= $tabbar ? 'has-tabbar' : 'no-tabbar' ?>">
<a class="skip" href="#main">Zum Inhalt springen</a>
<header class="topbar">
  <div class="wrap topbar-inner">
    <a class="brand" href="<?= e(base_path()) ?>/"><?= icon('gift', 'brand-mark') ?><span>Wunschliste</span></a>
    <?php if ($user): ?>
      <?php if ($tabbar): ?>
        <nav class="mainnav" aria-label="Hauptmenü">
          <?php foreach ($nav as [$path, $key, $label]): ?>
            <a href="<?= e(url($path)) ?>"<?= $current($key) ?>><?= e($label) ?></a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>
      <form class="account" method="post" action="<?= e(base_path()) ?>/abmelden">
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

<?php if ($tabbar): ?>
<nav class="tabbar" aria-label="Hauptmenü">
  <?php foreach ($nav as [$path, $key, , $label, $icon]): ?>
    <a href="<?= e(url($path)) ?>"<?= $current($key) ?>><?= icon($icon) ?><span><?= e($label) ?></span></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
</body>
</html>
