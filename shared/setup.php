<?php
// DEVELOPMENT-ONLY setup helper.
//
// It is disabled unless all of the following are true:
//   1. The request originates from localhost.
//   2. APP_ENV=development.
//   3. SMS_ALLOW_SETUP=1.
//
// To seed the temporary template administrator, also provide a strong
// SMS_SETUP_ADMIN_PASSWORD environment variable. The password is never shown
// in the response and is not stored in this repository.

require_once __DIR__ . '/bootstrap.php';

$remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$isLocalRequest = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
$isDevelopment = getenv('APP_ENV') === 'development';
$setupEnabled = getenv('SMS_ALLOW_SETUP') === '1';

if (!$isLocalRequest || !$isDevelopment || !$setupEnabled) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$message = '';
$messageType = '';

if (request_method() === 'POST') {
    if (!verify_csrf_token()) {
        http_response_code(403);
        $message = 'Invalid or expired security token. Refresh the page and try again.';
        $messageType = 'err';
    } else {
        $setupPassword = getenv('SMS_SETUP_ADMIN_PASSWORD');

        if (!is_string($setupPassword) || strlen($setupPassword) < 12) {
            http_response_code(503);
            $message = 'Setup is not configured. Define a development-only administrator password of at least 12 characters.';
            $messageType = 'err';
        } else {
            require_once __DIR__ . '/db.php';

            try {
                $sql = "CREATE TABLE IF NOT EXISTS users (
                    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    username      VARCHAR(60)   NOT NULL UNIQUE,
                    email         VARCHAR(150)  NOT NULL UNIQUE,
                    first_name    VARCHAR(100)  NOT NULL,
                    last_name     VARCHAR(100)  NOT NULL,
                    password_hash VARCHAR(255)  NOT NULL,
                    role          ENUM('admin','student') NOT NULL DEFAULT 'student',
                    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                $conn->query($sql);

                $hash = password_hash($setupPassword, PASSWORD_DEFAULT);
                // Temporary shared-template role only. Final client-approved
                // RBAC roles are intentionally not inferred here.
                $temporaryRole = 'admin';
                // The first account is a Super Admin in user_roles. users.role is a deprecated legacy column.
                $rbacRole = 'super_admin';
                $stmt = $conn->prepare(
                    'INSERT INTO users (username, email, first_name, last_name, password_hash, role)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role)'
                );
                $username = 'admin';
                $email = 'admin@bcp.edu.ph';
                $firstName = 'Admin';
                $lastName = 'User';
                $conn->begin_transaction();
                try {
                    $stmt->bind_param('ssssss', $username, $email, $firstName, $lastName, $hash, $temporaryRole);
                    $stmt->execute();
                    $stmt->close();

                    // When Phase 2 RBAC exists, setup must also create the
                    // authoritative mapping. Before migration, legacy setup
                    // remains usable and migration 002 performs the backfill.
                    $rbacTables = $conn->query(
                        "SELECT COUNT(*) AS total
                         FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME IN ('roles', 'user_roles')"
                    )->fetch_assoc();

                    if ((int)$rbacTables['total'] === 2) {
                        $hasFinalRoles = $conn->query("SELECT 1 FROM roles WHERE name = 'super_admin'")->num_rows > 0;
                        if (!$hasFinalRoles) { $rbacRole = 'admin'; }
                        $stmt = $conn->prepare(
                            'INSERT INTO user_roles (user_id, role_id, assigned_by)
                             SELECT users.id, roles.id, NULL
                             FROM users
                             INNER JOIN roles ON roles.name = ?
                             WHERE users.username = ?
                             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)'
                        );
                        $stmt->bind_param('ss', $rbacRole, $username);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $conn->commit();
                } catch (Throwable $error) {
                    $conn->rollback();
                    throw $error;
                }
                $conn->close();

                $message = 'Development setup completed. Disable SMS_ALLOW_SETUP now.';
                $messageType = 'ok';
            } catch (Throwable $error) {
                log_application_error('Development setup failed', $error);
                http_response_code(500);
                $message = 'Setup could not be completed. Check the server error log.';
                $messageType = 'err';
            }
        }
    }
} elseif (request_method() !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    $message = 'Method not allowed.';
    $messageType = 'err';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Development Setup</title>
  <style>
    body{font-family:"DM Sans", "DM Sans", "Segoe UI", sans-serif;max-width:560px;margin:60px auto;padding:0 20px;color:#1f2937}
    .panel{border:1px solid #d1d5db;border-radius:8px;padding:20px}
    .ok{background:#dcfce7;color:#166534;padding:14px;border-radius:8px;margin-bottom:16px}
    .err{background:#fee2e2;color:#991b1b;padding:14px;border-radius:8px;margin-bottom:16px}
    button{background:#2563eb;color:white;border:0;border-radius:6px;padding:10px 16px;cursor:pointer}
    code{background:#f3f4f6;padding:2px 5px;border-radius:4px}
  </style>
</head>
<body>
  <?php if ($message !== ''): ?>
    <div class="<?= $messageType ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="panel">
    <h1>Development setup</h1>
    <p>This local-only helper creates the legacy users table and seeds the temporary template administrator using the password supplied through <code>SMS_SETUP_ADMIN_PASSWORD</code>.</p>
    <p>The configured password will not be displayed.</p>
    <form method="post">
      <?= csrf_field() ?>
      <button type="submit">Run development setup</button>
    </form>
  </div>
</body>
</html>
