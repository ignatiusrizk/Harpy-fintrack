<?php
// Export transaksi ke CSV -- halaman GET biasa (BUKAN api/, respons file
// attachment bukan JSON), filter SAMA persis dgn list transaksi
// (from,to,account_id,category_id,type,q via txBuildWhere(), dipakai bareng
// dgn listTransactions() supaya aturan filter selalu konsisten). Dibuka via
// window.open/link download dari public/laporan.php (tombol "Export CSV"),
// bukan lewat window.api() (yg selalu POST JSON) -- ini murni GET biasa spt
// klik link biasa, supaya browser bisa langsung memicu unduhan file.

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/transaksi.php';
require_once __DIR__ . '/../core/laporan.php';

$user = requireLogin();
$spaceId = currentSpaceId();

$filters = [
    'from' => get('from', ''),
    'to' => get('to', ''),
    'account_id' => get('account_id', ''),
    'category_id' => get('category_id', ''),
    'type' => get('type', ''),
    'q' => get('q', ''),
];

$result = txListForExport($spaceId, $filters, TX_EXPORT_LIMIT);

$filename = 'transaksi-' . date('Ymd-His') . '.csv';

// Cache-Control: no-store sudah dikirim oleh requireLogin() (pola sama spt
// halaman lain), tidak diulang di sini.
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

streamTransactionsCsv($result['rows'], $result['truncated'], TX_EXPORT_LIMIT);
