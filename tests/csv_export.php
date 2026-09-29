<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../modules/curriculum/services/CurriculumExportService.php';
$class = new ReflectionClass(CurriculumExportService::class);
$service = $class->newInstanceWithoutConstructor();
$row = $class->getMethod('csvRow');
$row->setAccessible(true);
$stream = fopen('php://temp', 'w+b');
$cells = ['ordinary', 'comma,value', 'double"quote', "embedded\nnewline", 'backslash\\', '=1+1', ' @SUM(A1)', ''];
$row->invoke($service, $stream, $cells);
rewind($stream);
$bytes = stream_get_contents($stream);
if (substr($bytes, -2) !== "\r\n") { throw new RuntimeException('CSV requires CRLF record endings.'); }
rewind($stream);
$actual = fgetcsv($stream, 0, ',', '"', '');
$expected = $cells;
$expected[5] = "'" . $expected[5];
$expected[6] = "'" . $expected[6];
if ($actual !== $expected) { throw new RuntimeException('CSV escaping/formula neutralization regression.'); }
fclose($stream);
echo "PASS: CSV round-trip, embedded newlines, quotes, backslashes, CRLF and formula protection.\n";
