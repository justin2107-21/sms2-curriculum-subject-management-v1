<?php
// Preserve the existing sign-in/session routing; no database access here.
header('Location: auth/signin.php', true, 302);
exit;
