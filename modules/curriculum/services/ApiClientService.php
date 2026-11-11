<?php

/** Raised for machine-API failures. Carries a stable code, an HTTP status and optional response headers. */
final class ApiException extends RuntimeException
{
    public function __construct(public string $errorCode, string $message, public int $httpStatus, public array $headers = [])
    {
        parent::__construct($message);
    }
}

/**
 * API clients: keys, authentication and the per-client rate limit.
 * A key looks like csms_<8 chars>_<43 chars>. Only an HMAC-SHA256 of the secret part (keyed with API_KEY_PEPPER) is stored.
 * The plain key exists only in the return value of create() and rotate(); it is never logged, audited or stored.
 */
final class ApiClientService
{
    public const KEY_PATTERN = '/^csms_([a-z0-9]{8})_([A-Za-z0-9_-]{43})$/D';
    public const SCOPES = ['write', 'read', 'read_write'];

    public function __construct(private mysqli $db) {}

    private static function pepper(): string
    {
        $pepper = (string)(getenv('API_KEY_PEPPER') ?: '');
        if (strlen($pepper) < 32) throw new RuntimeException('API_KEY_PEPPER is not configured (at least 32 characters).');
        return $pepper;
    }

    public static function hashSecret(string $secret): string
    {
        return hash_hmac('sha256', $secret, self::pepper());
    }

    /** Keyed hash for rejected-item evidence: one-way, not guessable offline without the pepper. */
    public static function evidenceHash(string $canonical): string
    {
        return hash_hmac('sha256', $canonical, self::pepper());
    }

    /** @return array{key:string,prefix:string,hash:string} */
    private static function generate(): array
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $prefix = '';
        for ($i = 0; $i < 8; $i++) $prefix .= $alphabet[random_int(0, 35)];
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        return ['key' => 'csms_' . $prefix . '_' . $secret, 'prefix' => $prefix, 'hash' => self::hashSecret($secret)];
    }

    private function row(string $sql, string $types = '', array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    /** Creates a client and returns the plain key ONCE. Audited in the same transaction. */
    public function create(string $name, string $sourceSystem, string $scope, int $operatorId): array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 100) throw new InvalidArgumentException('Name must be 1 to 100 characters.');
        if (!preg_match('/^[a-z][a-z0-9_-]{1,39}$/D', $sourceSystem)) throw new InvalidArgumentException('Source system must be 2 to 40 characters: lowercase letters, digits, underscores and hyphens, starting with a letter.');
        if (!in_array($scope, self::SCOPES, true)) throw new InvalidArgumentException('Scope must be write, read or read_write.');
        $generated = self::generate();
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare('INSERT INTO api_clients (name, source_system, key_prefix, key_hash, scope, created_by) VALUES (?,?,?,?,?,?)');
            $stmt->bind_param('sssssi', $name, $sourceSystem, $generated['prefix'], $generated['hash'], $scope, $operatorId);
            $stmt->execute();
            $id = (int)$this->db->insert_id;
            $stmt->close();
            audit_log_required('API_CLIENT_CREATE', 'api_client', $id, 'API client created.',
                ['name' => $name, 'source_system' => $sourceSystem, 'scope' => $scope, 'key_prefix' => $generated['prefix']]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
        return ['id' => $id, 'name' => $name, 'source_system' => $sourceSystem, 'scope' => $scope, 'key' => $generated['key']];
    }

    /** Replaces the key of an active client; the old key stops working at once. Returns the new plain key ONCE. */
    public function rotate(string $name, int $operatorId): array
    {
        $this->db->begin_transaction();
        try {
            $client = $this->row('SELECT id, name, source_system, scope, is_active FROM api_clients WHERE name = ? FOR UPDATE', 's', [$name]);
            if (!$client) throw new InvalidArgumentException('API client not found.');
            if ((int)$client['is_active'] !== 1) throw new InvalidArgumentException('A revoked client cannot be rotated. Create a new client.');
            $generated = self::generate();
            $stmt = $this->db->prepare('UPDATE api_clients SET key_prefix = ?, key_hash = ?, rotated_at = NOW() WHERE id = ?');
            $id = (int)$client['id'];
            $stmt->bind_param('ssi', $generated['prefix'], $generated['hash'], $id);
            $stmt->execute(); $stmt->close();
            audit_log_required('API_CLIENT_ROTATE', 'api_client', $id, 'API client key rotated.',
                ['name' => $client['name'], 'source_system' => $client['source_system'], 'key_prefix' => $generated['prefix']]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
        return ['id' => $id, 'name' => $client['name'], 'source_system' => $client['source_system'], 'scope' => $client['scope'], 'key' => $generated['key']];
    }

    public function revoke(string $name, int $operatorId): array
    {
        $this->db->begin_transaction();
        try {
            $client = $this->row('SELECT id, name, source_system, is_active FROM api_clients WHERE name = ? FOR UPDATE', 's', [$name]);
            if (!$client) throw new InvalidArgumentException('API client not found.');
            $id = (int)$client['id'];
            $stmt = $this->db->prepare('UPDATE api_clients SET is_active = 0, revoked_at = COALESCE(revoked_at, NOW()) WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute(); $stmt->close();
            audit_log_required('API_CLIENT_REVOKE', 'api_client', $id, 'API client revoked.',
                ['name' => $client['name'], 'source_system' => $client['source_system']]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
        return ['id' => $id, 'name' => $client['name'], 'revoked' => true];
    }

    public function listClients(): array
    {
        return $this->db->query('SELECT name, source_system, scope, key_prefix, is_active, created_at, rotated_at, revoked_at, last_used_at FROM api_clients ORDER BY name')->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Authenticates an Authorization header value. The same 401 answers a missing, malformed, unknown, revoked or wrong key.
     * @throws ApiException
     */
    public function authenticate(?string $header): array
    {
        $deny = new ApiException('unauthorized', 'Invalid or missing API key.', 401, ['WWW-Authenticate' => 'Bearer']);
        if ($header === null || !preg_match('/^Bearer\s+(\S+)$/Di', trim($header), $bearer)) throw $deny;
        if (!preg_match(self::KEY_PATTERN, $bearer[1], $parts)) throw $deny;
        $client = $this->row('SELECT * FROM api_clients WHERE key_prefix = ?', 's', [$parts[1]]);
        // Always compute the keyed hash and compare in constant time, so an unknown prefix costs the same as a wrong secret.
        $expected = $client['key_hash'] ?? str_repeat('0', 64);
        $matches = hash_equals($expected, self::hashSecret($parts[2]));
        if (!$client || !$matches || (int)$client['is_active'] !== 1 || $client['revoked_at'] !== null) throw $deny;
        return $client;
    }

    /** Counts this request in the client's one-minute window and refuses with 429 over the limit. Also stamps last_used_at. */
    public function enforceRateLimit(int $clientId, int $limitPerMinute): void
    {
        // Assignments run left to right: the count decides using the old window_start, then the window is restarted.
        $stmt = $this->db->prepare(
            'UPDATE api_clients SET
               window_count = IF(window_start IS NULL OR window_start <= NOW() - INTERVAL 60 SECOND, 1, window_count + 1),
               window_start = IF(window_start IS NULL OR window_start <= NOW() - INTERVAL 60 SECOND, NOW(), window_start),
               last_used_at = NOW()
             WHERE id = ?');
        $stmt->bind_param('i', $clientId);
        $stmt->execute(); $stmt->close();
        $row = $this->row('SELECT window_count, GREATEST(1, 60 - TIMESTAMPDIFF(SECOND, window_start, NOW())) AS retry FROM api_clients WHERE id = ?', 'i', [$clientId]);
        if ($row && (int)$row['window_count'] > $limitPerMinute) {
            throw new ApiException('rate_limited', 'Too many requests. Slow down and retry later.', 429, ['Retry-After' => (string)(int)$row['retry']]);
        }
    }
}
