<?php
require_once __DIR__.'/../shared/bootstrap.php';
require_permission('rbac.manage');
require_once __DIR__.'/AccountAdministration.php';
$definitions=ProvisionalRoles::definitions();$db=authorization_connection();
$assigned=$db->query('SELECT r.name,p.name AS permission FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id')->fetch_all(MYSQLI_ASSOC);
$grants=[];foreach($assigned as $item)$grants[$item['name']][]=$item['permission'];
foreach((new AccountAdministration($db))->roles() as $role):
if(!isset($definitions[$role['name']])||$role['name']===ProvisionalRoles::SUPER_ADMIN)continue;
$own=in_array($role['name'],get_user_roles((int)current_user_id()),true);
$grantable=$definitions[$role['name']]['grantable_permissions']??$definitions[$role['name']]['permissions'];
?>
<section class="system-admin-card"><div class="system-admin-summary"><h2><?= htmlspecialchars($role['display_name'],ENT_QUOTES,'UTF-8') ?></h2><p><?= $own ? 'Your own role permissions are protected from modification.' : 'Select permissions within this role’s defined authority.' ?></p></div>
<form class="system-admin-summary" data-role-permissions><input type="hidden" name="action" value="permissions"><input type="hidden" name="role_id" value="<?= (int)$role['id'] ?>">
<div class="form-grid">
<?php foreach($grantable as $permission): ?><label><input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($permission,ENT_QUOTES,'UTF-8') ?>" <?= in_array($permission,$grants[$role['name']]??[],true)?'checked':'' ?> <?= $own?'disabled':'' ?>> <?= htmlspecialchars($permission,ENT_QUOTES,'UTF-8') ?></label><?php endforeach; ?>
</div><?php if(!$own): ?><div class="modal-actions"><button class="button-primary" type="submit">Save Permissions</button></div><?php endif; ?></form></section>
<?php endforeach; ?>
