<?php
// Legacy template route retained for compatibility. Dashboard is now the
// single landing page for this Curriculum & Subject Management subsystem.
require_once __DIR__ . '/../shared/bootstrap.php';
require_permission('dashboard.view');

header('Location: ../dashboard/dashboard.php', true, 302);
exit;
