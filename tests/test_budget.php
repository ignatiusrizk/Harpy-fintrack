<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/seed.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/kategori.php';
require_once __DIR__ . '/../core/transaksi.php';
require_once __DIR__ . '/../core/budget.php';

// Data test tetap — dibersihkan sebelum & sesudah.
$testEmail = 'test+budget@ft.local';
$otherEmail = 'test+budget-other@ft.local';

function cleanupTestBudget(string $email): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user === false) {
        return;
    }
    // Urutan hapus manual (sama alasan seperti test lain): budgets & transactions
    // dulu sebelum cascade users -> spaces -> categories/accounts, supaya tidak
    // kena FK RESTRICT.
    $pdo->prepare(
        'DELETE b FROM budgets b JOIN spaces s ON s.id = b.space_id WHERE s.user_id = ?'
    )->execute([$user['id']]);
    $pdo->prepare(
        'DELETE t FROM transactions t JOIN spaces s ON s.id = t.space_id WHERE s.user_id = ?'
    )->execute([$user['id']]);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
}

/**
 * Jalankan potongan kode PHP di subprocess terpisah (core sudah di-require)
 * supaya apiErr() yg exit() tidak mematikan proses test utama.
 */
function runSub(string $code): array
{
    $preamble = 'require ' . var_export(__DIR__ . '/../core/db.php', true) . ';'
        . 'require ' . var_export(__DIR__ . '/../core/helpers.php', true) . ';'
        . 'require ' . var_export(__DIR__ . '/../core/kategori.php', true) . ';'
        . 'require ' . var_export(__DIR__ . '/../core/transaksi.php', true) . ';'
        . 'require ' . var_export(__DIR__ . '/../core/budget.php', true) . ';'
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

function findStatus(array $rows, int $categoryId): ?array
{
    foreach ($rows as $r) {
        if ((int) $r['category_id'] === $categoryId) {
            return $r;
        }
    }
    return null;
}

ensureSession();

cleanupTestBudget($testEmail);
cleanupTestBudget($otherEmail);

// --- setup: user + space default + akun + kategori sub + space lain (lintas-space) ---

$userId = registerUser('Test Budget', $testEmail, 'password123');
$otherUserId = registerUser('Test Budget Other', $otherEmail, 'password123');

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM spaces WHERE user_id = ? ORDER BY id ASC LIMIT 1');
$stmt->execute([$userId]);
$spaceId = (int) $stmt->fetch()['id'];

$insAcc = $pdo->prepare('INSERT INTO accounts (space_id, name, type, initial_balance) VALUES (?, ?, ?, ?)');
$insAcc->execute([$spaceId, 'Dompet', 'cash', 1000000]);
$accountId = (int) $pdo->lastInsertId();

$spaceOtherId = createSpaceWithDefaults($otherUserId, 'Ruang Lain', 'personal');
$otherCatId = categoryId($spaceOtherId, 'Makan & Minum', 'expense');

$makanId = categoryId($spaceId, 'Makan & Minum', 'expense');
$belanjaId = categoryId($spaceId, 'Belanja', 'expense');
$gajiId = categoryId($spaceId, 'Gaji', 'income');
assertSame(true, $makanId > 0 && $belanjaId > 0 && $gajiId > 0, 'kategori seed default ditemukan');

$jajan = createCategory($spaceId, ['name' => 'Jajan', 'type' => 'expense', 'icon' => '🍿', 'color' => '#FF00FF', 'parent_id' => $makanId]);
$jajanId = (int) $jajan['id'];
assertSame($makanId, $jajan['parent_id'], 'setup: sub-kategori Jajan dibuat di bawah Makan & Minum');

$_SESSION['user_id'] = $userId;

$period = date('Y-m');
$prevPeriod = date('Y-m', strtotime($period . '-01 -1 month'));

// ==================== bgValidatePeriod / format ditolak ====================

$json = runSub('budgetStatus(' . var_export($spaceId, true) . ', "2026-13");');
assertSame(false, $json['ok'] ?? null, 'budgetStatus: period bulan tidak valid (13) -> ditolak');

$json = runSub('budgetStatus(' . var_export($spaceId, true) . ', "2026-7");');
assertSame(false, $json['ok'] ?? null, 'budgetStatus: period tanpa leading zero -> ditolak');

$json = runSub('setBudget(' . var_export($spaceId, true) . ', ' . var_export($makanId, true) . ', "bulan-ini", 100000);');
assertSame(false, $json['ok'] ?? null, 'setBudget: period bukan format YYYY-MM -> ditolak');

// ==================== setBudget: validasi kepemilikan & jenis ====================

$json = runSub('setBudget(' . var_export($spaceId, true) . ', ' . var_export($otherCatId, true) . ', ' . var_export($period, true) . ', 100000);');
assertSame(false, $json['ok'] ?? null, 'setBudget: kategori milik space lain -> ditolak');

$json = runSub('setBudget(' . var_export($spaceId, true) . ', ' . var_export($gajiId, true) . ', ' . var_export($period, true) . ', 100000);');
assertSame(false, $json['ok'] ?? null, 'setBudget: kategori income -> ditolak (budget hanya utk expense)');

// ==================== Skenario inti brief: budget 500rb Makan + expense 400rb -> pct 80 ====================

$set = setBudget($spaceId, $makanId, $period, 500000);
assertSame(false, $set['deleted'], 'setBudget: budget Makan & Minum 500rb tersimpan');

$today = date('Y-m-d');
createTransaction($spaceId, [
    'account_id' => $accountId, 'category_id' => $makanId, 'type' => 'expense',
    'amount' => 400000, 'tx_date' => $today, 'note' => 'Makan sebulan', 'to_account_id' => null,
]);

$status = budgetStatus($spaceId, $period);
$makanStatus = findStatus($status, $makanId);
assertSame(true, $makanStatus !== null, 'budgetStatus: Makan & Minum muncul di daftar (punya budget)');
assertSame(500000.0, $makanStatus !== null ? (float) $makanStatus['amount'] : null, 'budgetStatus: amount = 500rb');
assertSame(400000.0, $makanStatus !== null ? (float) $makanStatus['spent'] : null, 'budgetStatus: spent = 400rb');
assertSame(80, $makanStatus !== null ? $makanStatus['pct'] : null, 'budgetStatus: pct = 80');

// ==================== expense sub-kategori masuk ke budget parent ====================

createTransaction($spaceId, [
    'account_id' => $accountId, 'category_id' => $jajanId, 'type' => 'expense',
    'amount' => 100000, 'tx_date' => $today, 'note' => 'Jajan', 'to_account_id' => null,
]);

$status = budgetStatus($spaceId, $period);
$makanStatus = findStatus($status, $makanId);
assertSame(500000.0, $makanStatus !== null ? (float) $makanStatus['spent'] : null, 'budgetStatus: expense sub-kategori (Jajan) ikut masuk spent parent (400rb+100rb=500rb)');
assertSame(100, $makanStatus !== null ? $makanStatus['pct'] : null, 'budgetStatus: pct parent jadi 100 setelah expense anak ikut dihitung');
assertSame(null, findStatus($status, $jajanId), 'budgetStatus: Jajan (sub, belum ber-budget sendiri) tidak muncul sbg baris terpisah');

// ==================== sub-kategori yg ber-budget sendiri: tidak dobel ke parent ====================

setBudget($spaceId, $jajanId, $period, 50000);

$status = budgetStatus($spaceId, $period);
$makanStatus = findStatus($status, $makanId);
$jajanStatus = findStatus($status, $jajanId);
assertSame(400000.0, $makanStatus !== null ? (float) $makanStatus['spent'] : null, 'budgetStatus: setelah Jajan ber-budget sendiri, spent parent kembali cuma expense-nya sendiri (400rb, tidak dobel)');
assertSame(true, $jajanStatus !== null, 'budgetStatus: Jajan kini muncul sbg baris sendiri (punya budget sendiri)');
assertSame(100000.0, $jajanStatus !== null ? (float) $jajanStatus['spent'] : null, 'budgetStatus: spent Jajan = 100rb (expense-nya sendiri)');
assertSame(200, $jajanStatus !== null ? $jajanStatus['pct'] : null, 'budgetStatus: pct Jajan = 200 (100rb / 50rb) -- kondisi "Lewat"');

// ==================== unbudgetedCategories ====================

$unbudgeted = unbudgetedCategories($spaceId, $period);
$unbudgetedIds = array_map(fn ($c) => (int) $c['id'], $unbudgeted);
assertSame(false, in_array($makanId, $unbudgetedIds, true), 'unbudgetedCategories: Makan & Minum (sudah ber-budget) tidak muncul');
assertSame(false, in_array($jajanId, $unbudgetedIds, true), 'unbudgetedCategories: Jajan (sudah ber-budget) tidak muncul');
assertSame(true, in_array($belanjaId, $unbudgetedIds, true), 'unbudgetedCategories: Belanja (belum ber-budget) muncul');
assertSame(false, in_array($gajiId, $unbudgetedIds, true), 'unbudgetedCategories: kategori income tidak ikut ditawarkan');

// ==================== setBudget amount 0/kosong -> hapus ====================

$del = setBudget($spaceId, $jajanId, $period, 0);
assertSame(true, $del['deleted'], 'setBudget: amount 0 -> baris budget dihapus');
$status = budgetStatus($spaceId, $period);
assertSame(null, findStatus($status, $jajanId), 'budgetStatus: Jajan tidak lagi muncul setelah budget-nya dihapus (amount 0)');

$set = setBudget($spaceId, $belanjaId, $period, 200000);
assertSame(false, $set['deleted'], 'setBudget: set ulang Belanja 200rb utk uji hapus via string kosong');
$del2 = setBudget($spaceId, $belanjaId, $period, '');
assertSame(true, $del2['deleted'], 'setBudget: amount string kosong -> baris budget dihapus juga');

$json = runSub('setBudget(' . var_export($spaceId, true) . ', ' . var_export($makanId, true) . ', ' . var_export($period, true) . ', -1000);');
assertSame(false, $json['ok'] ?? null, 'setBudget: amount negatif -> ditolak');

// ==================== copy_prev: salin, yg sudah ada di-skip (tidak menimpa) ====================

// Budget bulan lalu: Makan & Minum 300rb (beda dari bulan ini yg 500rb) +
// Belanja 150rb (Belanja bulan ini sudah dihapus di atas -> harus tersalin).
$pdo->prepare('INSERT INTO budgets (space_id, category_id, period, amount) VALUES (?, ?, ?, ?)')
    ->execute([$spaceId, $makanId, $prevPeriod, 300000]);
$pdo->prepare('INSERT INTO budgets (space_id, category_id, period, amount) VALUES (?, ?, ?, ?)')
    ->execute([$spaceId, $belanjaId, $prevPeriod, 150000]);

$copied = copyPrevBudgets($spaceId, $period);
assertSame(1, $copied, 'copyPrevBudgets: hanya 1 baris tersalin (Belanja) -- Makan & Minum sudah ada di period ini, di-skip');

$status = budgetStatus($spaceId, $period);
$makanStatus = findStatus($status, $makanId);
$belanjaStatus = findStatus($status, $belanjaId);
assertSame(500000.0, $makanStatus !== null ? (float) $makanStatus['amount'] : null, 'copyPrevBudgets: budget Makan & Minum period ini TIDAK tertimpa (tetap 500rb, bukan 300rb dari bulan lalu)');
assertSame(150000.0, $belanjaStatus !== null ? (float) $belanjaStatus['amount'] : null, 'copyPrevBudgets: Belanja tersalin dgn amount 150rb dari bulan lalu');

$json = runSub('copyPrevBudgets(' . var_export($spaceId, true) . ', "salah");');
assertSame(false, $json['ok'] ?? null, 'copyPrevBudgets: period tidak valid -> ditolak');

// --- cleanup ---------------------------------------------------------------

cleanupTestBudget($testEmail);
cleanupTestBudget($otherEmail);

$stmt = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE email IN (?, ?)');
$stmt->execute([$testEmail, $otherEmail]);
assertSame(0, (int) $stmt->fetch()['c'], 'cleanup: user test terhapus');
