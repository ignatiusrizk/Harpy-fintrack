<?php
// Dashboard (placeholder). Saldo & ringkasan sungguhan menyusul di task lain
// (core/balance.php dst.) -- untuk sekarang sekadar sapaan + kartu kosong.

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';

$user = requireLogin();

pageHeader('Dashboard', $user);
?>
<section class="card">
  <p class="greet">Halo, <strong><?= e($user['name']) ?></strong> 👋</p>
  <p class="greet-sub">Selamat datang kembali di Harpy FinTrack.</p>
</section>

<section class="card">
  <p class="balance-label">Saldo Total</p>
  <p class="balance-value"><?= e(rupiah(0)) ?></p>
  <p class="balance-note">Ringkasan akun &amp; saldo segera hadir.</p>
</section>
<?php
pageFooter('dashboard');
