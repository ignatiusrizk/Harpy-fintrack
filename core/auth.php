<?php
// Auth: registrasi, login (+ rate-limit & remember-me), session, ruang aktif.
// user_id/space_id aktif SELALU berasal dari session — tidak pernah dari input klien.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/seed.php';
// requireLogin() di bawah manggil runRecurringForUser() (pseudo-cron) di
// SETIAP halaman terproteksi -- bukan cuma public/recurring.php. Kalau
// core/recurring.php cuma di-require_once oleh halaman itu sendiri, hook di
// requireLogin() tidak akan pernah nyala di halaman lain (mis. index.php,
// transaksi.php) krn function_exists('runRecurringForUser') masih false di
// sana. Require langsung di sini supaya fungsinya SELALU tersedia di semua
// halaman yg memanggil requireLogin() (auth.php di-require di semua halaman).
require_once __DIR__ . '/recurring.php';

const FT_REMEMBER_COOKIE = 'ft_remember';
const FT_REMEMBER_DAYS = 30;
const FT_RATE_LIMIT_MAX = 5;
const FT_RATE_LIMIT_MINUTES = 15;

/**
 * Daftarkan user baru + buat space "Pribadi" default berisi kategori seed.
 * Return id user baru. Email sudah dipakai -> apiErr 422 (menghentikan eksekusi).
 */
function registerUser(string $name, string $email, string $pass): int
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch() !== false) {
        apiErr('Email sudah terdaftar', 422);
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);

    // Satu transaksi untuk user + space + kategori seed: kalau salah satu
    // gagal, semuanya batal -- jangan sampai ada user tanpa space (akun rusak
    // permanen, currentSpaceId() akan selalu error).
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
        $stmt->execute([$name, $email, $hash]);
        $userId = (int) $pdo->lastInsertId();

        createSpaceWithDefaults($userId, 'Pribadi', 'personal');

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $userId;
}

/**
 * Verifikasi kredensial email+password. Cek rate limit dulu (maks
 * FT_RATE_LIMIT_MAX gagal per FT_RATE_LIMIT_MINUTES menit, per email+ip) --
 * kena limit -> apiErr 429 (menghentikan eksekusi). Percobaan gagal dicatat ke
 * login_attempts; percobaan sukses tidak dicatat. Tidak menyentuh $_SESSION --
 * pemanggil (api/auth.php) yang menjalankan logInAs() setelah true.
 */
function attemptLogin(string $email, string $pass): bool
{
    $pdo = db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $since = date('Y-m-d H:i:s', time() - FT_RATE_LIMIT_MINUTES * 60);
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) c FROM login_attempts WHERE email = ? AND ip = ? AND attempted_at >= ?'
    );
    $stmt->execute([$email, $ip, $since]);
    $count = (int) $stmt->fetch()['c'];
    if ($count >= FT_RATE_LIMIT_MAX) {
        apiErr('Terlalu banyak percobaan, coba lagi dalam ' . FT_RATE_LIMIT_MINUTES . ' menit', 429);
    }

    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user === false || !password_verify($pass, $user['password_hash'])) {
        $pdo->prepare('INSERT INTO login_attempts (email, ip) VALUES (?, ?)')->execute([$email, $ip]);
        return false;
    }

    return true;
}

/**
 * Tandai user sebagai login di session (dipanggil setelah attemptLogin() true,
 * atau setelah registrasi, atau lewat auto-login remember-me). Regenerasi
 * session id (mitigasi session fixation) & reset space_id (fallback dihitung
 * ulang lewat currentSpaceId()).
 */
function logInAs(int $userId): void
{
    ensureSession();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    unset($_SESSION['space_id']);
}

/**
 * Hapus session + remember-me token & cookie. Dipakai oleh logout.php dan
 * action logout di api/auth.php.
 */
function logoutUser(): void
{
    ensureSession();
    clearRememberCookie();
    $_SESSION = [];
    session_destroy();
}

/**
 * Simpan remember-me token baru (selector:validator, 30 hari) untuk user, dan
 * set cookie ft_remember. validator disimpan ter-hash (sha256) di DB.
 */
function setRememberCookie(int $userId): void
{
    $selector = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + FT_REMEMBER_DAYS * 86400;

    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $selector, hash('sha256', $validator), date('Y-m-d H:i:s', $expires)]);

    setcookie(FT_REMEMBER_COOKIE, $selector . ':' . $validator, [
        'expires' => $expires,
        'path' => '/',
        'secure' => isHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Hapus token remember-me (DB + cookie) milik cookie saat ini, kalau ada.
 */
function clearRememberCookie(): void
{
    if (!isset($_COOKIE[FT_REMEMBER_COOKIE])) {
        return;
    }
    $parts = explode(':', $_COOKIE[FT_REMEMBER_COOKIE], 2);
    $selector = $parts[0] ?? '';
    if ($selector !== '') {
        db()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
    }
    setcookie(FT_REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    unset($_COOKIE[FT_REMEMBER_COOKIE]);
}

/**
 * Coba auto-login dari cookie ft_remember. Valid -> rotasi token (hapus lama,
 * buat baru) & return user_id. Tidak ada/invalid/kedaluwarsa -> null.
 */
function tryRememberLogin(): ?int
{
    if (!isset($_COOKIE[FT_REMEMBER_COOKIE])) {
        return null;
    }
    $parts = explode(':', $_COOKIE[FT_REMEMBER_COOKIE], 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$selector, $validator] = $parts;

    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT id, user_id, validator_hash, expires_at FROM remember_tokens WHERE selector = ?'
    );
    $stmt->execute([$selector]);
    $token = $stmt->fetch();
    if ($token === false) {
        return null;
    }

    // Bandingkan expires_at (ditulis via PHP date()) dengan waktu PHP juga --
    // jangan campur dengan NOW() MySQL.
    if ($token['expires_at'] < date('Y-m-d H:i:s')) {
        $pdo->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$token['id']]);
        return null;
    }

    if (!hash_equals($token['validator_hash'], hash('sha256', $validator))) {
        // Validator tidak cocok -- kemungkinan token dicuri, hapus demi keamanan.
        $pdo->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$token['id']]);
        return null;
    }

    $pdo->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$token['id']]);
    $userId = (int) $token['user_id'];
    setRememberCookie($userId);

    return $userId;
}

/**
 * Resolusi user yang sedang login: dari session, atau auto-login lewat cookie
 * remember-me. Return null kalau tidak ada sesi valid.
 */
function resolveLoggedInUser(): ?array
{
    ensureSession();

    if (!isset($_SESSION['user_id'])) {
        $userId = tryRememberLogin();
        if ($userId !== null) {
            logInAs($userId);
        }
    }

    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, name, email FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if ($user === false) {
        // User terhapus tapi session masih menyimpan id lama -- paksa logout.
        session_destroy();
        return null;
    }

    return $user;
}

// Throttle pseudo-cron recurring: maks 1x per sekian detik PER SESI (bukan
// per user/global) -- cukup utk menghindari query berulang tiap kali user
// reload halaman berkali-kali dalam waktu singkat. runRecurringForUser()
// sendiri sudah scoped & idempoten (lihat core/recurring.php), throttle ini
// murni soal biaya query per page load, bukan soal korektnes.
const FT_RECURRING_THROTTLE_SECONDS = 15 * 60;

/**
 * Guard halaman: return user row kalau login (termasuk auto-login via
 * remember-me), redirect ke login.php kalau tidak. Jalankan pseudo-cron
 * recurring (runRecurringForUser() dari core/recurring.php, di-require
 * langsung di atas jadi SELALU tersedia) di sini -- HANYA di halaman, bukan
 * API (requireLoginApi() di bawah SENGAJA tidak memanggil ini, supaya
 * request AJAX beruntun tidak ikut trigger cron berulang-ulang). Throttle
 * maks 1x/15 menit per sesi via $_SESSION['last_recurring_run'].
 * function_exists() dipertahankan sbg guard defensif (bukan krn fungsinya
 * pernah tidak ada di jalur normal).
 */
function requireLogin(): array
{
    $user = resolveLoggedInUser();
    if ($user === null) {
        header('Location: /login.php');
        exit;
    }

    header('Cache-Control: no-store');

    if (function_exists('runRecurringForUser')) {
        $now = time();
        $last = (int) ($_SESSION['last_recurring_run'] ?? 0);
        if ($now - $last >= FT_RECURRING_THROTTLE_SECONDS) {
            $_SESSION['last_recurring_run'] = $now;
            runRecurringForUser((int) $user['id']);
        }
    }

    return $user;
}

/**
 * Guard endpoint API: return user row kalau login, JSON 401 (apiErr) kalau
 * tidak -- menghentikan eksekusi. TIDAK menjalankan pseudo-cron recurring
 * (lihat requireLogin()) -- endpoint API dipanggil berkali-kali per halaman
 * (list akun, kategori, dst.), menjalankan cron di tiap panggilan itu boros
 * & tidak perlu.
 */
function requireLoginApi(): array
{
    $user = resolveLoggedInUser();
    if ($user === null) {
        apiErr('Belum login', 401);
    }

    header('Cache-Control: no-store');

    return $user;
}

/**
 * Id space aktif user saat ini: dari $_SESSION['space_id'] kalau valid milik
 * user, fallback ke space pertama (diurutkan id) milik user & simpan ke
 * session. Dipanggil setelah requireLogin()/requireLoginApi() (user_id sudah
 * ada di session).
 */
function currentSpaceId(): int
{
    ensureSession();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId === 0) {
        apiErr('Belum login', 401);
    }

    $pdo = db();

    $spaceId = $_SESSION['space_id'] ?? null;
    if ($spaceId !== null) {
        $stmt = $pdo->prepare('SELECT id FROM spaces WHERE id = ? AND user_id = ?');
        $stmt->execute([$spaceId, $userId]);
        if ($stmt->fetch() !== false) {
            return (int) $spaceId;
        }
    }

    $stmt = $pdo->prepare('SELECT id FROM spaces WHERE user_id = ? ORDER BY id ASC LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if ($row === false) {
        apiErr('Tidak ada ruang untuk user ini', 500);
    }

    $_SESSION['space_id'] = (int) $row['id'];
    return (int) $row['id'];
}
