<?php
// Halaman Transaksi: ringkasan periode, filter (chip bulan ini/lalu/custom +
// jenis/akun/kategori/catatan), list dikelompokkan per tanggal, sheet
// tambah/edit (segmented jenis, picker akun & kategori custom, tanggal,
// catatan). ?add=1 (dari tombol + bottom nav) membuka sheet tambah otomatis.

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';

$user = requireLogin();
$autoAdd = get('add', '') === '1';

pageHeader('Transaksi', $user);
?>
<section class="card tx-summary">
  <p class="tx-summary-period" id="txPeriodLabel">Periode ini</p>
  <div class="tx-summary-row">
    <div class="tx-summary-col">
      <p class="tx-summary-label">Masuk</p>
      <p class="tx-summary-value tx-amount-income" id="sumIncome">Rp 0</p>
    </div>
    <div class="tx-summary-col tx-summary-col-right">
      <p class="tx-summary-label">Keluar</p>
      <p class="tx-summary-value tx-amount-expense" id="sumExpense">Rp 0</p>
    </div>
  </div>
</section>

<div class="chip-row" id="monthChips">
  <button type="button" class="chip active" data-preset="this_month">Bulan ini</button>
  <button type="button" class="chip" data-preset="last_month">Bulan lalu</button>
  <button type="button" class="chip" data-preset="custom">Custom</button>
</div>

<div class="custom-range" id="customRange" hidden>
  <input type="date" id="rangeFrom">
  <span class="custom-range-sep">–</span>
  <input type="date" id="rangeTo">
  <button type="button" class="btn-secondary" id="rangeApply">Terapkan</button>
</div>

<div class="tx-filters">
  <select id="filterType">
    <option value="">Semua Jenis</option>
    <option value="income">Masuk</option>
    <option value="expense">Keluar</option>
    <option value="transfer">Transfer</option>
  </select>
  <select id="filterAccount"><option value="">Semua Akun</option></select>
  <select id="filterCategory"><option value="">Semua Kategori</option></select>
</div>
<div class="tx-filters tx-filters-search">
  <input type="text" id="filterQ" placeholder="Cari catatan...">
</div>

<div id="txGroups"></div>
<p class="tx-empty" id="txEmpty" hidden>Belum ada transaksi pada periode/filter ini.</p>
<button type="button" class="btn-secondary tx-more" id="loadMoreBtn" hidden>Muat lagi</button>

<div class="sheet-overlay" id="txSheetOverlay" hidden>
  <div class="sheet">
    <h2 class="sheet-title" id="txSheetTitle">Tambah Transaksi</h2>
    <form id="txForm">
      <input type="hidden" id="txId" value="">

      <div class="segmented" id="txTypeSeg">
        <button type="button" class="segmented-btn active" data-type="income">Masuk</button>
        <button type="button" class="segmented-btn" data-type="expense">Keluar</button>
        <button type="button" class="segmented-btn" data-type="transfer">Transfer</button>
      </div>

      <label class="field">
        <span>Nominal</span>
        <input type="number" id="txAmount" class="amount-input" min="1" step="1" placeholder="0" inputmode="numeric" required>
      </label>

      <label class="field">
        <span>Akun</span>
        <button type="button" class="picker-btn" id="txAccountBtn">Pilih akun</button>
        <input type="hidden" id="txAccountId">
      </label>

      <label class="field" id="txToAccountField" hidden>
        <span>Akun Tujuan</span>
        <button type="button" class="picker-btn" id="txToAccountBtn">Pilih akun tujuan</button>
        <input type="hidden" id="txToAccountId">
      </label>

      <label class="field" id="txCategoryField">
        <span>Kategori</span>
        <button type="button" class="picker-btn" id="txCategoryBtn">Pilih kategori</button>
        <input type="hidden" id="txCategoryId">
      </label>

      <label class="field">
        <span>Tanggal</span>
        <input type="date" id="txDate" required>
      </label>

      <label class="field">
        <span>Catatan (opsional)</span>
        <input type="text" id="txNote" maxlength="255" placeholder="mis. Makan siang">
      </label>

      <p class="sheet-msg" id="txSheetMsg"></p>
      <div class="sheet-actions">
        <button type="button" class="btn-danger-link" id="txDelete" hidden>Hapus</button>
        <span class="sheet-actions-spacer"></span>
        <button type="button" class="btn-secondary" id="txCancel">Batal</button>
        <button type="submit" class="btn-primary" id="txSubmit">Simpan</button>
      </div>
    </form>
  </div>
</div>

<div class="sheet-overlay" id="pickOverlay" hidden>
  <div class="sheet">
    <h2 class="sheet-title" id="pickTitle">Pilih</h2>
    <div class="picker-list" id="pickList"></div>
    <div class="sheet-actions">
      <button type="button" class="btn-secondary" id="pickCancel">Tutup</button>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';

  var DAY_NAMES = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
  var MONTH_NAMES = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
  var MONTH_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

  function pad2(n) { return String(n).length < 2 ? '0' + n : String(n); }

  function fmtDate(d) {
    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
  }

  // Parse 'YYYY-MM-DD' sbg tanggal LOKAL (bukan UTC) -- hindari geser hari
  // krn parsing UTC bawaan `new Date(str)` beda timezone browser.
  function parseDate(str) {
    var p = str.split('-');
    return new Date(+p[0], +p[1] - 1, +p[2]);
  }

  function fullDateLabel(dateStr) {
    var d = parseDate(dateStr);
    return DAY_NAMES[d.getDay()] + ', ' + d.getDate() + ' ' + MONTH_NAMES[d.getMonth()] + ' ' + d.getFullYear();
  }

  function shortDateLabel(dateStr) {
    var d = parseDate(dateStr);
    return d.getDate() + ' ' + MONTH_SHORT[d.getMonth()] + ' ' + d.getFullYear();
  }

  function monthRange(offsetMonths) {
    var now = new Date();
    var first = new Date(now.getFullYear(), now.getMonth() + offsetMonths, 1);
    var last = new Date(first.getFullYear(), first.getMonth() + 1, 0);
    return { from: fmtDate(first), to: fmtDate(last) };
  }

  var today = fmtDate(new Date());

  // ---------- referensi: akun & kategori (dimuat sekali, dipakai filter+picker) ----------

  var accountsCache = [];
  var categoriesTree = { expense: [], income: [] };

  function loadRefData() {
    return Promise.all([
      api('api/akun.php?a=list', {}).then(function (j) { accountsCache = j.accounts || []; }),
      api('api/kategori.php?a=list', {}).then(function (j) { categoriesTree = j.categories || { expense: [], income: [] }; }),
    ]);
  }

  function populateFilterSelects() {
    var accSel = document.getElementById('filterAccount');
    accountsCache.forEach(function (acc) {
      var opt = document.createElement('option');
      opt.value = acc.id;
      opt.textContent = acc.name;
      accSel.appendChild(opt);
    });

    var catSel = document.getElementById('filterCategory');
    ['expense', 'income'].forEach(function (type) {
      var roots = categoriesTree[type] || [];
      if (roots.length === 0) return;
      var group = document.createElement('optgroup');
      group.label = type === 'expense' ? 'Pengeluaran' : 'Pemasukan';
      roots.forEach(function (root) {
        appendCatOption(group, root, 0);
        (root.children || []).forEach(function (child) { appendCatOption(group, child, 1); });
      });
      catSel.appendChild(group);
    });
  }

  function appendCatOption(parentEl, cat, depth) {
    var opt = document.createElement('option');
    opt.value = cat.id;
    opt.textContent = (depth > 0 ? '— ' : '') + cat.name;
    parentEl.appendChild(opt);
  }

  // ---------- state filter & list ----------

  var state = { preset: 'this_month', from: '', to: '', type: '', account_id: '', category_id: '', q: '', page: 1 };
  var loadedCount = 0;
  var lastGroupKey = null;
  var lastGroupListEl = null;

  var groupsEl = document.getElementById('txGroups');
  var emptyEl = document.getElementById('txEmpty');
  var loadMoreBtn = document.getElementById('loadMoreBtn');
  var periodLabel = document.getElementById('txPeriodLabel');
  var sumIncomeEl = document.getElementById('sumIncome');
  var sumExpenseEl = document.getElementById('sumExpense');

  function currentFilters() {
    return {
      from: state.from, to: state.to, type: state.type,
      account_id: state.account_id, category_id: state.category_id,
      q: state.q, page: state.page,
    };
  }

  function txRowEl(row) {
    var el = document.createElement('div');
    el.className = 'tx-row';
    el.dataset.payload = JSON.stringify(row);

    var icon = document.createElement('span');
    icon.className = 'tx-icon';
    if (row.type === 'transfer') {
      icon.textContent = '↔';
      icon.style.background = 'var(--bg-page)';
    } else {
      icon.textContent = row.category_icon || '🏷️';
      icon.style.background = (row.category_color || '#94A3B8') + '22';
    }

    var body = document.createElement('span');
    body.className = 'tx-body';
    var title = document.createElement('span');
    title.className = 'tx-title';
    var sub = document.createElement('span');
    sub.className = 'tx-sub';
    if (row.type === 'transfer') {
      title.textContent = row.note || 'Transfer';
      sub.textContent = row.account_name + ' → ' + row.to_account_name;
    } else {
      title.textContent = row.note || row.category_name || '-';
      sub.textContent = row.account_name;
    }
    body.appendChild(title);
    body.appendChild(sub);

    var amount = document.createElement('span');
    var amt = Number(row.amount) || 0;
    if (row.type === 'income') {
      amount.className = 'tx-amount tx-amount-income';
      amount.textContent = '+' + rupiahFmt(amt);
    } else if (row.type === 'expense') {
      amount.className = 'tx-amount tx-amount-expense';
      amount.textContent = '-' + rupiahFmt(amt);
    } else {
      amount.className = 'tx-amount tx-amount-transfer';
      amount.textContent = '↔ ' + rupiahFmt(amt);
    }

    el.appendChild(icon);
    el.appendChild(body);
    el.appendChild(amount);
    return el;
  }

  function appendRows(rows) {
    rows.forEach(function (row) {
      if (row.tx_date !== lastGroupKey) {
        lastGroupKey = row.tx_date;
        var section = document.createElement('section');
        section.className = 'tx-group';
        var header = document.createElement('h3');
        header.className = 'tx-group-title';
        header.textContent = fullDateLabel(row.tx_date);
        var list = document.createElement('div');
        list.className = 'tx-group-list';
        section.appendChild(header);
        section.appendChild(list);
        groupsEl.appendChild(section);
        lastGroupListEl = list;
      }
      lastGroupListEl.appendChild(txRowEl(row));
    });
  }

  function reload() {
    state.page = 1;
    loadedCount = 0;
    lastGroupKey = null;
    lastGroupListEl = null;
    groupsEl.innerHTML = '';
    return api('api/transaksi.php?a=list', currentFilters()).then(function (json) {
      renderSummary(json);
      appendRows(json.rows || []);
      loadedCount = (json.rows || []).length;
      emptyEl.hidden = loadedCount > 0;
      loadMoreBtn.hidden = loadedCount >= json.total_rows;
    }).catch(function (err) {
      toast(err.message);
    });
  }

  function loadMore() {
    state.page += 1;
    return api('api/transaksi.php?a=list', currentFilters()).then(function (json) {
      appendRows(json.rows || []);
      loadedCount += (json.rows || []).length;
      loadMoreBtn.hidden = loadedCount >= json.total_rows;
    }).catch(function (err) {
      toast(err.message);
    });
  }

  function renderSummary(json) {
    sumIncomeEl.textContent = rupiahFmt(json.total_income || 0);
    sumExpenseEl.textContent = rupiahFmt(json.total_expense || 0);
    if (state.from && state.to) {
      periodLabel.textContent = state.from === state.to
        ? shortDateLabel(state.from)
        : shortDateLabel(state.from) + ' – ' + shortDateLabel(state.to);
    } else {
      periodLabel.textContent = 'Semua periode';
    }
  }

  loadMoreBtn.addEventListener('click', loadMore);

  // ---------- filter: chip bulan + custom range ----------

  var chipsEl = document.getElementById('monthChips');
  var customRangeEl = document.getElementById('customRange');
  var rangeFromInput = document.getElementById('rangeFrom');
  var rangeToInput = document.getElementById('rangeTo');

  function setPreset(preset) {
    state.preset = preset;
    Array.prototype.forEach.call(chipsEl.children, function (chip) {
      chip.classList.toggle('active', chip.dataset.preset === preset);
    });
    customRangeEl.hidden = preset !== 'custom';

    if (preset === 'this_month') {
      var r1 = monthRange(0);
      state.from = r1.from; state.to = r1.to;
      reload();
    } else if (preset === 'last_month') {
      var r2 = monthRange(-1);
      state.from = r2.from; state.to = r2.to;
      reload();
    } else {
      if (!rangeFromInput.value) rangeFromInput.value = state.from;
      if (!rangeToInput.value) rangeToInput.value = state.to;
    }
  }

  chipsEl.addEventListener('click', function (ev) {
    var chip = ev.target.closest('.chip');
    if (!chip) return;
    setPreset(chip.dataset.preset);
  });

  document.getElementById('rangeApply').addEventListener('click', function () {
    if (!rangeFromInput.value || !rangeToInput.value) {
      toast('Isi tanggal awal & akhir dulu');
      return;
    }
    state.from = rangeFromInput.value;
    state.to = rangeToInput.value;
    reload();
  });

  // ---------- filter: jenis/akun/kategori/catatan ----------

  document.getElementById('filterType').addEventListener('change', function (ev) {
    state.type = ev.target.value;
    reload();
  });
  document.getElementById('filterAccount').addEventListener('change', function (ev) {
    state.account_id = ev.target.value;
    reload();
  });
  document.getElementById('filterCategory').addEventListener('change', function (ev) {
    state.category_id = ev.target.value;
    reload();
  });

  var qTimer = null;
  document.getElementById('filterQ').addEventListener('input', function (ev) {
    var val = ev.target.value;
    clearTimeout(qTimer);
    qTimer = setTimeout(function () {
      state.q = val.trim();
      reload();
    }, 400);
  });

  // ---------- picker overlay (akun / kategori, dipakai dari sheet tambah/edit) ----------

  var pickOverlay = document.getElementById('pickOverlay');
  var pickTitle = document.getElementById('pickTitle');
  var pickList = document.getElementById('pickList');

  function openPicker(title, items, onPick) {
    pickTitle.textContent = title;
    pickList.innerHTML = '';
    if (items.length === 0) {
      var empty = document.createElement('p');
      empty.className = 'cat-empty';
      empty.textContent = 'Tidak ada pilihan.';
      pickList.appendChild(empty);
    }
    items.forEach(function (item) {
      var row = document.createElement('button');
      row.type = 'button';
      row.className = 'picker-row' + (item.depth ? ' picker-row-child' : '');
      if (item.icon) {
        var icon = document.createElement('span');
        icon.className = 'cat-icon cat-icon-sm';
        if (item.color) icon.style.background = item.color + '22';
        icon.textContent = item.icon;
        row.appendChild(icon);
      }
      var label = document.createElement('span');
      label.textContent = item.label;
      row.appendChild(label);
      row.addEventListener('click', function () {
        onPick(item);
        closePicker();
      });
      pickList.appendChild(row);
    });
    pickOverlay.hidden = false;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { pickOverlay.classList.add('show'); });
    });
  }

  function closePicker() {
    pickOverlay.classList.remove('show');
    setTimeout(function () { pickOverlay.hidden = true; }, 180);
  }

  document.getElementById('pickCancel').addEventListener('click', closePicker);
  pickOverlay.addEventListener('click', function (ev) {
    if (ev.target === pickOverlay) closePicker();
  });

  // ---------- sheet tambah/edit transaksi ----------

  var overlay = document.getElementById('txSheetOverlay');
  var form = document.getElementById('txForm');
  var sheetTitle = document.getElementById('txSheetTitle');
  var msgEl = document.getElementById('txSheetMsg');
  var idInput = document.getElementById('txId');
  var amountInput = document.getElementById('txAmount');
  var dateInput = document.getElementById('txDate');
  var noteInput = document.getElementById('txNote');
  var submitBtn = document.getElementById('txSubmit');
  var deleteBtn = document.getElementById('txDelete');
  var typeSeg = document.getElementById('txTypeSeg');

  var accountBtn = document.getElementById('txAccountBtn');
  var accountIdInput = document.getElementById('txAccountId');
  var toAccountField = document.getElementById('txToAccountField');
  var toAccountBtn = document.getElementById('txToAccountBtn');
  var toAccountIdInput = document.getElementById('txToAccountId');
  var categoryField = document.getElementById('txCategoryField');
  var categoryBtn = document.getElementById('txCategoryBtn');
  var categoryIdInput = document.getElementById('txCategoryId');

  function currentType() {
    var active = typeSeg.querySelector('.segmented-btn.active');
    return active ? active.dataset.type : 'income';
  }

  function setType(type) {
    Array.prototype.forEach.call(typeSeg.children, function (btn) {
      btn.classList.toggle('active', btn.dataset.type === type);
    });
    var isTransfer = type === 'transfer';
    toAccountField.hidden = !isTransfer;
    categoryField.hidden = isTransfer;
    if (isTransfer) {
      categoryIdInput.value = '';
      categoryBtn.textContent = 'Pilih kategori';
    } else {
      toAccountIdInput.value = '';
      toAccountBtn.textContent = 'Pilih akun tujuan';
    }
  }

  typeSeg.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.segmented-btn');
    if (!btn) return;
    setType(btn.dataset.type);
  });

  accountBtn.addEventListener('click', function () {
    var items = accountsCache.map(function (acc) {
      return { id: acc.id, label: acc.name, icon: null };
    });
    openPicker('Pilih Akun', items, function (item) {
      accountIdInput.value = item.id;
      accountBtn.textContent = item.label;
    });
  });

  toAccountBtn.addEventListener('click', function () {
    var items = accountsCache
      .filter(function (acc) { return String(acc.id) !== String(accountIdInput.value); })
      .map(function (acc) { return { id: acc.id, label: acc.name, icon: null }; });
    openPicker('Pilih Akun Tujuan', items, function (item) {
      toAccountIdInput.value = item.id;
      toAccountBtn.textContent = item.label;
    });
  });

  categoryBtn.addEventListener('click', function () {
    var type = currentType();
    var roots = categoriesTree[type] || [];
    var items = [];
    roots.forEach(function (root) {
      items.push({ id: root.id, label: root.name, icon: root.icon, color: root.color, depth: 0 });
      (root.children || []).forEach(function (child) {
        items.push({ id: child.id, label: child.name, icon: child.icon, color: child.color, depth: 1 });
      });
    });
    openPicker('Pilih Kategori', items, function (item) {
      categoryIdInput.value = item.id;
      categoryBtn.textContent = item.label;
    });
  });

  function openTxSheet(mode, row) {
    msgEl.textContent = '';
    form.reset();
    deleteBtn.hidden = true;
    accountBtn.textContent = 'Pilih akun';
    toAccountBtn.textContent = 'Pilih akun tujuan';
    categoryBtn.textContent = 'Pilih kategori';
    accountIdInput.value = '';
    toAccountIdInput.value = '';
    categoryIdInput.value = '';

    if (mode === 'edit' && row) {
      sheetTitle.textContent = 'Edit Transaksi';
      idInput.value = row.id;
      setType(row.type);
      amountInput.value = Math.round(Number(row.amount));
      dateInput.value = row.tx_date;
      noteInput.value = row.note || '';
      accountIdInput.value = row.account_id;
      accountBtn.textContent = row.account_name;
      if (row.type === 'transfer') {
        toAccountIdInput.value = row.to_account_id;
        toAccountBtn.textContent = row.to_account_name;
      } else {
        categoryIdInput.value = row.category_id;
        categoryBtn.textContent = row.category_name || 'Pilih kategori';
      }
      deleteBtn.hidden = false;
    } else {
      sheetTitle.textContent = 'Tambah Transaksi';
      idInput.value = '';
      setType('income');
      amountInput.value = '';
      dateInput.value = today;
      noteInput.value = '';
      if (accountsCache.length > 0) {
        accountIdInput.value = accountsCache[0].id;
        accountBtn.textContent = accountsCache[0].name;
      }
    }

    overlay.hidden = false;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { overlay.classList.add('show'); });
    });
  }

  function closeTxSheet() {
    overlay.classList.remove('show');
    setTimeout(function () { overlay.hidden = true; }, 180);
  }

  document.getElementById('txCancel').addEventListener('click', closeTxSheet);
  overlay.addEventListener('click', function (ev) {
    if (ev.target === overlay) closeTxSheet();
  });

  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    msgEl.textContent = '';
    submitBtn.disabled = true;

    var type = currentType();
    var payload = {
      type: type,
      amount: amountInput.value,
      tx_date: dateInput.value,
      note: noteInput.value.trim(),
      account_id: accountIdInput.value,
    };
    if (type === 'transfer') {
      payload.to_account_id = toAccountIdInput.value;
    } else {
      payload.category_id = categoryIdInput.value;
    }

    var action;
    if (idInput.value) {
      action = 'update';
      payload.id = idInput.value;
    } else {
      action = 'create';
    }

    try {
      await api('api/transaksi.php?a=' + action, payload);
      closeTxSheet();
      toast('Transaksi tersimpan');
      reload();
    } catch (err) {
      msgEl.textContent = err.message;
    } finally {
      submitBtn.disabled = false;
    }
  });

  deleteBtn.addEventListener('click', async function () {
    var id = idInput.value;
    if (!id) return;
    var ok = await lmConfirm('Hapus transaksi ini? Tindakan ini tidak bisa dibatalkan.', 'Konfirmasi');
    if (!ok) return;
    try {
      await api('api/transaksi.php?a=delete', { id: id });
      closeTxSheet();
      toast('Transaksi dihapus');
      reload();
    } catch (err) {
      msgEl.textContent = err.message;
    }
  });

  groupsEl.addEventListener('click', function (ev) {
    var row = ev.target.closest('.tx-row');
    if (!row) return;
    openTxSheet('edit', JSON.parse(row.dataset.payload));
  });

  // ---------- init ----------
  // Ditunda ke DOMContentLoaded: script inline ini dicetak SEBELUM
  // assets/app.js (yg mendefinisikan window.api) dimuat oleh pageFooter() --
  // manggil api() langsung di sini (bukan dari handler klik spt akun.php)
  // akan gagal krn api belum ada. DOMContentLoaded aman krn baru terpicu
  // setelah semua <script> sinkron (termasuk app.js di bawah) selesai jalan.

  document.addEventListener('DOMContentLoaded', function () {
    var initialRange = monthRange(0);
    state.from = initialRange.from;
    state.to = initialRange.to;

    loadRefData().then(function () {
      populateFilterSelects();
      reload();
      if (<?= $autoAdd ? 'true' : 'false' ?>) {
        openTxSheet('add', null);
      }
    });
  });
})();
</script>
<?php
pageFooter('transaksi');
