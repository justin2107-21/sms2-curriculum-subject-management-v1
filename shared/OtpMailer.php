<?php
// Sends the sign-in code by email with PHPMailer (lib/PHPMailer, no composer).
// Settings come from real environment variables, read the same way as shared/database_config.php:
//   SMTP_HOST, SMTP_PORT (default 587), SMTP_USER, SMTP_PASS, SMTP_FROM, SMTP_FROM_NAME. Nothing here logs the code, the password or the address.
// Transport: port 587 (or any port except 465) requires STARTTLS; port 465 uses implicit TLS. The server certificate and its host name are always
// verified (never switched off). Connect and read timeouts are 10 seconds, so a blocked port gives a quick failure and sign-in stays closed.
// Only a loopback host (a local test sink) may skip TLS.

final class OtpMailer
{
    /** @var callable|null Tests replace the real SMTP send: fn(string $to, string $subject, string $html, string $text): bool */
    private static $transport = null;

    /** Connect and read timeout for the SMTP server. */
    public const TIMEOUT_SECONDS = 10;

    public static function useTransport(?callable $transport): void { self::$transport = $transport; }

    private static function env(string $name, string $fallback = ''): string
    {
        $value = getenv($name);
        return $value === false || $value === '' ? $fallback : (string)$value;
    }

    public static function configured(): bool
    {
        return self::env('SMTP_HOST') !== '' && filter_var(self::env('SMTP_FROM'), FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function subject(): string { return 'Your CSMS sign-in code'; }

    public static function send(string $to, string $displayName, string $code, int $minutes = 5): bool
    {
        $html = self::htmlBody($displayName, $code, $minutes);
        $text = self::textBody($displayName, $code, $minutes);
        if (self::$transport !== null) return (bool)(self::$transport)($to, self::subject(), $html, $text);
        if (!self::configured()) { error_log('[CSMS] OTP email not sent: SMTP is not configured.'); return false; }

        $base = __DIR__ . '/../lib/PHPMailer/';
        foreach (['Exception.php', 'PHPMailer.php', 'SMTP.php'] as $file) {
            if (!is_file($base . $file)) { error_log('[CSMS] OTP email not sent: PHPMailer files are missing.'); return false; }
            require_once $base . $file;
        }
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = self::env('SMTP_HOST');
            $port = (int)self::env('SMTP_PORT', '587');
            $mail->Port = $port > 0 && $port < 65536 ? $port : 587;
            $user = self::env('SMTP_USER');
            $mail->SMTPAuth = $user !== '';
            if ($user !== '') { $mail->Username = $user; $mail->Password = self::env('SMTP_PASS'); }
            $loopback = in_array(strtolower($mail->Host), ['localhost', '127.0.0.1', '::1'], true);
            if ($mail->Port === 465) $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            elseif (!$loopback) $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
            $mail->Timeout = self::TIMEOUT_SECONDS;
            $mail->SMTPKeepAlive = false;
            $mail->SMTPDebug = 0;
            $mail->setFrom(self::env('SMTP_FROM'), self::env('SMTP_FROM_NAME', 'CSMS-SMS2'));
            // Message-ID and the greeting use the sender's domain, not the container's internal host name; no library fingerprint header.
            $mail->Hostname = substr((string)strrchr(self::env('SMTP_FROM'), '@'), 1);
            $mail->XMailer = ' ';
            $mail->addAddress($to, $displayName);
            $mail->isHTML(true);
            $mail->Subject = self::subject();
            $mail->Body = $html;
            $mail->AltBody = $text;
            $mail->send();
            return true;
        } catch (Throwable $error) {
            // The exception text can carry server replies; keep only the type in the log.
            error_log('[CSMS] OTP email failed (' . get_class($error) . ').');
            return false;
        }
    }

    public static function textBody(string $displayName, string $code, int $minutes = 5): string
    {
        $name = trim($displayName) !== '' ? trim($displayName) : 'there';
        return "Hello {$name},\n\n"
            . "Your CSMS sign-in code is: {$code}\n\n"
            . "It is valid for {$minutes} minutes and can be used once.\n\n"
            . "If you did not try to sign in, ignore this email. Your account is safe and nothing was changed.\n\n"
            . "Bestlink College of the Philippines - Curriculum & Subject Management System\n";
    }

    /** Table-based layout, inline CSS only, web-safe fonts, no remote images (mail clients block them). */
    public static function htmlBody(string $displayName, string $code, int $minutes = 5): string
    {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $name = trim($displayName) !== '' ? trim($displayName) : 'there';
        $digits = $e($code);
        return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . $e(self::subject()) . '</title></head>'
            . '<body style="margin:0;padding:0;background-color:#eef2f8;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#eef2f8;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="480" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:480px;background-color:#ffffff;border:1px solid #dfe4ee;border-radius:8px;">'
            . '<tr><td style="background-color:#2163c5;padding:20px 28px;border-radius:8px 8px 0 0;font-family:Georgia,\'Times New Roman\',serif;font-size:22px;line-height:28px;color:#ffffff;">Curriculum &amp; Subject Management System</td></tr>'
            . '<tr><td style="padding:28px 28px 8px 28px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:24px;color:#1f2937;">'
            . '<p style="margin:0 0 12px 0;">Hello ' . $e($name) . ',</p>'
            . '<p style="margin:0;">Use this code to finish signing in:</p></td></tr>'
            . '<tr><td align="center" style="padding:12px 28px 8px 28px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="background-color:#eaf1fc;border:2px solid #2163c5;border-radius:8px;"><tr><td style="padding:14px 28px;font-family:\'Courier New\',Courier,monospace;font-size:36px;line-height:44px;font-weight:bold;letter-spacing:10px;color:#122a68;text-align:center;">' . $digits . '</td></tr></table>'
            . '</td></tr>'
            . '<tr><td style="padding:8px 28px 8px 28px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:22px;color:#374151;text-align:center;">This code is valid for <strong>' . (int)$minutes . ' minutes</strong> and can be used once.</td></tr>'
            . '<tr><td style="padding:16px 28px 28px 28px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:21px;color:#4b5563;">If you did not try to sign in, ignore this email. Your account is safe and nothing was changed.</td></tr>'
            . '<tr><td style="padding:14px 28px;border-top:1px solid #dfe4ee;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#6b7280;">Bestlink College of the Philippines</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
