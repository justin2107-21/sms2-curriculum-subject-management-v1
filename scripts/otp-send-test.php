<?php
// Sends ONE test email with the Email OTP template, to check the SMTP settings. Local use only (CLI; 404 over the web, and /scripts is blocked in .htaccess).
//   php scripts/otp-send-test.php someone@example.com
// Reads SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM, SMTP_FROM_NAME from the environment, the same as the application.
// The mail carries the sample code 123456 (not a real sign-in code and not stored anywhere). Nothing is written to the database.
// It prints only OK or FAILED and the NAMES of missing settings; never a password, host, address or server reply.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../shared/OtpMailer.php';

$to = trim((string)($argv[1] ?? ''));
if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage: php scripts/otp-send-test.php <email address>\n");
    exit(2);
}
$missing = [];
foreach (['SMTP_HOST', 'SMTP_FROM'] as $name) if ((string)getenv($name) === '') $missing[] = $name;
if ($missing) {
    fwrite(STDERR, 'FAILED: set ' . implode(' and ', $missing) . " in the environment first.\n");
    exit(1);
}
if (OtpMailer::send($to, 'Test', '123456', 5)) {
    echo "OK: one test email was handed to the mail server.\n";
    exit(0);
}
fwrite(STDERR, "FAILED: the email was not sent (check SMTP_PORT, SMTP_USER and SMTP_PASS; see the PHP error log for the error type).\n");
exit(1);
