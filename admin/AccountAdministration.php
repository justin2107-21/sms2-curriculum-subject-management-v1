<?php
require_once __DIR__.'/../database/ProvisionalRoles.php';
final class AccountAccessDenied extends RuntimeException {}
final class AccountAdministration
{
    public function __construct(private mysqli $db) {}
    private function id(mixed $value): int {
        $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id)throw new InvalidArgumentException('Select a valid record.');return $id;
    }
    public function roles(): array {
        return $this->db->query('SELECT id,name,display_name,user_type,description FROM roles WHERE is_active=1 ORDER BY display_name')->fetch_all(MYSQLI_ASSOC);
    }
    /** Active defined roles this actor may assign. Only rbac.manage holders reach Super Admin and Admin. */
    public function assignableRoles(int $actor): array {
        $all=user_can($actor,'rbac.manage');$definitions=ProvisionalRoles::definitions();
        return array_values(array_filter($this->roles(),static fn(array $r): bool=>isset($definitions[$r['name']])&&($all||in_array($r['name'],ProvisionalRoles::LIMITED_ROLES,true))));
    }
    /** Server-side role ceiling: every role name must be one this actor may touch. */
    private function assertCeiling(int $actor,array $roleNames): void {
        if(user_can($actor,'rbac.manage'))return;
        foreach($roleNames as $name)if(!in_array($name,ProvisionalRoles::LIMITED_ROLES,true))throw new AccountAccessDenied('You can manage only Staff and Acad accounts.');
    }
    public function manageableBy(int $actor,array $roleNames): bool {
        try{$this->assertCeiling($actor,$roleNames);return true;}catch(AccountAccessDenied){return false;}
    }
    private function roleNames(int $user,bool $lock=false): array {
        $s=$this->db->prepare('SELECT r.name FROM user_roles ur JOIN roles r ON r.id=ur.role_id AND r.is_active=1 WHERE ur.user_id=?'.($lock?' FOR UPDATE':''));$s->bind_param('i',$user);$s->execute();$names=array_column($s->get_result()->fetch_all(MYSQLI_ASSOC),'name');$s->close();return $names;
    }
    /** Refuses to remove the last active Super Admin. Locks the other Super Admin rows so two demotions cannot both pass. */
    private function assertAnotherSuperAdmin(int $user): void {
        $s=$this->db->prepare("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.name=? AND u.is_active=1 AND u.id<>? FOR UPDATE");
        $name=ProvisionalRoles::SUPER_ADMIN;$s->bind_param('si',$name,$user);$s->execute();$others=$s->get_result()->num_rows;$s->close();
        if($others<1)throw new InvalidArgumentException('This is the last active Super Admin. Make another Super Admin first.');
    }
    /** Loads an account for editing, subject to the role ceiling. */
    public function getFor(int $id,int $actor): array {
        $this->assertCeiling($actor,$this->roleNames($id));return $this->get($id);
    }
    public function get(int $id): array {
        $s=$this->db->prepare('SELECT id,username,email,first_name,last_name,is_active,must_change_password FROM users WHERE id=?');$s->bind_param('i',$id);$s->execute();$user=$s->get_result()->fetch_assoc();$s->close();
        if(!$user)throw new InvalidArgumentException('Account not found.');
        $s=$this->db->prepare('SELECT role_id FROM user_roles WHERE user_id=?');$s->bind_param('i',$id);$s->execute();$user['role_ids']=array_column($s->get_result()->fetch_all(MYSQLI_ASSOC),'role_id');$s->close();return $user;
    }
    private function text(array $input,string $name,int $limit): string {
        if(!is_string($input[$name]??null))throw new InvalidArgumentException('All account fields are required.');
        $v=trim($input[$name]);if($v===''||mb_strlen($v)>$limit||preg_match('/[\x00-\x1f\x7f]/',$v))throw new InvalidArgumentException('Invalid or overlong account field.');return $v;
    }
    public function save(array $input,int $actor): array {
        $id=empty($input['id'])?null:$this->id($input['id']);
        if($id===$actor)throw new InvalidArgumentException('Use Account Settings for your own profile. You cannot change your own role or activation.');
        $username=$this->text($input,'username',60);$email=$this->text($input,'email',150);
        $first=$this->text($input,'first_name',100);$last=$this->text($input,'last_name',100);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!preg_match('/^[A-Za-z0-9_.-]{3,60}$/',$username))throw new InvalidArgumentException('Enter a valid username and email.');
        $roleId=$this->id($input['role_id']??null);
        $s=$this->db->prepare('SELECT name FROM roles WHERE id=?');$s->bind_param('i',$roleId);$s->execute();$role=$s->get_result()->fetch_assoc();$s->close();
        if(!$role||!in_array($role['name'],array_column($this->roles(),'name'),true)||!isset(ProvisionalRoles::definitions()[$role['name']]))throw new InvalidArgumentException('Select an allowed role.');
        $this->assertCeiling($actor,[$role['name']]);
        if(!in_array($input['is_active']??null,['0','1',0,1],true))throw new InvalidArgumentException('Select a valid account status.');$active=(int)$input['is_active'];
        $this->db->begin_transaction();
        try{
            if($id){
                $before=$this->get($id);
                // Only prototype-role accounts are edited through this interface. Legacy accounts are preserved.
                $names=$this->roleNames($id,true);
                $this->assertCeiling($actor,$names);
                if(in_array(ProvisionalRoles::SUPER_ADMIN,$names,true)&&($role['name']!==ProvisionalRoles::SUPER_ADMIN||$active!==1))$this->assertAnotherSuperAdmin($id);
                if(count($names)!==1||!isset(ProvisionalRoles::definitions()[$names[0]]))throw new InvalidArgumentException('This legacy account is preserved; only accounts with a current role can be edited here.');
                $s=$this->db->prepare('UPDATE users SET username=?,email=?,first_name=?,last_name=?,is_active=? WHERE id=?');$s->bind_param('ssssii',$username,$email,$first,$last,$active,$id);$s->execute();$s->close();
            }else{
                $password=bin2hex(random_bytes(12));$hash=password_hash($password,PASSWORD_DEFAULT);
                $s=$this->db->prepare("INSERT INTO users(username,email,first_name,last_name,password_hash,is_active,must_change_password) VALUES(?,?,?,?,?,?,1)");$s->bind_param('sssssi',$username,$email,$first,$last,$hash,$active);$s->execute();$id=(int)$this->db->insert_id;$s->close();
            }
            $s=$this->db->prepare('DELETE FROM user_roles WHERE user_id=?');$s->bind_param('i',$id);$s->execute();$s->close();
            $s=$this->db->prepare('INSERT INTO user_roles(user_id,role_id,assigned_by) VALUES(?,?,?)');$s->bind_param('iii',$id,$roleId,$actor);$s->execute();$s->close();
            audit_log_required(isset($before)?'USER_ACCOUNT_UPDATE':'USER_ACCOUNT_CREATE','user',$id,'Administrator saved an account.',['role'=>$role['name'],'active'=>$active]);
            audit_log_required('RBAC_ROLE_ASSIGNMENT','user',$id,'Administrator assigned a role.',['role_id'=>$roleId,'previous_role_ids'=>$before['role_ids']??[]]);
            if(isset($before)&&(int)$before['is_active']!==$active)audit_log_required($active?'USER_ACCOUNT_ACTIVATE':'USER_ACCOUNT_DEACTIVATE','user',$id,'Administrator changed account activation.');
            $this->db->commit();return ['user'=>$this->get($id)]+(isset($password)?['temporary_password'=>$password]:[]);
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    public function savePermissions(array $input,int $actor): void {
        $id=$this->id($input['role_id']??null);$s=$this->db->prepare('SELECT name FROM roles WHERE id=?');$s->bind_param('i',$id);$s->execute();$role=$s->get_result()->fetch_assoc();$s->close();
        if(!user_can($actor,'rbac.manage'))throw new AccountAccessDenied('Only a Super Admin can edit permissions.');
        if(!$role||!isset(ProvisionalRoles::definitions()[$role['name']])||$role['name']===ProvisionalRoles::SUPER_ADMIN)throw new InvalidArgumentException('This role\'s permissions cannot be edited here.');
        $s=$this->db->prepare('SELECT 1 FROM user_roles WHERE user_id=? AND role_id=?');$s->bind_param('ii',$actor,$id);$s->execute();$own=$s->get_result()->num_rows;$s->close();
        if($own)throw new InvalidArgumentException('You cannot change permissions for your own role.');
        $names=$input['permissions']??[];if(!is_array($names))throw new InvalidArgumentException('Invalid permissions.');
        $definition=ProvisionalRoles::definitions()[$role['name']];
        $allowed=$definition['grantable_permissions']??$definition['permissions'];
        foreach($names as $name)if(!is_string($name)||!in_array($name,$allowed,true))throw new InvalidArgumentException('Permission exceeds the authority of this role.');
        foreach(['dashboard.view','account.view','account.update'] as $required)if(!in_array($required,$names,true))throw new InvalidArgumentException('Dashboard and personal account access must remain available.');
        $this->db->begin_transaction();try{
            $s=$this->db->prepare('DELETE FROM role_permissions WHERE role_id=?');$s->bind_param('i',$id);$s->execute();$s->close();
            foreach(array_unique($names) as $name){$s=$this->db->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE name=?');$s->bind_param('is',$id,$name);$s->execute();$s->close();}
            audit_log_required('RBAC_PERMISSION_CHANGE','role',$id,'Administrator updated role permissions.',['permissions'=>array_values(array_unique($names))]);$this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
}
