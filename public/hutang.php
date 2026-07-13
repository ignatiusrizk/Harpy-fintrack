<?php
// Halaman Hutang/Piutang & Cicilan (Task 5): kartu total Utang/Piutang, dua
// seksi (Utang/Piutang) berisi kartu progress per debt (pihak, sisa/pokok,
// progress bar, badge cicilan/jatuh-tempo), seksi Lunas collapsed (pola sama
// spt gl-done-toggle public/goals.php). Tap kartu -> sheet detail (stat grid
// + riwayat + aksi Bayar/Terima/Tandai Lunas/Edit/Hapus). FAB tambah -> sheet
// segmented arah + toggle Cicilan + toggle Dana ke akun. Sheet bayar & sheet
// edit terpisah, bisa dibuka DI ATAS sheet detail (pola z-index spt
// public/investasi.php). Semua data dimuat via JS (pola sama spt
// public/goals.php & public/investasi.php) -- halaman ini cuma shell +
// <script>. Diakses dari menu Lainnya -- $active tetap 'lainnya'.

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';

$user = requireLogin();

pageHeader('Hutang', $user);
?>
<section class="card ht-summary-card">
  <div class="ht-summary-row">
    <div class="ht-summary-col">
      <p class="balance-label">Total Utang</p>
      <p class="balance-value ht-summary-payable" id="htTotalPayable">Rp 0</p>
    </div>
    <div class="ht-summary-col">
      <p class="balance-label">Total Piutang</p>
      <p class="balance-value ht-summary-receivable" id="htTotalReceivable">Rp 0</p>
    </div>
  </div>
</section>

<section>
  <h2 class="ht-section-title">Utang</h2>
  <div class="ht-list" id="htPayableList"></div>
  <p class="ht-empty" id="htPayableEmpty" hidden>Belum ada utang. Tambah lewat tombol + di bawah.</p>
  <button type="button" class="ht-done-toggle" id="htPayableDoneToggle" hidden>
    <span>Lunas (<span id="htPayableDoneCount">0</span>)</span>
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
  </button>
  <div class="ht-list" id="htPayableDoneList" hidden></div>
</section>

<section>
  <h2 class="ht-section-title">Piutang</h2>
  <div class="ht-list" id="htReceivableList"></div>
  <p class="ht-empty" id="htReceivableEmpty" hidden>Belum ada piutang. Tambah lewat tombol + di bawah.</p>
  <button type="button" class="ht-done-toggle" id="htReceivableDoneToggle" hidden>
    <span>Lunas (<span id="htReceivableDoneCount">0</span>)</span>
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
  </button>
  <div class="ht-list" id="htReceivableDoneList" hidden></div>
</section>

<button type="button" class="fab" id="fabAdd" aria-label="Tambah utang/piutang">+</button>

<!-- Sheet: tambah utang/piutang -->
<div class="sheet-overlay" id="htAddSheetOverlay" hidden>
  <div class="sheet">
    <h2 class="sheet-title">Tambah Utang/Piutang</h2>
    <form id="htAddForm">
      <div class="segmented" id="htDirectionSeg">
        <button type="button" class="segmented-btn active" data-direction="payable">Utang</button>
        <button type="button" class="segmented-btn" data-direction="receivable">Piutang</button>
      </div>

      <label class="field">
        <span id="htPartyLabel">Pemberi Pinjaman</span>
        <input type="text" id="htParty" maxlength="100" placeholder="mis. Bank ABC / Budi" required>
      </label>

      <label class="field">
        <span>Pokok</span>
        <input type="text" id="htPrincipal" class="amount-input" placeholder="0" inputmode="numeric" required>
      </label>

      <label class="field">
        <span>Tanggal Mulai</span>
        <input type="date" id="htStartDate">
      </label>

      <label class="field">
        <span>Jatuh Tempo (opsional)</span>
        <input type="date" id="htDueDate">
      </label>

      <div class="ht-toggle-row">
        <span>Cicilan</span>
        <label class="rc-switch">
          <input type="checkbox" id="htIsInstallment">
          <span class="rc-switch-slider"></span>
        </label>
      </div>

      <div id="htInstallmentFields" hidden>
        <label class="field">
          <span>Jumlah Cicilan</span>
          <input type="number" id="htInstallmentCount" min="1" step="1" placeholder="mis. 12">
        </label>

        <label class="field">
          <span>Nominal per Cicilan</span>
          <input type="text" id="htInstallmentAmount" class="amount-input" placeholder="0" inputmode="numeric">
        </label>

        <label class="field">
          <span>Frekuensi</span>
          <select id="htFrequency">
            <option value="weekly">Mingguan</option>
            <option value="monthly" selected>Bulanan</option>
            <option value="yearly">Tahunan</option>
          </select>
        </label>
      </div>

      <div class="ht-toggle-row">
        <span id="htDisburseLabel">Dana masuk ke akun</span>
        <label class="rc-switch">
          <input type="checkbox" id="htDisburse">
          <span class="rc-switch-slider"></span>
        </label>
      </div>

      <div id="htDisburseFields" hidden>
        <label class="field">
          <span>Akun</span>
          <select id="htAccount"></select>
        </label>
        <p class="ht-hint" id="htDisburseHint"></p>
      </div>

      <label class="field">
        <span>Catatan (opsional)</span>
        <input type="text" id="htNote" maxlength="255" placeholder="mis. Cicilan motor">
      </label>

      <p class="sheet-msg" id="htAddSheetMsg"></p>
      <div class="sheet-actions">
        <span class="sheet-actions-spacer"></span>
        <button type="button" class="btn-secondary" id="htAddCancel">Batal</button>
        <button type="submit" class="btn-primary" id="htAddSubmit">Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- Sheet: detail (stat grid + riwayat + aksi) -->
<div class="sheet-overlay" id="htDetailSheetOverlay" hidden>
  <div class="sheet ht-detail-sheet">
    <h2 class="sheet-title" id="htDetailTitle">Pihak</h2>
    <p class="ht-detail-sub" id="htDetailSub"></p>

    <div class="ht-stat-grid">
      <div class="ht-stat"><span>Pokok</span><strong id="htDetailPrincipal">Rp 0</strong></div>
      <div class="ht-stat"><span>Sisa</span><strong id="htDetailOutstanding">Rp 0</strong></div>
      <div class="ht-stat"><span>Progres</span><strong id="htDetailProgress">0%</strong></div>
      <div class="ht-stat" id="htDetailCicilanStat"><span>Cicilan</span><strong id="htDetailCicilan">-</strong></div>
      <div class="ht-stat"><span>Jatuh Tempo</span><strong id="htDetailDue">-</strong></div>
      <div class="ht-stat" id="htDetailNextDueStat"><span>Cicilan Berikutnya</span><strong id="htDetailNextDue">-</strong></div>
    </div>

    <p class="ht-detail-note" id="htDetailNote" hidden></p>

    <div class="ht-detail-actions" id="htDetailActions">
      <button type="button" class="btn-secondary" id="htDetailPay">Bayar</button>
      <button type="button" class="btn-secondary" id="htDetailSettle">Tandai Lunas</button>
    </div>

    <p class="bg-section-title">Riwayat Pembayaran</p>
    <div class="ht-hist-list" id="htHistList"></div>
    <p class="ht-hist-empty" id="htHistEmpty" hidden>Belum ada pembayaran.</p>

    <div class="sheet-actions">
      <button type="button" class="btn-danger-link" id="htDetailDelete">Hapus</button>
      <span class="sheet-actions-spacer"></span>
      <button type="button" class="btn-secondary" id="htDetailEdit">Edit</button>
      <button type="button" class="btn-primary" id="htDetailClose">Tutup</button>
    </div>
  </div>
</div>

<!-- Sheet: bayar/terima -->
<div class="sheet-overlay" id="htPaySheetOverlay" hidden>
  <div class="sheet">
    <h2 class="sheet-title" id="htPaySheetTitle">Bayar</h2>
    <form id="htPayForm">
      <input type="hidden" id="htPayDebtId" value="">

      <label class="field">
        <span>Akun</span>
        <select id="htPayAccount"></select>
      </label>

      <label class="field">
        <span>Nominal</span>
        <input type="text" id="htPayAmount" class="amount-input" placeholder="0" inputmode="numeric" required>
      </label>

      <label class="field">
        <span>Tanggal</span>
        <input type="date" id="htPayDate">
      </label>

      <p class="ht-hint" id="htPayHint"></p>
      <p class="sheet-msg" id="htPaySheetMsg"></p>
      <div class="sheet-actions">
        <span class="sheet-actions-spacer"></span>
        <button type="button" class="btn-secondary" id="htPayCancel">Batal</button>
        <button type="submit" class="btn-primary" id="htPaySubmit">Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- Sheet: edit -->
<div class="sheet-overlay" id="htEditSheetOverlay" hidden>
  <div class="sheet">
    <h2 class="sheet-title" id="htEditSheetTitle">Edit</h2>
    <form id="htEditForm">
      <input type="hidden" id="htEditDebtId" value="">

      <label class="field">
        <span id="htEditPartyLabel">Pihak</span>
        <input type="text" id="htEditParty" maxlength="100" required>
      </label>

      <label class="field">
        <span>Pokok</span>
        <input type="text" id="htEditPrincipal" class="amount-input" placeholder="0" inputmode="numeric" required>
      </label>
      <p class="ht-hint" id="htEditPrincipalHint" hidden>Pokok tidak bisa diubah karena sudah ada pembayaran.</p>

      <label class="field">
        <span>Jatuh Tempo (opsional)</span>
        <input type="date" id="htEditDueDate">
      </label>

      <label class="field">
        <span>Catatan (opsional)</span>
        <input type="text" id="htEditNote" maxlength="255">
      </label>

      <p class="sheet-msg" id="htEditSheetMsg"></p>
      <div class="sheet-actions">
        <span class="sheet-actions-spacer"></span>
        <button type="button" class="btn-secondary" id="htEditCancel">Batal</button>
        <button type="submit" class="btn-primary" id="htEditSubmit">Simpan</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  'use strict';

  var DIRECTION_LABEL = { payable: 'Utang', receivable: 'Piutang' };
  var PARTY_LABEL = { payable: 'Pemberi Pinjaman', receivable: 'Peminjam' };
  var DISBURSE_LABEL = { payable: 'Dana masuk ke akun', receivable: 'Dana keluar dari akun' };
  var DISBURSE_HINT = {
    payable: 'Dana pinjaman langsung dicatat sebagai pemasukan (Pencairan Pinjaman) ke akun terpilih, sebesar pokok penuh.',
    receivable: 'Dana yang dipinjamkan langsung dicatat sebagai pengeluaran (Beri Pinjaman) dari akun terpilih, sebesar pokok penuh.',
  };
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

  // ---------- referensi: akun ----------

  var accountsCache = [];

  function loadAccounts() {
    return api('api/akun.php?a=list', {}).then(function (j) { accountsCache = j.accounts || []; });
  }

  function populateAccountSelect(sel) {
    sel.innerHTML = '';
    accountsCache.forEach(function (acc) {
      var opt = document.createElement('option');
      opt.value = acc.id;
      opt.textContent = acc.name;
      sel.appendChild(opt);
    });
  }

  // ---------- data & render ----------

  var summaryCache = { payable: [], receivable: [], total_payable: 0, total_receivable: 0 };

  function findDebt(id) {
    var found = null;
    ['payable', 'receivable'].forEach(function (dir) {
      summaryCache[dir].forEach(function (r) { if (String(r.id) === String(id)) found = r; });
    });
    return found;
  }

  function debtMetaParts(row) {
    var parts = [];
    if (row.status === 'settled') {
      parts.push({ text: 'Lunas' });
      return parts;
    }
    if (row.is_installment) {
      parts.push({ text: 'Cicilan ' + row.paid_count + '/' + row.installment_count, badge: true });
      if (row.next_due) parts.push({ text: 'Berikutnya ' + shortDateLabel(row.next_due) });
    }
    if (row.due_date) {
      var overdue = row.due_date < today;
      parts.push({ text: (overdue ? 'Lewat tempo ' : 'Jatuh tempo ') + shortDateLabel(row.due_date), overdue: overdue });
    } else if (!row.is_installment) {
      parts.push({ text: 'Tanpa jatuh tempo' });
    }
    return parts;
  }

  function cardEl(row) {
    var card = document.createElement('div');
    card.className = 'ht-card' + (row.status === 'settled' ? ' is-settled' : '');
    card.dataset.id = row.id;

    var top = document.createElement('div');
    top.className = 'ht-card-top';
    var name = document.createElement('span');
    name.className = 'ht-card-party';
    name.textContent = row.party;
    var pct = document.createElement('span');
    pct.className = 'ht-card-pct';
    pct.textContent = row.progress_pct + '%';
    top.appendChild(name);
    top.appendChild(pct);
    card.appendChild(top);

    var track = document.createElement('div');
    track.className = 'ht-progress';
    var bar = document.createElement('div');
    bar.className = 'ht-progress-bar';
    bar.style.width = Math.min(100, Math.max(0, row.progress_pct)) + '%';
    track.appendChild(bar);
    card.appendChild(track);

    var amounts = document.createElement('div');
    amounts.className = 'ht-card-amounts';
    amounts.textContent = rupiahFmt(row.outstanding) + ' / ' + rupiahFmt(row.principal);
    card.appendChild(amounts);

    var meta = document.createElement('div');
    meta.className = 'ht-card-meta';
    debtMetaParts(row).forEach(function (part) {
      var span = document.createElement('span');
      span.className = part.badge ? 'rc-badge' : (part.overdue ? 'ht-card-meta-overdue' : '');
      span.textContent = part.text;
      meta.appendChild(span);
    });
    card.appendChild(meta);

    card.addEventListener('click', function () { openDetailSheet(row.id); });

    return card;
  }

  var doneOpen = { payable: false, receivable: false };

  function renderSection(direction) {
    var rows = summaryCache[direction] || [];
    var active = rows.filter(function (r) { return r.status !== 'settled'; });
    var done = rows.filter(function (r) { return r.status === 'settled'; });

    var prefix = direction === 'payable' ? 'htPayable' : 'htReceivable';
    var listEl = document.getElementById(prefix + 'List');
    var emptyEl = document.getElementById(prefix + 'Empty');
    var doneToggle = document.getElementById(prefix + 'DoneToggle');
    var doneCountEl = document.getElementById(prefix + 'DoneCount');
    var doneListEl = document.getElementById(prefix + 'DoneList');

    listEl.innerHTML = '';
    active.forEach(function (r) { listEl.appendChild(cardEl(r)); });
    emptyEl.hidden = rows.length > 0;

    doneToggle.hidden = done.length === 0;
    doneCountEl.textContent = done.length;
    doneListEl.innerHTML = '';
    done.forEach(function (r) { doneListEl.appendChild(cardEl(r)); });
    doneListEl.hidden = !doneOpen[direction];
    doneToggle.classList.toggle('open', doneOpen[direction]);
  }

  ['payable', 'receivable'].forEach(function (direction) {
    var prefix = direction === 'payable' ? 'htPayable' : 'htReceivable';
    document.getElementById(prefix + 'DoneToggle').addEventListener('click', function () {
      doneOpen[direction] = !doneOpen[direction];
      renderSection(direction);
    });
  });

  function render(summary) {
    summaryCache = summary;
    document.getElementById('htTotalPayable').textContent = rupiahFmt(summary.total_payable);
    document.getElementById('htTotalReceivable').textContent = rupiahFmt(summary.total_receivable);
    renderSection('payable');
    renderSection('receivable');
  }

  function load() {
    return api('api/hutang.php?a=list', {}).then(function (json) {
      render(json || { payable: [], receivable: [], total_payable: 0, total_receivable: 0 });
    }).catch(function (err) {
      toast(err.message);
    });
  }

  // ---------- sheet tambah ----------

  var addOverlay = document.getElementById('htAddSheetOverlay');
  var addForm = document.getElementById('htAddForm');
  var addMsgEl = document.getElementById('htAddSheetMsg');
  var directionSeg = document.getElementById('htDirectionSeg');
  var partyLabelEl = document.getElementById('htPartyLabel');
  var partyInput = document.getElementById('htParty');
  var principalInput = document.getElementById('htPrincipal');
  document.addEventListener('DOMContentLoaded', function () { attachRupiahInput(principalInput); });
  var startDateInput = document.getElementById('htStartDate');
  var dueDateInput = document.getElementById('htDueDate');
  var isInstallmentInput = document.getElementById('htIsInstallment');
  var installmentFields = document.getElementById('htInstallmentFields');
  var installmentCountInput = document.getElementById('htInstallmentCount');
  var installmentAmountInput = document.getElementById('htInstallmentAmount');
  document.addEventListener('DOMContentLoaded', function () { attachRupiahInput(installmentAmountInput); });
  var frequencySelect = document.getElementById('htFrequency');
  var disburseInput = document.getElementById('htDisburse');
  var disburseFields = document.getElementById('htDisburseFields');
  var disburseLabelEl = document.getElementById('htDisburseLabel');
  var disburseHintEl = document.getElementById('htDisburseHint');
  var accountSelect = document.getElementById('htAccount');
  var noteInput = document.getElementById('htNote');
  var addSubmitBtn = document.getElementById('htAddSubmit');

  function currentAddDirection() {
    var active = directionSeg.querySelector('.segmented-btn.active');
    return active ? active.dataset.direction : 'payable';
  }

  function applyDirectionLabels(direction) {
    partyLabelEl.textContent = PARTY_LABEL[direction];
    disburseLabelEl.textContent = DISBURSE_LABEL[direction];
    disburseHintEl.textContent = DISBURSE_HINT[direction];
  }

  directionSeg.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.segmented-btn');
    if (!btn) return;
    Array.prototype.forEach.call(directionSeg.children, function (b) {
      b.classList.toggle('active', b === btn);
    });
    applyDirectionLabels(btn.dataset.direction);
  });

  isInstallmentInput.addEventListener('change', function () {
    installmentFields.hidden = !isInstallmentInput.checked;
  });

  disburseInput.addEventListener('change', function () {
    disburseFields.hidden = !disburseInput.checked;
    if (disburseInput.checked) populateAccountSelect(accountSelect);
  });

  function openAddSheet() {
    addMsgEl.textContent = '';
    addForm.reset();

    Array.prototype.forEach.call(directionSeg.children, function (b, i) {
      b.classList.toggle('active', i === 0);
    });
    applyDirectionLabels('payable');
    partyInput.value = '';
    principalInput.value = '';
    startDateInput.value = today;
    dueDateInput.value = '';
    isInstallmentInput.checked = false;
    installmentFields.hidden = true;
    installmentCountInput.value = '';
    installmentAmountInput.value = '';
    frequencySelect.value = 'monthly';
    disburseInput.checked = false;
    disburseFields.hidden = true;
    if (accountsCache.length > 0) accountSelect.value = accountsCache[0].id;
    noteInput.value = '';

    addOverlay.hidden = false;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { addOverlay.classList.add('show'); });
    });
  }

  function closeAddSheet() {
    addOverlay.classList.remove('show');
    setTimeout(function () { addOverlay.hidden = true; }, 180);
  }

  document.getElementById('fabAdd').addEventListener('click', openAddSheet);
  document.getElementById('htAddCancel').addEventListener('click', closeAddSheet);
  addOverlay.addEventListener('click', function (ev) {
    if (ev.target === addOverlay) closeAddSheet();
  });

  addForm.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    addMsgEl.textContent = '';
    addSubmitBtn.disabled = true;

    var direction = currentAddDirection();
    var isInstallment = isInstallmentInput.checked;
    var disburse = disburseInput.checked;

    var payload = {
      direction: direction,
      party: partyInput.value.trim(),
      principal: rupiahInputValue(principalInput),
      note: noteInput.value.trim(),
      start_date: startDateInput.value,
      due_date: dueDateInput.value,
      is_installment: isInstallment,
      installment_count: isInstallment ? installmentCountInput.value : '',
      installment_amount: isInstallment ? rupiahInputValue(installmentAmountInput) : '',
      frequency: isInstallment ? frequencySelect.value : '',
      disburse: disburse,
      account_id: disburse ? accountSelect.value : '',
    };

    try {
      await api('api/hutang.php?a=create', payload);
      closeAddSheet();
      toast(direction === 'payable' ? 'Utang tersimpan' : 'Piutang tersimpan');
      load();
    } catch (err) {
      addMsgEl.textContent = err.message;
    } finally {
      addSubmitBtn.disabled = false;
    }
  });

  // ---------- sheet detail ----------

  var detailOverlay = document.getElementById('htDetailSheetOverlay');
  var detailTitle = document.getElementById('htDetailTitle');
  var detailSub = document.getElementById('htDetailSub');
  var detailPrincipal = document.getElementById('htDetailPrincipal');
  var detailOutstanding = document.getElementById('htDetailOutstanding');
  var detailProgress = document.getElementById('htDetailProgress');
  var detailCicilanStat = document.getElementById('htDetailCicilanStat');
  var detailCicilan = document.getElementById('htDetailCicilan');
  var detailDue = document.getElementById('htDetailDue');
  var detailNextDueStat = document.getElementById('htDetailNextDueStat');
  var detailNextDue = document.getElementById('htDetailNextDue');
  var detailNote = document.getElementById('htDetailNote');
  var detailActions = document.getElementById('htDetailActions');
  var detailPayBtn = document.getElementById('htDetailPay');
  var detailSettleBtn = document.getElementById('htDetailSettle');
  var histList = document.getElementById('htHistList');
  var histEmpty = document.getElementById('htHistEmpty');
  var currentDetailDebt = null;

  function openDetailSheet(id) {
    var row = findDebt(id);
    if (!row) return;
    currentDetailDebt = row;

    detailTitle.textContent = row.party;
    detailSub.textContent = DIRECTION_LABEL[row.direction] + ' · ' + (row.status === 'settled' ? 'Lunas' : 'Aktif');
    detailPrincipal.textContent = rupiahFmt(row.principal);
    detailOutstanding.textContent = rupiahFmt(row.outstanding);
    detailProgress.textContent = row.progress_pct + '%';

    detailCicilanStat.hidden = !row.is_installment;
    detailCicilan.textContent = row.is_installment ? (row.paid_count + '/' + row.installment_count) : '-';
    detailDue.textContent = row.due_date ? shortDateLabel(row.due_date) : 'Tanpa jatuh tempo';
    detailNextDueStat.hidden = !(row.is_installment && row.status !== 'settled');
    detailNextDue.textContent = row.next_due ? shortDateLabel(row.next_due) : '-';

    detailNote.hidden = !row.note;
    detailNote.textContent = row.note || '';

    var isSettled = row.status === 'settled';
    detailActions.hidden = isSettled;
    detailPayBtn.textContent = row.direction === 'payable' ? 'Bayar' : 'Terima';

    histList.innerHTML = '';
    histEmpty.hidden = true;
    loadHistory(id);

    detailOverlay.hidden = false;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { detailOverlay.classList.add('show'); });
    });
  }

  function closeDetailSheet() {
    detailOverlay.classList.remove('show');
    setTimeout(function () { detailOverlay.hidden = true; }, 180);
  }

  function histRowEl(h) {
    var el = document.createElement('div');
    el.className = 'ht-hist-row';

    var body = document.createElement('span');
    body.className = 'ht-hist-body';
    var line1 = document.createElement('span');
    line1.textContent = rupiahFmt(h.amount);
    var line2 = document.createElement('span');
    line2.className = 'ht-hist-date';
    line2.textContent = h.pay_date + (h.tx_note ? ' · ' + h.tx_note : '');
    body.appendChild(line1);
    body.appendChild(line2);

    el.appendChild(body);
    return el;
  }

  function loadHistory(id) {
    return api('api/hutang.php?a=history&debt_id=' + encodeURIComponent(id), {}).then(function (json) {
      var rows = json.history || [];
      histList.innerHTML = '';
      rows.forEach(function (h) { histList.appendChild(histRowEl(h)); });
      histEmpty.hidden = rows.length > 0;
    }).catch(function (err) {
      toast(err.message);
    });
  }

  document.getElementById('htDetailClose').addEventListener('click', closeDetailSheet);
  detailOverlay.addEventListener('click', function (ev) {
    if (ev.target === detailOverlay) closeDetailSheet();
  });

  document.getElementById('htDetailEdit').addEventListener('click', function () {
    openEditSheet(currentDetailDebt);
  });

  document.getElementById('htDetailPay').addEventListener('click', function () {
    openPaySheet(currentDetailDebt);
  });

  detailSettleBtn.addEventListener('click', async function () {
    if (!currentDetailDebt) return;
    var ok = await lmConfirm(
      'Tandai "' + currentDetailDebt.party + '" lunas? Sisa ' + rupiahFmt(currentDetailDebt.outstanding) + ' dianggap lunas tanpa transaksi kas baru.',
      'Konfirmasi'
    );
    if (!ok) return;
    detailSettleBtn.disabled = true;
    try {
      await api('api/hutang.php?a=settle', { debt_id: currentDetailDebt.id });
      toast('Ditandai lunas');
      await load();
      var refreshed = findDebt(currentDetailDebt.id);
      if (refreshed) openDetailSheet(refreshed.id);
    } catch (err) {
      toast(err.message);
    } finally {
      detailSettleBtn.disabled = false;
    }
  });

  document.getElementById('htDetailDelete').addEventListener('click', async function () {
    if (!currentDetailDebt) return;
    var word = currentDetailDebt.direction === 'payable' ? 'utang' : 'piutang';
    var ok = await lmConfirm(
      'Hapus ' + word + ' "' + currentDetailDebt.party + '"? Transaksi kas yang sudah tercatat TIDAK ikut terhapus, hanya tautannya yang lepas.',
      'Konfirmasi'
    );
    if (!ok) return;
    try {
      await api('api/hutang.php?a=delete', { debt_id: currentDetailDebt.id });
      closeDetailSheet();
      toast(word.charAt(0).toUpperCase() + word.slice(1) + ' dihapus');
      load();
    } catch (err) {
      toast(err.message);
    }
  });

  // ---------- sheet bayar/terima ----------

  var payOverlay = document.getElementById('htPaySheetOverlay');
  var payForm = document.getElementById('htPayForm');
  var payTitle = document.getElementById('htPaySheetTitle');
  var payMsgEl = document.getElementById('htPaySheetMsg');
  var payDebtIdInput = document.getElementById('htPayDebtId');
  var payAccountSelect = document.getElementById('htPayAccount');
  var payAmountInput = document.getElementById('htPayAmount');
  document.addEventListener('DOMContentLoaded', function () { attachRupiahInput(payAmountInput); });
  var payDateInput = document.getElementById('htPayDate');
  var payHintEl = document.getElementById('htPayHint');
  var paySubmitBtn = document.getElementById('htPaySubmit');
  var payExpectedNextDue = null;

  function openPaySheet(row) {
    payMsgEl.textContent = '';
    payForm.reset();
    populateAccountSelect(payAccountSelect);

    payDebtIdInput.value = row.id;
    payExpectedNextDue = row.is_installment ? row.next_due : null;
    payDateInput.value = today;
    if (accountsCache.length > 0) payAccountSelect.value = accountsCache[0].id;

    if (row.is_installment && row.installment_amount) {
      var def = Math.min(Number(row.installment_amount), Number(row.outstanding));
      setRupiahInput(payAmountInput, Math.round(def));
    } else {
      payAmountInput.value = '';
    }

    if (row.direction === 'payable') {
      payTitle.textContent = 'Bayar — ' + row.party;
      paySubmitBtn.textContent = 'Bayar';
      payHintEl.textContent = 'Dana diambil dari akun terpilih & tercatat sebagai pengeluaran kategori Bayar Utang/Cicilan. Maks ' + rupiahFmt(row.outstanding) + ' (sisa utang).';
    } else {
      payTitle.textContent = 'Terima — ' + row.party;
      paySubmitBtn.textContent = 'Terima';
      payHintEl.textContent = 'Dana masuk ke akun terpilih & tercatat sebagai pemasukan kategori Terima Piutang. Maks ' + rupiahFmt(row.outstanding) + ' (sisa piutang).';
    }

    payOverlay.hidden = false;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { payOverlay.classList.add('show'); });
    });
    setTimeout(function () { payAmountInput.focus(); }, 200);
  }

  function closePaySheet() {
    payOverlay.classList.remove('show');
    setTimeout(function () { payOverlay.hidden = true; }, 180);
  }

  document.getElementById('htPayCancel').addEventListener('click', closePaySheet);
  payOverlay.addEventListener('click', function (ev) {
    if (ev.target === payOverlay) closePaySheet();
  });

  // Idempoten dobel-klik: submitBtn disable selama request berjalan (pola sama
  // spt sheet goals/recurring) -- server sendiri jg sudah aman (lock baris
  // debt FOR UPDATE + expected_next_due di payDebt()), ini lapisan pertama.
  payForm.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    payMsgEl.textContent = '';
    paySubmitBtn.disabled = true;

    var payload = {
      debt_id: payDebtIdInput.value,
      account_id: payAccountSelect.value,
      amount: rupiahInputValue(payAmountInput),
      date: payDateInput.value,
      expected_next_due: payExpectedNextDue,
    };

    try {
      await api('api/hutang.php?a=pay', payload);
      closePaySheet();
      toast('Pembayaran tercatat');
      await load();
      var refreshed = findDebt(payload.debt_id);
      if (refreshed && !detailOverlay.hidden) openDetailSheet(refreshed.id);
    } catch (err) {
      payMsgEl.textContent = err.message;
    } finally {
      paySubmitBtn.disabled = false;
    }
  });

  // ---------- sheet edit ----------

  var editOverlay = document.getElementById('htEditSheetOverlay');
  var editForm = document.getElementById('htEditForm');
  var editTitle = document.getElementById('htEditSheetTitle');
  var editMsgEl = document.getElementById('htEditSheetMsg');
  var editDebtIdInput = document.getElementById('htEditDebtId');
  var editPartyLabelEl = document.getElementById('htEditPartyLabel');
  var editPartyInput = document.getElementById('htEditParty');
  var editPrincipalInput = document.getElementById('htEditPrincipal');
  document.addEventListener('DOMContentLoaded', function () { attachRupiahInput(editPrincipalInput); });
  var editPrincipalHint = document.getElementById('htEditPrincipalHint');
  var editDueDateInput = document.getElementById('htEditDueDate');
  var editNoteInput = document.getElementById('htEditNote');
  var editSubmitBtn = document.getElementById('htEditSubmit');

  function openEditSheet(row) {
    editMsgEl.textContent = '';
    editForm.reset();

    editTitle.textContent = 'Edit ' + DIRECTION_LABEL[row.direction];
    editPartyLabelEl.textContent = PARTY_LABEL[row.direction];
    editDebtIdInput.value = row.id;
    editPartyInput.value = row.party;
    setRupiahInput(editPrincipalInput, Math.round(Number(row.principal)));
    var lockPrincipal = row.paid_count > 0;
    editPrincipalInput.disabled = lockPrincipal;
    editPrincipalHint.hidden = !lockPrincipal;
    editDueDateInput.value = row.due_date || '';
    editNoteInput.value = row.note || '';

    editOverlay.hidden = false;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { editOverlay.classList.add('show'); });
    });
  }

  function closeEditSheet() {
    editOverlay.classList.remove('show');
    setTimeout(function () { editOverlay.hidden = true; }, 180);
  }

  document.getElementById('htEditCancel').addEventListener('click', closeEditSheet);
  editOverlay.addEventListener('click', function (ev) {
    if (ev.target === editOverlay) closeEditSheet();
  });

  editForm.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    editMsgEl.textContent = '';
    editSubmitBtn.disabled = true;

    var payload = {
      debt_id: editDebtIdInput.value,
      party: editPartyInput.value.trim(),
      principal: rupiahInputValue(editPrincipalInput),
      due_date: editDueDateInput.value,
      note: editNoteInput.value.trim(),
    };

    try {
      await api('api/hutang.php?a=update', payload);
      closeEditSheet();
      toast('Perubahan tersimpan');
      await load();
      var refreshed = findDebt(payload.debt_id);
      if (refreshed && !detailOverlay.hidden) openDetailSheet(refreshed.id);
    } catch (err) {
      editMsgEl.textContent = err.message;
    } finally {
      editSubmitBtn.disabled = false;
    }
  });

  // ---------- init ----------
  // Ditunda ke DOMContentLoaded: script inline ini dicetak SEBELUM
  // assets/app.js (yg mendefinisikan window.api) dimuat oleh pageFooter().
  // Pola sama spt public/goals.php & public/investasi.php.

  document.addEventListener('DOMContentLoaded', function () {
    loadAccounts().then(load);
  });
})();
</script>
<?php
pageFooter('lainnya');
