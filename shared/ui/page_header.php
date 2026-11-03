<?php
// Page header with breadcrumb above the title (D5).
// $crumbs: list of [label, href|null]; the last entry is the current page.
require_once __DIR__ . '/profile.php';

if (!function_exists('ui_page_header')) {
    function ui_page_header(string $title, array $crumbs = [], string $subtitle = '', string $actionsHtml = ''): void
    {
        ?>
<div class="csms-page-header">
  <?php if ($crumbs): ?>
  <nav aria-label="Breadcrumb">
    <ol class="breadcrumb mb-1">
      <?php foreach ($crumbs as $i => [$label, $href]): $last = $i === array_key_last($crumbs); ?>
        <?php if ($last || !$href): ?>
        <li class="breadcrumb-item active"<?= $last ? ' aria-current="page"' : '' ?>><?= ui_e($label) ?></li>
        <?php else: ?>
        <li class="breadcrumb-item"><a href="<?= ui_e($href) ?>"><?= ui_e($label) ?></a></li>
        <?php endif; ?>
      <?php endforeach; ?>
    </ol>
  </nav>
  <?php endif; ?>
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
    <h1 class="h1 mb-0"><?= ui_e($title) ?></h1>
    <?php if ($actionsHtml !== ''): ?><div class="d-flex gap-2"><?= $actionsHtml /* caller-escaped markup */ ?></div><?php endif; ?>
  </div>
  <?php if ($subtitle !== ''): ?><p class="csms-page-header__subtitle"><?= ui_e($subtitle) ?></p><?php endif; ?>
</div>
        <?php
    }
}
