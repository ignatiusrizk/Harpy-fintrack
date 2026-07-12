<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/seed.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/kategori.php';
require_once __DIR__ . '/../core/transaksi.php';
require_once __DIR__ . '/../core/goals.php';

// Data test tetap — dibersihkan sebelum & sesudah.
$testEmail = 'test+goals@ft.local';
$otherEmail = 'test+goals-other@ft.local';

function cleanupTestGoals(string $email): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user === false) {
        return;
    }
    // Urutan hapus manual (sama alasan spt test lain): transactions dulu
    // sebelum cascade users -> spaces -> accounts, supaya tidak kena FK
    // RESTRICT (transactions.account_id). goals/goal_entries CASCADE otomatis
    // lewat spaces -- tidak ada RESTRICT yg menyangkut mereka.
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
        . 'require ' . var_export(__DIR__ . '/../core/goals.php', true) . ';'
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

function goalEntryCount(int $goalId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) c FROM goal_entries WHERE goal_id = ?');
    $stmt->execute([$goalId]);
    return (int) $stmt->fetch()['c'];
}

function goalRow(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM goals WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

ensureSession();

cleanupTestGoals($testEmail);
cleanupTestGoals($otherEmail);

// --- setup: 2 user + space + akun ------------------------------------------

$userId = registerUser('Test Goals', $testEmail, 'password123');
$otherUserId = registerUser('Test Goals Other', $otherEmail, 'password123');

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM spaces WHERE user_id = ? ORDER BY id ASC LIMIT 1');
$stmt->execute([$userId]);
$spaceId = (int) $stmt->fetch()['id'];
$stmt->execute([$otherUserId]);
$otherSpaceId = (int) $stmt->fetch()['id'];

$insAcc = $pdo->prepare('INSERT INTO accounts (space_id, name, type, initial_balance) VALUES (?, ?, ?, ?)');
$insAcc->execute([$spaceId, 'Dompet', 'cash', 1000000]);
$accountId = (int) $pdo->lastInsertId();
$insAcc->execute([$otherSpaceId, 'Dompet Lain', 'cash', 0]);
$otherAccountId = (int) $pdo->lastInsertId();

$_SESSION['user_id'] = $userId;

$today = date('Y-m-d');

// ==================== createGoal: validasi gagal (subprocess) ====================

$json = runSub('createGoal(' . var_export($spaceId, true) . ', ' . var_export([
    'name' => '', 'target_amount' => 500000, 'target_date' => '',
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createGoal: nama kosong -> ditolak');

$json = runSub('createGoal(' . var_export($spaceId, true) . ', ' . var_export([
    'name' => 'Dana Darurat', 'target_amount' => 0, 'target_date' => '',
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createGoal: target_amount 0 -> ditolak');

$json = runSub('createGoal(' . var_export($spaceId, true) . ', ' . var_export([
    'name' => 'Dana Darurat', 'target_amount' => 500000, 'target_date' => '2026-02-30',
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createGoal: target_date kalender palsu -> ditolak');

// ==================== createGoal/list/update: sukses ====================

$goal = createGoal($spaceId, [
    'name' => 'Dana Darurat', 'target_amount' => 1000000, 'target_date' => null,
]);
assertSame(true, $goal['id'] > 0, 'createGoal: sukses, id > 0');
$goalId = (int) $goal['id'];
assertSame(false, $goal['is_done'], 'createGoal: is_done default false');
assertSame(0.0, (float) $goal['saved'], 'createGoal: saved awal 0');

$listed = listGoals($spaceId);
$found = null;
foreach ($listed as $row) {
    if ((int) $row['id'] === $goalId) {
        $found = $row;
    }
}
assertSame(true, $found !== null, 'listGoals: goal baru muncul');
assertSame(null, $found['days_left'], 'listGoals: tanpa target_date -> days_left null');

$targetSoon = date('Y-m-d', strtotime($today . ' +30 days'));
$upd = updateGoal($goalId, [
    'name' => 'Dana Darurat (revisi)', 'target_amount' => 1000000, 'target_date' => $targetSoon,
]);
assertSame('Dana Darurat (revisi)', $upd['name'], 'updateGoal: nama berubah');
assertSame($targetSoon, $upd['target_date'], 'updateGoal: target_date berubah');

$listed = listGoals($spaceId);
foreach ($listed as $row) {
    if ((int) $row['id'] === $goalId) {
        $found = $row;
    }
}
assertSame(30, $found['days_left'], 'listGoals: days_left dihitung dari target_date (30 hari ke depan)');

// ownGoal: user lain gagal (404) di semua mutasi

$json = runSub($sessAs($otherUserId) . 'updateGoal(' . var_export($goalId, true) . ', ' . var_export([
    'name' => 'Hack', 'target_amount' => 1, 'target_date' => '',
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'updateGoal: user lain -> ditolak (ownGoal)');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'Tidak ditemukan'), 'updateGoal: pesan "Tidak ditemukan"');

$json = runSub($sessAs($otherUserId) . 'deleteGoal(' . var_export($goalId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'deleteGoal: user lain -> ditolak');

$json = runSub($sessAs($otherUserId) . 'depositGoal(' . var_export($goalId, true) . ', ' . var_export($accountId, true) . ', 100000, null);');
assertSame(false, $json['ok'] ?? null, 'depositGoal: user lain -> ditolak (ownGoal)');

// ==================== deposit: transaksi + entry + saved/pct benar ====================

$dep1 = depositGoal($goalId, $accountId, 300000, null);
assertSame(true, $dep1['transaction']['id'] > 0, 'depositGoal: transaksi tercatat, id > 0');
$txId1 = (int) $dep1['transaction']['id'];
$tx1 = txRow($txId1);
assertSame('expense', $tx1['type'], 'depositGoal: transaksi bertipe expense');
assertSame(300000.0, (float) $tx1['amount'], 'depositGoal: nominal transaksi sesuai');
assertSame($goalId, (int) $tx1['goal_id'], 'depositGoal: transaksi tertaut goal_id');
$goalCatId = categoryId($spaceId, 'Tabungan Goal', 'expense');
assertSame(true, $goalCatId > 0, 'depositGoal: kategori "Tabungan Goal" expense ditemukan (seed)');
assertSame($goalCatId, (int) $tx1['category_id'], 'depositGoal: transaksi pakai kategori Tabungan Goal expense');

assertSame(1, goalEntryCount($goalId), 'depositGoal: 1 goal_entry tercatat');

$afterDep1 = listGoals($spaceId);
$found = null;
foreach ($afterDep1 as $row) {
    if ((int) $row['id'] === $goalId) {
        $found = $row;
    }
}
assertSame(300000.0, (float) $found['saved'], 'listGoals: saved = Σ goal_entries setelah 1x setor');
assertSame(30, $found['pct'], 'listGoals: pct = 30 (300rb / 1jt)');

$dep2 = depositGoal($goalId, $accountId, 400000, $today);
assertSame(2, goalEntryCount($goalId), 'depositGoal: 2 goal_entry tercatat setelah setor ke-2');
$afterDep2 = listGoals($spaceId);
foreach ($afterDep2 as $row) {
    if ((int) $row['id'] === $goalId) {
        $found = $row;
    }
}
assertSame(700000.0, (float) $found['saved'], 'listGoals: saved = 700rb setelah setor ke-2');
assertSame(70, $found['pct'], 'listGoals: pct = 70');

// deposit ke akun milik space lain -> ditolak
$json = runSub($sessAs($userId) . 'depositGoal(' . var_export($goalId, true) . ', ' . var_export($otherAccountId, true) . ', 50000, null);');
assertSame(false, $json['ok'] ?? null, 'depositGoal: akun milik space lain -> ditolak');
assertSame(2, goalEntryCount($goalId), 'depositGoal: entry tidak nambah setelah percobaan akun space lain gagal');

// deposit nominal 0/negatif -> ditolak
$json = runSub($sessAs($userId) . 'depositGoal(' . var_export($goalId, true) . ', ' . var_export($accountId, true) . ', 0, null);');
assertSame(false, $json['ok'] ?? null, 'depositGoal: nominal 0 -> ditolak');

// ==================== withdraw: mengurangi saved & tolak melebihi ====================

// withdraw melebihi saved (700rb tersimpan, tarik 800rb) -> ditolak
$json = runSub($sessAs($userId) . 'withdrawGoal(' . var_export($goalId, true) . ', ' . var_export($accountId, true) . ', 800000, null);');
assertSame(false, $json['ok'] ?? null, 'withdrawGoal: melebihi saved -> ditolak');
assertSame(2, goalEntryCount($goalId), 'withdrawGoal: entry tidak nambah setelah percobaan melebihi saved');

$wd1 = withdrawGoal($goalId, $accountId, 200000, null);
assertSame(true, $wd1['transaction']['id'] > 0, 'withdrawGoal: transaksi tercatat, id > 0');
$txIdW1 = (int) $wd1['transaction']['id'];
$txW1 = txRow($txIdW1);
assertSame('income', $txW1['type'], 'withdrawGoal: transaksi bertipe income');
assertSame(200000.0, (float) $txW1['amount'], 'withdrawGoal: nominal transaksi sesuai');
assertSame($goalId, (int) $txW1['goal_id'], 'withdrawGoal: transaksi tertaut goal_id');

$goalIncomeCatId = categoryId($spaceId, 'Tabungan Goal', 'income');
assertSame(true, $goalIncomeCatId > 0, 'withdrawGoal: kategori "Tabungan Goal" income dibuat on-demand');
assertSame($goalIncomeCatId, (int) $txW1['category_id'], 'withdrawGoal: transaksi pakai kategori Tabungan Goal income');

assertSame(3, goalEntryCount($goalId), 'withdrawGoal: goal_entry ke-3 tercatat (minus)');

$afterWd1 = listGoals($spaceId);
foreach ($afterWd1 as $row) {
    if ((int) $row['id'] === $goalId) {
        $found = $row;
    }
}
assertSame(500000.0, (float) $found['saved'], 'listGoals: saved = 500rb setelah tarik 200rb (700rb - 200rb)');
assertSame(50, $found['pct'], 'listGoals: pct = 50 setelah tarik');

// ==================== finish: is_done=1, deposit/withdraw ditolak setelahnya ====================

$finished = finishGoal($goalId);
assertSame(true, $finished['is_done'], 'finishGoal: is_done true');
$goalAfterFinish = goalRow($goalId);
assertSame(1, (int) $goalAfterFinish['is_done'], 'finishGoal: tersimpan di DB');

$json = runSub($sessAs($userId) . 'depositGoal(' . var_export($goalId, true) . ', ' . var_export($accountId, true) . ', 10000, null);');
assertSame(false, $json['ok'] ?? null, 'depositGoal: goal sudah selesai -> ditolak');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'selesai'), 'depositGoal(selesai): pesan sebut "selesai"');

$json = runSub($sessAs($userId) . 'withdrawGoal(' . var_export($goalId, true) . ', ' . var_export($accountId, true) . ', 10000, null);');
assertSame(false, $json['ok'] ?? null, 'withdrawGoal: goal sudah selesai -> ditolak');

assertSame(3, goalEntryCount($goalId), 'finishGoal: tidak ada entry baru setelah percobaan setor/tarik ke goal selesai');

// finishGoal: user lain gagal
$json = runSub($sessAs($otherUserId) . 'finishGoal(' . var_export($goalId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'finishGoal: user lain -> ditolak');

// ==================== delete: entries hilang, transaksi tetap ada dgn goal_id NULL ====================

$txIdsBeforeDelete = [$txId1, (int) $dep2['transaction']['id'], $txIdW1];

deleteGoal($goalId);

assertSame(0, goalEntryCount($goalId), 'deleteGoal: goal_entries hilang (CASCADE)');
assertSame(null, goalRow($goalId), 'deleteGoal: baris goal hilang');

foreach ($txIdsBeforeDelete as $txId) {
    $tx = txRow($txId);
    assertSame(true, $tx !== null, 'deleteGoal: transaksi #' . $txId . ' TIDAK ikut terhapus');
    assertSame(null, $tx['goal_id'], 'deleteGoal: goal_id transaksi #' . $txId . ' jadi NULL');
}

// --- cleanup -----------------------------------------------------------------

cleanupTestGoals($testEmail);
cleanupTestGoals($otherEmail);

$stmt = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE email IN (?, ?)');
$stmt->execute([$testEmail, $otherEmail]);
assertSame(0, (int) $stmt->fetch()['c'], 'cleanup: user test terhapus');
