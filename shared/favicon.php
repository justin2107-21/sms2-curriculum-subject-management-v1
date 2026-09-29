<?php
$faviconAppRoot = strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/modules/curriculum/pages/') !== false ? '../../../' : '../';
$faviconFile = dirname(__DIR__) . '/images/BCP_LOGO.png';
$faviconHref = $faviconAppRoot . 'images/BCP_LOGO.png?v=' . (string)filemtime($faviconFile);
?>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($faviconHref, ENT_QUOTES, 'UTF-8') ?>"/>
<?php unset($faviconAppRoot, $faviconFile, $faviconHref); ?>
