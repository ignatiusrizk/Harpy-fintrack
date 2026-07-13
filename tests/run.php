<?php
// Test runner CLI: jalankan semua tests/test_*.php, rekap PASS/FAIL.

$files = glob(__DIR__ . '/test_*.php');
sort($files);

$maxExit = 0;
$results = [];

foreach ($files as $file) {
    $label = basename($file);
    echo "== {$label} ==\n";
    passthru(PHP_BINARY . ' ' . escapeshellarg($file), $code);
    echo "\n";
    $results[] = ['label' => $label, 'code' => $code];
    $maxExit = max($maxExit, $code);
}

echo "==================== Rekap ====================\n";
foreach ($results as $r) {
    $status = $r['code'] === 0 ? 'PASS' : 'FAIL';
    echo "{$status}  {$r['label']}\n";
}
echo "=================================================\n";
echo $maxExit === 0 ? "SEMUA PASS\n" : "ADA YANG FAIL\n";

exit($maxExit);
