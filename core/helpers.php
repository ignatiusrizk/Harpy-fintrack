<?php
// Helper umum: format rupiah, response API, input, escaping.

/**
 * Format nominal ke format Rupiah Indonesia. Dibulatkan ke rupiah penuh.
 * Nilai negatif -> tanda minus di depan "Rp" (mis. -5000 -> "-Rp 5.000").
 */
function rupiah(float|string $n): string
{
    $n = (float) $n;
    $negatif = $n < 0;
    $bulat = (int) round(abs($n));
    $formatted = number_format($bulat, 0, ',', '.');
    return ($negatif ? '-' : '') . 'Rp ' . $formatted;
}

/**
 * Kirim response JSON sukses lalu hentikan eksekusi.
 */
function apiOk(array $data = []): never
{
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

/**
 * Kirim response JSON error lalu hentikan eksekusi.
 * Throwable: log detail ke error_log, klien hanya dapat pesan generik (http default 500).
 * string: dipakai langsung sebagai pesan error ke klien (http default 400).
 */
function apiErr(Throwable|string $e, ?int $http = null): never
{
    if (is_string($e)) {
        $http ??= 400;
        $error = $e;
    } else {
        error_log((string) $e);
        $http ??= 500;
        $error = 'Terjadi kesalahan';
    }

    http_response_code($http);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

/**
 * Body JSON request (dipakai window.api() JS -- selalu POST JSON), didekode
 * & di-cache sekali per request. Content-Type bukan application/json, atau
 * body bukan JSON object valid -> array kosong (fallback ke $_POST biasa).
 */
function jsonBody(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') === false) {
        $data = [];
        return $data;
    }
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    $data = is_array($decoded) ? $decoded : [];
    return $data;
}

/**
 * Ambil nilai dari body JSON (window.api()) kalau ada, fallback ke $_POST
 * (form-urlencoded/multipart, mis. register.php/login.php yang submit
 * FormData langsung). Trim kalau string.
 */
function post(string $k, $default = null)
{
    $json = jsonBody();
    if (array_key_exists($k, $json)) {
        $v = $json[$k];
        return is_string($v) ? trim($v) : $v;
    }
    if (!isset($_POST[$k])) {
        return $default;
    }
    $v = $_POST[$k];
    return is_string($v) ? trim($v) : $v;
}

/**
 * Ambil nilai dari $_GET, trim kalau string.
 */
function get(string $k, $default = null)
{
    if (!isset($_GET[$k])) {
        return $default;
    }
    $v = $_GET[$k];
    return is_string($v) ? trim($v) : $v;
}

/**
 * Escape output HTML.
 */
function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * Pastikan session PHP aktif, dengan cookie httponly+samesite Lax.
 * Idempoten — aman dipanggil berkali-kali dari file manapun.
 */
function ensureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Ambil (atau buat) token CSRF tersimpan di session.
 */
function csrf_token(): string
{
    ensureSession();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/**
 * Validasi header X-CSRF-Token terhadap token session. Mismatch -> apiErr 419
 * (menghentikan eksekusi). Wajib dipanggil di awal setiap endpoint API POST.
 */
function csrf_check(): void
{
    ensureSession();
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $session = $_SESSION['csrf'] ?? '';
    if ($session === '' || $header === '' || !hash_equals($session, $header)) {
        apiErr('Token CSRF tidak valid, muat ulang halaman', 419);
    }
}

/**
 * Validasi kepemilikan ruang: space harus milik user_id di session. Tidak
 * ditemukan/bukan milik user -> apiErr 404 (menghentikan eksekusi). Return row
 * space (id, user_id, name, type, created_at) kalau valid.
 */
function ownSpace(int $spaceId): array
{
    ensureSession();
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = db()->prepare('SELECT * FROM spaces WHERE id = ? AND user_id = ?');
    $stmt->execute([$spaceId, $userId]);
    $row = $stmt->fetch();
    if ($row === false) {
        apiErr('Tidak ditemukan', 404);
    }
    return $row;
}

/**
 * Validasi kepemilikan akun: rantai akun -> ruang -> user_id di session.
 * Tidak ditemukan/bukan milik user -> apiErr 404 (menghentikan eksekusi).
 * Return row akun (id, space_id, name, type, initial_balance, is_archived)
 * kalau valid.
 */
function ownAccount(int $accountId): array
{
    ensureSession();
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = db()->prepare(
        'SELECT a.* FROM accounts a JOIN spaces s ON s.id = a.space_id WHERE a.id = ? AND s.user_id = ?'
    );
    $stmt->execute([$accountId, $userId]);
    $row = $stmt->fetch();
    if ($row === false) {
        apiErr('Tidak ditemukan', 404);
    }
    return $row;
}

/**
 * Validasi kepemilikan kategori: rantai kategori -> ruang -> user_id di
 * session. Tidak ditemukan/bukan milik user -> apiErr 404 (menghentikan
 * eksekusi). Return row kategori (id, space_id, name, type, icon, color,
 * parent_id) kalau valid.
 */
function ownCategory(int $categoryId): array
{
    ensureSession();
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = db()->prepare(
        'SELECT c.* FROM categories c JOIN spaces s ON s.id = c.space_id WHERE c.id = ? AND s.user_id = ?'
    );
    $stmt->execute([$categoryId, $userId]);
    $row = $stmt->fetch();
    if ($row === false) {
        apiErr('Tidak ditemukan', 404);
    }
    return $row;
}

/**
 * Validasi kepemilikan transaksi: rantai transaksi -> ruang -> user_id di
 * session. Tidak ditemukan/bukan milik user -> apiErr 404 (menghentikan
 * eksekusi). Return row transaksi lengkap kalau valid.
 */
function ownTransaction(int $transactionId): array
{
    ensureSession();
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = db()->prepare(
        'SELECT t.* FROM transactions t JOIN spaces s ON s.id = t.space_id WHERE t.id = ? AND s.user_id = ?'
    );
    $stmt->execute([$transactionId, $userId]);
    $row = $stmt->fetch();
    if ($row === false) {
        apiErr('Tidak ditemukan', 404);
    }
    return $row;
}

/**
 * Validasi kepemilikan recurring: rantai recurring -> ruang -> user_id di
 * session. Tidak ditemukan/bukan milik user -> apiErr 404 (menghentikan
 * eksekusi). Return row recurring lengkap kalau valid.
 */
function ownRecurring(int $recurringId): array
{
    ensureSession();
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = db()->prepare(
        'SELECT r.* FROM recurrings r JOIN spaces s ON s.id = r.space_id WHERE r.id = ? AND s.user_id = ?'
    );
    $stmt->execute([$recurringId, $userId]);
    $row = $stmt->fetch();
    if ($row === false) {
        apiErr('Tidak ditemukan', 404);
    }
    return $row;
}

/**
 * Validasi kepemilikan goal: rantai goal -> ruang -> user_id di session.
 * Tidak ditemukan/bukan milik user -> apiErr 404 (menghentikan eksekusi).
 * Return row goal (id, space_id, name, target_amount, target_date, is_done)
 * kalau valid.
 */
function ownGoal(int $goalId): array
{
    ensureSession();
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = db()->prepare(
        'SELECT g.* FROM goals g JOIN spaces s ON s.id = g.space_id WHERE g.id = ? AND s.user_id = ?'
    );
    $stmt->execute([$goalId, $userId]);
    $row = $stmt->fetch();
    if ($row === false) {
        apiErr('Tidak ditemukan', 404);
    }
    return $row;
}
