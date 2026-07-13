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

function txRow(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM transactions WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

require_once __DIR__ . '/../core/balance.php';
require_once __DIR__ . '/../core/recurring.php';

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

// akun milik space lain (utk tes cross-space account di bawah)
$insAcc->execute([$otherSpaceId, 'Dompet Lain', 'cash', 0]);
$otherAccountId = (int) $pdo->lastInsertId();

// ==================== createDebt: validasi gagal (subprocess) ====================

$json = runSub('createDebt(' . var_export($spaceId, true) . ', ' . var_export([
    'direction' => 'invalid', 'party' => 'X', 'principal' => 1000, 'start_date' => $today,
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createDebt: direction tidak valid -> ditolak');

$json = runSub('createDebt(' . var_export($spaceId, true) . ', ' . var_export([
    'direction' => 'payable', 'party' => '', 'principal' => 1000, 'start_date' => $today,
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createDebt: party kosong -> ditolak');

$json = runSub('createDebt(' . var_export($spaceId, true) . ', ' . var_export([
    'direction' => 'payable', 'party' => 'X', 'principal' => 0, 'start_date' => $today,
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createDebt: principal 0 -> ditolak');

$json = runSub('createDebt(' . var_export($spaceId, true) . ', ' . var_export([
    'direction' => 'payable', 'party' => 'X', 'principal' => 1000, 'start_date' => $today,
    'is_installment' => true, 'installment_count' => 0, 'installment_amount' => 100, 'frequency' => 'monthly',
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createDebt: cicilan installment_count < 1 -> ditolak');

$json = runSub('createDebt(' . var_export($spaceId, true) . ', ' . var_export([
    'direction' => 'payable', 'party' => 'X', 'principal' => 1000, 'start_date' => $today,
    'is_installment' => true, 'installment_count' => 5, 'installment_amount' => 100, 'frequency' => 'harian',
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createDebt: frequency tidak valid -> ditolak');

// ==================== (a) createDebt payable + disburse ====================

$balBeforeDisburse = accountBalance($accountId);

$debtPay = createDebt($spaceId, [
    'direction' => 'payable', 'party' => 'Bank ABC', 'principal' => 2000000,
    'start_date' => $today, 'disburse' => true, 'account_id' => $accountId,
]);
assertSame(true, $debtPay['id'] > 0, 'createDebt: sukses, id > 0');
$debtPayId = (int) $debtPay['id'];
assertSame(2000000.0, (float) $debtPay['outstanding'], 'createDebt: outstanding awal = principal');
assertSame(2000000.0, debtOutstanding($debtPayId), 'createDebt: debtOutstanding() = principal');
assertSame('active', $debtPay['status'], 'createDebt: status awal active');

$stmt = $pdo->prepare('SELECT * FROM transactions WHERE debt_id = ?');
$stmt->execute([$debtPayId]);
$disburseTxs = $stmt->fetchAll();
assertSame(1, count($disburseTxs), 'createDebt(disburse): 1 transaksi tercatat');
assertSame('income', $disburseTxs[0]['type'], 'createDebt(disburse): transaksi bertipe income (payable = terima dana)');
$disburseCatId = categoryId($spaceId, 'Pencairan Pinjaman', 'income');
assertSame($disburseCatId, (int) $disburseTxs[0]['category_id'], 'createDebt(disburse): kategori "Pencairan Pinjaman"');
assertSame(2000000.0, (float) $disburseTxs[0]['amount'], 'createDebt(disburse): nominal transaksi = principal');

$balAfterDisburse = accountBalance($accountId);
assertSame($balBeforeDisburse + 2000000.0, $balAfterDisburse, 'createDebt(disburse): saldo akun naik sebesar principal');

// createDebt(disburse) dgn akun milik space lain -> ditolak, tidak membuat debt
$countDebtsBefore = (int) $pdo->query("SELECT COUNT(*) c FROM debts WHERE space_id = {$spaceId}")->fetch()['c'];
$json = runSub($sessAs($userId) . 'createDebt(' . var_export($spaceId, true) . ', ' . var_export([
    'direction' => 'payable', 'party' => 'X', 'principal' => 1000, 'start_date' => $today,
    'disburse' => true, 'account_id' => $otherAccountId,
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createDebt(disburse): akun milik space lain -> ditolak');
$countDebtsAfter = (int) $pdo->query("SELECT COUNT(*) c FROM debts WHERE space_id = {$spaceId}")->fetch()['c'];
assertSame($countDebtsBefore, $countDebtsAfter, 'createDebt(disburse): gagal akun lain -> tidak ada debt baru tersisa (rollback)');

// ==================== (b) payDebt payable 300rb ====================

$balBeforePay = accountBalance($accountId);

$pay1 = payDebt($debtPayId, $accountId, 300000, null);
assertSame(1700000.0, (float) $pay1['outstanding'], 'payDebt: outstanding turun jadi 1.700.000');
assertSame('active', $pay1['status'], 'payDebt: status masih active');

$stmt = $pdo->prepare("SELECT * FROM transactions WHERE debt_id = ? AND type = 'expense'");
$stmt->execute([$debtPayId]);
$payTxs = $stmt->fetchAll();
assertSame(1, count($payTxs), 'payDebt: 1 transaksi expense tercatat');
$payCatId = categoryId($spaceId, 'Bayar Utang/Cicilan', 'expense');
assertSame($payCatId, (int) $payTxs[0]['category_id'], 'payDebt: kategori "Bayar Utang/Cicilan"');
assertSame(300000.0, (float) $payTxs[0]['amount'], 'payDebt: nominal transaksi 300rb');

$balAfterPay = accountBalance($accountId);
assertSame($balBeforePay - 300000.0, $balAfterPay, 'payDebt: saldo akun turun 300rb');
assertSame(1700000.0, debtOutstanding($debtPayId), 'payDebt: debtOutstanding() ikut turun');

// pay > outstanding -> ditolak, tidak posting apapun
$json = runSub($sessAs($userId) . 'payDebt(' . var_export($debtPayId, true) . ', ' . var_export($accountId, true) . ', 5000000, null);');
assertSame(false, $json['ok'] ?? null, 'payDebt: nominal melebihi outstanding -> ditolak');
assertSame(1700000.0, debtOutstanding($debtPayId), 'payDebt: outstanding tidak berubah setelah percobaan gagal');

// pay akun milik space lain -> ditolak
$json = runSub($sessAs($userId) . 'payDebt(' . var_export($debtPayId, true) . ', ' . var_export($otherAccountId, true) . ', 10000, null);');
assertSame(false, $json['ok'] ?? null, 'payDebt: akun milik space lain -> ditolak');

// payDebt: user lain (bukan pemilik debt) -> ditolak (ownDebt)
$json = runSub($sessAs($otherUserId) . 'payDebt(' . var_export($debtPayId, true) . ', ' . var_export($accountId, true) . ', 10000, null);');
assertSame(false, $json['ok'] ?? null, 'payDebt: user lain -> ditolak (ownDebt)');

// ==================== (c) receivable pay ====================

$debtRecv = createDebt($spaceId, [
    'direction' => 'receivable', 'party' => 'Cici', 'principal' => 500000, 'start_date' => $today,
]);
$debtRecvId = (int) $debtRecv['id'];

$balBeforeRecv = accountBalance($accountId);
$payRecv = payDebt($debtRecvId, $accountId, 200000, null);
assertSame(300000.0, (float) $payRecv['outstanding'], 'payDebt(receivable): outstanding turun jadi 300rb');

$stmt = $pdo->prepare("SELECT * FROM transactions WHERE debt_id = ? AND type = 'income'");
$stmt->execute([$debtRecvId]);
$recvTxs = $stmt->fetchAll();
assertSame(1, count($recvTxs), 'payDebt(receivable): 1 transaksi income tercatat');
$receiveCatId = categoryId($spaceId, 'Terima Piutang', 'income');
assertSame($receiveCatId, (int) $recvTxs[0]['category_id'], 'payDebt(receivable): kategori "Terima Piutang"');
assertSame(200000.0, (float) $recvTxs[0]['amount'], 'payDebt(receivable): nominal transaksi 200rb');

$balAfterRecv = accountBalance($accountId);
assertSame($balBeforeRecv + 200000.0, $balAfterRecv, 'payDebt(receivable): saldo akun naik 200rb (uang diterima)');

// ==================== (d) cicilan: next_due maju anchor-aware + expectedNextDue basi -> 409 ====================

$debtCicilan = createDebt($spaceId, [
    'direction' => 'payable', 'party' => 'Leasing XYZ', 'principal' => 10200000,
    'start_date' => $today, 'is_installment' => true, 'installment_count' => 12,
    'installment_amount' => 850000, 'frequency' => 'monthly',
]);
$debtCicilanId = (int) $debtCicilan['id'];
assertSame(true, (bool) $debtCicilan['is_installment'], 'createDebt(cicilan): is_installment true');
assertSame(12, (int) $debtCicilan['installment_count'], 'createDebt(cicilan): installment_count 12');
assertSame($today, $debtCicilan['next_due'], 'createDebt(cicilan): next_due = start_date');

$payC1 = payDebt($debtCicilanId, $accountId, 850000, null, $today);
$expectedNextDue = advanceNextRun($today, 'monthly', $today);
assertSame($expectedNextDue, $payC1['next_due'], 'payDebt(cicilan): next_due maju 1 periode (anchor start_date)');

$stmt = $pdo->prepare('SELECT COUNT(*) c FROM debt_payments WHERE debt_id = ?');
$stmt->execute([$debtCicilanId]);
assertSame(1, (int) $stmt->fetch()['c'], 'payDebt(cicilan): paid_count = 1 setelah 1x bayar');

// expectedNextDue basi (kirim next_due LAMA yg sudah maju) -> 409, tidak posting
$json = runSub($sessAs($userId) . 'payDebt(' . var_export($debtCicilanId, true) . ', ' . var_export($accountId, true) . ', 850000, null, ' . var_export($today, true) . ');');
assertSame(false, $json['ok'] ?? null, 'payDebt(cicilan): expectedNextDue basi -> ditolak (409)');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'berubah'), 'payDebt(cicilan): pesan sebut jadwal "berubah"');

$stmt->execute([$debtCicilanId]);
assertSame(1, (int) $stmt->fetch()['c'], 'payDebt(cicilan): expectedNextDue basi tidak menambah pembayaran baru');

// ==================== (e) updateDebt: principal ditolak setelah ada payment ====================

$json = runSub($sessAs($userId) . 'updateDebt(' . var_export($debtPayId, true) . ', ' . var_export([
    'party' => 'Bank ABC', 'principal' => 999999, 'note' => '', 'due_date' => '',
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'updateDebt: ubah principal setelah ada payment -> ditolak');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'pokok'), 'updateDebt: pesan sebut "pokok"');

// principal SAMA (tidak diubah) + field lain berubah -> sukses
$updated = updateDebt($debtPayId, [
    'party' => 'Bank ABC (revisi)', 'principal' => 2000000, 'note' => 'Cicilan motor', 'due_date' => '',
]);
assertSame('Bank ABC (revisi)', $updated['party'], 'updateDebt: party berubah');
assertSame('Cicilan motor', $updated['note'], 'updateDebt: note berubah');
assertSame(2000000.0, (float) $updated['principal'], 'updateDebt: principal tetap 2.000.000');

// updateDebt: user lain -> ditolak
$json = runSub($sessAs($otherUserId) . 'updateDebt(' . var_export($debtRecvId, true) . ', ' . var_export([
    'party' => 'Hack', 'principal' => 500000, 'note' => '', 'due_date' => '',
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'updateDebt: user lain -> ditolak (ownDebt)');

// debt TANPA payment sama sekali -> principal boleh diubah bebas
$debtNoPay = createDebt($spaceId, ['direction' => 'payable', 'party' => 'NoPay', 'principal' => 100000, 'start_date' => $today]);
$updatedNoPay = updateDebt((int) $debtNoPay['id'], [
    'party' => 'NoPay', 'principal' => 250000, 'note' => '', 'due_date' => '',
]);
assertSame(250000.0, (float) $updatedNoPay['principal'], 'updateDebt: principal boleh diubah kalau belum ada payment');

// ==================== (f) deleteDebt: payments hilang, transaksi kas tetap ada debt_id NULL ====================

$debtDel = createDebt($spaceId, ['direction' => 'payable', 'party' => 'Toko Del', 'principal' => 100000, 'start_date' => $today]);
$debtDelId = (int) $debtDel['id'];
payDebt($debtDelId, $accountId, 50000, null);

$stmt = $pdo->prepare('SELECT id FROM transactions WHERE debt_id = ?');
$stmt->execute([$debtDelId]);
$delTxId = (int) $stmt->fetch()['id'];

// deleteDebt: user lain -> ditolak dulu, sebelum benar-benar dihapus pemiliknya
$json = runSub($sessAs($otherUserId) . 'deleteDebt(' . var_export($debtDelId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'deleteDebt: user lain -> ditolak');

deleteDebt($debtDelId);

$stmt = $pdo->prepare('SELECT COUNT(*) c FROM debt_payments WHERE debt_id = ?');
$stmt->execute([$debtDelId]);
assertSame(0, (int) $stmt->fetch()['c'], 'deleteDebt: debt_payments hilang (CASCADE)');

$stmt = $pdo->prepare('SELECT id FROM debts WHERE id = ?');
$stmt->execute([$debtDelId]);
assertSame(false, $stmt->fetch(), 'deleteDebt: baris debt hilang');

$delTx = txRow($delTxId);
assertSame(true, $delTx !== null, 'deleteDebt: transaksi kas TIDAK ikut terhapus');
assertSame(null, $delTx['debt_id'], 'deleteDebt: debt_id transaksi jadi NULL');

// ==================== (g) debtNetWorth: +receivable -payable ====================

$netWorthBefore = debtNetWorth($userId);

$debtNwPayable = createDebt($spaceId, ['direction' => 'payable', 'party' => 'NW Payable', 'principal' => 500000, 'start_date' => $today]);
$debtNwReceivable = createDebt($spaceId, ['direction' => 'receivable', 'party' => 'NW Receivable', 'principal' => 800000, 'start_date' => $today]);

$netWorthAfter = debtNetWorth($userId);
assertSame(300000.0, round($netWorthAfter - $netWorthBefore, 2), 'debtNetWorth: +800rb piutang - 500rb hutang = +300rb net');

// ==================== (h) auto-settle saat dibayar lunas ====================

$debtSettle = createDebt($spaceId, ['direction' => 'payable', 'party' => 'Kios', 'principal' => 100000, 'start_date' => $today]);
$debtSettleId = (int) $debtSettle['id'];

$paySettle = payDebt($debtSettleId, $accountId, 100000, null);
assertSame(0.0, (float) $paySettle['outstanding'], 'payDebt: outstanding 0 setelah lunas');
assertSame('settled', $paySettle['status'], 'payDebt: auto-settle status jadi settled');

$stmt = $pdo->prepare('SELECT status FROM debts WHERE id = ?');
$stmt->execute([$debtSettleId]);
assertSame('settled', $stmt->fetch()['status'], 'payDebt: status settled tersimpan di DB');

// pay ke debt yg sudah settled -> ditolak, tidak posting apapun
$stmt2 = $pdo->prepare("SELECT COUNT(*) c FROM transactions WHERE debt_id = ?");
$stmt2->execute([$debtSettleId]);
$txCountBeforeRetry = (int) $stmt2->fetch()['c'];
$json = runSub($sessAs($userId) . 'payDebt(' . var_export($debtSettleId, true) . ', ' . var_export($accountId, true) . ', 10000, null);');
assertSame(false, $json['ok'] ?? null, 'payDebt: bayar debt sudah settled -> ditolak');
$stmt2->execute([$debtSettleId]);
assertSame($txCountBeforeRetry, (int) $stmt2->fetch()['c'], 'payDebt: settled -> tidak ada transaksi baru tercatat');

// ==================== settleDebt: tandai lunas manual ====================

$debtManualSettle = createDebt($spaceId, ['direction' => 'receivable', 'party' => 'Manual', 'principal' => 50000, 'start_date' => $today]);
$settled = settleDebt((int) $debtManualSettle['id']);
assertSame('settled', $settled['status'], 'settleDebt: status langsung jadi settled');

$json = runSub($sessAs($otherUserId) . 'settleDebt(' . var_export($debtRecvId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'settleDebt: user lain -> ditolak');

// ==================== debtSummary & debtHistory ====================

$summary = debtSummary($spaceId);
assertSame(true, is_array($summary['payable']), 'debtSummary: array "payable" ada');
assertSame(true, is_array($summary['receivable']), 'debtSummary: array "receivable" ada');
assertSame(true, isset($summary['total_payable']), 'debtSummary: total_payable ada');
assertSame(true, isset($summary['total_receivable']), 'debtSummary: total_receivable ada');

$foundCicilan = null;
foreach ($summary['payable'] as $row) {
    if ((int) $row['id'] === $debtCicilanId) {
        $foundCicilan = $row;
    }
}
assertSame(true, $foundCicilan !== null, 'debtSummary: debt cicilan muncul di daftar payable');
assertSame(1, $foundCicilan['paid_count'], 'debtSummary: paid_count = 1 utk debt cicilan');
assertSame($expectedNextDue, $foundCicilan['next_due'], 'debtSummary: next_due sesuai hasil payDebt terakhir');

$history = debtHistory($debtCicilanId);
assertSame(1, count($history), 'debtHistory: 1 pembayaran tercatat utk debt cicilan');
assertSame(850000.0, (float) $history[0]['amount'], 'debtHistory: nominal sesuai');

$json = runSub($sessAs($otherUserId) . 'debtHistory(' . var_export($debtCicilanId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'debtHistory: user lain -> ditolak (ownDebt)');

// --- cleanup -----------------------------------------------------------------

cleanupTestHutang($testEmail);
cleanupTestHutang($otherEmail);

$stmt = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE email IN (?, ?)');
$stmt->execute([$testEmail, $otherEmail]);
assertSame(0, (int) $stmt->fetch()['c'], 'cleanup: user test terhapus');
