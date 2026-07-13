<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/seed.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/balance.php'; // require_once core/portfolio.php di dalamnya
require_once __DIR__ . '/../core/portfolio.php';

/**
 * Bandingkan dua float dgn toleransi epsilon (float PHP biasa, bukan bcmath
 * -- lihat catatan presisi di core/portfolio.php).
 */
function assertClose(float $expected, float $actual, string $label, float $eps = 0.005): void
{
    if (abs($expected - $actual) <= $eps) {
        echo "\xE2\x9C\x93 {$label}\n";
        return;
    }
    echo "\xE2\x9C\x97 {$label} — expected: {$expected}, got: {$actual}\n";
    $GLOBALS['__test_exit_code'] = 1;
}

// Data test tetap — dibersihkan sebelum & sesudah.
$testEmail = 'test+portfolio@ft.local';
$otherEmail = 'test+portfolio-other@ft.local';

function cleanupTestPortfolio(string $email): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user === false) {
        return;
    }
    // assets CASCADE dari spaces -> assets, asset_transactions/asset_prices
    // CASCADE dari assets. Tidak ada RESTRICT yg menyangkut tabel ini (beda
    // dgn transactions.account_id) jadi cukup hapus user, cascade beres.
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
}

/**
 * Jalankan potongan kode PHP di subprocess terpisah (core sudah di-require)
 * supaya apiErr() yg exit() tidak mematikan proses test utama. Pola sama
 * persis dgn tests/test_goals.php.
 */
function runSub(string $code): array
{
    $preamble = 'require ' . var_export(__DIR__ . '/../core/db.php', true) . ';'
        . 'require ' . var_export(__DIR__ . '/../core/helpers.php', true) . ';'
        . 'require ' . var_export(__DIR__ . '/../core/portfolio.php', true) . ';'
        . 'session_start();';
    $output = shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($preamble . $code));
    $json = json_decode((string) $output, true);
    return is_array($json) ? $json : ['ok' => null, 'raw' => $output];
}

$sessAs = function (int $uid): string {
    return '$_SESSION["user_id"] = ' . var_export($uid, true) . '; ';
};

ensureSession();

cleanupTestPortfolio($testEmail);
cleanupTestPortfolio($otherEmail);

// --- setup: 2 user, tiap-tiap punya space personal (dari registerUser) +
// user utama dapat tambahan 1 space business (utk tes portfolioValue lintas
// space) -----------------------------------------------------------------

$userId = registerUser('Test Portfolio', $testEmail, 'password123');
$otherUserId = registerUser('Test Portfolio Other', $otherEmail, 'password123');

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM spaces WHERE user_id = ? ORDER BY id ASC LIMIT 1');
$stmt->execute([$userId]);
$spaceId = (int) $stmt->fetch()['id'];
$stmt->execute([$otherUserId]);
$otherSpaceId = (int) $stmt->fetch()['id'];

$businessSpaceId = createSpaceWithDefaults($userId, 'Bisnis Test', 'business');

$_SESSION['user_id'] = $userId;

// ==================== createAsset: validasi gagal ====================

$json = runSub($sessAs($userId) . 'createAsset(' . var_export($spaceId, true) . ', ' . var_export([
    'name' => '', 'type' => 'stock', 'code' => '', 'unit_label' => '',
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createAsset: nama kosong -> ditolak');

$json = runSub($sessAs($userId) . 'createAsset(' . var_export($spaceId, true) . ', ' . var_export([
    'name' => 'BBCA', 'type' => 'bukan_jenis_valid', 'code' => '', 'unit_label' => '',
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createAsset: type tidak valid -> ditolak');

// ==================== createAsset: sukses + default unit_label ====================

$asset = createAsset($spaceId, ['name' => 'BBCA', 'type' => 'stock', 'code' => 'BBCA', 'unit_label' => '']);
assertSame(true, $asset['id'] > 0, 'createAsset: sukses, id > 0');
$assetId = (int) $asset['id'];
assertSame('unit', $asset['unit_label'], 'createAsset: unit_label default "unit"');
assertSame('BBCA', $asset['code'], 'createAsset: code tersimpan');

// ownAsset: user lain gagal (404)
$json = runSub($sessAs($otherUserId) . 'updateAsset(' . var_export($assetId, true) . ', ' . var_export([
    'name' => 'Hack', 'type' => 'stock', 'code' => '', 'unit_label' => '',
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'updateAsset: user lain -> ditolak (ownAsset)');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'Tidak ditemukan'), 'updateAsset: pesan "Tidak ditemukan"');

// ==================== assetPosition: posisi kosong (belum ada transaksi) ====================

$pos = assetPosition($assetId);
assertSame(0.0, $pos['units'], 'assetPosition: units 0 sebelum ada transaksi');
assertSame(0.0, $pos['cost'], 'assetPosition: cost 0 sebelum ada transaksi');
assertSame(0.0, $pos['avg_price'], 'assetPosition: avg_price 0 sebelum ada transaksi');
assertSame(0.0, $pos['value'], 'assetPosition: value 0 sebelum ada transaksi');

// ==================== beli 10@1000 fee 10 -> cost 10.010 ====================

$buy1 = tradeAsset($assetId, 'buy', 10, 1000, 10, null);
assertSame(true, $buy1['id'] > 0, 'tradeAsset(buy1): tercatat, id > 0');
$pos = assetPosition($assetId);
assertSame(10.0, $pos['units'], 'beli1: units = 10');
assertClose(10010.0, $pos['cost'], 'beli1: cost = 10.010 (10*1000+10 fee)');
assertClose(1001.0, $pos['avg_price'], 'beli1: avg_price = 1.001 (10010/10)');

// ==================== beli 10@1200 fee 0 -> units 20, cost 22.010, avg 1.100,5 ====================

$buy2 = tradeAsset($assetId, 'buy', 10, 1200, 0, null);
$pos = assetPosition($assetId);
assertSame(20.0, $pos['units'], 'beli2: units = 20');
assertClose(22010.0, $pos['cost'], 'beli2: cost = 22.010 (10010 + 10*1200)');
assertClose(1100.5, $pos['avg_price'], 'beli2: avg_price = 1.100,5 (22010/20)');

// ==================== jual 5 -> cost berkurang proporsional ====================
// cost sebelum jual = 22.010, proporsi = 5/20 = 0,25 -> dikurangi 5.502,5
// -> cost = 16.507,5, units = 15

$sell1 = tradeAsset($assetId, 'sell', 5, 1150, 0, null);
$pos = assetPosition($assetId);
assertSame(15.0, $pos['units'], 'jual5: units = 15 (20-5)');
assertClose(16507.5, $pos['cost'], 'jual5: cost = 16.507,5 (22010 - 22010*5/20)');
assertClose(1100.5, $pos['avg_price'], 'jual5: avg_price tetap 1.100,5 (16507.5/15, avg cost tak berubah krn jual)');

// ==================== jual 100 (melebihi posisi 15) -> ditolak ====================

$json = runSub($sessAs($userId) . 'tradeAsset(' . var_export($assetId, true) . ", 'sell', 100, 1150, 0, null);");
assertSame(false, $json['ok'] ?? null, 'tradeAsset(sell): 100 unit melebihi posisi 15 -> ditolak');
$posAfterRejected = assetPosition($assetId);
assertSame(15.0, $posAfterRejected['units'], 'tradeAsset(sell over): posisi TIDAK berubah setelah percobaan ditolak');

// jual nominal 0/negatif -> ditolak
$json = runSub($sessAs($userId) . 'tradeAsset(' . var_export($assetId, true) . ", 'sell', 0, 1150, 0, null);");
assertSame(false, $json['ok'] ?? null, 'tradeAsset(sell): unit 0 -> ditolak');

// side tidak valid -> ditolak
$json = runSub($sessAs($userId) . 'tradeAsset(' . var_export($assetId, true) . ", 'hold', 1, 1150, 0, null);");
assertSame(false, $json['ok'] ?? null, 'tradeAsset: side tidak valid -> ditolak');

// ==================== set_price 1300 -> value & gain benar ====================
// value = 15 * 1300 = 19.500 ; gain = 19.500 - 16.507,5 = 2.992,5
// gain_pct = 2992,5 / 16507,5 * 100 ≈ 18,1332...%

setAssetPrice($assetId, 1300, null);
$pos = assetPosition($assetId);
assertSame(15.0, $pos['units'], 'set_price: units tak berubah = 15');
assertClose(1300.0, $pos['last_price'], 'set_price: last_price = 1.300');
assertClose(19500.0, $pos['value'], 'set_price: value = 19.500 (15*1300)');
assertClose(2992.5, $pos['gain'], 'set_price: gain = 2.992,5 (19500-16507.5)');
$expectedGainPct = 2992.5 / 16507.5 * 100;
assertClose($expectedGainPct, $pos['gain_pct'], 'set_price: gain_pct sesuai rumus gain/cost*100', 0.01);

// setAssetPrice: harga negatif -> ditolak
$json = runSub($sessAs($userId) . 'setAssetPrice(' . var_export($assetId, true) . ', -100, null);');
assertSame(false, $json['ok'] ?? null, 'setAssetPrice: harga negatif -> ditolak');

// setAssetPrice: user lain -> ditolak (ownAsset)
$json = runSub($sessAs($otherUserId) . 'setAssetPrice(' . var_export($assetId, true) . ', 1300, null);');
assertSame(false, $json['ok'] ?? null, 'setAssetPrice: user lain -> ditolak (ownAsset)');

// ==================== deleteAssetTrade: sukses (posisi akhir tidak negatif) ====================
// tambah beli 5@1000 (units jadi 20, cost jadi 16507.5+5000=21507.5), lalu
// hapus lagi trade itu -> posisi harus kembali persis ke sebelum (15 / 16507.5)

$buyExtra = tradeAsset($assetId, 'buy', 5, 1000, 0, null);
$posMid = assetPosition($assetId);
assertSame(20.0, $posMid['units'], 'buyExtra: units jadi 20');
assertClose(21507.5, $posMid['cost'], 'buyExtra: cost jadi 21.507,5');

deleteAssetTrade((int) $buyExtra['id']);
$posAfterDelete = assetPosition($assetId);
assertSame(15.0, $posAfterDelete['units'], 'deleteAssetTrade: units kembali 15 setelah hapus buyExtra');
assertClose(16507.5, $posAfterDelete['cost'], 'deleteAssetTrade: cost kembali 16.507,5 setelah hapus buyExtra');

// deleteAssetTrade: user lain -> ditolak (ownAsset via rantai asset_transactions)
$json = runSub($sessAs($otherUserId) . 'deleteAssetTrade(' . var_export((int) $buy1['id'], true) . ');');
assertSame(false, $json['ok'] ?? null, 'deleteAssetTrade: user lain -> ditolak (ownAsset)');

// ==================== deleteAssetTrade: ditolak kalau posisi akhir jadi negatif ====================
// aset terpisah: beli 10, jual 10 (posisi 0, valid). Hapus baris BELI -> yg
// tersisa cuma "jual 10" sendirian -> assetPosition() proses sell dgn
// units_sebelum=0 (proporsi diguardsebagai 0, lihat komentar assetPosition())
// -> units jadi -10 (negatif) -> HARUS ditolak, baris beli tidak jadi hilang.

$asset2 = createAsset($spaceId, ['name' => 'Emas Antam', 'type' => 'gold', 'code' => '', 'unit_label' => 'gram']);
$asset2Id = (int) $asset2['id'];
$a2Buy = tradeAsset($asset2Id, 'buy', 10, 1000000, 0, null);
$a2Sell = tradeAsset($asset2Id, 'sell', 10, 1000000, 0, null);
$pos2 = assetPosition($asset2Id);
assertSame(0.0, $pos2['units'], 'asset2: posisi 0 setelah beli10 jual10');

$json = runSub($sessAs($userId) . 'deleteAssetTrade(' . var_export((int) $a2Buy['id'], true) . ');');
assertSame(false, $json['ok'] ?? null, 'deleteAssetTrade: hapus beli yg jadi tumpuan jual -> ditolak (posisi akhir negatif)');
$pos2AfterRejected = assetPosition($asset2Id);
assertSame(0.0, $pos2AfterRejected['units'], 'deleteAssetTrade(ditolak): posisi asset2 tidak berubah (masih 0, baris beli tidak jadi hilang)');

// ==================== assetHistory: trades + prices urut terbaru ====================

$hist = assetHistory($assetId);
assertSame(true, count($hist['trades']) >= 3, 'assetHistory: trades minimal 3 baris (buy1, buy2, sell1 -- buyExtra sudah dihapus)');
assertSame(true, count($hist['prices']) >= 1, 'assetHistory: prices minimal 1 baris (set_price 1300)');
// urut DESC: baris pertama = tx_date/id terbesar
$firstTradeId = $hist['trades'][0]['id'];
assertSame(true, $firstTradeId >= (int) $sell1['id'], 'assetHistory: trades urut terbaru dulu (DESC)');

// assetHistory: user lain -> ditolak
$json = runSub($sessAs($otherUserId) . 'assetHistory(' . var_export($assetId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'assetHistory: user lain -> ditolak (ownAsset)');

// ==================== portfolioSummary: total & alokasi per type ====================

$summary = portfolioSummary($spaceId);
assertSame(true, count($summary['assets']) >= 2, 'portfolioSummary: minimal 2 aset (BBCA + Emas Antam)');
assertClose(19500.0, $summary['total_value'], 'portfolioSummary: total_value = 19.500 (BBCA saja, Emas Antam posisi 0)');
assertClose(16507.5, $summary['total_cost'], 'portfolioSummary: total_cost = 16.507,5');

$allocTotal = 0.0;
foreach ($summary['allocation'] as $a) {
    $allocTotal += $a['pct'];
}
assertClose(100.0, $allocTotal, 'portfolioSummary: total alokasi persen = 100%', 0.1);

$stockAlloc = null;
foreach ($summary['allocation'] as $a) {
    if ($a['type'] === 'stock') {
        $stockAlloc = $a;
    }
}
assertSame(true, $stockAlloc !== null, 'portfolioSummary: alokasi type stock ada');
assertClose(100.0, $stockAlloc['pct'], 'portfolioSummary: alokasi stock = 100% (Emas Antam posisi/value 0, tak nyumbang value)');

// portfolioSummary: space user lain tidak ikut ketiban
$otherSummary = portfolioSummary($otherSpaceId);
assertSame(0, count($otherSummary['assets']), 'portfolioSummary: space user lain kosong (isolasi tenant/space)');

// ==================== deleteAsset: ditolak kalau punya transaksi/harga ====================

$json = runSub($sessAs($userId) . 'deleteAsset(' . var_export($assetId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'deleteAsset: aset dgn transaksi -> ditolak');

$json = runSub($sessAs($userId) . 'deleteAsset(' . var_export($asset2Id, true) . ');');
assertSame(false, $json['ok'] ?? null, 'deleteAsset: aset dgn transaksi (asset2) -> ditolak walau posisi 0');

// aset baru tanpa transaksi/harga -> boleh dihapus
$asset3 = createAsset($spaceId, ['name' => 'Kosong', 'type' => 'other', 'code' => '', 'unit_label' => '']);
deleteAsset((int) $asset3['id']);
$stmt = $pdo->prepare('SELECT COUNT(*) c FROM assets WHERE id = ?');
$stmt->execute([(int) $asset3['id']]);
assertSame(0, (int) $stmt->fetch()['c'], 'deleteAsset: aset kosong (tanpa transaksi/harga) berhasil dihapus');

// deleteAsset: user lain -> ditolak
$json = runSub($sessAs($otherUserId) . 'deleteAsset(' . var_export($assetId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'deleteAsset: user lain -> ditolak (ownAsset)');

// ==================== portfolioValue: Σ lintas space (personal & business) ====================

// space personal (BBCA value 19500, Emas Antam 0) + space business (aset baru)
$businessAsset = createAsset($businessSpaceId, ['name' => 'Reksa Dana X', 'type' => 'mutual_fund', 'code' => '', 'unit_label' => '']);
tradeAsset((int) $businessAsset['id'], 'buy', 100, 2000, 0, null);
setAssetPrice((int) $businessAsset['id'], 2500, null);
// value business = 100 * 2500 = 250.000

$pv = portfolioValue($userId);
assertClose(19500.0 + 250000.0, $pv, 'portfolioValue: jumlah lintas space personal (19.500) + business (250.000)');

// user tanpa aset sama sekali -> 0
$pvOther = portfolioValue($otherUserId);
assertSame(0.0, $pvOther, 'portfolioValue: user tanpa aset = 0');

// ==================== netWorth: menyertakan portfolioValue setelah Task 9 ====================

$insAcc = $pdo->prepare('INSERT INTO accounts (space_id, name, type, initial_balance) VALUES (?, ?, ?, ?)');
$insAcc->execute([$spaceId, 'Dompet PT', 'cash', 500000]);

$netWorthBefore = 500000.0; // akun space personal saja, sebelum portfolioValue dihitung manual
$netWorthActual = netWorth($userId);
// netWorth = akun space PERSONAL (500rb) + portfolioValue SEMUA space (19.500 + 250.000)
// -- catatan: portfolioValue sengaja lintas TIPE space (personal+business,
// beda dgn saldo akun yg cuma personal), sesuai spesifikasi brief Task 9.
assertClose($netWorthBefore + 19500.0 + 250000.0, $netWorthActual, 'netWorth: akun personal + portfolioValue (personal+business) setelah Task 9');
assertSame(true, $netWorthActual > $netWorthBefore, 'netWorth: naik dibanding saldo akun saja (portfolio ikut ditambahkan)');

// --- cleanup -----------------------------------------------------------------

cleanupTestPortfolio($testEmail);
cleanupTestPortfolio($otherEmail);

$stmt = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE email IN (?, ?)');
$stmt->execute([$testEmail, $otherEmail]);
assertSame(0, (int) $stmt->fetch()['c'], 'cleanup: user test terhapus');
