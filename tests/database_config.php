<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../shared/database_config.php';
$names = ['DB_HOST','DB_PORT','DB_NAME','DB_DATABASE','DB_USER','DB_USERNAME','DB_PASS','DB_PASSWORD',
    'SMS_DB_HOST','SMS_DB_PORT','SMS_DB_NAME','SMS_DB_USER','SMS_DB_PASS','APP_ENV'];
$saved = [];
foreach ($names as $name) { $saved[$name] = getenv($name); putenv($name); }
$checks = 0;
function configCheck(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    $checks++; echo 'PASS: ' . $label . PHP_EOL;
}
try {
    configCheck(csms_database_config() === ['host'=>'localhost','user'=>'root','password'=>'','database'=>'sms_db','port'=>3306], 'XAMPP defaults');
    $secret = bin2hex(random_bytes(24));
    foreach (['SMS_DB_HOST=legacy-host','SMS_DB_PORT=3307','SMS_DB_NAME=legacy-db','SMS_DB_USER=legacy-user'] as $value) { putenv($value); }
    putenv('SMS_DB_PASS=' . $secret);
    $config = csms_database_config();
    configCheck($config['host']==='legacy-host' && $config['port']===3307 && $config['database']==='legacy-db' && $config['user']==='legacy-user' && $config['password']===$secret, 'Existing SMS aliases');
    foreach (['DB_HOST=managed-host','DB_PORT=3308','DB_DATABASE=managed-db','DB_USERNAME=managed-user'] as $value) { putenv($value); }
    putenv('DB_PASSWORD=' . $secret);
    $config = csms_database_config();
    configCheck($config['host']==='managed-host' && $config['port']===3308 && $config['database']==='managed-db' && $config['user']==='managed-user' && $config['password']===$secret, 'HostForge alternate names');
    foreach (['DB_NAME=preferred-db','DB_USER=preferred-user'] as $value) { putenv($value); }
    putenv('DB_PASS=' . $secret . 'x');
    $config = csms_database_config();
    configCheck($config['database']==='preferred-db' && $config['user']==='preferred-user' && $config['password']===$secret.'x', 'Preferred alias priority');
    putenv('DB_PASS=0'); configCheck(csms_database_config()['password']==='0', 'Zero is a string value, not missing');
    putenv('DB_PASS='); configCheck(csms_database_config()['password']===$secret, 'Empty alias falls through');
    foreach (['0','65536','3306junk','-1','1.5',"3306\n"] as $port) {
        putenv('DB_PORT=' . $port); $rejected = false;
        try { csms_database_config(); } catch (RuntimeException $error) { $rejected = true; }
        configCheck($rejected, 'Invalid port rejected');
    }
    putenv('DB_PORT=65535'); configCheck(csms_database_config()['port']===65535, 'Upper port bound');
    putenv('APP_ENV=production'); configCheck(csms_database_config()['database']==='preferred-db', 'Explicit managed production configuration');
    putenv('DB_HOST=localhost'); $rejected = false;
    try { csms_database_config(); } catch (RuntimeException $error) { $rejected = true; }
    configCheck($rejected, 'Production refuses localhost fallback');
    putenv('DB_HOST=managed-host'); putenv('DB_USER=root'); $rejected = false;
    try { csms_database_config(); } catch (RuntimeException $error) { $rejected = true; }
    configCheck($rejected, 'Production refuses root fallback');
    echo "Configuration checks passed: $checks. No database connection was opened.\n";
} finally {
    foreach ($saved as $name => $value) { putenv($value === false ? $name : $name . '=' . $value); }
}
