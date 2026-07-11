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
 * Ambil nilai dari $_POST, trim kalau string.
 */
function post(string $k, $default = null)
{
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
