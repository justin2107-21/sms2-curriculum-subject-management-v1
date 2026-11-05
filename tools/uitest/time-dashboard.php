<?php
// Times dashboard/dashboard_data.php for each role over real HTTP on sms_db_uitest (own PHP server on port 8098).
// Usage: DB_NAME=sms_db_uitest php tools/uitest/time-dashboard.php [runs=7] [programCode]
// Reports min / median / max milliseconds per role for all programs and for one program, plus payload size.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('DB_NAME') !== 'sms_db_uitest') { fwrite(STDERR, "Refusing: set DB_NAME=sms_db_uitest\n"); exit(1); }
require_once __DIR__ . '/../../shared/database_config.php';
$db = csms_database_connect(csms_database_config());
$runs = (int)($argv[1] ?? 7); $programCode = $argv[2] ?? 'SYN-P01';
$programId = (int)$db->query("SELECT id FROM academic_programs WHERE program_code='" . $db->real_escape_string($programCode) . "'")->fetch_row()[0];
$creds = [];
foreach (file(__DIR__ . '/../../.env.uitest', FILE_IGNORE_NEW_LINES) as $line) if (preg_match('/^(UITEST_\w+)=(.*)$/', $line, $m)) $creds[$m[1]] = $m[2];

$port = 8098;
$log = tempnam(sys_get_temp_dir(), 'dash');
$env = getenv(); $env['APP_ENV'] = 'development'; $env['SMS_SESSION_COOKIE_SECURE'] = '0'; $env['CIMF_AI_ENABLED'] = 'false';
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__, 2)], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__, 2), $env);
for ($i = 0; $i < 40; $i++) { $c = @stream_socket_client("tcp://127.0.0.1:$port", $e, $s, .2); if ($c) { fclose($c); break; } usleep(100000); }

final class Client {
    private array $cookies = []; public string $csrf = '';
    public function __construct(private int $port) {}
    public function req(string $path, ?array $post = null): array {
        $headers = []; if ($this->cookies) $headers[] = 'Cookie: ' . http_build_query($this->cookies, '', '; ');
        $opts = ['ignore_errors' => true, 'follow_location' => 0, 'method' => $post === null ? 'GET' : 'POST'];
        if ($post !== null) { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; $opts['content'] = http_build_query($post); }
        $opts['header'] = implode("\r\n", $headers);
        $t = hrtime(true);
        $body = (string)file_get_contents("http://127.0.0.1:{$this->port}/$path", false, stream_context_create(['http' => $opts]));
        $ms = (hrtime(true) - $t) / 1e6;
        $status = 0;
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) $status = (int)$m[1];
            if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $h, $m)) $this->cookies[$m[1]] = urldecode($m[2]);
        }
        if (preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m)) $this->csrf = $m[1];
        return [$status, $body, $ms];
    }
}
$median = static function (array $v): float { sort($v); return $v[intdiv(count($v), 2)]; };
echo "| Role | Scope | Runs | Min ms | Median ms | Max ms | Payload KB | Status |\n|---|---|---:|---:|---:|---:|---:|---|\n";
try {
    foreach (['superadmin', 'admin', 'staff', 'acadoffice', 'acadfaculty'] as $key) {
        $c = new Client($port); $c->req('auth/signin.php');
        $up = strtoupper($key);
        [$s] = $c->req('shared/auth_actions.php', ['action' => 'login', 'username' => $creds["UITEST_{$up}_USER"], 'password' => $creds["UITEST_{$up}_PASS"], '_csrf_token' => $c->csrf]);
        if ($s !== 200) throw new RuntimeException("login $key: $s");
        $c->req('dashboard/account.php');
        foreach (['all programs' => '', "program $programCode" => "?program_id=$programId"] as $scope => $query) {
            $c->req("dashboard/dashboard_data.php$query"); // warm-up (opcache, connection)
            $times = []; $size = 0; $status = 'ok';
            for ($i = 0; $i < $runs; $i++) {
                [$st, $body, $ms] = $c->req("dashboard/dashboard_data.php$query");
                $times[] = $ms; $size = strlen($body);
                if ($st !== 200 || !(json_decode($body, true)['success'] ?? false)) $status = "HTTP $st";
            }
            printf("| %s | %s | %d | %.0f | %.0f | %.0f | %.0f | %s |\n", $key, $scope, $runs, min($times), $median($times), max($times), $size / 1024, $status);
        }
    }
} finally {
    proc_terminate($server);
}
