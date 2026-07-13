<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/seed.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/kategori.php';
require_once __DIR__ . '/../core/transaksi.php';
require_once __DIR__ . '/../core/hutang.php';

// Data test tetap — dibersihkan sebelum & sesudah.
$testEmail = 'test+hutang@ft.local';
$otherEmail = 'test+hutang-other@ft.local';

function cleanupTestHutang(string $email): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user === false) {
        return;
    }
    // Urutan hapus manual (pola sama spt test_goals.php): transactions dulu
    // sebelum cascade users -> spaces -> accounts, supaya tidak kena FK
    // RESTRICT (transactions.account_id). debts/debt_payments CASCADE
    // otomatis lewat spaces -- tidak ada RESTRICT yg menyangkut mereka
    // (transactions.debt_id sendiri SET NULL, tidak menghalangi apapun).
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
        . 'require ' . var_export(__DIR__ . '/../core/hutang.php', true) . ';'
        . 'session_start();';
    $output = shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($preamble . $code));
    $json = json_decode((string) $output, true);
    return is_array($json) ? $json : ['ok' => null, 'raw' => $output];
}

$sessAs = function (int $uid): string {
    return '$_SESSION["user_id"] = ' . var_export($uid, true) . '; ';
};

function categoryId(int $spaceId, string $name, string $type): int
{
    $stmt = db()->prepare('SELECT id FROM categories WHERE space_id = ? AND name = ? AND type = ? LIMIT 1');
    $stmt->execute([$spaceId, $name, $type]);
    $row = $stmt->fetch();
    return $row === false ? 0 : (int) $row['id'];
}

ensureSession();

cleanupTestHutang($testEmail);
cleanupTestHutang($otherEmail);

// --- setup: 2 user + space + akun ------------------------------------------

$userId = registerUser('Test Hutang', $testEmail, 'password123');
$otherUserId = registerUser('Test Hutang Other', $otherEmail, 'password123');

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM spaces WHERE user_id = ? ORDER BY id ASC LIMIT 1');
$stmt->execute([$userId]);
$spaceId = (int) $stmt->fetch()['id'];
$stmt->execute([$otherUserId]);
$otherSpaceId = (int) $stmt->fetch()['id'];

$insAcc = $pdo->prepare('INSERT INTO accounts (space_id, name, type, initial_balance) VALUES (?, ?, ?, ?)');
$insAcc->execute([$spaceId, 'Dompet', 'cash', 1000000]);
$accountId = (int) $pdo->lastInsertId();

$_SESSION['user_id'] = $userId;

$today = date('Y-m-d');

// ==================== debtOutstanding: principal - Σ payments ====================

$insDebt = $pdo->prepare(
    'INSERT INTO debts (space_id, direction, party, principal, start_date) VALUES (?, ?, ?, ?, ?)'
);
$insDebt->execute([$spaceId, 'payable', 'Budi', 1000000, $today]);
$debtId = (int) $pdo->lastInsertId();

$insPay = $pdo->prepare('INSERT INTO debt_payments (debt_id, amount, pay_date) VALUES (?, ?, ?)');
$insPay->execute([$debtId, 300000, $today]);
$insPay->execute([$debtId, 200000, $today]);

assertSame(500000.0, debtOutstanding($debtId), 'debtOutstanding: 1.000.000 - (300.000 + 200.000) = 500.000');

// debt tanpa payment sama sekali -> outstanding = principal penuh
$insDebt->execute([$spaceId, 'receivable', 'Ani', 750000, $today]);
$debtId2 = (int) $pdo->lastInsertId();
assertSame(750000.0, debtOutstanding($debtId2), 'debtOutstanding: tanpa payment = principal penuh');

// ==================== debtCategory: buat on-demand + idempoten ====================

$catPayId1 = debtCategory($spaceId, 'pay');
assertSame(true, $catPayId1 > 0, 'debtCategory(pay): id > 0');
$expectedCatId = categoryId($spaceId, 'Bayar Utang/Cicilan', 'expense');
assertSame(true, $expectedCatId > 0, 'debtCategory(pay): kategori expense "Bayar Utang/Cicilan" dibuat');
assertSame($expectedCatId, $catPayId1, 'debtCategory(pay): id sesuai kategori yg dibuat');

$catPayId2 = debtCategory($spaceId, 'pay');
assertSame($catPayId1, $catPayId2, 'debtCategory(pay): idempoten, panggilan ke-2 return id sama (tidak duplikat)');

$catReceiveId = debtCategory($spaceId, 'receive');
$expectedReceiveCatId = categoryId($spaceId, 'Terima Piutang', 'income');
assertSame($expectedReceiveCatId, $catReceiveId, 'debtCategory(receive): kategori income "Terima Piutang"');

$catDisburseInId = debtCategory($spaceId, 'disburse_in');
$expectedDisburseInCatId = categoryId($spaceId, 'Pencairan Pinjaman', 'income');
assertSame($expectedDisburseInCatId, $catDisburseInId, 'debtCategory(disburse_in): kategori income "Pencairan Pinjaman"');

$catDisburseOutId = debtCategory($spaceId, 'disburse_out');
$expectedDisburseOutCatId = categoryId($spaceId, 'Beri Pinjaman', 'expense');
assertSame($expectedDisburseOutCatId, $catDisburseOutId, 'debtCategory(disburse_out): kategori expense "Beri Pinjaman"');

// ==================== ownDebt: kepemilikan ====================

$owned = ownDebt($debtId);
assertSame($debtId, (int) $owned['id'], 'ownDebt: return row debt yg benar utk pemilik');
assertSame($spaceId, (int) $owned['space_id'], 'ownDebt: space_id sesuai');

// ownDebt: user lain gagal (404), lewat subprocess supaya apiErr exit tidak
// mematikan proses test utama.
$json = runSub($sessAs($otherUserId) . 'ownDebt(' . var_export($debtId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'ownDebt: user lain -> ditolak');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'Tidak ditemukan'), 'ownDebt: pesan "Tidak ditemukan"');

// ownDebt: id tidak ada sama sekali -> gagal juga
$json = runSub($sessAs($userId) . 'ownDebt(999999999);');
assertSame(false, $json['ok'] ?? null, 'ownDebt: id tidak ada -> ditolak');

// --- cleanup -----------------------------------------------------------------

cleanupTestHutang($testEmail);
cleanupTestHutang($otherEmail);

$stmt = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE email IN (?, ?)');
$stmt->execute([$testEmail, $otherEmail]);
assertSame(0, (int) $stmt->fetch()['c'], 'cleanup: user test terhapus');
