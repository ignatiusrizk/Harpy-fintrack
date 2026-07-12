<?php
// Halaman Recurring: seksi "Menunggu konfirmasi" (reminder due -> tombol
// Catat/Lewati), list semua template (badge frekuensi & mode, next_run,
// switch aktif, tap baris -> sheet edit), FAB tambah (sheet type segmented +
// akun/kategori select + frekuensi + tanggal mulai + mode radio auto/
// reminder). Semua data dimuat via JS (pola sama spt public/budget.php) --
// halaman ini cuma shell + <script>. Diakses dari menu Lainnya -- $active
// tetap 'lainnya'.

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';

$user = requireLogin();

pageHeader('Recurring', $user);
?>
<section id="rcDueSection" hidden>
  <h2 class="rc-section-title">Menunggu Konfirmasi</h2>
  <div class="rc-due-list" id="rcDueList"></div>
</section>

<section>
  <h2 class="rc-section-title">Semua Template</h2>
  <div class="rc-list" id="rcList"></div>
  <p class="rc-empty" id="rcEmpty" hidden>Belum ada transaksi berulang. Tambah lewat tombol + di bawah.</p>
</section>

<button type="button" class="fab" id="fabAdd" aria-label="Tambah recurring">+</button>

<div class="sheet-overlay" id="rcSheetOverlay" hidden>
  <div class="sheet">
    <h2 class="sheet-title" id="rcSheetTitle">Tambah Recurring</h2>
    <form id="rcForm">
      <input type="hidden" id="rcId" value="">

      <div class="segmented" id="rcTypeSeg">
        <button type="button" class="segmented-btn" data-type="income">Masuk</button>
        <button type="button" class="segmented-btn active" data-type="expense">Keluar</button>
      </div>

      <label class="field">
        <span>Nominal</span>
        <input type="number" id="rcAmount" class="amount-input" min="1" step="1" placeholder="0" inputmode="numeric" required>
      </label>

      <label class="field">
        <span>Akun</span>
        <select id="rcAccount"></select>
      </label>

      <label class="field">
        <span>Kategori</span>
        <select id="rcCategory"></select>
      </label>

      <label class="field">
        <span>Frekuensi</span>
        <select id="rcFrequency">
          <option value="daily">Harian</option>
          <option value="weekly">Mingguan</option>
          <option value="monthly" selected>Bulanan</option>
          <option value="yearly">Tahunan</option>
        </select>
      </label>

      <label class="field" id="rcStartDateField">
        <span>Tanggal Mulai</span>
        <input type="date" id="rcStartDate">
      </label>

      <label class="field">
        <span>Catatan (opsional)</span>
        <input type="text" id="rcNote" maxlength="255" placeholder="mis. Cicilan motor">
      </label>

      <label class="field">
        <span>Mode</span>
        <div class="rc-mode-options">
          <label class="rc-mode-option">
            <input type="radio" name="rcMode" value="reminder" checked>
            <span class="rc-mode-option-text">
              <strong>Pengingat</strong>
              <span>Muncul di "Menunggu konfirmasi" -- Anda yang menekan Catat saat siap.</span>
            </span>
          </label>
          <label class="rc-mode-option">
            <input type="radio" name="rcMode" value="auto">
            <span class="rc-mode-option-text">
              <strong>Otomatis</strong>
              <span>Transaksi langsung tercatat sendiri tiap jatuh tempo, tanpa konfirmasi.</span>
            </span>
          </label>
        </div>
      </label>

      <p class="sheet-msg" id="rcSheetMsg"></p>
      <div class="sheet-actions">
        <button type="button" class="btn-danger-link" id="rcDelete" hidden>Hapus</button>
        <span class="sheet-actions-spacer"></span>
        <button type="button" class="btn-secondary" id="rcCancel">Batal</button>
        <button type="submit" class="btn-primary" id="rcSubmit">Simpan</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  'use strict';

  var FREQ_LABELS = { daily: 'Harian', weekly: 'Mingguan', monthly: 'Bulanan', yearly: 'Tahunan' };
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

  // ---------- referensi: akun & kategori ----------

  var accountsCache = [];
  var categoriesTree = { expense: [], income: [] };

  function loadRefData() {
    return Promise.all([
      api('api/akun.php?a=list', {}).then(function (j) { accountsCache = j.accounts || []; }),
      api('api/kategori.php?a=list', {}).then(function (j) { categoriesTree = j.categories || { expense: [], income: [] }; }),
    ]);
  }

  function populateAccountSelect() {
    var sel = document.getElementById('rcAccount');
    sel.innerHTML = '';
    accountsCache.forEach(function (acc) {
      var opt = document.createElement('option');
      opt.value = acc.id;
      opt.textContent = acc.name;
      sel.appendChild(opt);
    });
  }

  function populateCategorySelect(type, selectedId) {
    var sel = document.getElementById('rcCategory');
    sel.innerHTML = '';
    var roots = categoriesTree[type] || [];
    roots.forEach(function (root) {
      var opt = document.createElement('option');
      opt.value = root.id;
      opt.textContent = root.name;
      sel.appendChild(opt);
      (root.children || []).forEach(function (child) {
        var copt = document.createElement('option');
        copt.value = child.id;
        copt.textContent = '— ' + child.name;
        sel.appendChild(copt);
      });
    });
    if (selectedId) sel.value = selectedId;
  }

  // ---------- seksi "Menunggu konfirmasi" ----------

  var dueSection = document.getElementById('rcDueSection');
  var dueList = document.getElementById('rcDueList');

  function dueCardEl(row) {
    var card = document.createElement('div');
    card.className = 'rc-due-card';

    var body = document.createElement('div');
    body.className = 'rc-due-body';
    var name = document.createElement('div');
    name.className = 'rc-due-name';
    name.textContent = row.note || row.category_name;
    var meta = document.createElement('div');
    meta.className = 'rc-due-meta';
    var sign = row.type === 'income' ? '+' : '-';
    meta.textContent = sign + rupiahFmt(row.amount) + ' · jatuh tempo ' + shortDateLabel(row.next_run);
    body.appendChild(name);
    body.appendChild(meta);

    var actions = document.createElement('div');
    actions.className = 'rc-due-actions';
    var skipBtn = document.createElement('button');
    skipBtn.type = 'button';
    skipBtn.textContent = 'Lewati';
    skipBtn.addEventListener('click', function () { doSkip(row.id); });
    var confirmBtn = document.createElement('button');
    confirmBtn.type = 'button';
    confirmBtn.className = 'rc-due-confirm';
    confirmBtn.textContent = 'Catat';
    confirmBtn.addEventListener('click', function () { doConfirm(row.id); });
    actions.appendChild(skipBtn);
    actions.appendChild(confirmBtn);

    card.appendChild(body);
    card.appendChild(actions);
    return card;
  }

  async function doConfirm(id) {
    try {
      await api('api/recurring.php?a=confirm', { id: id });
      toast('Transaksi tercatat');
      load();
    } catch (err) {
      toast(err.message);
    }
  }

  async function doSkip(id) {
    var ok = await lmConfirm('Lewati periode ini? Tidak ada transaksi yang dicatat.', 'Konfirmasi');
    if (!ok) return;
    try {
      await api('api/recurring.php?a=skip', { id: id });
      toast('Periode dilewati');
      load();
    } catch (err) {
      toast(err.message);
    }
  }

  // ---------- list semua template ----------

  var listEl = document.getElementById('rcList');
  var emptyEl = document.getElementById('rcEmpty');

  function rowEl(row) {
    var el = document.createElement('div');
    el.className = 'rc-row' + (row.is_active ? '' : ' is-inactive');
    el.dataset.payload = JSON.stringify(row);

    var icon = document.createElement('span');
    icon.className = 'rc-row-icon';
    icon.style.background = (row.category_color || '#94A3B8') + '22';
    icon.textContent = row.category_icon || '🏷️';

    var body = document.createElement('span');
    body.className = 'rc-row-body';
    var title = document.createElement('span');
    title.className = 'rc-row-title';
    title.textContent = row.note || row.category_name;
    var meta = document.createElement('span');
    meta.className = 'rc-row-meta';
    var freqBadge = document.createElement('span');
    freqBadge.className = 'rc-badge';
    freqBadge.textContent = FREQ_LABELS[row.frequency] || row.frequency;
    var modeBadge = document.createElement('span');
    modeBadge.className = 'rc-badge' + (row.mode === 'auto' ? ' rc-badge-auto' : '');
    modeBadge.textContent = row.mode === 'auto' ? 'Otomatis' : 'Pengingat';
    var next = document.createElement('span');
    next.className = 'rc-row-next';
    next.textContent = 'Berikutnya ' + shortDateLabel(row.next_run);
    meta.appendChild(freqBadge);
    meta.appendChild(modeBadge);
    body.appendChild(title);
    body.appendChild(meta);
    body.appendChild(next);

    var amount = document.createElement('span');
    amount.className = 'rc-row-amount ' + (row.type === 'income' ? 'rc-amount-income' : 'rc-amount-expense');
    amount.textContent = (row.type === 'income' ? '+' : '-') + rupiahFmt(row.amount);

    var switchLabel = document.createElement('label');
    switchLabel.className = 'rc-switch';
    var switchInput = document.createElement('input');
    switchInput.type = 'checkbox';
    switchInput.checked = row.is_active;
    var switchSlider = document.createElement('span');
    switchSlider.className = 'rc-switch-slider';
    switchLabel.appendChild(switchInput);
    switchLabel.appendChild(switchSlider);
    switchLabel.addEventListener('click', function (ev) {
      ev.stopPropagation();
    });
    switchInput.addEventListener('change', function () {
      doToggle(row.id);
    });

    el.appendChild(icon);
    el.appendChild(body);
    el.appendChild(amount);
    el.appendChild(switchLabel);
    return el;
  }

  async function doToggle(id) {
    try {
      await api('api/recurring.php?a=toggle', { id: id });
      load();
    } catch (err) {
      toast(err.message);
      load(); // revert tampilan switch ke state server yg sebenarnya
    }
  }

  function render(recurrings) {
    var due = recurrings.filter(function (r) { return r.due; });
    dueSection.hidden = due.length === 0;
    dueList.innerHTML = '';
    due.forEach(function (r) { dueList.appendChild(dueCardEl(r)); });

    listEl.innerHTML = '';
    recurrings.forEach(function (r) { listEl.appendChild(rowEl(r)); });
    emptyEl.hidden = recurrings.length > 0;
  }

  function load() {
    return api('api/recurring.php?a=list', {}).then(function (json) {
      render(json.recurrings || []);
    }).catch(function (err) {
      toast(err.message);
    });
  }

  listEl.addEventListener('click', function (ev) {
    if (ev.target.closest('.rc-switch')) return;
    var row = ev.target.closest('.rc-row');
    if (!row) return;
    openSheet('edit', JSON.parse(row.dataset.payload));
  });

  // ---------- sheet tambah/edit ----------

  var overlay = document.getElementById('rcSheetOverlay');
  var form = document.getElementById('rcForm');
  var sheetTitle = document.getElementById('rcSheetTitle');
  var msgEl = document.getElementById('rcSheetMsg');
  var idInput = document.getElementById('rcId');
  var amountInput = document.getElementById('rcAmount');
  var accountSelect = document.getElementById('rcAccount');
  var categorySelect = document.getElementById('rcCategory');
  var frequencySelect = document.getElementById('rcFrequency');
  var startDateField = document.getElementById('rcStartDateField');
  var startDateInput = document.getElementById('rcStartDate');
  var noteInput = document.getElementById('rcNote');
  var submitBtn = document.getElementById('rcSubmit');
  var deleteBtn = document.getElementById('rcDelete');
  var typeSeg = document.getElementById('rcTypeSeg');

  function currentType() {
    var active = typeSeg.querySelector('.segmented-btn.active');
    return active ? active.dataset.type : 'expense';
  }

  function setType(type, selectedCategoryId) {
    Array.prototype.forEach.call(typeSeg.children, function (btn) {
      btn.classList.toggle('active', btn.dataset.type === type);
    });
    populateCategorySelect(type, selectedCategoryId);
  }

  typeSeg.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.segmented-btn');
    if (!btn) return;
    setType(btn.dataset.type, null);
  });

  function setMode(mode) {
    var radios = form.querySelectorAll('input[name="rcMode"]');
    Array.prototype.forEach.call(radios, function (r) { r.checked = r.value === mode; });
  }

  function currentMode() {
    var checked = form.querySelector('input[name="rcMode"]:checked');
    return checked ? checked.value : 'reminder';
  }

  function openSheet(mode, row) {
    msgEl.textContent = '';
    form.reset();
    populateAccountSelect();
    deleteBtn.hidden = true;

    if (mode === 'edit' && row) {
      sheetTitle.textContent = 'Edit Recurring';
      idInput.value = row.id;
      setType(row.type, row.category_id);
      amountInput.value = Math.round(Number(row.amount));
      accountSelect.value = row.account_id;
      frequencySelect.value = row.frequency;
      noteInput.value = row.note || '';
      setMode(row.mode);
      // Tanggal mulai TIDAK diedit lewat sheet ini (anchor_date/next_run
      // sudah berjalan) -- pola sama spt initial_balance di akun.php.
      startDateField.hidden = true;
      deleteBtn.hidden = false;
    } else {
      sheetTitle.textContent = 'Tambah Recurring';
      idInput.value = '';
      setType('expense', null);
      amountInput.value = '';
      if (accountsCache.length > 0) accountSelect.value = accountsCache[0].id;
      frequencySelect.value = 'monthly';
      startDateInput.value = today;
      startDateField.hidden = false;
      noteInput.value = '';
      setMode('reminder');
    }

    overlay.hidden = false;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { overlay.classList.add('show'); });
    });
  }

  function closeSheet() {
    overlay.classList.remove('show');
    setTimeout(function () { overlay.hidden = true; }, 180);
  }

  document.getElementById('fabAdd').addEventListener('click', function () {
    openSheet('add', null);
  });
  document.getElementById('rcCancel').addEventListener('click', closeSheet);
  overlay.addEventListener('click', function (ev) {
    if (ev.target === overlay) closeSheet();
  });

  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    msgEl.textContent = '';
    submitBtn.disabled = true;

    var payload = {
      type: currentType(),
      amount: amountInput.value,
      account_id: accountSelect.value,
      category_id: categorySelect.value,
      frequency: frequencySelect.value,
      mode: currentMode(),
      note: noteInput.value.trim(),
    };

    var action;
    if (idInput.value) {
      action = 'update';
      payload.id = idInput.value;
    } else {
      action = 'create';
      payload.start_date = startDateInput.value;
    }

    try {
      await api('api/recurring.php?a=' + action, payload);
      closeSheet();
      toast('Recurring tersimpan');
      load();
    } catch (err) {
      msgEl.textContent = err.message;
    } finally {
      submitBtn.disabled = false;
    }
  });

  deleteBtn.addEventListener('click', async function () {
    var id = idInput.value;
    if (!id) return;
    var ok = await lmConfirm('Hapus recurring ini? Transaksi yang sudah tercatat sebelumnya tidak ikut terhapus.', 'Konfirmasi');
    if (!ok) return;
    try {
      await api('api/recurring.php?a=delete', { id: id });
      closeSheet();
      toast('Recurring dihapus');
      load();
    } catch (err) {
      msgEl.textContent = err.message;
    }
  });

  // ---------- init ----------
  // Ditunda ke DOMContentLoaded: script inline ini dicetak SEBELUM
  // assets/app.js (yg mendefinisikan window.api) dimuat oleh pageFooter().
  // Pola sama spt public/transaksi.php & public/budget.php.

  document.addEventListener('DOMContentLoaded', function () {
    loadRefData().then(load);
  });
})();
</script>
<?php
pageFooter('lainnya');
