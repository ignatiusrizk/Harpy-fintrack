<?php
// Koneksi PDO singleton.

date_default_timezone_set('Asia/Jakarta');

/**
 * @return PDO
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $config = require __DIR__ . '/config.php';

    $dsn = 'mysql:dbname=' . $config['name'] . ';charset=utf8mb4';
    if ($config['host'] === 'localhost') {
        // Dev lokal: pakai unix socket kalau tersedia, urutan kandidat sesuai
        // instalasi Homebrew MariaDB umum di macOS.
        $socketCandidates = ['/tmp/mysql.sock', '/opt/homebrew/var/mysql/mysql.sock'];
        $socket = null;
        foreach ($socketCandidates as $candidate) {
            if (file_exists($candidate)) {
                $socket = $candidate;
                break;
            }
        }
        $dsn .= $socket !== null
            ? ';unix_socket=' . $socket
            : ';host=' . $config['host'];
    } else {
        $dsn .= ';host=' . $config['host'];
        if (!empty($config['port'])) {
            $dsn .= ';port=' . $config['port'];
        }
    }

    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Samakan zona waktu sesi MySQL dengan PHP (Asia/Jakarta) supaya NOW()/
    // CURRENT_TIMESTAMP di DB tidak selisih 7 jam dengan waktu yang ditulis PHP.
    $pdo->exec("SET time_zone = '+07:00'");

    return $pdo;
}
