<?php
// White card with a clear title and an optional small link-out icon (D5).
// Usage: ui_card_begin('Rules', ['link_href' => '...', 'link_label' => 'Open rules']);
//        ... body markup ...
//        ui_card_end();
require_once __DIR__ . '/profile.php';

if (!function_exists('ui_card_begin')) {
    function ui_card_begin(string $title, array $opts = []): void
    {
        $href = $opts['link_href'] ?? null;
        $linkLabel = $opts['link_label'] ?? 'Open';
        $id = $opts['id'] ?? '';
        ?>
<section class="card mb-3"<?= $id !== '' ? ' id="' . ui_e($id) . '"' : '' ?>>
  <div class="card-header d-flex align-items-center justify-content-between gap-2">
    <h2 class="h3 mb-0"><?= ui_e($title) ?></h2>
    <?php if ($href): ?>
    <a class="csms-card-link" href="<?= ui_e($href) ?>" aria-label="<?= ui_e($linkLabel) ?>: <?= ui_e($title) ?>">
      <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
    </a>
    <?php endif; ?>
  </div>
  <div class="card-body">
        <?php
    }
}

if (!function_exists('ui_card_end')) {
    function ui_card_end(): void
    {
        echo "  </div>\n</section>\n";
    }
}
