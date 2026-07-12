<?php
// Endpoint dashboard: ?a=summary — SATU response agregat utk halaman utama
// (public/index.php). POST, wajib login. Baca-saja (tidak mengubah state) --
// tidak wajib CSRF, pola sama spt public/api/investasi.php ?a=list. Semua
// data utk SPACE AKTIF (currentSpaceId()) KECUALI net_worth (seluruh user,
// lewat netWorth() -- lintas semua ruang personal + portfolio). Semua logika
// agregat ada di core/dashboard.php (dipindah dari sini supaya deret bulan
// arus kas bisa diuji unit tanpa mengeksekusi endpoint) -- file ini tinggal
// routing tipis, pola sama dgn endpoint lain.

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/dashboard.php';

$user = requireLoginApi();
$spaceId = currentSpaceId();
$action = get('a', '');

try {
    switch ($action) {
        case 'summary':
            apiOk(dashboardSummary($spaceId, (int) $user['id']));
            break;

        default:
            apiErr('Aksi tidak dikenal', 404);
    }
} catch (Throwable $e) {
    apiErr($e);
}
