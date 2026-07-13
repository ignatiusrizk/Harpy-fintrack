<?php
// Menu "Lainnya": link ke semua halaman fitur + logout.

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
<?php
pageFooter('lainnya');
