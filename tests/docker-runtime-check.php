<?php
// Mounted outside DocumentRoot for image verification; excluded from the image.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $root = '/var/www/html';
    foreach (['.git', '.github', '.env', 'docs', 'tests', 'database/seeds'] as $path) {
        if (file_exists($root . '/' . $path)) {
            throw new RuntimeException('Private/development artifact in image.');
        }
    }
    echo "PASS: Private/development artifacts excluded from image.\n";
    foreach (['mysqli','mysqlnd','curl','dom','fileinfo','json','session','ctype','filter'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('Required extension unavailable: ' . $extension);
        }
        echo 'PASS: PHP extension ' . $extension . PHP_EOL;
    }
    $count = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
        $process = proc_open([PHP_BINARY, '-l', $file->getPathname()],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Unable to start PHP lint.'); }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]); fclose($pipes[1]);
        stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new RuntimeException('Application PHP lint failed.'); }
        $count++;
    }
    if ($count === 0) { throw new RuntimeException('No application PHP files found.'); }
    echo 'PASS: In-container PHP lint: ' . $count . ' files.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
