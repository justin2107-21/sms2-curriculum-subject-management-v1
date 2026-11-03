<?php
require_once __DIR__ . '/../shared/bootstrap.php';
require_permission('audit.view');
require_once __DIR__ . '/../shared/db.php';

$rowsPerPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalRows = (int)$conn->query('SELECT COUNT(*) AS total FROM audit_logs')->fetch_assoc()['total'];
$totalPages = max(1, (int)ceil($totalRows / $rowsPerPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $rowsPerPage;

$stmt = $conn->prepare(
    'SELECT audit_logs.id, audit_logs.created_at, audit_logs.action,
            audit_logs.entity_type, audit_logs.entity_id,
            audit_logs.description, audit_logs.ip_address,
            users.username, users.first_name, users.last_name
     FROM audit_logs
     LEFT JOIN users ON users.id = audit_logs.user_id
     ORDER BY audit_logs.created_at DESC, audit_logs.id DESC
     LIMIT ? OFFSET ?'
);
$stmt->bind_param('ii', $rowsPerPage, $offset);
$stmt->execute();
$auditRows = $stmt->get_result();

$avatarInitial = strtoupper(substr((string)($_SESSION['first_name'] ?? 'U'), 0, 1));
$systemStyleVersion = (string)filemtime(__DIR__ . '/../css/system-admin.css');
$typographyVersion = (string)filemtime(__DIR__ . '/../css/csms-typography.css');
$dashboardScriptVersion = (string)filemtime(__DIR__ . '/../js/dashboard.js');

function audit_page_url(int $pageNumber): string
{
    return 'audit_logs.php?page=' . $pageNumber;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Audit Logs – BCP Student Management System</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="../css/system-admin.css?v=<?= $systemStyleVersion ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <link rel="stylesheet" href="../css/csms-typography.css?v=<?= $typographyVersion ?>"/>
</head>
<body>
<?php
$APP_ROOT = '../';
$ACTIVE_NAV = 'audit';
require_once __DIR__ . '/../shared/sidebar.php';
?>

<div class="main">
  <?php require __DIR__ . '/../shared/topbar.php'; ?>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Audit Logs</h2>
    </div>

    <div class="audit-card">
      <div class="audit-summary">
        <?= number_format($totalRows) ?> recorded event<?= $totalRows === 1 ? '' : 's' ?>.
        Audit records are read-only in ordinary application workflows.
      </div>
      <div class="audit-table-wrap">
        <table class="audit-table">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>User</th>
              <th>Action</th>
              <th>Entity</th>
              <th class="num">Entity ID</th>
              <th>Description</th>
              <th>IP Address</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($auditRows->num_rows > 0): ?>
            <?php while ($row = $auditRows->fetch_assoc()): ?>
              <?php
              $fullName = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
              $userLabel = $row['username'] !== null
                  ? ($fullName !== '' ? $fullName . ' (' . $row['username'] . ')' : $row['username'])
                  : 'System / unauthenticated';
              ?>
              <tr>
                <td><?= htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($userLabel, ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="audit-action"><?= htmlspecialchars((string)$row['action'], ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><?= htmlspecialchars((string)$row['entity_type'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="num"><?= $row['entity_id'] === null ? '<span class="audit-muted">—</span>' : (int)$row['entity_id'] ?></td>
                <td class="audit-description"><?= htmlspecialchars((string)$row['description'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= $row['ip_address'] === null ? '<span class="audit-muted">—</span>' : htmlspecialchars((string)$row['ip_address'], ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="7" class="audit-muted">No audit events have been recorded.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
      <nav class="audit-pagination" aria-label="Audit log pagination">
        <?= $page > 1
            ? '<a href="' . audit_page_url($page - 1) . '">Previous</a>'
            : '<span class="disabled">Previous</span>' ?>
        <?php for ($number = max(1, $page - 2); $number <= min($totalPages, $page + 2); $number++): ?>
          <a href="<?= audit_page_url($number) ?>" class="<?= $number === $page ? 'current' : '' ?>"><?= $number ?></a>
        <?php endfor; ?>
        <?= $page < $totalPages
            ? '<a href="' . audit_page_url($page + 1) . '">Next</a>'
            : '<span class="disabled">Next</span>' ?>
      </nav>
      <?php endif; ?>
    </div>
  </div>

  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js?v=<?= $dashboardScriptVersion ?>"></script>
</body>
</html>
<?php
$stmt->close();
$conn->close();
?>
