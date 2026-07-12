<?php
// Endpoint laporan: ?a=monthly|yearly|pnl. Semua POST (baca-saja, tidak
// wajib CSRF, pola sama spt public/api/budget.php ?a=list & public/api/
// investasi.php ?a=list). Validasi bisnis (format period/year, aturan
// sub-kategori, guard type business) ada di core/laporan.php. Export CSV
// TIDAK di sini -- lihat public/export.php (halaman GET biasa, bukan
// endpoint JSON, krn responsenya file attachment bukan JSON).

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/laporan.php';

$user = requireLoginApi();
$spaceId = currentSpaceId();
$action = get('a', '');

try {
    switch ($action) {
        case 'monthly':
            $period = (string) post('period', date('Y-m'));
            apiOk(['period' => $period, 'report' => laporanMonthly($spaceId, $period)]);
            break;

        case 'yearly':
            $year = (int) post('year', (int) date('Y'));
            apiOk(['year' => $year, 'report' => laporanYearly($spaceId, $year)]);
            break;

        case 'pnl':
            // Terima SALAH SATU `period` (YYYY-MM, laba-rugi bulanan) atau
            // `year` (YYYY, laba-rugi tahunan) -- laporanPnl() yg mendeteksi
            // formatnya sendiri lewat regex, di sini cuma pilih mana yg diisi.
            $period = (string) post('period', '');
            $year = post('year');
            $periodOrYear = $period !== '' ? $period : (string) ($year ?? '');
            if ($periodOrYear === '') {
                apiErr('Parameter period atau year wajib diisi');
            }
            apiOk(['periode' => $periodOrYear, 'report' => laporanPnl($spaceId, $periodOrYear)]);
            break;

        default:
            apiErr('Aksi tidak dikenal', 404);
    }
} catch (Throwable $e) {
    apiErr($e);
}
