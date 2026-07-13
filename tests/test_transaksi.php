<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/seed.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/balance.php';
require_once __DIR__ . '/../core/transaksi.php';
require_once __DIR__ . '/../core/kategori.php';
require_once __DIR__ . '/../core/goals.php';

// Data test tetap — dibersihkan sebelum & sesudah.
$testEmail = 'test+transaksi@ft.local';
$otherEmail = 'test+transaksi-other@ft.local';

function cleanupTestTransaksi(string $email): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user === false) {
        return;
    }
    // Hapus transactions eksplisit dulu (sama alasan seperti cleanupTestBalance):
    // cascade users -> spaces -> {accounts, transactions} tidak menjamin urutan,
    // dan accounts punya FK RESTRICT dari transactions.
    $pdo->prepare(
        'DELETE t FROM transactions t JOIN spaces s ON s.id = t.space_id WHERE s.user_id = ?'
    )->execute([$user['id']]);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
}

/**
 * Jalankan potongan kode PHP di subprocess terpisah (dengan core sudah
 * di-require + session dimulai) supaya apiErr() yang exit() proses tidak
 * mematikan proses test utama. Return array hasil json_decode stdout.
 */
function runSub(string $code): array
{
    // require_once (bukan require polos) -- balance.php sejak Task 3 (hutang)
    // require_once hutang.php yg sendirinya require_once transaksi.php, jadi
    // transaksi.php sudah otomatis ter-load lewat balance.php sebelum baris
    // eksplisitnya sendiri di bawah dieksekusi. require polos akan mengulang
    // eksekusi file itu & bikin "Cannot redeclare function" fatal.
    $preamble = 'require_once ' . var_export(__DIR__ . '/../core/db.php', true) . ';'
        . 'require_once ' . var_export(__DIR__ . '/../core/helpers.php', true) . ';'
        . 'require_once ' . var_export(__DIR__ . '/../core/balance.php', true) . ';'
        . 'require_once ' . var_export(__DIR__ . '/../core/transaksi.php', true) . ';'
        . 'require_once ' . var_export(__DIR__ . '/../core/kategori.php', true) . ';'
        . 'require_once ' . var_export(__DIR__ . '/../core/goals.php', true) . ';'
        . 'session_start();';
    $output = shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($preamble . $code));
    $json = json_decode((string) $output, true);
    return is_array($json) ? $json : ['ok' => null, 'raw' => $output];
}

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

function goalEntryCount(int $goalId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) c FROM goal_entries WHERE goal_id = ?');
    $stmt->execute([$goalId]);
    return (int) $stmt->fetch()['c'];
}

function goalSaved(int $goalId): float
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(amount), 0) saved FROM goal_entries WHERE goal_id = ?');
    $stmt->execute([$goalId]);
    return (float) $stmt->fetch()['saved'];
}

ensureSession();

cleanupTestTransaksi($testEmail);
cleanupTestTransaksi($otherEmail);

// --- setup: user + space default + 2 akun + space usaha (utk tes lintas space) ---

$userId = registerUser('Test Transaksi', $testEmail, 'password123');
$otherUserId = registerUser('Test Transaksi Other', $otherEmail, 'password123');

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM spaces WHERE user_id = ? ORDER BY id ASC LIMIT 1');
$stmt->execute([$userId]);
$spaceId = (int) $stmt->fetch()['id'];

$insAcc = $pdo->prepare('INSERT INTO accounts (space_id, name, type, initial_balance) VALUES (?, ?, ?, ?)');
$insAcc->execute([$spaceId, 'Dompet', 'cash', 100000]);
$accountA = (int) $pdo->lastInsertId();
$insAcc->execute([$spaceId, 'Bank', 'bank', 0]);
$accountB = (int) $pdo->lastInsertId();

$spaceUsahaId = createSpaceWithDefaults($userId, 'Usaha Test', 'business');
$insAcc->execute([$spaceUsahaId, 'Kas Usaha', 'cash', 0]);
$accountC = (int) $pdo->lastInsertId();

$gajiId = categoryId($spaceId, 'Gaji', 'income');
$makanId = categoryId($spaceId, 'Makan & Minum', 'expense');
$belanjaId = categoryId($spaceId, 'Belanja', 'expense');
assertSame(true, $gajiId > 0 && $makanId > 0 && $belanjaId > 0, 'kategori seed default ditemukan (Gaji/Makan & Minum/Belanja)');

// Dipakai oleh panggilan LANGSUNG (bukan subprocess) ke fungsi yg validasi
// kepemilikan (ownCategory/ownTransaction) -- mis. deleteCategory,
// updateTransaction, deleteTransaction di bawah.
$_SESSION['user_id'] = $userId;

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// ==================== Kategori: CRUD sukses ====================

$kado = createCategory($spaceId, ['name' => 'Kado', 'type' => 'expense', 'icon' => '🎁', 'color' => '#FF00FF', 'parent_id' => null]);
assertSame(true, $kado['id'] > 0, 'createCategory sukses (root)');
$kadoId = (int) $kado['id'];

$kadoUltah = createCategory($spaceId, ['name' => 'Kado Ultah', 'type' => 'expense', 'icon' => '', 'color' => '', 'parent_id' => $kadoId]);
assertSame($kadoId, $kadoUltah['parent_id'], 'createCategory sukses (sub-kategori 1 level)');
assertSame('🏷️', $kadoUltah['icon'], 'createCategory: icon kosong -> default');
$kadoUltahId = (int) $kadoUltah['id'];

$tree = listCategoriesTree($spaceId);
$foundKado = null;
foreach ($tree['expense'] as $node) {
    if ((int) $node['id'] === $kadoId) {
        $foundKado = $node;
        break;
    }
}
assertSame(true, $foundKado !== null, 'listCategoriesTree: root Kado ada di bucket expense');
assertSame(1, $foundKado !== null ? count($foundKado['children']) : -1, 'listCategoriesTree: Kado punya 1 anak');
assertSame('Kado Ultah', $foundKado !== null ? $foundKado['children'][0]['name'] : null, 'listCategoriesTree: nama anak cocok');

// ==================== Kategori: validasi gagal (subprocess) ====================

$json = runSub('createCategory(' . var_export($spaceId, true) . ', ' . var_export(['name' => 'X', 'type' => 'expense', 'parent_id' => $kadoUltahId], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createCategory: parent adalah sub-kategori (2 level) -> ditolak');

$json = runSub('createCategory(' . var_export($spaceId, true) . ', ' . var_export(['name' => 'Y', 'type' => 'expense', 'parent_id' => $gajiId], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createCategory: parent beda type -> ditolak');

// updateCategory/deleteCategory lewat ownCategory() -- subprocess WAJIB set
// session user_id dulu, kalau tidak gagalnya krn "Tidak ditemukan" (ownership)
// duluan, bukan aturan bisnis yg sedang diuji.
$sessAs = function (int $uid): string {
    return '$_SESSION["user_id"] = ' . var_export($uid, true) . '; ';
};

$json = runSub($sessAs($userId) . 'updateCategory(' . var_export($kadoId, true) . ', ' . var_export(['name' => 'Kado', 'type' => 'expense', 'parent_id' => $kadoId], true) . ');');
assertSame(false, $json['ok'] ?? null, 'updateCategory: parent = diri sendiri -> ditolak');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'induk dirinya sendiri'), 'updateCategory: pesan sebut "induk dirinya sendiri"');

$json = runSub($sessAs($userId) . 'updateCategory(' . var_export($kadoId, true) . ', ' . var_export(['name' => 'Kado', 'type' => 'expense', 'parent_id' => $belanjaId], true) . ');');
assertSame(false, $json['ok'] ?? null, 'updateCategory: kategori yg sudah punya anak -> tidak bisa jadi sub-kategori lain');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'sudah punya sub-kategori'), 'updateCategory: pesan sebut "sudah punya sub-kategori"');

// Ganti type kategori yg PUNYA anak (bukan skenario "jadi anak" di atas, tapi
// "sedang jadi induk" & type-nya diubah) -- juga harus ditolak, supaya tidak
// ada anak yg type-nya beda dari induknya.
$json = runSub($sessAs($userId) . 'updateCategory(' . var_export($kadoId, true) . ', ' . var_export(['name' => 'Kado', 'type' => 'income', 'parent_id' => null], true) . ');');
assertSame(false, $json['ok'] ?? null, 'updateCategory: ganti type kategori yg punya anak -> ditolak');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'ubah jenis'), 'updateCategory: pesan sebut "ubah jenis"');

// ==================== Transaksi: create sukses (income/expense/transfer) ====================

$txIncome = createTransaction($spaceId, [
    'account_id' => $accountA, 'category_id' => $gajiId, 'type' => 'income',
    'amount' => 50000, 'tx_date' => $today, 'note' => null, 'to_account_id' => null,
]);
assertSame(true, $txIncome['id'] > 0, 'createTransaction income sukses');
$txIncomeId = (int) $txIncome['id'];

$txExpense = createTransaction($spaceId, [
    'account_id' => $accountA, 'category_id' => $makanId, 'type' => 'expense',
    'amount' => 20000, 'tx_date' => $today, 'note' => 'Makan siang capcay', 'to_account_id' => null,
]);
assertSame(true, $txExpense['id'] > 0, 'createTransaction expense sukses');
$txExpenseId = (int) $txExpense['id'];

$txTransfer = createTransaction($spaceId, [
    'account_id' => $accountA, 'category_id' => null, 'type' => 'transfer',
    'amount' => 30000, 'tx_date' => $today, 'note' => null, 'to_account_id' => $accountB,
]);
assertSame(true, $txTransfer['id'] > 0, 'createTransaction transfer sukses');
assertSame(null, $txTransfer['category_id'], 'createTransaction transfer: category_id dipaksa null');
$txTransferId = (int) $txTransfer['id'];

assertSame(100000.0, accountBalance($accountA), 'saldo A setelah income+expense+transfer keluar = 100rb (100rb+50rb-20rb-30rb)');
assertSame(30000.0, accountBalance($accountB), 'saldo B setelah transfer masuk = 30rb');

// ==================== Transaksi: validasi gagal (subprocess) ====================

$json = runSub('createTransaction(' . var_export($spaceId, true) . ', ' . var_export([
    'account_id' => $accountA, 'category_id' => $makanId, 'type' => 'expense',
    'amount' => 0, 'tx_date' => $today, 'note' => null, 'to_account_id' => null,
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createTransaction: amount<=0 -> ditolak');

$json = runSub('createTransaction(' . var_export($spaceId, true) . ', ' . var_export([
    'account_id' => $accountA, 'category_id' => $makanId, 'type' => 'expense',
    'amount' => -5000, 'tx_date' => $today, 'note' => null, 'to_account_id' => null,
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createTransaction: amount negatif -> ditolak');

$json = runSub('createTransaction(' . var_export($spaceId, true) . ', ' . var_export([
    'account_id' => $accountA, 'category_id' => null, 'type' => 'transfer',
    'amount' => 10000, 'tx_date' => $today, 'note' => null, 'to_account_id' => $accountA,
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createTransaction: transfer ke akun sendiri -> ditolak');

$json = runSub('createTransaction(' . var_export($spaceId, true) . ', ' . var_export([
    'account_id' => $accountA, 'category_id' => null, 'type' => 'transfer',
    'amount' => 10000, 'tx_date' => $today, 'note' => null, 'to_account_id' => $accountC,
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createTransaction: transfer lintas space (akun tujuan di space lain) -> ditolak');

$json = runSub('createTransaction(' . var_export($spaceId, true) . ', ' . var_export([
    'account_id' => $accountA, 'category_id' => $makanId, 'type' => 'income',
    'amount' => 10000, 'tx_date' => $today, 'note' => null, 'to_account_id' => null,
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createTransaction: kategori type mismatch (expense dipakai di income) -> ditolak');

$json = runSub('createTransaction(' . var_export($spaceId, true) . ', ' . var_export([
    'account_id' => $accountA, 'category_id' => null, 'type' => 'expense',
    'amount' => 10000, 'tx_date' => $today, 'note' => null, 'to_account_id' => null,
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createTransaction: expense tanpa category_id -> ditolak');

// ==================== Transaksi: list filter & agregat ====================

$all = listTransactions($spaceId, []);
assertSame(3, $all['total_rows'], 'listTransactions tanpa filter: 3 baris');
assertSame(50000.0, (float) $all['total_income'], 'listTransactions tanpa filter: total_income 50rb');
assertSame(20000.0, (float) $all['total_expense'], 'listTransactions tanpa filter: total_expense 20rb (transfer tidak dihitung)');

$byCategory = listTransactions($spaceId, ['category_id' => $makanId]);
assertSame(1, $byCategory['total_rows'], 'listTransactions filter category_id: 1 baris');
assertSame(20000.0, (float) $byCategory['total_expense'], 'listTransactions filter category_id: total_expense cocok');

$byAccountB = listTransactions($spaceId, ['account_id' => $accountB]);
assertSame(1, $byAccountB['total_rows'], 'listTransactions filter account_id (tujuan transfer): 1 baris');

$byType = listTransactions($spaceId, ['type' => 'income']);
assertSame(1, $byType['total_rows'], 'listTransactions filter type=income: 1 baris');

$byQ = listTransactions($spaceId, ['q' => 'capcay']);
assertSame(1, $byQ['total_rows'], 'listTransactions filter q (LIKE note): 1 baris');

$rangeToday = listTransactions($spaceId, ['from' => $today, 'to' => $today]);
assertSame(3, $rangeToday['total_rows'], 'listTransactions range hari ini: 3 baris');

$rangeTomorrow = listTransactions($spaceId, ['from' => $tomorrow, 'to' => $tomorrow]);
assertSame(0, $rangeTomorrow['total_rows'], 'listTransactions range besok (kosong): 0 baris');

// ==================== Kategori: delete diblok (subprocess) ====================

$json = runSub($sessAs($userId) . 'deleteCategory(' . var_export($makanId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'deleteCategory: kategori dipakai transaksi -> ditolak');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'transaksi'), 'deleteCategory: pesan sebut "transaksi"');

$json = runSub($sessAs($userId) . 'deleteCategory(' . var_export($kadoId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'deleteCategory: kategori punya sub-kategori -> ditolak');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'sub-kategori'), 'deleteCategory: pesan sebut "sub-kategori"');

// budget guard: seed 1 baris budget utk Belanja, lalu coba hapus.
$pdo->prepare('INSERT INTO budgets (space_id, category_id, period, amount) VALUES (?, ?, ?, ?)')
    ->execute([$spaceId, $belanjaId, date('Y-m'), 500000]);
$json = runSub($sessAs($userId) . 'deleteCategory(' . var_export($belanjaId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'deleteCategory: kategori dipakai budget -> ditolak');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'budget'), 'deleteCategory: pesan sebut "budget"');
$pdo->prepare('DELETE FROM budgets WHERE space_id = ? AND category_id = ?')->execute([$spaceId, $belanjaId]);

// Kado Ultah tidak dipakai apa pun -> boleh dihapus langsung (bukan subprocess, sukses tidak exit).
deleteCategory($kadoUltahId);
deleteCategory($kadoId);
$stmt = $pdo->prepare('SELECT COUNT(*) c FROM categories WHERE id IN (?, ?)');
$stmt->execute([$kadoId, $kadoUltahId]);
assertSame(0, (int) $stmt->fetch()['c'], 'deleteCategory: Kado & Kado Ultah terhapus setelah tidak lagi diblok');

// ==================== Transaksi: update & delete ====================

$updated = updateTransaction($txExpenseId, [
    'account_id' => $accountA, 'category_id' => $makanId, 'type' => 'expense',
    'amount' => 25000, 'tx_date' => $today, 'note' => 'Makan siang capcay (revisi)', 'to_account_id' => null,
]);
assertSame(25000.0, (float) $updated['amount'], 'updateTransaction: amount berubah jadi 25rb');
assertSame(95000.0, accountBalance($accountA), 'updateTransaction: saldo A ikut berubah (100rb+50rb-25rb-30rb)');

deleteTransaction($txTransferId);
assertSame(125000.0, accountBalance($accountA), 'deleteTransaction: saldo A setelah transfer dihapus (100rb+50rb-25rb)');
assertSame(0.0, accountBalance($accountB), 'deleteTransaction: saldo B kembali 0 setelah transfer dihapus');

$afterDelete = listTransactions($spaceId, []);
assertSame(2, $afterDelete['total_rows'], 'listTransactions setelah delete transfer: 2 baris tersisa');

// ==================== ownTransaction: user lain gagal ====================

$json = runSub('$_SESSION["user_id"] = ' . var_export($otherUserId, true) . '; ownTransaction(' . var_export($txIncomeId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'ownTransaction: user lain gagal (ok:false)');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'Tidak ditemukan'), 'ownTransaction: user lain -> pesan "Tidak ditemukan"');

// updateTransaction/deleteTransaction endpoint pun pakai ownTransaction -> otomatis terlindungi (dites di atas).

// ==================== Transaksi tertaut goal: update/delete ditolak ====================
// Transaksi hasil depositGoal() ikut disorot listTransactions()/UI Transaksi
// spt transaksi biasa, tapi kalau diedit/dihapus dari sana goal_entries jadi
// basi (lihat komentar txRejectIfGoalLinked()) -- deleteTransaction/
// updateTransaction WAJIB menolaknya & mengarahkan ke halaman Goals.

$goal = createGoal($spaceId, ['name' => 'Dana Darurat', 'target_amount' => 500000, 'target_date' => null]);
$goalId = (int) $goal['id'];
$dep = depositGoal($goalId, $accountA, 100000, $today);
$goalTxId = (int) $dep['transaction']['id'];

assertSame(1, goalEntryCount($goalId), 'setup goal-linked: 1 goal_entry setelah depositGoal');
assertSame(100000.0, goalSaved($goalId), 'setup goal-linked: saved = 100rb setelah depositGoal');
$balanceABeforeReject = accountBalance($accountA);

// (a) delete transaksi goal-linked -> ditolak, goal_entries & goal TIDAK berubah, saldo TIDAK berubah.
$json = runSub($sessAs($userId) . 'deleteTransaction(' . var_export($goalTxId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'deleteTransaction: transaksi tertaut goal -> ditolak');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'Goals'), 'deleteTransaction(goal-linked): pesan arahkan ke "Goals"');
assertSame(true, txRow($goalTxId) !== null, 'deleteTransaction(goal-linked ditolak): transaksi TIDAK ikut terhapus');
assertSame(1, goalEntryCount($goalId), 'deleteTransaction(goal-linked ditolak): goal_entries TIDAK berubah');
assertSame(100000.0, goalSaved($goalId), 'deleteTransaction(goal-linked ditolak): saved goal TIDAK berubah');
assertSame($balanceABeforeReject, accountBalance($accountA), 'deleteTransaction(goal-linked ditolak): saldo akun TIDAK berubah');

// (b) update transaksi goal-linked -> ditolak, amount transaksi & saved goal TIDAK berubah.
$json = runSub($sessAs($userId) . 'updateTransaction(' . var_export($goalTxId, true) . ', ' . var_export([
    'account_id' => $accountA, 'category_id' => $makanId, 'type' => 'expense',
    'amount' => 999000, 'tx_date' => $today, 'note' => 'Coba ganti', 'to_account_id' => null,
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'updateTransaction: transaksi tertaut goal -> ditolak');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'Goals'), 'updateTransaction(goal-linked): pesan arahkan ke "Goals"');
$goalTxAfterRejectedUpdate = txRow($goalTxId);
assertSame(100000.0, (float) $goalTxAfterRejectedUpdate['amount'], 'updateTransaction(goal-linked ditolak): amount transaksi TIDAK berubah');
assertSame(100000.0, goalSaved($goalId), 'updateTransaction(goal-linked ditolak): saved goal TIDAK berubah');

// (c) setelah goal induknya dihapus, transaksi (kini goal_id NULL) boleh dihapus normal.
deleteGoal($goalId);
$goalTxAfterGoalDelete = txRow($goalTxId);
assertSame(null, $goalTxAfterGoalDelete['goal_id'], 'deleteGoal: goal_id transaksi jadi NULL setelah goal induk dihapus');

deleteTransaction($goalTxId); // langsung (bukan subprocess) -- harus sukses, tidak exit.
assertSame(null, txRow($goalTxId), 'deleteTransaction: transaksi ex-goal (goal_id NULL) berhasil dihapus normal');

// ==================== listTransactions: filter q escape wildcard LIKE (%, _) ====================
// Bug lama: '%'/'_' dari input user tidak di-escape sebelum masuk LIKE ->
// dianggap wildcard, bukan karakter literal. q='%' saja pada kode lama akan
// jadi pola '%%%' yg cocok dgn SEMUA baris (termasuk yg tidak punya '%' sama
// sekali) -- sekarang harus HANYA cocok baris yg benar-benar punya '%'.

$txWithPercent = createTransaction($spaceId, [
    'account_id' => $accountA, 'category_id' => $makanId, 'type' => 'expense',
    'amount' => 15000, 'tx_date' => $today, 'note' => 'Diskon 50% dari toko', 'to_account_id' => null,
]);
$txNoPercent = createTransaction($spaceId, [
    'account_id' => $accountA, 'category_id' => $makanId, 'type' => 'expense',
    'amount' => 12000, 'tx_date' => $today, 'note' => 'Beli kopi susu', 'to_account_id' => null,
]);

$byPercent = listTransactions($spaceId, ['q' => '%']);
$percentIds = array_map(fn ($r) => (int) $r['id'], $byPercent['rows']);
assertSame(true, in_array((int) $txWithPercent['id'], $percentIds, true), 'listTransactions q="%": catatan berisi "%" literal ditemukan');
assertSame(false, in_array((int) $txNoPercent['id'], $percentIds, true), 'listTransactions q="%": catatan TANPA "%" tidak ikut cocok (bukan wildcard "cocok semua")');

// --- cleanup ---------------------------------------------------------------

cleanupTestTransaksi($testEmail);
cleanupTestTransaksi($otherEmail);

$stmt = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE email IN (?, ?)');
$stmt->execute([$testEmail, $otherEmail]);
assertSame(0, (int) $stmt->fetch()['c'], 'cleanup: user test terhapus');
