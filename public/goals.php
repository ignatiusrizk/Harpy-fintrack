<?php
// Halaman Goals: kartu progress per goal (bar + pct, saved/target, sisa hari
// ke target_date), tombol Setor/Tarik (sheet pilih akun+nominal+tanggal),
// goal selesai pindah ke seksi "Selesai" (collapsed). FAB tambah (sheet nama +
// target nominal + tenggat opsional). Tap kartu -> sheet edit (nama/target/
// tenggat, tombol Tandai Selesai, Hapus). Semua data dimuat via JS (pola sama
// spt public/budget.php & public/recurring.php) -- halaman ini cuma shell +
// <script>. Diakses dari menu Lainnya -- $active tetap 'lainnya'.

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';

$user = requireLogin();

pageHeader('Goals', $user);
?>
<section class="gl-list" id="glList"></section>
<p class="gl-empty" id="glEmpty" hidden>Belum ada target tabungan. Tambah lewat tombol + di bawah.</p>

<button type="button" class="gl-done-toggle" id="glDoneToggle" hidden>
  <span>Selesai (<span id="glDoneCount">0</span>)</span>
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
</button>
<div class="gl-list" id="glDoneList" hidden></div>

<button type="button" class="fab" id="fabAdd" aria-label="Tambah goal">+</button>

<div class="sheet-overlay" id="glSheetOverlay" hidden>
  <div class="sheet">
    <h2 class="sheet-title" id="glSheetTitle">Tambah Goal</h2>
    <form id="glForm">
      <input type="hidden" id="glId" value="">

      <label class="field">
        <span>Nama Goal</span>
        <input type="text" id="glName" maxlength="100" placeholder="mis. Dana Darurat" required>
      </label>

      <label class="field">
        <span>Target Nominal</span>
        <input type="text" id="glTargetAmount" class="amount-input" placeholder="0" inputmode="numeric" required>
      </label>

      <label class="field">
        <span>Tenggat (opsional)</span>
        <input type="date" id="glTargetDate">
      </label>

      <p class="sheet-msg" id="glSheetMsg"></p>
      <div class="sheet-actions">
        <button type="button" class="btn-secondary" id="glFinishBtn" hidden>Tandai Selesai</button>
        <button type="button" class="btn-danger-link" id="glDelete" hidden>Hapus</button>
        <span class="sheet-actions-spacer"></span>
        <button type="button" class="btn-secondary" id="glCancel">Batal</button>
        <button type="submit" class="btn-primary" id="glSubmit">Simpan</button>
      </div>
    </form>
  </div>
</div>

<div class="sheet-overlay" id="glTxSheetOverlay" hidden>
  <div class="sheet">
    <h2 class="sheet-title" id="glTxSheetTitle">Setor</h2>
    <form id="glTxForm">
      <input type="hidden" id="glTxGoalId" value="">
      <input type="hidden" id="glTxMode" value="deposit">

      <label class="field">
        <span>Akun</span>
        <select id="glTxAccount"></select>
      </label>

      <label class="field">
        <span>Nominal</span>
        <input type="text" id="glTxAmount" class="amount-input" placeholder="0" inputmode="numeric" required>
      </label>

      <label class="field">
        <span>Tanggal</span>
        <input type="date" id="glTxDate">
      </label>

      <p class="gl-tx-hint" id="glTxHint"></p>
      <p class="sheet-msg" id="glTxSheetMsg"></p>
      <div class="sheet-actions">
        <span class="sheet-actions-spacer"></span>
        <button type="button" class="btn-secondary" id="glTxCancel">Batal</button>
        <button type="submit" class="btn-primary" id="glTxSubmit">Simpan</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  'use strict';

  var MONTH_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

  function parseDate(str) {
    var p = str.split('-');
    return new Date(+p[0], +p[1] - 1, +p[2]);
  }

  function shortDateLabel(dateStr) {
    var d = parseDate(dateStr);
    return d.getDate() + ' ' + MONTH_SHORT[d.getMonth()] + ' ' + d.getFullYear();
  }

  var today = (function () {
    var d = new Date();
    var pad = function (n) { return String(n).length < 2 ? '0' + n : String(n); };
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  })();

  function daysLeftLabel(row) {
    if (row.target_date === null) return 'Tanpa tenggat';
    if (row.days_left < 0) return 'Lewat tenggat ' + (-row.days_left) + ' hari (' + shortDateLabel(row.target_date) + ')';
    if (row.days_left === 0) return 'Tenggat hari ini';
    return 'Sisa ' + row.days_left + ' hari lagi (' + shortDateLabel(row.target_date) + ')';
  }

  // ---------- referensi: akun ----------

  var accountsCache = [];

  function loadAccounts() {
    return api('api/akun.php?a=list', {}).then(function (j) { accountsCache = j.accounts || []; });
  }

  function populateAccountSelect() {
    var sel = document.getElementById('glTxAccount');
    sel.innerHTML = '';
    accountsCache.forEach(function (acc) {
      var opt = document.createElement('option');
      opt.value = acc.id;
      opt.textContent = acc.name;
      sel.appendChild(opt);
    });
  }

  // ---------- render kartu goal ----------

  var listEl = document.getElementById('glList');
  var emptyEl = document.getElementById('glEmpty');
  var doneToggle = document.getElementById('glDoneToggle');
  var doneCountEl = document.getElementById('glDoneCount');
  var doneListEl = document.getElementById('glDoneList');

  function cardEl(row) {
    var card = document.createElement('div');
    card.className = 'gl-card' + (row.is_done ? ' is-done' : '');
    card.dataset.payload = JSON.stringify(row);

    var top = document.createElement('div');
    top.className = 'gl-card-top';
    var name = document.createElement('span');
    name.className = 'gl-name';
    name.textContent = row.name;
    var pct = document.createElement('span');
    pct.className = 'gl-pct';
    pct.textContent = row.pct + '%';
    top.appendChild(name);
    top.appendChild(pct);
    card.appendChild(top);

    var track = document.createElement('div');
    track.className = 'gl-progress';
    var bar = document.createElement('div');
    bar.className = 'gl-progress-bar';
    bar.style.width = Math.min(100, Math.max(0, row.pct)) + '%';
    track.appendChild(bar);
    card.appendChild(track);

    var amounts = document.createElement('div');
    amounts.className = 'gl-amounts';
    amounts.textContent = rupiahFmt(row.saved) + ' / ' + rupiahFmt(row.target_amount);
    card.appendChild(amounts);

    var meta = document.createElement('div');
    meta.className = 'gl-meta' + (row.target_date !== null && row.days_left < 0 && !row.is_done ? ' gl-meta-overdue' : '');
    meta.textContent = daysLeftLabel(row);
    card.appendChild(meta);

    if (!row.is_done) {
      var actions = document.createElement('div');
      actions.className = 'gl-actions';
      var withdrawBtn = document.createElement('button');
      withdrawBtn.type = 'button';
      withdrawBtn.className = 'btn-secondary';
      withdrawBtn.textContent = 'Tarik';
      withdrawBtn.addEventListener('click', function (ev) {
        ev.stopPropagation();
        openTxSheet('withdraw', row);
      });
      var depositBtn = document.createElement('button');
      depositBtn.type = 'button';
      depositBtn.className = 'btn-primary';
      depositBtn.textContent = 'Setor';
      depositBtn.addEventListener('click', function (ev) {
        ev.stopPropagation();
        openTxSheet('deposit', row);
      });
      actions.appendChild(withdrawBtn);
      actions.appendChild(depositBtn);
      card.appendChild(actions);
    }

    card.addEventListener('click', function () {
      openGoalSheet('edit', row);
    });

    return card;
  }

  var doneOpen = false;

  function render(goals) {
    var active = goals.filter(function (g) { return !g.is_done; });
    var done = goals.filter(function (g) { return g.is_done; });

    listEl.innerHTML = '';
    active.forEach(function (g) { listEl.appendChild(cardEl(g)); });
    emptyEl.hidden = goals.length > 0;

    doneToggle.hidden = done.length === 0;
    doneCountEl.textContent = done.length;
    doneListEl.innerHTML = '';
    done.forEach(function (g) { doneListEl.appendChild(cardEl(g)); });
    doneListEl.hidden = !doneOpen;
    doneToggle.classList.toggle('open', doneOpen);
  }

  doneToggle.addEventListener('click', function () {
    doneOpen = !doneOpen;
    doneListEl.hidden = !doneOpen;
    doneToggle.classList.toggle('open', doneOpen);
  });

  function load() {
    return api('api/goals.php?a=list', {}).then(function (json) {
      render(json.goals || []);
    }).catch(function (err) {
      toast(err.message);
    });
  }

  // ---------- sheet tambah/edit goal ----------

  var overlay = document.getElementById('glSheetOverlay');
  var form = document.getElementById('glForm');
  var sheetTitle = document.getElementById('glSheetTitle');
  var msgEl = document.getElementById('glSheetMsg');
  var idInput = document.getElementById('glId');
  var nameInput = document.getElementById('glName');
  var targetAmountInput = document.getElementById('glTargetAmount');
  document.addEventListener('DOMContentLoaded', function () { attachRupiahInput(targetAmountInput); });
  var targetDateInput = document.getElementById('glTargetDate');
  var submitBtn = document.getElementById('glSubmit');
  var deleteBtn = document.getElementById('glDelete');
  var finishBtn = document.getElementById('glFinishBtn');

  function openGoalSheet(mode, row) {
    msgEl.textContent = '';
    form.reset();

    if (mode === 'edit' && row) {
      sheetTitle.textContent = 'Edit Goal';
      idInput.value = row.id;
      nameInput.value = row.name;
      setRupiahInput(targetAmountInput, Math.round(Number(row.target_amount)));
      targetDateInput.value = row.target_date || '';
      deleteBtn.hidden = false;
      finishBtn.hidden = row.is_done;
      submitBtn.hidden = row.is_done;
      nameInput.disabled = row.is_done;
      targetAmountInput.disabled = row.is_done;
      targetDateInput.disabled = row.is_done;
    } else {
      sheetTitle.textContent = 'Tambah Goal';
      idInput.value = '';
      nameInput.value = '';
      targetAmountInput.value = '';
      targetDateInput.value = '';
      deleteBtn.hidden = true;
      finishBtn.hidden = true;
      submitBtn.hidden = false;
      nameInput.disabled = false;
      targetAmountInput.disabled = false;
      targetDateInput.disabled = false;
    }

    overlay.hidden = false;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { overlay.classList.add('show'); });
    });
  }

  function closeGoalSheet() {
    overlay.classList.remove('show');
    setTimeout(function () { overlay.hidden = true; }, 180);
  }

  document.getElementById('fabAdd').addEventListener('click', function () {
    openGoalSheet('add', null);
  });
  document.getElementById('glCancel').addEventListener('click', closeGoalSheet);
  overlay.addEventListener('click', function (ev) {
    if (ev.target === overlay) closeGoalSheet();
  });

  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    msgEl.textContent = '';
    submitBtn.disabled = true;

    var payload = {
      name: nameInput.value.trim(),
      target_amount: rupiahInputValue(targetAmountInput),
      target_date: targetDateInput.value,
    };

    var action;
    if (idInput.value) {
      action = 'update';
      payload.id = idInput.value;
    } else {
      action = 'create';
    }

    try {
      await api('api/goals.php?a=' + action, payload);
      closeGoalSheet();
      toast('Goal tersimpan');
      load();
    } catch (err) {
      msgEl.textContent = err.message;
    } finally {
      submitBtn.disabled = false;
    }
  });

  finishBtn.addEventListener('click', async function () {
    var id = idInput.value;
    if (!id) return;
    var ok = await lmConfirm('Tandai goal "' + nameInput.value + '" selesai? Setor/tarik tidak bisa dilakukan lagi setelah ini.', 'Konfirmasi');
    if (!ok) return;
    finishBtn.disabled = true;
    try {
      await api('api/goals.php?a=finish', { id: id });
      closeGoalSheet();
      toast('Goal ditandai selesai');
      load();
    } catch (err) {
      msgEl.textContent = err.message;
    } finally {
      finishBtn.disabled = false;
    }
  });

  deleteBtn.addEventListener('click', async function () {
    var id = idInput.value;
    if (!id) return;
    var ok = await lmConfirm('Hapus goal "' + nameInput.value + '"? Transaksi setor/tarik yang sudah tercatat sebelumnya tidak ikut terhapus, hanya tautannya ke goal ini yang lepas.', 'Konfirmasi');
    if (!ok) return;
    try {
      await api('api/goals.php?a=delete', { id: id });
      closeGoalSheet();
      toast('Goal dihapus');
      load();
    } catch (err) {
      msgEl.textContent = err.message;
    }
  });

  // ---------- sheet setor/tarik ----------

  var txOverlay = document.getElementById('glTxSheetOverlay');
  var txForm = document.getElementById('glTxForm');
  var txSheetTitle = document.getElementById('glTxSheetTitle');
  var txMsgEl = document.getElementById('glTxSheetMsg');
  var txGoalIdInput = document.getElementById('glTxGoalId');
  var txModeInput = document.getElementById('glTxMode');
  var txAccountSelect = document.getElementById('glTxAccount');
  var txAmountInput = document.getElementById('glTxAmount');
  document.addEventListener('DOMContentLoaded', function () { attachRupiahInput(txAmountInput); });
  var txDateInput = document.getElementById('glTxDate');
  var txHintEl = document.getElementById('glTxHint');
  var txSubmitBtn = document.getElementById('glTxSubmit');

  function openTxSheet(mode, row) {
    txMsgEl.textContent = '';
    txForm.reset();
    populateAccountSelect();

    txGoalIdInput.value = row.id;
    txModeInput.value = mode;
    txDateInput.value = today;
    txAmountInput.value = '';
    if (accountsCache.length > 0) txAccountSelect.value = accountsCache[0].id;

    if (mode === 'deposit') {
      txSheetTitle.textContent = 'Setor — ' + row.name;
      txSubmitBtn.textContent = 'Setor';
      txHintEl.textContent = 'Dana diambil dari akun terpilih & tercatat sebagai pengeluaran kategori Tabungan Goal.';
    } else {
      txSheetTitle.textContent = 'Tarik — ' + row.name;
      txSubmitBtn.textContent = 'Tarik';
      txHintEl.textContent = 'Dana masuk ke akun terpilih & tercatat sebagai pemasukan kategori Tabungan Goal. Maks ' + rupiahFmt(row.saved) + ' tersimpan.';
    }

    txOverlay.hidden = false;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { txOverlay.classList.add('show'); });
    });
    setTimeout(function () { txAmountInput.focus(); }, 200);
  }

  function closeTxSheet() {
    txOverlay.classList.remove('show');
    setTimeout(function () { txOverlay.hidden = true; }, 180);
  }

  document.getElementById('glTxCancel').addEventListener('click', closeTxSheet);
  txOverlay.addEventListener('click', function (ev) {
    if (ev.target === txOverlay) closeTxSheet();
  });

  // Idempoten dobel-klik: submitBtn disable selama request berjalan (pola
  // sama spt sheet recurring/budget) -- server sendiri juga sudah aman (lock
  // baris goal FOR UPDATE di depositGoal/withdrawGoal), ini lapisan pertama.
  txForm.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    txMsgEl.textContent = '';
    txSubmitBtn.disabled = true;

    var action = txModeInput.value === 'deposit' ? 'deposit' : 'withdraw';
    var payload = {
      goal_id: txGoalIdInput.value,
      account_id: txAccountSelect.value,
      amount: rupiahInputValue(txAmountInput),
      date: txDateInput.value,
    };

    try {
      await api('api/goals.php?a=' + action, payload);
      closeTxSheet();
      toast(action === 'deposit' ? 'Setoran tercatat' : 'Penarikan tercatat');
      load();
    } catch (err) {
      txMsgEl.textContent = err.message;
    } finally {
      txSubmitBtn.disabled = false;
    }
  });

  // ---------- init ----------
  // Ditunda ke DOMContentLoaded: script inline ini dicetak SEBELUM
  // assets/app.js (yg mendefinisikan window.api) dimuat oleh pageFooter().
  // Pola sama spt public/transaksi.php & public/budget.php.

  document.addEventListener('DOMContentLoaded', function () {
    loadAccounts().then(load);
  });
})();
</script>
<?php
pageFooter('lainnya');
