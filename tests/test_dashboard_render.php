<?php
// Bungkus PHP utk smoke JS renderer dashboard (tests/dashboard_render_smoke.js)
// supaya ikut terjaring `php tests/run.php`. Smoke menjalankan SKRIP ASLI
// public/index.php di DOM shim & membuktikan dueRowEl() tidak throw dgn item
// upcoming kind='debt' (regresi live: item debt tak punya next_run ->
// shortDateLabel(undefined).split -> seluruh dashboard blank). Butuh `node`
// di PATH; kalau tidak ada, test di-skip (dilaporkan, bukan FAIL) supaya
// environment tanpa node tidak bikin suite merah.

require_once __DIR__ . '/bootstrap.php';

$smoke = __DIR__ . '/dashboard_render_smoke.js';

$nodeBin = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($nodeBin === '') {
    echo "\xE2\x9A\xA0 test_dashboard_render: node tidak ditemukan di PATH, smoke di-SKIP\n";
    return;
}

$cmd = escapeshellarg($nodeBin) . ' ' . escapeshellarg($smoke) . ' 2>&1';
$output = (string) shell_exec($cmd);
// exit code node dibawa lewat trailing marker (shell_exec tidak kasih code).
$code = 0;
$cmdWithCode = escapeshellarg($nodeBin) . ' ' . escapeshellarg($smoke) . ' > /dev/null 2>&1; echo $?';
$code = (int) trim((string) shell_exec($cmdWithCode));

assertSame(0, $code, 'dashboard renderer smoke: dueRowEl tidak throw dgn item debt (public/index.php)');
if ($code !== 0) {
    echo $output . "\n";
}
