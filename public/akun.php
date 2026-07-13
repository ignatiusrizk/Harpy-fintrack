<?php
// Halaman Akun: list kartu akun (ikon per type, saldo, badge arsip), FAB
// tambah, sheet form tambah/edit, arsip via lmConfirm. Diakses dari menu
// Lainnya -- $active tetap 'lainnya' supaya nav bawah tetap menyorot menu itu.

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/balance.php';
require_once __DIR__ . '/../core/layout.php';

$user = requireLogin();
$spaceId = currentSpaceId();
$accounts = spaceBalances($spaceId);

$typeLabels = ['cash' => 'Tunai', 'bank' => 'Bank', 'ewallet' => 'E-Wallet', 'other' => 'Lainnya'];
$typeIcons = ['cash' => '💵', 'bank' => '🏦', 'ewallet' => '📱', 'other' => '🗂️'];

pageHeader('Akun', $user);
?>
<section class="acc-list" id="accList">
<?php if (count($accounts) === 0): ?>
  <p class="acc-empty" id="accEmpty">Belum ada akun. Tambah akun pertama Anda lewat tombol + di bawah.</p>
<?php endif; ?>
<?php foreach ($accounts as $id => $acc): ?>
  <article class="acc-card<?= $acc['is_archived'] ? ' is-archived' : '' ?>" data-id="<?= (int) $id ?>" data-name="<?= e($acc['name']) ?>" data-type="<?= e($acc['type']) ?>">
    <span class="acc-icon"><?= $typeIcons[$acc['type']] ?? '💼' ?></span>
    <span class="acc-body">
      <span class="acc-name"><?= e($acc['name']) ?><?php if ($acc['is_archived']): ?><span class="acc-badge">Arsip</span><?php endif; ?></span>
      <span class="acc-type"><?= e($typeLabels[$acc['type']] ?? $acc['type']) ?></span>
    </span>
    <span class="acc-balance"><?= e(rupiah($acc['balance'])) ?></span>
    <button type="button" class="acc-menu-btn" aria-label="Opsi akun">⋮</button>
  </article>
<?php endforeach; ?>
</section>

<button type="button" class="fab" id="fabAdd" aria-label="Tambah akun">+</button>

<div class="acc-menu" id="accMenu" hidden>
  <button type="button" class="acc-menu-item" id="accMenuEdit">✏️ Edit</button>
  <button type="button" class="acc-menu-item acc-menu-item-danger" id="accMenuArchive">🗄️ Arsipkan</button>
</div>

<div class="sheet-overlay" id="accSheetOverlay" hidden>
  <div class="sheet">
    <h2 class="sheet-title" id="sheetTitle">Tambah Akun</h2>
    <form id="accForm">
      <input type="hidden" id="accId" value="">
      <label class="field">
        <span>Nama Akun</span>
        <input type="text" id="accName" maxlength="100" placeholder="mis. Dompet, BCA, GoPay" required>
      </label>
      <label class="field">
        <span>Jenis</span>
        <select id="accType">
          <option value="cash">💵 Tunai</option>
          <option value="bank">🏦 Bank</option>
          <option value="ewallet">📱 E-Wallet</option>
          <option value="other">🗂️ Lainnya</option>
        </select>
      </label>
      <label class="field" id="accInitialField">
        <span>Saldo Awal</span>
        <input type="text" id="accInitial" value="0" inputmode="numeric">
      </label>
      <p class="sheet-msg" id="sheetMsg"></p>
      <div class="sheet-actions">
        <button type="button" class="btn-secondary" id="accCancel">Batal</button>
        <button type="submit" class="btn-primary" id="accSubmit">Simpan</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  'use strict';

  var listEl = document.getElementById('accList');
  var overlay = document.getElementById('accSheetOverlay');
  var form = document.getElementById('accForm');
  var sheetTitle = document.getElementById('sheetTitle');
  var msgEl = document.getElementById('sheetMsg');
  var idInput = document.getElementById('accId');
  var nameInput = document.getElementById('accName');
  var typeInput = document.getElementById('accType');
  var initialInput = document.getElementById('accInitial');
  document.addEventListener('DOMContentLoaded', function () { attachRupiahInput(initialInput); });
  var initialField = document.getElementById('accInitialField');
  var submitBtn = document.getElementById('accSubmit');

  var cardMenu = document.getElementById('accMenu');
  var menuEditBtn = document.getElementById('accMenuEdit');
  var menuArchiveBtn = document.getElementById('accMenuArchive');
  var menuTarget = null; // { id, name, type } akun yang sedang dibuka menunya

  var typeIcons = { cash: '💵', bank: '🏦', ewallet: '📱', other: '🗂️' };
  var typeLabels = { cash: 'Tunai', bank: 'Bank', ewallet: 'E-Wallet', other: 'Lainnya' };

  // ---------- sheet form (tambah/edit) ----------

  function openSheet(mode, acc) {
    msgEl.textContent = '';
    form.reset();
    if (mode === 'edit') {
      sheetTitle.textContent = 'Edit Akun';
      idInput.value = acc.id;
      nameInput.value = acc.name;
      typeInput.value = acc.type;
      initialField.hidden = true;
    } else {
      sheetTitle.textContent = 'Tambah Akun';
      idInput.value = '';
      typeInput.value = 'cash';
      setRupiahInput(initialInput, '0');
      initialField.hidden = false;
    }
    overlay.hidden = false;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { overlay.classList.add('show'); });
    });
    setTimeout(function () { nameInput.focus(); }, 200);
  }

  function closeSheet() {
    overlay.classList.remove('show');
    setTimeout(function () { overlay.hidden = true; }, 180);
  }

  document.getElementById('fabAdd').addEventListener('click', function () {
    openSheet('add', null);
  });

  document.getElementById('accCancel').addEventListener('click', closeSheet);

  overlay.addEventListener('click', function (ev) {
    if (ev.target === overlay) closeSheet();
  });

  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    msgEl.textContent = '';
    submitBtn.disabled = true;

    var payload = {
      name: nameInput.value.trim(),
      type: typeInput.value,
    };
    var action;
    if (idInput.value) {
      action = 'update';
      payload.id = idInput.value;
    } else {
      action = 'create';
      payload.initial_balance = rupiahInputValue(initialInput) || 0;
    }

    try {
      await api('api/akun.php?a=' + action, payload);
      closeSheet();
      toast('Akun tersimpan');
      refreshList();
    } catch (err) {
      msgEl.textContent = err.message;
    } finally {
      submitBtn.disabled = false;
    }
  });

  // ---------- menu kartu (⋮ -> Edit / Arsipkan) ----------

  function closeCardMenu() {
    cardMenu.hidden = true;
    menuTarget = null;
  }

  function openCardMenu(btn, target) {
    menuTarget = target;
    var rect = btn.getBoundingClientRect();
    cardMenu.hidden = false;
    var menuWidth = cardMenu.offsetWidth || 160;
    var left = Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - 12);
    cardMenu.style.top = (rect.bottom + 6) + 'px';
    cardMenu.style.left = Math.max(12, left) + 'px';
  }

  listEl.addEventListener('click', function (ev) {
    var menuBtn = ev.target.closest('.acc-menu-btn');
    if (!menuBtn) return;
    ev.stopPropagation();
    var card = menuBtn.closest('.acc-card');
    var target = { id: card.dataset.id, name: card.dataset.name, type: card.dataset.type };
    if (menuTarget && menuTarget.id === target.id && !cardMenu.hidden) {
      closeCardMenu();
    } else {
      openCardMenu(menuBtn, target);
    }
  });

  document.addEventListener('click', function (ev) {
    if (!cardMenu.hidden && !ev.target.closest('#accMenu')) {
      closeCardMenu();
    }
  });

  menuEditBtn.addEventListener('click', function () {
    var target = menuTarget;
    closeCardMenu();
    if (target) openSheet('edit', target);
  });

  menuArchiveBtn.addEventListener('click', async function () {
    var target = menuTarget;
    closeCardMenu();
    if (!target) return;
    var ok = await lmConfirm(
      'Arsipkan akun "' + target.name + '"? Kalau akun ini belum punya transaksi, akun akan dihapus permanen.',
      'Konfirmasi'
    );
    if (!ok) return;
    try {
      var json = await api('api/akun.php?a=archive', { id: target.id });
      toast(json.deleted ? 'Akun dihapus' : 'Akun diarsipkan');
      refreshList();
    } catch (err) {
      toast(err.message);
    }
  });

  // ---------- render list (dipakai ulang setelah create/update/archive) ----------

  function accountCardEl(acc) {
    var card = document.createElement('article');
    card.className = 'acc-card' + (acc.is_archived ? ' is-archived' : '');
    card.dataset.id = acc.id;
    card.dataset.name = acc.name;
    card.dataset.type = acc.type;

    var icon = document.createElement('span');
    icon.className = 'acc-icon';
    icon.textContent = typeIcons[acc.type] || '💼';

    var body = document.createElement('span');
    body.className = 'acc-body';
    var nameEl = document.createElement('span');
    nameEl.className = 'acc-name';
    nameEl.appendChild(document.createTextNode(acc.name));
    if (acc.is_archived) {
      var badge = document.createElement('span');
      badge.className = 'acc-badge';
      badge.textContent = 'Arsip';
      nameEl.appendChild(badge);
    }
    var typeEl = document.createElement('span');
    typeEl.className = 'acc-type';
    typeEl.textContent = typeLabels[acc.type] || acc.type;
    body.appendChild(nameEl);
    body.appendChild(typeEl);

    var balanceEl = document.createElement('span');
    balanceEl.className = 'acc-balance';
    balanceEl.textContent = rupiahFmt(acc.balance);

    var menuBtn = document.createElement('button');
    menuBtn.type = 'button';
    menuBtn.className = 'acc-menu-btn';
    menuBtn.setAttribute('aria-label', 'Opsi akun');
    menuBtn.textContent = '⋮';

    card.appendChild(icon);
    card.appendChild(body);
    card.appendChild(balanceEl);
    card.appendChild(menuBtn);
    return card;
  }

  function renderList(accounts) {
    listEl.innerHTML = '';
    if (accounts.length === 0) {
      var empty = document.createElement('p');
      empty.className = 'acc-empty';
      empty.id = 'accEmpty';
      empty.textContent = 'Belum ada akun. Tambah akun pertama Anda lewat tombol + di bawah.';
      listEl.appendChild(empty);
      return;
    }
    accounts.forEach(function (acc) {
      listEl.appendChild(accountCardEl(acc));
    });
  }

  function refreshList() {
    return api('api/akun.php?a=list', {}).then(function (json) {
      renderList(json.accounts || []);
    }).catch(function (err) {
      toast(err.message);
    });
  }
})();
</script>
<?php
pageFooter('lainnya');
