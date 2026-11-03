<?php
require_once __DIR__.'/../shared/bootstrap.php';
require_permission('rbac.manage');
require_once __DIR__.'/AccountAdministration.php';
$adminRoles=(new AccountAdministration(authorization_connection()))->roles();
$prototypeDefinitions=ProvisionalRoles::definitions();
?>
<div class="curriculum-modal" id="adminAccountModal" hidden><div class="modal-backdrop" data-modal-close></div>
<section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="adminAccountTitle">
<div class="modal-header"><div><h2 id="adminAccountTitle">Create User</h2><p class="modal-context">Provisional user roles used for prototype development and evaluation, subject to client validation.</p></div><button class="modal-close-button" type="button" data-modal-close aria-label="Close">&times;</button></div>
<form id="adminAccountForm" class="domain-form"><input type="hidden" name="action" value="save"><input type="hidden" name="id">
<div class="form-grid">
<div class="form-field"><label for="adminUsername">Username</label><input id="adminUsername" name="username" autocomplete="off" required maxlength="60" pattern="[A-Za-z0-9_.-]{3,60}"></div>
<div class="form-field"><label for="adminEmail">Email</label><input id="adminEmail" name="email" type="email" autocomplete="off" required maxlength="150"></div>
<div class="form-field"><label for="adminFirstName">First Name</label><input id="adminFirstName" name="first_name" autocomplete="off" required maxlength="100"></div>
<div class="form-field"><label for="adminLastName">Last Name</label><input id="adminLastName" name="last_name" autocomplete="off" required maxlength="100"></div>
<div class="form-field"><label for="adminRole">Role</label><select id="adminRole" name="role_id" required><option value="">Select role</option>
<?php foreach($adminRoles as $role): if(!isset($prototypeDefinitions[$role['name']]))continue; ?><option value="<?= (int)$role['id'] ?>"><?= htmlspecialchars($role['display_name'],ENT_QUOTES,'UTF-8') ?></option><?php endforeach; ?>
</select></div><div class="form-field"><label for="adminActive">Account Status</label><select id="adminActive" name="is_active"><option value="1">Active</option><option value="0">Inactive</option></select></div>
</div><p class="form-error" id="adminAccountMessage" role="alert" hidden></p>
<div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Save User</button></div></form>
</section></div>
<div class="curriculum-modal" id="adminCredentialModal" hidden><div class="modal-backdrop" data-modal-close></div><section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="adminCredentialTitle"><div class="modal-header"><h2 id="adminCredentialTitle">Temporary Login</h2><button class="modal-close-button" type="button" data-modal-close aria-label="Close">&times;</button></div><div class="domain-form"><p>Shown once. Share securely with the account holder. A password change is required on first login.</p><output id="adminTemporaryCredential"></output></div><div class="modal-actions"><button type="button" class="button-primary" data-modal-close>Done</button></div></section></div>
