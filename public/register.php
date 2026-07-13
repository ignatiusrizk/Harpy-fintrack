<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';

ensureSession();
header('Cache-Control: no-store');

if (isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= e($token) ?>">
<title>Daftar — Harpy FinTrack</title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body {
    margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #F4F6F7; color: #1B2528; min-height: 100vh;
    display: flex; align-items: center; justify-content: center; padding: 24px;
  }
  .card {
    width: 100%; max-width: 380px; background: #fff; border-radius: 16px;
    padding: 28px 24px; box-shadow: 0 2px 12px rgba(0,0,0,0.06);
  }
  h1 { font-size: 20px; margin: 0 0 4px; color: #1FC0CB; }
  p.sub { margin: 0 0 20px; color: #667; font-size: 14px; }
  label { display: block; font-size: 13px; margin-bottom: 14px; font-weight: 600; color: #445; }
  input[type=text], input[type=email], input[type=password] {
    display: block; width: 100%; margin-top: 6px; padding: 12px 14px;
    border: 1px solid #D8DEE0; border-radius: 10px; font-size: 15px; font-weight: 400;
  }
  input:focus { outline: none; border-color: #1FC0CB; }
  small.hint { display: block; margin-top: 4px; font-weight: 400; color: #889; font-size: 12px; }
  button {
    width: 100%; padding: 13px; border: none; border-radius: 10px;
    background: #1FC0CB; color: #fff; font-size: 15px; font-weight: 600;
    cursor: pointer; margin-top: 6px;
  }
  button:disabled { opacity: 0.6; }
  .msg { min-height: 20px; color: #E11D48; font-size: 13px; margin: 12px 0 0; }
  .foot { margin-top: 18px; font-size: 14px; text-align: center; color: #556; }
  .foot a { color: #1FC0CB; font-weight: 600; text-decoration: none; }
</style>
</head>
<body>
  <div class="card">
    <h1>Harpy FinTrack</h1>
    <p class="sub">Buat akun baru — gratis</p>
    <form id="registerForm" novalidate>
      <label>Nama
        <input type="text" name="name" autocomplete="name" required>
      </label>
      <label>Email
        <input type="email" name="email" autocomplete="email" required>
      </label>
      <label>Kata Sandi
        <input type="password" name="password" autocomplete="new-password" minlength="8" required>
        <small class="hint">Minimal 8 karakter</small>
      </label>
      <label>Ulangi Kata Sandi
        <input type="password" name="password2" autocomplete="new-password" minlength="8" required>
      </label>
      <button type="submit" id="submitBtn">Daftar</button>
      <p class="msg" id="msg"></p>
    </form>
    <p class="foot">Sudah punya akun? <a href="login.php">Masuk</a></p>
  </div>

<script>
document.getElementById('registerForm').addEventListener('submit', async function (ev) {
  ev.preventDefault();
  var msg = document.getElementById('msg');
  var btn = document.getElementById('submitBtn');
  msg.textContent = '';

  var fd = new FormData(ev.target);
  if (fd.get('password') !== fd.get('password2')) {
    msg.textContent = 'Kata sandi tidak sama';
    return;
  }
  fd.delete('password2');

  btn.disabled = true;
  try {
    var res = await fetch('api/auth.php?a=register', {
      method: 'POST',
      headers: { 'X-CSRF-Token': document.querySelector('meta[name=csrf]').content },
      body: fd,
    });
    var json = await res.json();
    if (!json.ok) throw new Error(json.error || 'Gagal daftar');
    location.href = 'index.php';
  } catch (err) {
    msg.textContent = err.message;
    btn.disabled = false;
  }
});
</script>
</body>
</html>
