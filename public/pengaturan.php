<?php
// Halaman Pengaturan: seksi Profil (nama/email), Keamanan (ganti password),
// Ruang (list + tambah/ganti nama/hapus), catatan versi. Diakses dari menu
// Lainnya -- $active tetap 'lainnya'. Aksi tambah/rename/delete ruang
// me-reload halaman setelah sukses (data ruang & switcher header jadi
// konsisten dgn state server yg baru).

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/pengaturan.php';
require_once __DIR__ . '/../core/layout.php';

$user = requireLogin();
$spaces = spaceList((int) $user['id']);

pageHeader('Pengaturan', $user);
?>
<section class="card">
  <h2 class="pgt-section-title">Profil</h2>
  <form id="profileForm">
    <label class="field">
      <span>Nama</span>
      <input type="text" id="pfName" maxlength="100" value="<?= e($user['name']) ?>" required>
    </label>
    <label class="field">
      <span>Email</span>
      <input type="text" id="pfEmail" value="<?= e($user['email']) ?>" readonly disabled>
    </label>
    <p class="sheet-msg" id="pfMsg"></p>
    <div class="sheet-actions">
      <button type="submit" class="btn-primary" id="pfSubmit">Simpan</button>
    </div>
  </form>
</section>

<section class="card">
  <h2 class="pgt-section-title">Keamanan</h2>
  <form id="pwForm">
    <label class="field">
      <span>Password Lama</span>
      <input type="password" id="pwOld" autocomplete="current-password" required>
    </label>
    <label class="field">
      <span>Password Baru</span>
      <input type="password" id="pwNew" minlength="8" autocomplete="new-password" required>
    </label>
    <label class="field">
      <span>Konfirmasi Password Baru</span>
      <input type="password" id="pwConfirm" minlength="8" autocomplete="new-password" required>
    </label>
    <p class="sheet-msg" id="pwMsg"></p>
    <div class="sheet-actions">
      <button type="submit" class="btn-primary" id="pwSubmit">Ganti Password</button>
    </div>
  </form>
</section>

<section class="card">
  <div class="pgt-section-head">
    <h2 class="pgt-section-title">Ruang</h2>
    <button type="button" class="pgt-add-space" id="spaceAddBtn">+ Tambah</button>
  </div>
  <div class="pgt-space-list" id="spaceList">
<?php foreach ($spaces as $s): ?>
    <div class="pgt-space-row" data-id="<?= (int) $s['id'] ?>" data-name="<?= e($s['name']) ?>">
      <div class="pgt-space-info">
        <span class="pgt-space-name"><?= e($s['name']) ?><span class="space-badge"><?= $s['type'] === 'business' ? 'Usaha' : 'Pribadi' ?></span></span>
        <span class="pgt-space-meta"><?= (int) $s['account_count'] ?> akun, <?= (int) $s['tx_count'] ?> transaksi</span>
      </div>
      <div class="pgt-space-actions">
        <button type="button" class="pgt-space-btn" data-act="rename">Ganti nama</button>
        <button type="button" class="pgt-space-btn pgt-space-btn-danger" data-act="delete">Hapus</button>
      </div>
    </div>
<?php endforeach; ?>
  </div>
</section>

<p class="pgt-version">Harpy FinTrack v1.0</p>

<div class="sheet-overlay" id="spaceSheetOverlay" hidden>
  <div class="sheet">
    <h2 class="sheet-title">Tambah Ruang</h2>
    <form id="spaceForm">
      <label class="field">
        <span>Nama Ruang</span>
        <input type="text" id="spName" maxlength="50" placeholder="mis. Laundry Kiloan" required>
      </label>
      <label class="field">
        <span>Jenis</span>
        <div class="segmented" id="spTypeSeg">
          <button type="button" class="segmented-btn active" data-type="personal">Pribadi</button>
          <button type="button" class="segmented-btn" data-type="business">Usaha</button>
        </div>
      </label>
      <p class="sheet-msg" id="spMsg"></p>
      <div class="sheet-actions">
        <button type="button" class="btn-secondary" id="spCancel">Batal</button>
        <button type="submit" class="btn-primary" id="spSubmit">Simpan</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  'use strict';

  // ---------- Profil ----------

  var profileForm = document.getElementById('profileForm');
  var pfMsg = document.getElementById('pfMsg');
  var pfSubmit = document.getElementById('pfSubmit');

  profileForm.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    pfMsg.textContent = '';
    pfSubmit.disabled = true;
    try {
      await api('api/pengaturan.php?a=profile', { name: document.getElementById('pfName').value.trim() });
      toast('Profil tersimpan');
    } catch (err) {
      pfMsg.textContent = err.message;
    } finally {
      pfSubmit.disabled = false;
    }
  });

  // ---------- Keamanan (ganti password) ----------

  var pwForm = document.getElementById('pwForm');
  var pwMsg = document.getElementById('pwMsg');
  var pwSubmit = document.getElementById('pwSubmit');

  pwForm.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    pwMsg.textContent = '';

    var oldPw = document.getElementById('pwOld').value;
    var newPw = document.getElementById('pwNew').value;
    var confirmPw = document.getElementById('pwConfirm').value;

    if (newPw.length < 8) {
      pwMsg.textContent = 'Password baru minimal 8 karakter';
      return;
    }
    if (newPw !== confirmPw) {
      pwMsg.textContent = 'Konfirmasi password baru tidak cocok';
      return;
    }

    pwSubmit.disabled = true;
    try {
      await api('api/pengaturan.php?a=password', { old_password: oldPw, new_password: newPw });
      pwForm.reset();
      toast('Password berhasil diganti');
    } catch (err) {
      pwMsg.textContent = err.message;
    } finally {
      pwSubmit.disabled = false;
    }
  });

  // ---------- Ruang: tambah ----------

  var spaceListEl = document.getElementById('spaceList');
  var spaceOverlay = document.getElementById('spaceSheetOverlay');
  var spaceForm = document.getElementById('spaceForm');
  var spName = document.getElementById('spName');
  var spTypeSeg = document.getElementById('spTypeSeg');
  var spMsg = document.getElementById('spMsg');
  var spSubmit = document.getElementById('spSubmit');

  function openSpaceSheet() {
    spMsg.textContent = '';
    spaceForm.reset();
    Array.prototype.forEach.call(spTypeSeg.children, function (btn) {
      btn.classList.toggle('active', btn.dataset.type === 'personal');
    });
    spaceOverlay.hidden = false;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { spaceOverlay.classList.add('show'); });
    });
    setTimeout(function () { spName.focus(); }, 200);
  }

  function closeSpaceSheet() {
    spaceOverlay.classList.remove('show');
    setTimeout(function () { spaceOverlay.hidden = true; }, 180);
  }

  document.getElementById('spaceAddBtn').addEventListener('click', openSpaceSheet);
  document.getElementById('spCancel').addEventListener('click', closeSpaceSheet);
  spaceOverlay.addEventListener('click', function (ev) {
    if (ev.target === spaceOverlay) closeSpaceSheet();
  });

  spTypeSeg.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.segmented-btn');
    if (!btn) return;
    Array.prototype.forEach.call(spTypeSeg.children, function (b) {
      b.classList.toggle('active', b === btn);
    });
  });

  spaceForm.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    spMsg.textContent = '';
    spSubmit.disabled = true;

    var type = spTypeSeg.querySelector('.segmented-btn.active').dataset.type;

    try {
      await api('api/pengaturan.php?a=space_create', { name: spName.value.trim(), type: type });
      toast('Ruang baru dibuat');
      setTimeout(function () { window.location.reload(); }, 500);
    } catch (err) {
      spMsg.textContent = err.message;
      spSubmit.disabled = false;
    }
  });

  // ---------- Ruang: ganti nama / hapus ----------

  spaceListEl.addEventListener('click', async function (ev) {
    var btn = ev.target.closest('.pgt-space-btn');
    if (!btn) return;
    var row = btn.closest('.pgt-space-row');
    var id = row.dataset.id;
    var name = row.dataset.name;
    var act = btn.dataset.act;

    if (act === 'rename') {
      var newName = await lmPrompt('Nama baru untuk ruang "' + name + '":', name);
      if (newName === null) return;
      newName = newName.trim();
      if (newName === '') {
        toast('Nama tidak boleh kosong');
        return;
      }
      try {
        await api('api/pengaturan.php?a=space_rename', { id: id, name: newName });
        toast('Nama ruang diganti');
        setTimeout(function () { window.location.reload(); }, 500);
      } catch (err) {
        toast(err.message);
      }
      return;
    }

    if (act === 'delete') {
      var sure = await lmConfirm(
        'Menghapus ruang "' + name + '" akan menghapus SEMUA data di dalamnya (akun, transaksi, kategori, ' +
        'budget, recurring, goals, investasi) secara permanen dan tidak bisa dibatalkan. Lanjutkan?',
        'Hapus Ruang'
      );
      if (!sure) return;

      var typed = await lmPrompt('Ketik ulang nama ruang "' + name + '" untuk konfirmasi:', '');
      if (typed === null) return;
      if (typed.trim() !== name) {
        toast('Nama tidak cocok, penghapusan dibatalkan');
        return;
      }

      try {
        await api('api/pengaturan.php?a=space_delete', { id: id });
        toast('Ruang dihapus');
        setTimeout(function () { window.location.reload(); }, 500);
      } catch (err) {
        toast(err.message);
      }
    }
  });
})();
</script>
<?php
pageFooter('lainnya');
