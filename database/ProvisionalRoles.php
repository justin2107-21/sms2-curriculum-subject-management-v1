<?php
// Provisional user roles used for prototype development and evaluation, subject to client validation.
final class ProvisionalRoles
{
    public static function definitions(): array
    {
        $read = ['dashboard.view','account.view','account.update','program.view','curriculum.view',
            'subject.view','prerequisite.view','elective.view','program_assignment.view',
            'subject_history.view','equivalency.view','grade_weighting.view','compliance.view','curriculum_export.view'];
        $academicManage = ['program.create','program.update','curriculum.create','curriculum.update',
            'curriculum.version.create','subject.create','subject.update','prerequisite.manage',
            'elective.manage','program_assignment.manage','grade_weighting.manage','compliance.run',
            'compliance.manage','equivalency.manage','archive.view','archive.restore'];
        $definitions = [
            'system_administrator' => ['label'=>'System Administrator','description'=>'Manages accounts, roles, permissions, security, audit logs, and technical system settings.',
                'permissions'=>array_merge($read,$academicManage,['rbac.manage','audit.view','settings.view']), 'username'=>'csms_admin','name'=>'System Administrator'],
            'academic_curriculum_staff' => ['label'=>'Academic / Curriculum Staff','description'=>'Operational academic user for curriculum and subject management.',
                'permissions'=>array_merge($read,['program.create','program.update','curriculum.create','curriculum.update','curriculum.version.create','subject.create','subject.update','prerequisite.manage','elective.manage','program_assignment.manage','grade_weighting.manage','compliance.run','archive.view','archive.restore']), 'username'=>'academic_staff','name'=>'Academic Staff'],
            'department_head_curriculum_reviewer' => ['label'=>'Department Head / Curriculum Reviewer','description'=>'Reviews curriculum structures, equivalencies, compliance findings, and CIMF recommendations.',
                'permissions'=>array_merge($read,['equivalency.manage','compliance.run']), 'username'=>'curriculum_reviewer','name'=>'Curriculum Reviewer'],
            'faculty_academic_viewer' => ['label'=>'Faculty / Academic Viewer','description'=>'Read-only academic curriculum and subject viewer.',
                'permissions'=>$read, 'username'=>'faculty_viewer','name'=>'Faculty Viewer'],
        ];
        // These are available for deliberate RBAC-manager assignment only. They
        // are not included in the default permissions reconciled by seed().
        $task3 = ['curriculum.reference.attach','compliance.reference.verify','curriculum.reference.classify'];
        foreach ($definitions as &$definition) {
            $definition['grantable_permissions'] = array_values(array_unique(
                array_merge($definition['permissions'], $task3)
            ));
        }
        unset($definition);
        return $definitions;
    }
    public static function seed(mysqli $db): array
    {
        foreach ([['compliance.run','compliance','Run internal configured compliance validation.'],['compliance.manage','compliance','Manage configured compliance rules and references.'],['settings.view','settings','View technical system settings without secrets.']] as [$name,$module,$description]) {
            $s=$db->prepare('INSERT INTO permissions(name,module,description) VALUES(?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)');
            $s->bind_param('sss',$name,$module,$description);$s->execute();$s->close();
        }
        // Retain the established legacy rule-manager capability to run its checks.
        // New read-only roles do not inherit this grant.
        $db->query("INSERT IGNORE INTO role_permissions(role_id,permission_id)
            SELECT rp.role_id,run_permission.id FROM role_permissions rp
            JOIN permissions existing_permission ON existing_permission.id=rp.permission_id AND existing_permission.name='compliance.manage'
            JOIN permissions run_permission ON run_permission.name='compliance.run'");
        $ids=[];
        foreach (self::definitions() as $key=>$role) {
            $description=$role['description'].' Provisional; subject to client validation.';
            $s=$db->prepare('INSERT INTO roles(name,display_name,description,is_system) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),description=VALUES(description)');
            $s->bind_param('sss',$key,$role['label'],$description);$s->execute();$s->close();
            $s=$db->prepare('SELECT id FROM roles WHERE name=?');$s->bind_param('s',$key);$s->execute();$id=(int)$s->get_result()->fetch_row()[0];$s->close();$ids[$key]=$id;
            // Only these four managed prototype matrices are reconciled. Legacy roles are retained.
            $s=$db->prepare('DELETE FROM role_permissions WHERE role_id=?');$s->bind_param('i',$id);$s->execute();$s->close();
            foreach($role['permissions'] as $permission) {
                $s=$db->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE name=?');$s->bind_param('is',$id,$permission);$s->execute();
                if($s->affected_rows!==1)throw new RuntimeException('Required permission missing.');$s->close();
            }
        }
        return $ids;
    }
}
