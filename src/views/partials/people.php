<?php /** @var array $people */ ?>
<?php if (!$people): ?>
  <div class="empty empty-small">
    <?= icon('users') ?>
    <p>Hier ist sonst noch niemand.</p>
  </div>
<?php else: ?>
  <ul class="people">
    <?php foreach ($people as $p): $items = (int) $p['items']; $free = (int) $p['free']; ?>
      <li>
        <a class="person" href="<?= e(base_path()) ?>/liste/<?= (int) $p['id'] ?>">
          <?= avatar($p['name']) ?>
          <span class="person-text">
            <span class="person-name"><?= e($p['name']) ?></span>
            <span class="person-meta">
              <?php if ($items === 0): ?>noch keine Wünsche<?php else: ?><?= e(count_label($items, 'Wunsch', 'Wünsche')) ?> · <?= $free === 0 ? 'alle vergeben' : e($free . "\u{00A0}frei") ?><?php endif; ?>
            </span>
          </span>
          <?= icon('chevron', 'person-go') ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
