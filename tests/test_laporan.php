<?php
// Test laporan: agregat bulanan/tahunan per kategori (sub->parent digulung +
// rincian anak, transfer excluded) & per akun (transfer dihitung dua sisi),
// laba-rugi ruang usaha (ditolak utk ruang personal), + builder CSV export
// (txListForExport + streamTransactionsCsv, ditangkap lewat output buffering).

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/seed.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/kategori.php';
require_once __DIR__ . '/../core/transaksi.php';
require_once __DIR__ . '/../core/laporan.php';

$testEmail = 'test+laporan@ft.local';

function cleanupTestLaporan(string $email): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user === false) {
        return;
    }
    // Urutan hapus manual (sama alasan spt test lain): transactions dulu
    // sblm cascade users -> spaces -> accounts, supaya tidak kena FK RESTRICT
    // (fk_transactions_account) saat spaces di-cascade-delete.
    $pdo->prepare(
        'DELETE t FROM transactions t JOIN spaces s ON s.id = t.space_id WHERE s.user_id = ?'
    )->execute([$user['id']]);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
}

/**
 * Jalankan potongan kode PHP di subprocess terpisah supaya apiErr() yg
 * exit() tidak mematikan proses test utama. Pola sama spt test_budget.php.
 */
function runSub(string $code): array
{
    $preamble = 'require ' . var_export(__DIR__ . '/../core/db.php', true) . ';'
        . 'require ' . var_export(__DIR__ . '/../core/helpers.php', true) . ';'
        . 'require ' . var_export(__DIR__ . '/../core/laporan.php', true) . ';'
        . 'session_start();';
    $output = shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($preamble . $code));
    $json = json_decode((string) $output, true);
    return is_array($json) ? $json : ['ok' => null, 'raw' => $output];
}

function categoryId(int $spaceId, string $name, string $type): int
{
    $stmt = db()->prepare('SELECT id FROM categories WHERE space_id = ? AND name = ? AND type = ? AND parent_id IS NULL LIMIT 1');
    $stmt->execute([$spaceId, $name, $type]);
    $row = $stmt->fetch();
    return $row === false ? 0 : (int) $row['id'];
}

function findByCategoryId(array $items, ?int $categoryId): ?array
{
    foreach ($items as $item) {
        if ($item['category_id'] === $categoryId) {
            return $item;
        }
    }
    return null;
}

function findByAccountId(array $items, int $accountId): ?array
{
    foreach ($items as $item) {
        if ((int) $item['account_id'] === $accountId) {
            return $item;
        }
    }
    return null;
}

ensureSession();

cleanupTestLaporan($testEmail);

// --- setup: user + space default + 2 akun + kategori sub + space usaha ---

$userId = registerUser('Test Laporan', $testEmail, 'password123');
$_SESSION['user_id'] = $userId;

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM spaces WHERE user_id = ? ORDER BY id ASC LIMIT 1');
$stmt->execute([$userId]);
$spaceId = (int) $stmt->fetch()['id'];

$insAcc = $pdo->prepare('INSERT INTO accounts (space_id, name, type, initial_balance) VALUES (?, ?, ?, ?)');
$insAcc->execute([$spaceId, 'Dompet', 'cash', 0]);
$accountA = (int) $pdo->lastInsertId();
$insAcc->execute([$spaceId, 'Bank', 'bank', 0]);
$accountB = (int) $pdo->lastInsertId();

$makanId = categoryId($spaceId, 'Makan & Minum', 'expense');
$belanjaId = categoryId($spaceId, 'Belanja', 'expense');
$gajiId = categoryId($spaceId, 'Gaji', 'income');
assertSame(true, $makanId > 0 && $belanjaId > 0 && $gajiId > 0, 'kategori seed default ditemukan');

$jajan = createCategory($spaceId, ['name' => 'Jajan', 'type' => 'expense', 'icon' => '🍿', 'color' => '#FF00FF', 'parent_id' => $makanId]);
$jajanId = (int) $jajan['id'];

$period = date('Y-m');
$year = (int) date('Y');
$today = $period . '-10';

$insTx = $pdo->prepare(
    'INSERT INTO transactions (space_id, account_id, category_id, type, amount, tx_date, note, to_account_id)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);

// ==================== laporanMonthly: sub->parent + children + uncategorized + transfer ====================

$insTx->execute([$spaceId, $accountA, $makanId, 'expense', 50000, $today, 'Makan siang', null]);
$insTx->execute([$spaceId, $accountA, $jajanId, 'expense', 20000, $today, 'Jajan sore', null]);
$insTx->execute([$spaceId, $accountA, $belanjaId, 'expense', 30000, $today, 'Belanja bulanan', null]);
$insTx->execute([$spaceId, $accountA, $gajiId, 'income', 500000, $today, 'Gaji bulan ini', null]);
// Uncategorized: hanya bisa terjadi lewat category_id NULL langsung di DB
// (createTransaction() SENDIRI mewajibkan category_id -- lihat txValidate())
// -- disimulasikan lewat INSERT langsung, pola sama spt test lain yg butuh
// state "tidak mungkin lewat API biasa" (mis. copy_prev budget bulan lalu).
$insTx->execute([$spaceId, $accountA, null, 'expense', 15000, $today, 'Lupa kategori', null]);
$insTx->execute([$spaceId, $accountA, null, 'transfer', 100000, $today, 'Transfer ke bank', $accountB]);

$monthly = laporanMonthly($spaceId, $period);

$expense = $monthly['per_kategori']['expense'];
$income = $monthly['per_kategori']['income'];

$makanItem = findByCategoryId($expense, $makanId);
assertSame(true, $makanItem !== null, 'laporanMonthly: Makan & Minum muncul di per_kategori.expense');
assertSame(70000.0, $makanItem !== null ? (float) $makanItem['amount'] : null, 'laporanMonthly: Makan & Minum amount digulung (50rb induk + 20rb Jajan) = 70rb');
assertSame(1, $makanItem !== null ? count($makanItem['children']) : null, 'laporanMonthly: Makan & Minum punya 1 rincian anak');
assertSame($jajanId, $makanItem !== null ? $makanItem['children'][0]['category_id'] : null, 'laporanMonthly: rincian anak = Jajan');
assertSame(20000.0, $makanItem !== null ? (float) $makanItem['children'][0]['amount'] : null, 'laporanMonthly: rincian anak Jajan amount = 20rb (bukan ikut digulung ke induk)');
assertSame(null, findByCategoryId($expense, $jajanId), 'laporanMonthly: Jajan TIDAK muncul sbg item terpisah di per_kategori.expense (sudah terwakili di children Makan & Minum)');

$belanjaItem = findByCategoryId($expense, $belanjaId);
assertSame(true, $belanjaItem !== null, 'laporanMonthly: Belanja muncul di per_kategori.expense');
assertSame(30000.0, $belanjaItem !== null ? (float) $belanjaItem['amount'] : null, 'laporanMonthly: Belanja amount = 30rb (kategori root tanpa anak)');
assertSame(0, $belanjaItem !== null ? count($belanjaItem['children']) : null, 'laporanMonthly: Belanja tidak punya rincian anak');

$uncatItem = findByCategoryId($expense, null);
assertSame(true, $uncatItem !== null, 'laporanMonthly: "Tanpa kategori" muncul di per_kategori.expense');
assertSame('Tanpa kategori', $uncatItem !== null ? $uncatItem['name'] : null, 'laporanMonthly: nama item uncategorized = "Tanpa kategori"');
assertSame(15000.0, $uncatItem !== null ? (float) $uncatItem['amount'] : null, 'laporanMonthly: amount "Tanpa kategori" = 15rb');

assertSame(1, count($income), 'laporanMonthly: per_kategori.income cuma 1 item (Gaji)');
$gajiItem = findByCategoryId($income, $gajiId);
assertSame(500000.0, $gajiItem !== null ? (float) $gajiItem['amount'] : null, 'laporanMonthly: Gaji amount = 500rb');

// Transfer TIDAK muncul sbg item apapun di per_kategori (bukan pendapatan/beban).
assertSame(3, count($expense), 'laporanMonthly: per_kategori.expense cuma 3 item (Makan & Minum, Belanja, Tanpa kategori) -- transfer & Jajan (sub) tidak dihitung terpisah');

$perAkun = $monthly['per_akun'];
$akunA = findByAccountId($perAkun, $accountA);
$akunB = findByAccountId($perAkun, $accountB);
assertSame(500000.0, $akunA !== null ? (float) $akunA['masuk'] : null, 'laporanMonthly per_akun: Dompet masuk = 500rb (income)');
assertSame(215000.0, $akunA !== null ? (float) $akunA['keluar'] : null, 'laporanMonthly per_akun: Dompet keluar = 215rb (115rb expense + 100rb transfer keluar)');
assertSame(285000.0, $akunA !== null ? (float) $akunA['net'] : null, 'laporanMonthly per_akun: Dompet net = 285rb');
assertSame(100000.0, $akunB !== null ? (float) $akunB['masuk'] : null, 'laporanMonthly per_akun: Bank masuk = 100rb (transfer masuk, tujuan)');
assertSame(0.0, $akunB !== null ? (float) $akunB['keluar'] : null, 'laporanMonthly per_akun: Bank keluar = 0');
assertSame(100000.0, $akunB !== null ? (float) $akunB['net'] : null, 'laporanMonthly per_akun: Bank net = 100rb');

$total = $monthly['total'];
assertSame(500000.0, (float) $total['income'], 'laporanMonthly total: income = 500rb');
assertSame(115000.0, (float) $total['expense'], 'laporanMonthly total: expense = 115rb (50+20+30+15rb, transfer tidak dihitung)');
assertSame(385000.0, (float) $total['net'], 'laporanMonthly total: net = 385rb');

// ==================== laporanMonthly: format period ditolak ====================

$json = runSub('laporanMonthly(' . var_export($spaceId, true) . ', "2026-13");');
assertSame(false, $json['ok'] ?? null, 'laporanMonthly: bulan 13 -> ditolak');

$json = runSub('laporanMonthly(' . var_export($spaceId, true) . ', "2026-7");');
assertSame(false, $json['ok'] ?? null, 'laporanMonthly: tanpa leading zero -> ditolak');

// ==================== laporanYearly: menjumlah 12 bulan ====================

$otherMonthNum = ((int) date('n')) === 1 ? 2 : 1;
$otherMonth = sprintf('%04d-%02d', $year, $otherMonthNum);
$otherDate = $otherMonth . '-05';

$insTx->execute([$spaceId, $accountA, $makanId, 'expense', 10000, $otherDate, 'Makan bulan lain', null]);
$insTx->execute([$spaceId, $accountA, $gajiId, 'income', 200000, $otherDate, 'Gaji bulan lain', null]);

$yearly = laporanYearly($spaceId, $year);
$monthlyOther = laporanMonthly($spaceId, $otherMonth);

assertSame(
    (float) $monthly['total']['income'] + (float) $monthlyOther['total']['income'],
    (float) $yearly['total']['income'],
    'laporanYearly total.income = jumlah 2 laporanMonthly (bulan ini + bulan lain, satu-satunya bulan berisi transaksi)'
);
assertSame(
    (float) $monthly['total']['expense'] + (float) $monthlyOther['total']['expense'],
    (float) $yearly['total']['expense'],
    'laporanYearly total.expense = jumlah 2 laporanMonthly'
);
assertSame(
    (float) $monthly['total']['net'] + (float) $monthlyOther['total']['net'],
    (float) $yearly['total']['net'],
    'laporanYearly total.net = jumlah 2 laporanMonthly'
);

$yearlyMakan = findByCategoryId($yearly['per_kategori']['expense'], $makanId);
assertSame(80000.0, $yearlyMakan !== null ? (float) $yearlyMakan['amount'] : null, 'laporanYearly: Makan & Minum digulung lintas bulan (50rb+20rb bulan ini + 10rb bulan lain) = 80rb');
assertSame(1, $yearlyMakan !== null ? count($yearlyMakan['children']) : null, 'laporanYearly: rincian anak Jajan tetap 1 item (bukan dobel per bulan)');
assertSame(20000.0, $yearlyMakan !== null ? (float) $yearlyMakan['children'][0]['amount'] : null, 'laporanYearly: rincian anak Jajan = 20rb (jumlah sepanjang tahun)');

$json = runSub('laporanYearly(' . var_export($spaceId, true) . ', 1800);');
assertSame(false, $json['ok'] ?? null, 'laporanYearly: tahun 1800 (di luar rentang wajar) -> ditolak');

$json = runSub('laporanYearly(' . var_export($spaceId, true) . ', 2200);');
assertSame(false, $json['ok'] ?? null, 'laporanYearly: tahun 2200 (di luar rentang wajar) -> ditolak');

// ==================== laporanPnl: HANYA ruang business, income-expense=net ====================

$spaceUsahaId = createSpaceWithDefaults($userId, 'Usaha Test', 'business');
$insAcc->execute([$spaceUsahaId, 'Kas Usaha', 'cash', 0]);
$accountC = (int) $pdo->lastInsertId();

$gajiUsahaId = categoryId($spaceUsahaId, 'Gaji', 'income');
$belanjaUsahaId = categoryId($spaceUsahaId, 'Belanja', 'expense');
assertSame(true, $gajiUsahaId > 0 && $belanjaUsahaId > 0, 'setup ruang usaha: kategori seed default ditemukan');

$insTx->execute([$spaceUsahaId, $accountC, $gajiUsahaId, 'income', 300000, $today, 'Pendapatan jasa', null]);
$insTx->execute([$spaceUsahaId, $accountC, $belanjaUsahaId, 'expense', 100000, $today, 'Beban operasional', null]);

$pnl = laporanPnl($spaceUsahaId, $period);
assertSame(1, count($pnl['income']), 'laporanPnl bulanan: 1 item income (Gaji)');
assertSame(300000.0, (float) $pnl['income'][0]['amount'], 'laporanPnl bulanan: income Gaji = 300rb');
assertSame(1, count($pnl['expense']), 'laporanPnl bulanan: 1 item expense (Belanja)');
assertSame(100000.0, (float) $pnl['expense'][0]['amount'], 'laporanPnl bulanan: expense Belanja = 100rb');
assertSame(200000.0, (float) $pnl['net'], 'laporanPnl bulanan: net = pendapatan-beban = 200rb');

$pnlYear = laporanPnl($spaceUsahaId, (string) $year);
assertSame(200000.0, (float) $pnlYear['net'], 'laporanPnl tahunan (param YYYY): net = 200rb juga (satu-satunya periode berisi transaksi)');

$json = runSub('laporanPnl(' . var_export($spaceId, true) . ', ' . var_export($period, true) . ');');
assertSame(false, $json['ok'] ?? null, 'laporanPnl: ruang personal -> ditolak (laba-rugi hanya utk ruang usaha)');

$json = runSub('laporanPnl(' . var_export($spaceUsahaId, true) . ', "bogus");');
assertSame(false, $json['ok'] ?? null, 'laporanPnl: format periode bukan YYYY-MM/YYYY -> ditolak');

$json = runSub('laporanPnl(' . var_export($spaceUsahaId, true) . ', "2026-13");');
assertSame(false, $json['ok'] ?? null, 'laporanPnl: bulan 13 -> ditolak');

// ==================== Export CSV: txListForExport + streamTransactionsCsv ====================

$exportBase = txListForExport($spaceId, ['from' => $today, 'to' => $today]);
assertSame(6, count($exportBase['rows']), 'txListForExport: 6 baris (T1..T6) di tanggal $today, filter from=to=today');
assertSame(false, $exportBase['truncated'], 'txListForExport: tidak terpotong (limit default jauh lebih besar dari 6 baris)');

ob_start();
streamTransactionsCsv($exportBase['rows'], $exportBase['truncated'], TX_EXPORT_LIMIT);
$csv = ob_get_clean();

assertSame("\xEF\xBB\xBF", substr($csv, 0, 3), 'streamTransactionsCsv: BOM UTF-8 di awal output');
assertSame(0, strpos($csv, "\xEF\xBB\xBFtanggal;jenis;kategori;akun;jumlah;catatan\r\n"), 'streamTransactionsCsv: baris header persis setelah BOM');

$expectIncomeRow = $today . ';Pemasukan;Gaji;Dompet;500000.00;Gaji bulan ini' . "\r\n";
assertSame(true, str_contains($csv, $expectIncomeRow), 'streamTransactionsCsv: baris income Gaji sesuai format (jenis Indonesia, jumlah 2 desimal)');

$expectTransferRow = $today . ';Transfer;;Dompet → Bank;100000.00;Transfer ke bank' . "\r\n";
assertSame(true, str_contains($csv, $expectTransferRow), 'streamTransactionsCsv: baris transfer -- kategori kosong, akun "Sumber → Tujuan"');

$expectUncatRow = $today . ';Pengeluaran;;Dompet;15000.00;Lupa kategori' . "\r\n";
assertSame(true, str_contains($csv, $expectUncatRow), 'streamTransactionsCsv: baris expense tanpa kategori -- kolom kategori kosong');

// Escape CSV: catatan mengandung delimiter ';' & tanda kutip '"'.
$insTx->execute([$spaceId, $accountA, $belanjaId, 'expense', 25000, $today, 'Servis; ganti "oli" motor', null]);
$exportWithNote = txListForExport($spaceId, ['from' => $today, 'to' => $today]);
assertSame(7, count($exportWithNote['rows']), 'txListForExport: 7 baris setelah tambah transaksi catatan mengandung ; dan "');

ob_start();
streamTransactionsCsv($exportWithNote['rows'], $exportWithNote['truncated'], TX_EXPORT_LIMIT);
$csvEscaped = ob_get_clean();
$expectEscapedRow = $today . ';Pengeluaran;Belanja;Dompet;25000.00;"Servis; ganti ""oli"" motor"' . "\r\n";
assertSame(true, str_contains($csvEscaped, $expectEscapedRow), 'streamTransactionsCsv: catatan dgn ; dan " dibungkus kutip & kutip internal digandakan');

// Mitigasi CSV/formula injection: catatan diawali '=' -> dibubuhi apostrof
// depan (dipaksa jadi teks polos, bukan formula, saat dibuka Excel/Sheets).
$insTx->execute([$spaceId, $accountA, $belanjaId, 'expense', 5000, $today, '=SUM(A1:A10)', null]);
$exportWithFormula = txListForExport($spaceId, ['from' => $today, 'to' => $today]);
ob_start();
streamTransactionsCsv($exportWithFormula['rows'], $exportWithFormula['truncated'], TX_EXPORT_LIMIT);
$csvFormula = ob_get_clean();
assertSame(true, str_contains($csvFormula, ";'=SUM(A1:A10)\r\n"), 'streamTransactionsCsv: catatan diawali "=" dibubuhi apostrof depan (mitigasi formula injection)');

// Filter type=income -> hanya baris income.
$exportIncomeOnly = txListForExport($spaceId, ['from' => $today, 'to' => $today, 'type' => 'income']);
assertSame(1, count($exportIncomeOnly['rows']), 'txListForExport: filter type=income -> 1 baris (Gaji)');
assertSame('income', $exportIncomeOnly['rows'][0]['type'], 'txListForExport: filter type=income -> baris benar-benar type income');

// Truncation: limit 2 dari 8 baris tersedia -> truncated true, catatan di baris terakhir CSV.
$exportTruncated = txListForExport($spaceId, ['from' => $today, 'to' => $today], 2);
assertSame(2, count($exportTruncated['rows']), 'txListForExport: limit=2 -> tepat 2 baris dikembalikan');
assertSame(true, $exportTruncated['truncated'], 'txListForExport: limit=2 dari 8 baris tersedia -> truncated true');

ob_start();
streamTransactionsCsv($exportTruncated['rows'], $exportTruncated['truncated'], 2);
$csvTruncated = ob_get_clean();
assertSame(true, str_contains($csvTruncated, 'CATATAN: hasil dipotong 2 baris'), 'streamTransactionsCsv: truncated=true -> baris catatan penutup berisi jumlah limit');

$exportNotTruncated = txListForExport($spaceId, ['from' => $today, 'to' => $today], 100);
assertSame(false, $exportNotTruncated['truncated'], 'txListForExport: limit=100 dari 8 baris tersedia -> tidak terpotong');
assertSame(8, count($exportNotTruncated['rows']), 'txListForExport: limit=100 -> semua 8 baris dikembalikan');

// --- cleanup ---------------------------------------------------------------

cleanupTestLaporan($testEmail);

$stmt = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE email = ?');
$stmt->execute([$testEmail]);
assertSame(0, (int) $stmt->fetch()['c'], 'cleanup: user test terhapus');
