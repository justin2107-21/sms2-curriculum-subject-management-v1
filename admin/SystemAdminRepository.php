<?php

class SystemAdminRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function userCount(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) AS total FROM users')->fetch_assoc()['total'];
    }

    public function users(int $limit = 100): array
    {
        $safeLimit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            "SELECT users.id, users.username, users.email, users.first_name, users.last_name,
                    users.created_at, users.is_active,
                    GROUP_CONCAT(DISTINCT roles.display_name ORDER BY roles.display_name SEPARATOR ', ') AS role_names
             FROM users
             LEFT JOIN user_roles ON user_roles.user_id = users.id
             LEFT JOIN roles ON roles.id = user_roles.role_id AND roles.is_active = 1
             GROUP BY users.id, users.username, users.email, users.first_name,
                      users.last_name, users.created_at, users.is_active
             ORDER BY users.username ASC, users.id ASC
             LIMIT ?"
        );
        $stmt->bind_param('i', $safeLimit);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    public function permissionCount(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) AS total FROM permissions')->fetch_assoc()['total'];
    }

    public function permissions(): array
    {
        $result = $this->db->query(
            "SELECT permissions.name, permissions.module, permissions.description,
                    GROUP_CONCAT(DISTINCT roles.display_name ORDER BY roles.display_name SEPARATOR ', ') AS role_names
             FROM permissions
             LEFT JOIN role_permissions ON role_permissions.permission_id = permissions.id
             LEFT JOIN roles ON roles.id = role_permissions.role_id AND roles.is_active = 1
             GROUP BY permissions.id, permissions.name, permissions.module, permissions.description
             ORDER BY permissions.module ASC, permissions.name ASC"
        );
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }
}
