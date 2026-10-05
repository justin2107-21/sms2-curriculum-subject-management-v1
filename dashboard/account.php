<?php
require_once __DIR__ . '/../shared/bootstrap.php';
require_permission('account.view');
require_once __DIR__.'/../database/ProvisionalRoles.php';
$canDeleteOwnAccount = !array_intersect(get_user_roles((int)current_user_id()), array_keys(ProvisionalRoles::definitions()));
$canViewArchives = user_can((int)current_user_id(), 'archive.view');
$canRestoreArchives = $canViewArchives && user_can((int)current_user_id(), 'archive.restore');
$archiveTab = $canViewArchives && (($_GET['tab'] ?? '') === 'archives');
$first_name     = htmlspecialchars($_SESSION['first_name'] ?? '');
$last_name      = htmlspecialchars($_SESSION['last_name']  ?? '');
$email          = htmlspecialchars($_SESSION['email']      ?? '');
$username       = htmlspecialchars($_SESSION['username']   ?? '');
$role = htmlspecialchars(implode(', ', array_map(static fn($key)=>ProvisionalRoles::definitions()[$key]['label'] ?? ucfirst($key),get_user_roles((int)current_user_id()))), ENT_QUOTES, 'UTF-8');
$avatar_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$accountCssVersion = (string)filemtime(__DIR__ . '/../css/account.css');
$typographyVersion = (string)filemtime(__DIR__ . '/../css/csms-typography.css');
$dashboardScriptVersion = (string)filemtime(__DIR__ . '/../js/dashboard.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <title>Account Settings – BCP Student Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="../css/account.css?v=<?= $accountCssVersion ?>"/>
  <link rel="stylesheet" href="../css/archives.css?v=<?= (int)filemtime(__DIR__ . '/../css/archives.css') ?>"/>
  <link rel="stylesheet" href="../css/csms-typography.css?v=<?= $typographyVersion ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../';
$ACTIVE_NAV = 'settings';
require __DIR__ . '/../shared/sidebar.php';
?>
<div class="main">
<?php require __DIR__ . '/../shared/topbar.php'; ?>
<main class="content account-content">
<div class="account-wrap <?= $archiveTab ? 'archives-open' : '' ?>" id="settingsWrap">
  <nav class="settings-tabs" aria-label="Settings sections">
    <a href="account.php" class="<?= !$archiveTab ? 'selected' : '' ?>">Account</a>
    <?php if ($canViewArchives): ?><a href="account.php?tab=archives" class="<?= $archiveTab ? 'selected' : '' ?>">Archives</a><?php endif; ?>
  </nav>
  <div id="accountPanel" <?= $archiveTab ? 'hidden' : '' ?>>
<?php if (current_user()['must_change_password']): ?><div class="settings-card"><div class="settings-card-body"><p role="status">Change your temporary password in the Password section before accessing other modules.</p></div></div><?php endif; ?>

  <!-- Profile card -->
  <div class="settings-card">
    <div class="settings-card-header">
      <i class="fa-solid fa-user"></i>
      <h2>Profile Information</h2>
    </div>
    <div class="settings-card-body">
      <div class="avatar-row">
        <div class="avatar-circle" id="avatarCircle"><?= $avatar_initial ?></div>
        <div class="avatar-info">
          <div class="avatar-name" id="displayName"><?= $first_name . ' ' . $last_name ?></div>
          <div class="avatar-role"><?= $role ?></div>
        </div>
      </div>
      <form id="profileForm">
        <div class="form-grid">
          <div class="form-field">
            <label>First Name</label>
            <input type="text" name="first_name" value="<?= $first_name ?>" placeholder="First name"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Last Name</label>
            <input type="text" name="last_name" value="<?= $last_name ?>" placeholder="Last name"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field full">
            <label>Email Address</label>
            <input type="email" name="email" value="<?= $email ?>" placeholder="email@example.com"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field full">
            <label>Username</label>
            <input type="text" name="username" value="<?= $username ?>" placeholder="Username"/>
            <span class="field-error"></span>
          </div>
        </div>
      </form>
    </div>
    <div class="settings-card-footer">
      <button class="btn-save" id="btnSaveProfile">Save Changes</button>
    </div>
  </div>

  <!-- Change password card -->
  <div class="settings-card">
    <div class="settings-card-header">
      <i class="fa-solid fa-lock"></i>
      <h2>Change Password</h2>
    </div>
    <div class="settings-card-body">
      <form id="passwordForm">
        <div class="form-grid">
          <div class="form-field full">
            <label>Current Password</label>
            <div class="password-wrap">
              <input type="password" name="current_password" id="fCurrentPw" placeholder="Enter current password" autocomplete="current-password"/>
              <button type="button" class="toggle-pw" data-target="fCurrentPw">
                <i class="fa-solid fa-eye"></i>
              </button>
            </div>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>New Password</label>
            <div class="password-wrap">
              <input type="password" name="new_password" id="fNewPw" placeholder="New password (min <?= current_user()['must_change_password'] ? 12 : 6 ?>)" minlength="<?= current_user()['must_change_password'] ? 12 : 6 ?>" maxlength="72" autocomplete="new-password"/>
              <button type="button" class="toggle-pw" data-target="fNewPw">
                <i class="fa-solid fa-eye"></i>
              </button>
            </div>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Confirm New Password</label>
            <div class="password-wrap">
              <input type="password" name="confirm_password" id="fConfirmPw" placeholder="Repeat new password" autocomplete="new-password"/>
              <button type="button" class="toggle-pw" data-target="fConfirmPw">
                <i class="fa-solid fa-eye"></i>
              </button>
            </div>
            <span class="field-error"></span>
          </div>
        </div>
      </form>
    </div>
    <div class="settings-card-footer">
      <button class="btn-save" id="btnSavePassword">Update Password</button>
    </div>
  </div>

  <!-- Danger zone -->
  <div class="settings-card">
    <div class="settings-card-header">
      <i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i>
      <h2 style="color:#dc2626;">Danger Zone</h2>
    </div>
    <div class="danger-zone">
      <div class="danger-info">
        <h3>Sign Out</h3>
        <p>End your current session and return to the sign-in page.</p>
      </div>
      <button class="btn-danger" id="btnSignOut">Sign Out</button>
    </div>
    <?php if ($canDeleteOwnAccount): ?><div class="danger-zone" style="border-top:1px solid #fef2f2;">
      <div class="danger-info">
        <h3>Delete Account</h3>
        <p>Permanently remove your account. This cannot be undone.</p>
      </div>
      <button class="btn-danger" id="btnDeleteAccount">Delete Account</button>
    </div><?php endif; ?>
  </div>

  </div>
  <?php if ($canViewArchives): ?>
  <section id="archivesPanel" class="archives-panel" data-can-restore="<?= $canRestoreArchives ? '1' : '0' ?>" <?= !$archiveTab ? 'hidden' : '' ?>>
    <header class="archives-heading"><h1>Archives</h1><p>View and restore archived CSMS records.</p></header>
    <div class="archive-summary" id="archiveSummary"></div>
    <form id="archiveFilters" class="archive-filters" role="search">
      <input type="search" name="search" placeholder="Search archived records..." aria-label="Search archived records" maxlength="100">
      <select name="module" aria-label="Module"><option value="">All modules</option><option value="program">Academic Programs</option><option value="curriculum">Curricula</option><option value="subject">Subjects</option><option value="elective_group">Elective Groups</option></select>
      <select name="date" aria-label="Date archived"><option value="all">All dates</option><option value="today">Today</option><option value="7">Last 7 days</option><option value="30">Last 30 days</option><option value="custom">Custom range</option></select>
      <input type="date" name="from" aria-label="From date" hidden><input type="date" name="to" aria-label="To date" hidden>
      <select name="archived_by" aria-label="Archived by"><option value="">Anyone</option></select>
      <select name="original_status" aria-label="Original status"><option value="">Any previous status</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="draft">Draft</option></select>
      <input type="search" name="program" placeholder="Program / Strand" aria-label="Program or strand" maxlength="80">
    </form>
    <div class="archive-chips" id="archiveChips" aria-label="Archive modules"></div>
    <div class="archive-table-wrap"><table class="archive-table"><thead><tr><th>Record</th><th>Module</th><th>Original Context</th><th>Archived On</th><th>Archived By</th><th>Status</th><th>Actions</th></tr></thead><tbody id="archiveRows"></tbody></table></div>
    <div id="archiveEmpty" class="archive-empty" hidden></div>
    <footer class="archive-pager"><span id="archivePageStatus"></span><div><select id="archivePageSize" aria-label="Rows per page"><option>20</option><option>50</option><option>100</option></select><button type="button" id="archivePrev">Previous</button><button type="button" id="archiveNext">Next</button></div></footer>
  </section>
  <?php endif; ?>
</div>
</main>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<?php if ($canViewArchives): ?>
<div class="archive-modal" id="archiveDetailsModal" hidden><div class="archive-modal-backdrop" data-archive-close></div><section class="archive-dialog" role="dialog" aria-modal="true" aria-labelledby="archiveDetailsTitle"><header><h2 id="archiveDetailsTitle">Archived Record</h2><button type="button" data-archive-close aria-label="Close">&times;</button></header><div class="archive-dialog-body" id="archiveDetailsBody"></div><footer><button type="button" data-archive-close>Close</button><?php if ($canRestoreArchives): ?><button type="button" class="archive-restore" id="archiveDetailsRestore">Restore</button><?php endif; ?></footer></section></div>
<div class="archive-modal" id="archiveConfirmModal" hidden><div class="archive-modal-backdrop" data-archive-close></div><section class="archive-dialog archive-dialog-small" role="alertdialog" aria-modal="true" aria-labelledby="archiveConfirmTitle"><header><h2 id="archiveConfirmTitle">Restore record?</h2><button type="button" data-archive-close aria-label="Close">&times;</button></header><div class="archive-dialog-body"><p>This will make the archived record available again where applicable.</p><p id="archiveConfirmName" class="archive-confirm-name"></p><p class="archive-error" id="archiveRestoreError" hidden></p></div><footer><button type="button" data-archive-close>Cancel</button><button type="button" class="archive-restore" id="archiveConfirmRestore">Restore Record</button></footer></section></div>
<script src="../js/archives.js?v=<?= (int)filemtime(__DIR__ . '/../js/archives.js') ?>" defer></script>
<?php endif; ?>
<script src="../js/dashboard.js?v=<?= $dashboardScriptVersion ?>"></script>

<script>
const API = '../shared/auth_actions.php';
const SIGNIN = '../auth/signin.php';
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

function showToast(message, type = 'success') {
    const labels = { success: 'Saved', error: 'Error' };
    let toast = document.querySelector('.toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.className = 'toast';
        toast.innerHTML = `<div class="toast-label"><span class="toast-dot"></span><span class="toast-title"></span></div><div class="toast-msg"></div>`;
        document.body.appendChild(toast);
    }
    toast.querySelector('.toast-title').textContent = labels[type] ?? type;
    toast.querySelector('.toast-msg').textContent   = message;
    toast.className = `toast ${type}`;
    void toast.offsetWidth;
    toast.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => toast.classList.remove('show'), 3500);
}

function validateFields(form, fields) {
    let valid = true;
    fields.forEach(({ name, label }) => {
        const input = form.querySelector(`[name="${name}"]`);
        if (!input) return;
        const field = input.closest('.form-field');
        const err   = field?.querySelector('.field-error');
        if (!input.value.trim()) {
            input.classList.add('input-error');
            field?.classList.add('has-error');
            if (err) err.textContent = `${label} is required.`;
            valid = false;
        } else { clearErr(input); }
    });
    return valid;
}

function clearErr(input) {
    input.classList.remove('input-error');
    input.closest('.form-field')?.classList.remove('has-error');
    const e = input.closest('.form-field')?.querySelector('.field-error');
    if (e) e.textContent = '';
}

document.querySelectorAll('.form-field input').forEach(i => {
    i.addEventListener('input', () => { if (i.value.trim()) clearErr(i); });
});

document.querySelectorAll('.toggle-pw').forEach(btn => {
    btn.addEventListener('click', () => {
        const inp = document.getElementById(btn.dataset.target);
        inp.type  = inp.type === 'password' ? 'text' : 'password';
    });
});

async function postAction(fd) {
    fd.set('_csrf_token', CSRF_TOKEN);
    return fetch(API, { method: 'POST', body: fd }).then(r => r.json());
}

document.getElementById('btnSaveProfile').addEventListener('click', async () => {
    const form = document.getElementById('profileForm');
    if (!validateFields(form, [
        { name: 'first_name', label: 'First Name' },
        { name: 'last_name',  label: 'Last Name'  },
        { name: 'email',      label: 'Email'      },
        { name: 'username',   label: 'Username'   },
    ])) return;
    const fd = new FormData(form);
    fd.set('action', 'update_profile');
    try {
        const data = await postAction(fd);
        if (data.success) {
            const first = form.querySelector('[name="first_name"]').value.trim();
            const last  = form.querySelector('[name="last_name"]').value.trim();
            document.getElementById('displayName').textContent  = `${first} ${last}`;
            document.getElementById('avatarCircle').textContent = first.charAt(0).toUpperCase();
            showToast(data.message, 'success');
        } else { showToast(data.message, 'error'); }
    } catch { showToast('Request failed.', 'error'); }
});

document.getElementById('btnSavePassword').addEventListener('click', async () => {
    const form = document.getElementById('passwordForm');
    if (!validateFields(form, [
        { name: 'current_password', label: 'Current Password' },
        { name: 'new_password',     label: 'New Password'     },
        { name: 'confirm_password', label: 'Confirm Password' },
    ])) return;
    const newPw = form.querySelector('[name="new_password"]').value;
    const conf  = form.querySelector('[name="confirm_password"]').value;
    if (newPw !== conf) {
        const inp = form.querySelector('[name="confirm_password"]');
        inp.classList.add('input-error');
        inp.closest('.form-field')?.classList.add('has-error');
        const e = inp.closest('.form-field')?.querySelector('.field-error');
        if (e) e.textContent = 'Passwords do not match.';
        return;
    }
    const fd = new FormData(form);
    fd.set('action', 'change_password');
    try {
        const data = await postAction(fd);
        if (data.success) { form.reset(); showToast(data.message, 'success'); if (<?= current_user()['must_change_password'] ? 'true' : 'false' ?>) window.location.href = 'dashboard.php'; }
        else { showToast(data.message, 'error'); }
    } catch { showToast('Request failed.', 'error'); }
});

document.getElementById('btnSignOut').addEventListener('click', async () => {
    const fd = new FormData(); fd.set('action', 'logout');
    await postAction(fd).catch(() => {});
    window.location.href = SIGNIN;
});

document.getElementById('btnDeleteAccount')?.addEventListener('click', async () => {
    if (!confirm('Permanently delete your account? This cannot be undone.')) return;
    const fd = new FormData(); fd.set('action', 'delete_account');
    try {
        const data = await postAction(fd);
        if (data.success) {
            showToast('Account deleted. Redirecting…', 'error');
            setTimeout(() => { window.location.href = SIGNIN; }, 2000);
        } else { showToast(data.message, 'error'); }
    } catch { showToast('Request failed.', 'error'); }
});
</script>
</body>
</html>
