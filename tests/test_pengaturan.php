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
    // Cascade DB menghapus spaces/accounts/categories/remember_tokens milik user ini.
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
passwordChange($userId, 'password123', 'passwordbaru123');
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

// ==================== spaceDelete: ruang aktif -> session pindah + CASCADE ====================

// userId sekarang punya 2 space: $spaceId (Pribadi, lebih tua) & $newSpaceId (Usaha, aktif).
$insAcc = $pdo->prepare('INSERT INTO accounts (space_id, name, type, initial_balance) VALUES (?, ?, ?, ?)');
$insAcc->execute([$newSpaceId, 'Kas Usaha', 'cash', 500000]);
$accInNewSpace = (int) $pdo->lastInsertId();

$_SESSION['user_id'] = $userId;
$_SESSION['space_id'] = $newSpaceId; // ruang aktif = ruang yg akan dihapus

spaceDelete($newSpaceId);

assertSame(null, spaceRow($newSpaceId), 'spaceDelete: baris ruang hilang');
$stmtAcc = $pdo->prepare('SELECT COUNT(*) c FROM accounts WHERE id = ?');
$stmtAcc->execute([$accInNewSpace]);
assertSame(0, (int) $stmtAcc->fetch()['c'], 'spaceDelete: akun di dalamnya ikut hilang (CASCADE)');
assertSame($spaceId, $_SESSION['space_id'], 'spaceDelete: ruang aktif dihapus -> session pindah ke ruang tersisa tertua');

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
