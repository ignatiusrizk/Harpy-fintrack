<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/seed.php';
require_once __DIR__ . '/../core/auth.php';

// Data test tetap (email brief: test+auth@ft.local) — dibersihkan sebelum & sesudah.
$testEmail = 'test+auth@ft.local';
$testIp = '127.0.0.1';

function cleanupTestAuth(string $email): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user !== false) {
        // Cascade DB menghapus spaces/categories/remember_tokens milik user ini.
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
    }
    $pdo->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
}

// Mulai session di awal (sebelum output apapun) supaya session_start() di
// dalam ensureSession() tidak warning "headers already sent" gara-gara CLI
// sudah nge-echo hasil assertSame duluan.
ensureSession();

// Bersihkan sisa run sebelumnya (kalau ada) sebelum mulai.
cleanupTestAuth($testEmail);

// --- registerUser: buat user + space Pribadi + seed kategori ---------------

$userId = registerUser('Test Auth', $testEmail, 'password123');
assertSame(true, $userId > 0, 'registerUser mengembalikan id > 0');

$pdo = db();

$stmt = $pdo->prepare('SELECT id, name, type FROM spaces WHERE user_id = ?');
$stmt->execute([$userId]);
$spaces = $stmt->fetchAll();
assertSame(1, count($spaces), 'registerUser membuat tepat 1 space');
assertSame('Pribadi', $spaces[0]['name'] ?? null, 'space default bernama "Pribadi"');
assertSame('personal', $spaces[0]['type'] ?? null, 'space default type "personal"');

$spaceId = (int) $spaces[0]['id'];

$stmt = $pdo->prepare('SELECT COUNT(*) c FROM categories WHERE space_id = ?');
$stmt->execute([$spaceId]);
$catCount = (int) $stmt->fetch()['c'];
assertSame(true, $catCount >= 14, "seed kategori >= 14 (dapat: {$catCount})");

$stmt = $pdo->prepare("SELECT COUNT(*) c FROM categories WHERE space_id = ? AND icon IS NOT NULL AND icon != '' AND color IS NOT NULL AND color != ''");
$stmt->execute([$spaceId]);
$withIconColor = (int) $stmt->fetch()['c'];
assertSame($catCount, $withIconColor, 'semua kategori seed punya icon & color');

// --- currentSpaceId: fallback ke space pertama milik user -------------------

$_SESSION['user_id'] = $userId;
unset($_SESSION['space_id']);
assertSame($spaceId, currentSpaceId(), 'currentSpaceId fallback ke space pertama saat belum diset');

$_SESSION['space_id'] = $spaceId;
assertSame($spaceId, currentSpaceId(), 'currentSpaceId pakai session kalau valid milik user');

// space_id di session milik user lain -> harus fallback, bukan dipakai mentah-mentah.
$_SESSION['space_id'] = 999999;
assertSame($spaceId, currentSpaceId(), 'currentSpaceId fallback kalau space_id session tidak valid/bukan milik user');

unset($_SESSION['user_id'], $_SESSION['space_id']);

// --- attemptLogin: benar & salah --------------------------------------------

$_SERVER['REMOTE_ADDR'] = $testIp;

// Bersihkan login_attempts dulu supaya percobaan berikut tidak kena rate limit
// dari test sebelumnya di run yang sama.
$pdo->prepare('DELETE FROM login_attempts WHERE email = ? AND ip = ?')->execute([$testEmail, $testIp]);

assertSame(true, attemptLogin($testEmail, 'password123'), 'attemptLogin sukses dengan password benar');
assertSame(false, attemptLogin($testEmail, 'passwordsalah'), 'attemptLogin gagal dengan password salah');
assertSame(false, attemptLogin('tidakada@ft.local', 'apapun'), 'attemptLogin gagal untuk email tidak terdaftar');

// --- rate limit: percobaan ke-6 ditolak --------------------------------------

// Reset lagi supaya hitungan bersih: butuh tepat 5 gagal sebelum percobaan ke-6.
$pdo->prepare('DELETE FROM login_attempts WHERE email = ? AND ip = ?')->execute([$testEmail, $testIp]);

for ($i = 1; $i <= 5; $i++) {
    $ok = attemptLogin($testEmail, 'passwordsalah');
    assertSame(false, $ok, "attemptLogin gagal ke-{$i} (belum kena limit)");
}

// Percobaan ke-6 memanggil apiErr() yang exit() proses — harus dijalankan di
// subprocess terpisah supaya proses test utama tetap hidup untuk cleanup.
$code = 'require ' . var_export(__DIR__ . '/../core/auth.php', true) . ';'
    . '$_SERVER["REMOTE_ADDR"] = ' . var_export($testIp, true) . ';'
    . 'attemptLogin(' . var_export($testEmail, true) . ', "passwordsalah");';
$output = shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($code));
$json = json_decode((string) $output, true);
assertSame(false, $json['ok'] ?? null, 'percobaan ke-6 -> ok:false (rate limit)');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'banyak percobaan'), 'percobaan ke-6 -> pesan rate limit');

// --- cleanup ------------------------------------------------------------------

cleanupTestAuth($testEmail);
$pdo->prepare('DELETE FROM login_attempts WHERE email = ?')->execute(['tidakada@ft.local']);

$stmt = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE email = ?');
$stmt->execute([$testEmail]);
assertSame(0, (int) $stmt->fetch()['c'], 'cleanup: user test terhapus');
