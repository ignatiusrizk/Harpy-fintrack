<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/seed.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/balance.php';

// Data test tetap — dibersihkan sebelum & sesudah.
$testEmail = 'test+balance@ft.local';
$otherEmail = 'test+balance-other@ft.local';

function cleanupTestBalance(string $email): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user === false) {
        return;
    }
    // Hapus transactions dulu secara eksplisit: cascade MySQL dari users ->
    // spaces -> {accounts, transactions} tidak menjamin urutan, jadi delete
    // account bisa lebih dulu dieksekusi daripada delete transactions dan
    // kena FK RESTRICT (fk_transactions_account). Transactions dihapus manual
    // di sini supaya cascade sisanya (accounts, categories, spaces) aman.
    $pdo->prepare(
        'DELETE t FROM transactions t JOIN spaces s ON s.id = t.space_id WHERE s.user_id = ?'
    )->execute([$user['id']]);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
}

ensureSession();

cleanupTestBalance($testEmail);
cleanupTestBalance($otherEmail);

// --- seed: user + space default + 2 akun -------------------------------

$userId = registerUser('Test Balance', $testEmail, 'password123');
$otherUserId = registerUser('Test Balance Other', $otherEmail, 'password123');

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM spaces WHERE user_id = ? ORDER BY id ASC LIMIT 1');
$stmt->execute([$userId]);
$spaceId = (int) $stmt->fetch()['id'];

$ins = $pdo->prepare('INSERT INTO accounts (space_id, name, type, initial_balance) VALUES (?, ?, ?, ?)');
$ins->execute([$spaceId, 'Dompet', 'cash', 100000]);
$accountA = (int) $pdo->lastInsertId();
$ins->execute([$spaceId, 'Bank', 'bank', 0]);
$accountB = (int) $pdo->lastInsertId();

// income 50rb & expense 20rb di akun A, transfer 30rb A -> B
$insTx = $pdo->prepare(
    'INSERT INTO transactions (space_id, account_id, type, amount, tx_date, to_account_id) VALUES (?, ?, ?, ?, ?, ?)'
);
$insTx->execute([$spaceId, $accountA, 'income', 50000, date('Y-m-d'), null]);
$insTx->execute([$spaceId, $accountA, 'expense', 20000, date('Y-m-d'), null]);
$insTx->execute([$spaceId, $accountA, 'transfer', 30000, date('Y-m-d'), $accountB]);

// --- accountBalance -----------------------------------------------------

assertSame(100000.0, accountBalance($accountA), 'accountBalance A = 100rb (100rb+50rb-20rb-30rb)');
assertSame(30000.0, accountBalance($accountB), 'accountBalance B = 30rb (0+30rb transfer masuk)');

// --- spaceBalances: satu query agregat -----------------------------------

$balances = spaceBalances($spaceId);
assertSame(true, isset($balances[$accountA]), 'spaceBalances punya entry akun A');
assertSame(true, isset($balances[$accountB]), 'spaceBalances punya entry akun B');
assertSame('Dompet', $balances[$accountA]['name'] ?? null, 'spaceBalances A name cocok');
assertSame('cash', $balances[$accountA]['type'] ?? null, 'spaceBalances A type cocok');
assertSame(100000.0, $balances[$accountA]['balance'] ?? null, 'spaceBalances A balance cocok');
assertSame('Bank', $balances[$accountB]['name'] ?? null, 'spaceBalances B name cocok');
assertSame('bank', $balances[$accountB]['type'] ?? null, 'spaceBalances B type cocok');
assertSame(30000.0, $balances[$accountB]['balance'] ?? null, 'spaceBalances B balance cocok');

// --- netWorth: sum akun space personal user + portfolioValue (Task 9) ----

// core/balance.php sekarang require_once core/portfolio.php (Task 9) --
// portfolioValue() SELALU ada begitu balance.php dimuat. User test ini belum
// punya aset investasi sama sekali, jadi portfolioValue() = 0 & netWorth
// tidak berubah dari sebelum Task 9. Skenario "netWorth naik setelah
// beli+set_price" dites khusus di tests/test_portfolio.php.
assertSame(true, function_exists('portfolioValue'), 'portfolioValue ada (Task 9, via require_once core/balance.php)');
assertSame(0.0, portfolioValue($userId), 'portfolioValue user tanpa aset = 0');
assertSame(130000.0, netWorth($userId), 'netWorth = 100rb + 30rb (tanpa aset investasi)');

// --- ownSpace / ownAccount: kepemilikan -----------------------------------

$_SESSION['user_id'] = $userId;
$owned = ownAccount($accountA);
assertSame($accountA, (int) $owned['id'], 'ownAccount sukses utk pemilik, return row akun');

$ownedSpace = ownSpace($spaceId);
assertSame($spaceId, (int) $ownedSpace['id'], 'ownSpace sukses utk pemilik, return row space');

unset($_SESSION['user_id']);

// User lain TIDAK bisa ownAccount akun ini -> apiErr(...,404) -> exit().
// Jalankan di subprocess terpisah spy proses test utama tetap hidup utk cleanup.
$code = 'require ' . var_export(__DIR__ . '/../core/db.php', true) . ';'
    . 'require ' . var_export(__DIR__ . '/../core/helpers.php', true) . ';'
    . 'require ' . var_export(__DIR__ . '/../core/balance.php', true) . ';'
    . 'session_start();'
    . '$_SESSION["user_id"] = ' . var_export($otherUserId, true) . ';'
    . 'ownAccount(' . var_export($accountA, true) . ');';
$output = shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($code));
$json = json_decode((string) $output, true);
assertSame(false, $json['ok'] ?? null, 'user lain -> ownAccount gagal (ok:false)');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'Tidak ditemukan'), 'user lain -> pesan "Tidak ditemukan"');

// Sekaligus pastikan ownSpace juga menolak space milik user lain.
$code2 = 'require ' . var_export(__DIR__ . '/../core/db.php', true) . ';'
    . 'require ' . var_export(__DIR__ . '/../core/helpers.php', true) . ';'
    . 'require ' . var_export(__DIR__ . '/../core/balance.php', true) . ';'
    . 'session_start();'
    . '$_SESSION["user_id"] = ' . var_export($otherUserId, true) . ';'
    . 'ownSpace(' . var_export($spaceId, true) . ');';
$output2 = shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($code2));
$json2 = json_decode((string) $output2, true);
assertSame(false, $json2['ok'] ?? null, 'user lain -> ownSpace gagal (ok:false)');

// --- cleanup ---------------------------------------------------------------

cleanupTestBalance($testEmail);
cleanupTestBalance($otherEmail);

$stmt = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE email IN (?, ?)');
$stmt->execute([$testEmail, $otherEmail]);
assertSame(0, (int) $stmt->fetch()['c'], 'cleanup: user test terhapus');
