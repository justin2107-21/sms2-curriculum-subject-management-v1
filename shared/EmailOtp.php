<?php
// Optional Email OTP for sign-in (OTP_ENABLED=1). Rules, all enforced here and tested in tests/email-otp.php:
//   - 6 digits from random_int, valid exactly 5 minutes, single use; only a salted hash is stored (table login_otp_challenges).
//   - A new code marks every older code of that user as used.
//   - 5 wrong entries lock a code. A new code can be requested after 60 seconds, at most 3 codes per rolling 15 minutes.
//   - Times are UTC. The clock is injectable so tests do not wait.
// This class never sends mail and never writes the audit log; auth_actions.php does both so the code is never logged.

final class EmailOtpService
{
    public const CODE_LENGTH = 6;
    public const VALID_SECONDS = 300;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_COOLDOWN_SECONDS = 60;
    public const SEND_LIMIT = 3;
    public const SEND_WINDOW_SECONDS = 900;
    /** After the password step the browser may sit on the code screen this long; then the password is asked again. */
    public const PENDING_SECONDS = 900;

    /** @var callable */
    private $clock;

    public function __construct(private mysqli $db, ?callable $clock = null)
    {
        $this->clock = $clock ?? 'time';
    }

    /** OTP_ENABLED defaults to off. Anything but 1, true, yes or on is off. */
    public static function enabled(): bool
    {
        $flag = strtolower(trim((string)(getenv('OTP_ENABLED') ?: '0')));
        return in_array($flag, ['1', 'true', 'yes', 'on'], true);
    }

    public static function validEmail(?string $email): bool
    {
        $email = trim((string)$email);
        return $email !== '' && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** True when this user must enter a code: the flag is on and the account has a usable email address. */
    public static function requiredFor(array $user): bool
    {
        return self::enabled() && self::validEmail($user['email'] ?? null);
    }

    /** j***@example.com style hint for the code screen. */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at < 1) return '***';
        return substr($email, 0, 1) . str_repeat('*', max(2, min(6, $at - 1))) . substr($email, $at);
    }

    private function now(): int { return (int)($this->clock)(); }
    private static function stamp(int $time): string { return gmdate('Y-m-d H:i:s', $time); }
    private static function parse(string $stamp): int { return (int)strtotime($stamp . ' UTC'); }

    private static function hash(int $userId, string $code, string $salt): string
    {
        return $salt . ':' . hash('sha256', $salt . ':' . $userId . ':' . $code);
    }

    /**
     * Create a new code. Returns one of:
     *   ['status' => 'ok', 'code', 'challenge_id', 'expires_at', 'resend_at']
     *   ['status' => 'cooldown', 'retry_after', 'valid_until' (the still-usable earlier code, or 0)]
     *   ['status' => 'limit', 'retry_after']
     * The plain code is returned once, for the email, and is not stored.
     */
    public function issue(int $userId): array
    {
        $now = $this->now();
        $this->db->begin_transaction();
        try {
            $windowStart = self::stamp($now - self::SEND_WINDOW_SECONDS);
            $stmt = $this->db->prepare('SELECT id, expires_at, attempts, used_at, created_at FROM login_otp_challenges WHERE user_id = ? AND created_at > ? ORDER BY id DESC FOR UPDATE');
            $stmt->bind_param('is', $userId, $windowStart);
            $stmt->execute();
            $recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (count($recent) >= self::SEND_LIMIT) {
                $oldest = self::parse((string)end($recent)['created_at']);
                $this->db->commit();
                return ['status' => 'limit', 'retry_after' => max(1, $oldest + self::SEND_WINDOW_SECONDS - $now)];
            }
            if ($recent) {
                $last = $recent[0];
                $sentAt = self::parse((string)$last['created_at']);
                if ($now - $sentAt < self::RESEND_COOLDOWN_SECONDS) {
                    $usable = $last['used_at'] === null && (int)$last['attempts'] < self::MAX_ATTEMPTS && self::parse((string)$last['expires_at']) > $now;
                    $this->db->commit();
                    return ['status' => 'cooldown', 'retry_after' => self::RESEND_COOLDOWN_SECONDS - ($now - $sentAt), 'valid_until' => $usable ? self::parse((string)$last['expires_at']) : 0];
                }
            }

            $usedAt = self::stamp($now);
            $stmt = $this->db->prepare('UPDATE login_otp_challenges SET used_at = ? WHERE user_id = ? AND used_at IS NULL');
            $stmt->bind_param('si', $usedAt, $userId);
            $stmt->execute();
            $stmt->close();

            $code = str_pad((string)random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
            $hash = self::hash($userId, $code, bin2hex(random_bytes(8)));
            $expires = $now + self::VALID_SECONDS;
            $expiresAt = self::stamp($expires);
            $createdAt = self::stamp($now);
            $stmt = $this->db->prepare('INSERT INTO login_otp_challenges (user_id, code_hash, expires_at, attempts, used_at, created_at) VALUES (?, ?, ?, 0, NULL, ?)');
            $stmt->bind_param('isss', $userId, $hash, $expiresAt, $createdAt);
            $stmt->execute();
            $id = (int)$this->db->insert_id;
            $stmt->close();
            // Old rows are of no use after a day: keep the table small.
            $old = self::stamp($now - 86400);
            $stmt = $this->db->prepare('DELETE FROM login_otp_challenges WHERE created_at < ?');
            $stmt->bind_param('s', $old);
            $stmt->execute();
            $stmt->close();
            $this->db->commit();
            return ['status' => 'ok', 'code' => $code, 'challenge_id' => $id, 'expires_at' => $expires, 'resend_at' => $now + self::RESEND_COOLDOWN_SECONDS];
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    /** The email could not be sent: remove that code so it neither counts toward the limit nor stays usable. */
    public function discard(int $challengeId): void
    {
        $stmt = $this->db->prepare('DELETE FROM login_otp_challenges WHERE id = ?');
        $stmt->bind_param('i', $challengeId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Check an entered code against the user's newest code. Returns ['status' => ...]:
     *   ok | wrong (+ attempts_left) | locked | expired | used | none | invalid_format
     * ok marks the code used, so it cannot be used twice. Only the newest code can ever succeed.
     */
    public function verify(int $userId, string $entered): array
    {
        $entered = trim($entered);
        if (!preg_match('/^[0-9]{6}$/D', $entered)) return ['status' => 'invalid_format'];
        $now = $this->now();
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare('SELECT id, code_hash, expires_at, attempts, used_at FROM login_otp_challenges WHERE user_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) { $this->db->commit(); return ['status' => 'none']; }
            if ($row['used_at'] !== null) { $this->db->commit(); return ['status' => 'used']; }
            $id = (int)$row['id'];
            if (self::parse((string)$row['expires_at']) <= $now) {
                $usedAt = self::stamp($now);
                $stmt = $this->db->prepare('UPDATE login_otp_challenges SET used_at = ? WHERE id = ?');
                $stmt->bind_param('si', $usedAt, $id);
                $stmt->execute();
                $stmt->close();
                $this->db->commit();
                return ['status' => 'expired'];
            }
            if ((int)$row['attempts'] >= self::MAX_ATTEMPTS) { $this->db->commit(); return ['status' => 'locked']; }

            [$salt] = explode(':', (string)$row['code_hash'], 2);
            if (hash_equals((string)$row['code_hash'], self::hash($userId, $entered, $salt))) {
                $usedAt = self::stamp($now);
                $stmt = $this->db->prepare('UPDATE login_otp_challenges SET used_at = ? WHERE id = ? AND used_at IS NULL');
                $stmt->bind_param('si', $usedAt, $id);
                $stmt->execute();
                $won = $stmt->affected_rows === 1;
                $stmt->close();
                $this->db->commit();
                return ['status' => $won ? 'ok' : 'used'];
            }
            $attempts = (int)$row['attempts'] + 1;
            $stmt = $this->db->prepare('UPDATE login_otp_challenges SET attempts = ? WHERE id = ?');
            $stmt->bind_param('ii', $attempts, $id);
            $stmt->execute();
            $stmt->close();
            $this->db->commit();
            return $attempts >= self::MAX_ATTEMPTS ? ['status' => 'locked'] : ['status' => 'wrong', 'attempts_left' => self::MAX_ATTEMPTS - $attempts];
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }
}
