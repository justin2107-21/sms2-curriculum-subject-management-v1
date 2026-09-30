<?php
// One-time first-admin provisioning; deliberately not a password-reset tool.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('CSMS_BOOTSTRAP_ADMIN') !== '1') { exit(0); }
require_once __DIR__.'/../shared/database_config.php';
require_once __DIR__.'/../shared/audit.php';
$conn=null;$lock=null;$transaction=false;$stage='input validation';
function bootstrapAdminText(string $name,int $limit): string {
    $value=trim(getenv($name) ?: '');
    if ($value==='' || !preg_match('//u',$value) || preg_match('/[\x00-\x1f\x7f]/',$value)
        || preg_match_all('/./us',$value)>$limit) { throw new RuntimeException('Invalid bootstrap field.'); }
    return $value;
}
try {
    $username=bootstrapAdminText('CSMS_ADMIN_USERNAME',60);
    $config=csms_database_config();
    $conn=csms_database_connect($config);
    $stage='schema readiness';
    // Reuse the authoritative checksum/history verifier, without applying DDL.
    $process=proc_open([PHP_BINARY,__DIR__.'/deploy.php','--status'],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) { throw new RuntimeException('Status unavailable.'); }
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);
    stream_get_contents($pipes[2]);fclose($pipes[2]);
    $status=json_decode($output,true);
    if (proc_close($process)!==0 || !isset($status['pending'],$status['applied']) || $status['pending']!==[] || $status['applied']<1) {
        throw new RuntimeException('Migrations must be complete.');
    }
    $lock='csms-admin-'.substr(hash('sha256',$config['database']),0,48);
    $stmt=$conn->prepare('SELECT GET_LOCK(?,10)');$stmt->bind_param('s',$lock);$stmt->execute();
    if ((int)$stmt->get_result()->fetch_row()[0]!==1) { $lock=null;throw new RuntimeException('Provisioning busy.'); }
    $stmt->close();
    $conn->begin_transaction();$transaction=true;
    $stage='administrator role verification';
    $role=$conn->query("SELECT id FROM roles WHERE name='admin' FOR UPDATE")->fetch_assoc();
    if (!$role) { throw new RuntimeException('Administrator role unavailable.'); }
    $roleId=(int)$role['id'];
    $stmt=$conn->prepare('SELECT id,role FROM users WHERE username=? FOR UPDATE');
    $stmt->bind_param('s',$username);$stmt->execute();$existing=$stmt->get_result()->fetch_assoc();$stmt->close();
    if ($existing) {
        $stage='existing account verification';$id=(int)$existing['id'];
        $stmt=$conn->prepare('SELECT 1 FROM user_roles WHERE user_id=? AND role_id=?');
        $stmt->bind_param('ii',$id,$roleId);$stmt->execute();$mapped=$stmt->get_result()->num_rows===1;$stmt->close();
        if (!$mapped || $existing['role']!=='admin') { throw new RuntimeException('Existing account is not an administrator; no promotion permitted.'); }
        $conn->commit();$transaction=false;
        echo "Administrator provisioning already complete; account and password unchanged.\n";
    } else {
        $stage='first administrator verification';
        if ($conn->query("SELECT 1 FROM user_roles INNER JOIN roles ON roles.id=user_roles.role_id WHERE roles.name='admin' LIMIT 1")->num_rows>0
            || $conn->query("SELECT 1 FROM users WHERE role='admin' LIMIT 1")->num_rows>0) {
            throw new RuntimeException('An administrator already exists; use normal account administration.');
        }
        $stage='administrator details validation';
        $email=bootstrapAdminText('CSMS_ADMIN_EMAIL',150);
        $first=bootstrapAdminText('CSMS_ADMIN_FIRST_NAME',100);
        $last=bootstrapAdminText('CSMS_ADMIN_LAST_NAME',100);
        $password=getenv('CSMS_ADMIN_PASSWORD');
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || !is_string($password)
            || strlen($password)<12 || strlen($password)>72 || strpos($password,"\0")!==false) {
            throw new RuntimeException('Valid email and strong 12-72 byte password required.');
        }
        $hash=password_hash($password,PASSWORD_DEFAULT);
        if (!is_string($hash)) { throw new RuntimeException('Password hashing failed.'); }
        unset($password);
        $stage='transactional account creation';
        $stmt=$conn->prepare("INSERT INTO users(username,email,first_name,last_name,password_hash,role) VALUES (?,?,?,?,?,'admin')");
        $stmt->bind_param('sssss',$username,$email,$first,$last,$hash);$stmt->execute();$id=(int)$conn->insert_id;$stmt->close();
        $stmt=$conn->prepare('INSERT INTO user_roles(user_id,role_id,assigned_by) VALUES (?,?,NULL)');
        $stmt->bind_param('ii',$id,$roleId);$stmt->execute();
        if ($stmt->affected_rows!==1) { throw new RuntimeException('Role assignment failed.'); }$stmt->close();
        audit_log_required('ADMIN_BOOTSTRAP_CREATE','user',$id,'First administrator provisioned by explicit CLI bootstrap.',['source'=>'startup_cli']);
        $conn->commit();$transaction=false;
        echo "First administrator provisioned; disable and remove bootstrap variables now.\n";
    }
} catch (Throwable $error) {
    if ($conn instanceof mysqli && $transaction) { try {$conn->rollback();}catch(Throwable $ignored){} }
    // No driver messages, form values, password hashes or exception traces.
    fwrite(STDERR,'Administrator provisioning failed at '.$stage.'; no account changes committed.'.PHP_EOL);
    $failed=true;
} finally {
    if ($conn instanceof mysqli && $lock!==null) {
        try {$stmt=$conn->prepare('SELECT RELEASE_LOCK(?)');$stmt->bind_param('s',$lock);$stmt->execute();$stmt->close();}catch(Throwable $ignored){}
    }
}
exit(isset($failed)?1:0);
