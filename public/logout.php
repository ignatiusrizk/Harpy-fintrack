<?php
// Logout = mutasi state, jadi tidak boleh jalan dari GET polos (CSRF: situs
// lain bisa memaksa logout via <img src>/link). Diterima kalau:
//   - POST dengan token CSRF valid (field `csrf` atau header X-CSRF-Token), atau
//   - GET dengan ?t=<token> valid (untuk link logout di menu -- Task 3 tinggal
//     append csrf_token() ke href).
// GET polos/token salah -> tampilkan halaman konfirmasi kecil (tombol POST).

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/seed.php';
require_once __DIR__ . '/../core/auth.php';

ensureSession();
header('Cache-Control: no-store');

// Belum login sama sekali -> tidak ada yang perlu di-logout.
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$sessionToken = $_SESSION['csrf'] ?? '';
$given = post('csrf') ?? get('t') ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

if ($sessionToken !== '' && $given !== '' && hash_equals($sessionToken, (string) $given)) {
    logoutUser();
    header('Location: /login.php');
    exit;
}

// Token tidak ada/tidak cocok -> konfirmasi manual.
$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Keluar — Harpy FinTrack</title>
<style>
  * { box-sizing: border-box; }
  body {
    margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #F4F6F7; color: #1B2528; min-height: 100vh;
    display: flex; align-items: center; justify-content: center; padding: 24px;
  }
  .card {
    width: 100%; max-width: 380px; background: #fff; border-radius: 16px;
    padding: 28px 24px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); text-align: center;
  }
  h1 { font-size: 18px; margin: 0 0 8px; }
  p { margin: 0 0 20px; color: #667; font-size: 14px; }
  button {
    width: 100%; padding: 13px; border: none; border-radius: 10px;
    background: #E11D48; color: #fff; font-size: 15px; font-weight: 600; cursor: pointer;
  }
  a.batal { display: inline-block; margin-top: 14px; color: #1FC0CB; font-size: 14px; font-weight: 600; text-decoration: none; }
</style>
</head>
<body>
  <div class="card">
    <h1>Keluar dari akun?</h1>
    <p>Sesi Anda di perangkat ini akan diakhiri.</p>
    <form method="post" action="logout.php">
      <input type="hidden" name="csrf" value="<?= e($token) ?>">
      <button type="submit">Ya, keluar</button>
    </form>
    <a class="batal" href="index.php">Batal</a>
  </div>
</body>
</html>
