<?php
// Menu "Lainnya": link ke halaman fitur yang belum ada (dibuat task berikutnya)
// + logout. Juga jadi tempat tombol tes dialog custom sementara (Task 3).

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';

$user = requireLogin();
$logoutToken = csrf_token();

pageHeader('Lainnya', $user);
?>
<section class="menu-grid">
  <a class="menu-item" href="akun.php"><span class="menu-icon">🏦</span><span>Akun</span></a>
  <a class="menu-item" href="kategori.php"><span class="menu-icon">🏷️</span><span>Kategori</span></a>
  <a class="menu-item" href="recurring.php"><span class="menu-icon">🔁</span><span>Recurring</span></a>
  <a class="menu-item" href="goals.php"><span class="menu-icon">🏆</span><span>Goals</span></a>
  <a class="menu-item" href="investasi.php"><span class="menu-icon">📈</span><span>Investasi</span></a>
  <a class="menu-item" href="laporan.php"><span class="menu-icon">📊</span><span>Laporan</span></a>
  <a class="menu-item" href="pengaturan.php"><span class="menu-icon">⚙️</span><span>Pengaturan</span></a>
  <a class="menu-item" href="logout.php?t=<?= e($logoutToken) ?>"><span class="menu-icon">🚪</span><span>Logout</span></a>
</section>

<section class="card">
  <p class="test-label">Tes dialog custom (sementara)</p>
  <div class="test-buttons">
    <button type="button" class="btn-secondary" id="testAlert">Alert</button>
    <button type="button" class="btn-secondary" id="testConfirm">Confirm</button>
    <button type="button" class="btn-secondary" id="testPrompt">Prompt</button>
  </div>
</section>

<script>
document.getElementById('testAlert').addEventListener('click', function () {
  lmAlert('Ini contoh alert custom, bukan alert() bawaan browser.', 'Info');
});
document.getElementById('testConfirm').addEventListener('click', async function () {
  var ok = await lmConfirm('Yakin lanjut dengan aksi ini?', 'Konfirmasi');
  toast(ok ? 'Dikonfirmasi' : 'Dibatalkan');
});
document.getElementById('testPrompt').addEventListener('click', async function () {
  var val = await lmPrompt('Masukkan nama Anda:', '');
  if (val !== null) toast('Anda mengetik: ' + val);
});
</script>
<?php
pageFooter('lainnya');
