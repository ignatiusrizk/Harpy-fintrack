<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/seed.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/pengaturan.php';

// Data test tetap — dibersihkan sebelum & sesudah.
$testEmail = 'test+pengaturan@ft.local';
$otherEmail = 'test+pengaturan-other@ft.local';

function cleanupTestPengaturan(string $email): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user === false) {
        return;
    }
    // transactions & recurrings dihapus manual dulu (RESTRICT ke accounts/
    // categories menghalangi cascade users -> spaces -> accounts, alasan
    // sama spt spaceDelete & cleanup di test lain), sisanya cascade.
    $pdo->prepare(
        'DELETE t FROM transactions t JOIN spaces s ON s.id = t.space_id WHERE s.user_id = ?'
    )->execute([$user['id']]);
    $pdo->prepare(
        'DELETE r FROM recurrings r JOIN spaces s ON s.id = r.space_id WHERE s.user_id = ?'
    )->execute([$user['id']]);
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
        . 'require ' . var_export(__DIR__ . '/../core/seed.php', true) . ';'
        . 'require ' . var_export(__DIR__ . '/../core/auth.php', true) . ';'
        . 'require ' . var_export(__DIR__ . '/../core/pengaturan.php', true) . ';'
        . 'session_start();';
    $output = shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($preamble . $code));
    $json = json_decode((string) $output, true);
    return is_array($json) ? $json : ['ok' => null, 'raw' => $output];
}

$sessAs = function (int $uid, ?int $spaceId = null): string {
    $code = '$_SESSION["user_id"] = ' . var_export($uid, true) . '; ';
    if ($spaceId !== null) {
        $code .= '$_SESSION["space_id"] = ' . var_export($spaceId, true) . '; ';
    }
    return $code;
};

function userRow(int $id): ?array
{
    $stmt = db()->prepare('SELECT id, name, email, password_hash FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function spaceRow(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM spaces WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

ensureSession();

cleanupTestPengaturan($testEmail);
cleanupTestPengaturan($otherEmail);

// --- setup: 2 user (masing-masing 1 space "Pribadi" dari registerUser) -----

$userId = registerUser('Test Pengaturan', $testEmail, 'password123');
$otherUserId = registerUser('Test Pengaturan Other', $otherEmail, 'password123');

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM spaces WHERE user_id = ? ORDER BY id ASC LIMIT 1');
$stmt->execute([$userId]);
$spaceId = (int) $stmt->fetch()['id'];
$stmt->execute([$otherUserId]);
$otherSpaceId = (int) $stmt->fetch()['id'];

$_SESSION['user_id'] = $userId;
$_SESSION['space_id'] = $spaceId;

// ==================== profileUpdate ====================

$json = runSub($sessAs($userId) . 'profileUpdate(' . var_export($userId, true) . ', "");');
assertSame(false, $json['ok'] ?? null, 'profileUpdate: nama kosong -> ditolak');

$updated = profileUpdate($userId, 'Nama Baru');
assertSame('Nama Baru', $updated['name'], 'profileUpdate: nama tersimpan');
$fresh = userRow($userId);
assertSame('Nama Baru', $fresh['name'], 'profileUpdate: nama berubah di DB');

// ==================== passwordChange: salah/benar ====================

$json = runSub($sessAs($userId) . 'passwordChange(' . var_export($userId, true)
    . ', "passwordsalah", "passwordbaru123");');
assertSame(false, $json['ok'] ?? null, 'passwordChange: password lama salah -> ditolak');
$stillOld = userRow($userId);

$json = runSub($sessAs($userId) . 'passwordChange(' . var_export($userId, true)
    . ', "password123", "pendek");');
assertSame(false, $json['ok'] ?? null, 'passwordChange: password baru < 8 karakter -> ditolak');

$pdo->prepare('INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)')
    ->execute([$userId, 'seltest', hash('sha256', 'valtest'), date('Y-m-d H:i:s', time() + 3600)]);
$stmt = $pdo->prepare('SELECT COUNT(*) c FROM remember_tokens WHERE user_id = ?');
$stmt->execute([$userId]);
assertSame(1, (int) $stmt->fetch()['c'], 'setup: remember_token test tersimpan');

$beforeHash = userRow($userId)['password_hash'];
// Sukses dijalankan di subprocess (bukan proses test utama): CLI test sudah
// nge-echo hasil assert, jadi session_regenerate_id() di sana kena "headers
// already sent" -- di subprocess (serupa konteks API asli) belum ada output.
$json = runSub($sessAs($userId) . '$sidBefore = session_id();'
    . 'passwordChange(' . var_export($userId, true) . ', "password123", "passwordbaru123");'
    . 'echo json_encode(["ok" => true, "sid_changed" => session_id() !== $sidBefore]);');
assertSame(true, $json['ok'] ?? null, 'passwordChange: sukses dgn password lama benar');
assertSame(true, $json['sid_changed'] ?? null, 'passwordChange: session id diregenerasi (mitigasi fixation)');
$afterHash = userRow($userId)['password_hash'];
assertSame(true, $beforeHash !== $afterHash, 'passwordChange: hash berubah setelah sukses');
assertSame(true, password_verify('passwordbaru123', $afterHash), 'passwordChange: password baru bisa diverifikasi');
assertSame(false, password_verify('password123', $afterHash), 'passwordChange: password lama sudah tidak berlaku');

$stmt->execute([$userId]);
assertSame(0, (int) $stmt->fetch()['c'], 'passwordChange: semua remember_tokens user ikut dihapus (cookie "ingat saya" lama tidak berlaku)');

// ==================== spaceCreate: auto-switch ====================

$_SESSION['space_id'] = $spaceId; // pastikan mulai dari space lama
$newSpace = spaceCreate($userId, 'Usaha Laundry', 'business');
assertSame(true, $newSpace['id'] > 0, 'spaceCreate: sukses, id > 0');
$newSpaceId = (int) $newSpace['id'];
assertSame($newSpaceId, $_SESSION['space_id'], 'spaceCreate: session space_id auto-switch ke ruang baru');
assertSame('business', spaceRow($newSpaceId)['type'], 'spaceCreate: type tersimpan business');

$json = runSub($sessAs($userId) . 'spaceCreate(' . var_export($userId, true) . ', "", "personal");');
assertSame(false, $json['ok'] ?? null, 'spaceCreate: nama kosong -> ditolak');

$json = runSub($sessAs($userId) . 'spaceCreate(' . var_export($userId, true) . ', "Ruang X", "aneh");');
assertSame(false, $json['ok'] ?? null, 'spaceCreate: type tidak valid -> ditolak');

// ==================== spaceRename ====================

$renamed = spaceRename($newSpaceId, 'Usaha Laundry Kiloan');
assertSame('Usaha Laundry Kiloan', $renamed['name'], 'spaceRename: nama berubah');
assertSame('Usaha Laundry Kiloan', spaceRow($newSpaceId)['name'], 'spaceRename: tersimpan di DB');

$json = runSub($sessAs($otherUserId) . 'spaceRename(' . var_export($newSpaceId, true) . ', "Hack");');
assertSame(false, $json['ok'] ?? null, 'spaceRename: user lain -> ditolak (ownSpace, 404)');

// ==================== spaceSwitch: ke ruang user lain ditolak (404) ====================

$json = runSub($sessAs($userId) . 'spaceSwitch(' . var_export($otherSpaceId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'spaceSwitch: ruang milik user lain -> ditolak');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'Tidak ditemukan'), 'spaceSwitch: pesan "Tidak ditemukan"');

$switched = spaceSwitch($spaceId);
assertSame($spaceId, $_SESSION['space_id'], 'spaceSwitch: session space_id berpindah ke ruang sendiri');
assertSame($spaceId, $switched['id'], 'spaceSwitch: return ruang yg dituju');

// ==================== spaceDelete: satu-satunya ruang ditolak ====================

// otherUserId cuma punya 1 space ("Pribadi") -- coba hapus harus ditolak.
$json = runSub($sessAs($otherUserId, $otherSpaceId) . 'spaceDelete(' . var_export($otherSpaceId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'spaceDelete: satu-satunya ruang -> ditolak');
assertSame(true, spaceRow($otherSpaceId) !== null, 'spaceDelete: ruang satu-satunya TIDAK terhapus');

// ==================== spaceDelete: ruang aktif berisi data realistis -> session pindah + semua hilang ====================

// userId sekarang punya 2 space: $spaceId (Pribadi, lebih tua) & $newSpaceId (Usaha, aktif).
// Isi ruang Usaha dgn data realistis yg mencakup SEMUA jalur FK, termasuk
// jalur RESTRICT (transactions/recurrings -> accounts/categories) yg dulu
// bikin DELETE spaces gagal ERROR 1451.
$insAcc = $pdo->prepare('INSERT INTO accounts (space_id, name, type, initial_balance) VALUES (?, ?, ?, ?)');
$insAcc->execute([$newSpaceId, 'Kas Usaha', 'cash', 500000]);
$accInNewSpace = (int) $pdo->lastInsertId();

$stmt = $pdo->prepare('SELECT id FROM categories WHERE space_id = ? AND type = ? LIMIT 1');
$stmt->execute([$newSpaceId, 'expense']);
$catInNewSpace = (int) $stmt->fetch()['id'];
assertSame(true, $catInNewSpace > 0, 'setup: kategori seed ruang usaha ditemukan');

$pdo->prepare(
    'INSERT INTO transactions (space_id, account_id, category_id, type, amount, tx_date) VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$newSpaceId, $accInNewSpace, $catInNewSpace, 'expense', 75000, date('Y-m-d')]);
$txInNewSpace = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO recurrings (space_id, account_id, category_id, type, amount, frequency, anchor_date, next_run, mode)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([$newSpaceId, $accInNewSpace, $catInNewSpace, 'expense', 100000, 'monthly', date('Y-m-d'), date('Y-m-d'), 'reminder']);
$rcInNewSpace = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO goals (space_id, name, target_amount) VALUES (?, ?, ?)')
    ->execute([$newSpaceId, 'Goal Usaha', 1000000]);
$goalInNewSpace = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO goal_entries (goal_id, transaction_id, amount, entry_date) VALUES (?, ?, ?, ?)')
    ->execute([$goalInNewSpace, $txInNewSpace, 75000, date('Y-m-d')]);

$pdo->prepare('INSERT INTO assets (space_id, name, type, unit_label) VALUES (?, ?, ?, ?)')
    ->execute([$newSpaceId, 'Emas Usaha', 'gold', 'gram']);
$assetInNewSpace = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO asset_transactions (asset_id, side, units, price_per_unit, tx_date) VALUES (?, ?, ?, ?, ?)'
)->execute([$assetInNewSpace, 'buy', 5, 1200000, date('Y-m-d')]);
$pdo->prepare('INSERT INTO asset_prices (asset_id, price_per_unit, priced_at) VALUES (?, ?, ?)')
    ->execute([$assetInNewSpace, 1300000, date('Y-m-d')]);

$_SESSION['user_id'] = $userId;
$_SESSION['space_id'] = $newSpaceId; // ruang aktif = ruang yg akan dihapus

spaceDelete($newSpaceId); // dulu: ERROR 1451 di sini (FK RESTRICT); kini harus sukses

assertSame(null, spaceRow($newSpaceId), 'spaceDelete: baris ruang hilang');

$countIn = function (string $table, string $col, int $id) use ($pdo): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM {$table} WHERE {$col} = ?");
    $stmt->execute([$id]);
    return (int) $stmt->fetch()['c'];
};
assertSame(0, $countIn('accounts', 'id', $accInNewSpace), 'spaceDelete: akun ikut hilang');
assertSame(0, $countIn('transactions', 'id', $txInNewSpace), 'spaceDelete: transaksi ikut hilang');
assertSame(0, $countIn('recurrings', 'id', $rcInNewSpace), 'spaceDelete: recurring ikut hilang');
assertSame(0, $countIn('goals', 'id', $goalInNewSpace), 'spaceDelete: goal ikut hilang');
assertSame(0, $countIn('goal_entries', 'goal_id', $goalInNewSpace), 'spaceDelete: goal_entries ikut hilang');
assertSame(0, $countIn('categories', 'space_id', $newSpaceId), 'spaceDelete: kategori ikut hilang');
assertSame(0, $countIn('assets', 'id', $assetInNewSpace), 'spaceDelete: aset ikut hilang');
assertSame(0, $countIn('asset_transactions', 'asset_id', $assetInNewSpace), 'spaceDelete: trade aset ikut hilang');
assertSame(0, $countIn('asset_prices', 'asset_id', $assetInNewSpace), 'spaceDelete: harga aset ikut hilang');
assertSame($spaceId, $_SESSION['space_id'], 'spaceDelete: ruang aktif dihapus -> session pindah ke ruang tersisa tertua');
assertSame(false, $pdo->inTransaction(), 'spaceDelete: tidak meninggalkan transaksi DB terbuka');

// spaceDelete ruang yg BUKAN aktif -> session tidak berubah
$json = runSub($sessAs($otherUserId) . 'spaceDelete(' . var_export($spaceId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'spaceDelete: ruang milik user lain -> ditolak (ownSpace, 404)');

// ==================== spaceList ====================

$list = spaceList($userId);
assertSame(1, count($list), 'spaceList: tinggal 1 ruang setelah spaceDelete');
assertSame($spaceId, $list[0]['id'], 'spaceList: ruang yg tersisa sesuai');

// --- cleanup -----------------------------------------------------------------

cleanupTestPengaturan($testEmail);
cleanupTestPengaturan($otherEmail);

$stmt = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE email IN (?, ?)');
$stmt->execute([$testEmail, $otherEmail]);
assertSame(0, (int) $stmt->fetch()['c'], 'cleanup: user test terhapus');
