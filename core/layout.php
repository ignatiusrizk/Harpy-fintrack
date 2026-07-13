<?php
// Shell UI bersama: header app (judul + switcher ruang) & bottom nav.
// Pemakaian di halaman terproteksi:
//   $user = requireLogin();
//   pageHeader('Judul', $user);
//   ... konten ...
//   pageFooter('kunci-nav-aktif');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

/**
 * Cetak <head> + buka <body> + header app (judul, switcher ruang) + buka <main>.
 * $user: row user (id, name, email) dari requireLogin().
 * Halaman dinamis: no-store sudah dikirim oleh requireLogin(), tidak diulang di sini.
 */
function pageHeader(string $title, array $user): void
{
    $csrf = csrf_token();
    $activeSpaceId = currentSpaceId();

    $stmt = db()->prepare('SELECT id, name, type FROM spaces WHERE user_id = ? ORDER BY id ASC');
    $stmt->execute([$user['id']]);
    $spaces = $stmt->fetchAll();

    $activeName = '-';
    foreach ($spaces as $s) {
        if ((int) $s['id'] === $activeSpaceId) {
            $activeName = $s['name'];
            break;
        }
    }
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= e($csrf) ?>">
<title><?= e($title) ?> — Harpy FinTrack</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="app-header">
  <h1 class="app-title"><?= e($title) ?></h1>
  <div class="space-switcher">
    <button type="button" class="space-btn" id="spaceBtn">
      <span><?= e($activeName) ?></span>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
    </button>
    <div class="space-menu" id="spaceMenu" hidden>
<?php foreach ($spaces as $s): ?>
<?php $isActive = (int) $s['id'] === $activeSpaceId; ?>
      <button type="button" class="space-item<?= $isActive ? ' active' : '' ?>" data-id="<?= (int) $s['id'] ?>"<?= $isActive ? ' disabled' : '' ?>>
        <span><?= e($s['name']) ?></span>
        <span class="space-badge"><?= $s['type'] === 'business' ? 'Usaha' : 'Pribadi' ?></span>
      </button>
<?php endforeach; ?>
      <a href="pengaturan.php" class="space-item space-item-add">+ Tambah ruang</a>
    </div>
  </div>
</header>
<main class="app-main">
<?php
}

/**
 * Tutup </main> + cetak bottom nav (5 item) + muat dialog.js/app.js + tutup body/html.
 * $active: kunci item aktif -- 'dashboard' | 'transaksi' | 'budget' | 'lainnya' (kosongkan
 * kalau tidak ada yang perlu disorot, mis. halaman turunan seperti akun.php).
 */
function pageFooter(string $active): void
{
    ?>
</main>
<nav class="bottom-nav">
  <a href="index.php" class="nav-item<?= $active === 'dashboard' ? ' active' : '' ?>">
    <span class="nav-icon">🏠</span>
    <span class="nav-label">Dashboard</span>
  </a>
  <a href="transaksi.php" class="nav-item<?= $active === 'transaksi' ? ' active' : '' ?>">
    <span class="nav-icon">📒</span>
    <span class="nav-label">Transaksi</span>
  </a>
  <a href="transaksi.php?add=1" class="nav-add" aria-label="Tambah transaksi">+</a>
  <a href="budget.php" class="nav-item<?= $active === 'budget' ? ' active' : '' ?>">
    <span class="nav-icon">🎯</span>
    <span class="nav-label">Budget</span>
  </a>
  <a href="lainnya.php" class="nav-item<?= $active === 'lainnya' ? ' active' : '' ?>">
    <span class="nav-icon">☰</span>
    <span class="nav-label">Lainnya</span>
  </a>
</nav>
<script src="assets/dialog.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
<?php
}
