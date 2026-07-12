<?php
// Halaman Budget: selector bulan (chevron), kartu total (anggaran vs
// terpakai), list per kategori ber-anggaran dgn progress bar (hijau/kuning
// >=80%/merah >100%), sheet set/edit nominal, "Salin dari bulan lalu" saat
// period kosong, seksi "Belum dianggarkan". Semua data (list per period)
// dimuat via JS -- halaman ini cuma shell + <script>.

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';

$user = requireLogin();
$initialPeriod = date('Y-m');

pageHeader('Budget', $user);
?>
<div class="bg-month-nav">
  <button type="button" class="bg-month-btn" id="bgPrevMonth" aria-label="Bulan sebelumnya">‹</button>
  <span class="bg-month-label" id="bgMonthLabel">-</span>
  <button type="button" class="bg-month-btn" id="bgNextMonth" aria-label="Bulan berikutnya">›</button>
</div>

<section class="card bg-total-card">
  <p class="bg-total-label">Total Anggaran</p>
  <p class="bg-total-row">
    <span class="bg-total-spent" id="bgTotalSpent">Rp 0</span>
    <span class="bg-total-sep">/</span>
    <span class="bg-total-amount" id="bgTotalAmount">Rp 0</span>
  </p>
  <div class="bg-progress"><div class="bg-progress-bar" id="bgTotalBar"></div></div>
</section>

<button type="button" class="btn-secondary bg-copy-btn" id="bgCopyBtn" hidden>Salin dari bulan lalu</button>

<section class="bg-list" id="bgList"></section>
<p class="bg-empty" id="bgEmpty" hidden>Belum ada anggaran bulan ini.</p>

<section id="bgUnbudgetedSection" hidden>
  <h2 class="bg-section-title">Belum dianggarkan</h2>
  <div class="bg-list" id="bgUnbudgetedList"></div>
</section>

<div class="sheet-overlay" id="bgSheetOverlay" hidden>
  <div class="sheet">
    <h2 class="sheet-title" id="bgSheetTitle">Atur Anggaran</h2>
    <form id="bgForm">
      <input type="hidden" id="bgCategoryId" value="">
      <label class="field">
        <span>Nominal Anggaran per Bulan</span>
        <input type="number" id="bgAmount" class="amount-input" min="0" step="1000" placeholder="0" required>
      </label>
      <p class="sheet-msg" id="bgSheetMsg"></p>
      <div class="sheet-actions">
        <button type="button" class="btn-danger-link" id="bgDelete" hidden>Hapus</button>
        <span class="sheet-actions-spacer"></span>
        <button type="button" class="btn-secondary" id="bgCancel">Batal</button>
        <button type="submit" class="btn-primary" id="bgSubmit">Simpan</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  'use strict';

  var MONTH_NAMES = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

  var state = { period: <?= json_encode($initialPeriod) ?> };

  var monthLabel = document.getElementById('bgMonthLabel');
  var totalSpentEl = document.getElementById('bgTotalSpent');
  var totalAmountEl = document.getElementById('bgTotalAmount');
  var totalBarEl = document.getElementById('bgTotalBar');
  var copyBtn = document.getElementById('bgCopyBtn');
  var listEl = document.getElementById('bgList');
  var emptyEl = document.getElementById('bgEmpty');
  var unbudgetedSection = document.getElementById('bgUnbudgetedSection');
  var unbudgetedList = document.getElementById('bgUnbudgetedList');

  var overlay = document.getElementById('bgSheetOverlay');
  var form = document.getElementById('bgForm');
  var sheetTitle = document.getElementById('bgSheetTitle');
  var msgEl = document.getElementById('bgSheetMsg');
  var categoryIdInput = document.getElementById('bgCategoryId');
  var amountInput = document.getElementById('bgAmount');
  var submitBtn = document.getElementById('bgSubmit');
  var deleteBtn = document.getElementById('bgDelete');

  // ---------- format bulan & navigasi ----------

  function periodLabel(period) {
    var parts = period.split('-');
    var y = parseInt(parts[0], 10);
    var m = parseInt(parts[1], 10);
    return MONTH_NAMES[m - 1] + ' ' + y;
  }

  function shiftPeriod(period, delta) {
    var parts = period.split('-');
    var y = parseInt(parts[0], 10);
    var m = parseInt(parts[1], 10) - 1 + delta;
    y += Math.floor(m / 12);
    m = ((m % 12) + 12) % 12;
    var mm = (m + 1) < 10 ? '0' + (m + 1) : String(m + 1);
    return y + '-' + mm;
  }

  // ---------- progress bar ----------

  function barClass(pct) {
    if (pct > 100) return 'bg-progress-bar-danger';
    if (pct >= 80) return 'bg-progress-bar-warn';
    return 'bg-progress-bar-ok';
  }

  // ---------- render baris kategori (ber-anggaran / belum) ----------

  function categoryRowEl(item, isBudgeted) {
    var row = document.createElement('div');
    row.className = 'bg-row';
    row.dataset.id = isBudgeted ? item.category_id : item.id;
    row.dataset.name = item.name;
    row.dataset.amount = isBudgeted ? item.amount : '';

    var icon = document.createElement('span');
    icon.className = 'bg-icon';
    icon.style.background = (item.color || '#94A3B8') + '22';
    icon.textContent = item.icon || '🏷️';

    var body = document.createElement('div');
    body.className = 'bg-body';

    var top = document.createElement('div');
    top.className = 'bg-row-top';
    var nameEl = document.createElement('span');
    nameEl.className = 'bg-name';
    nameEl.textContent = item.name;
    top.appendChild(nameEl);

    if (isBudgeted) {
      var amountsEl = document.createElement('span');
      amountsEl.className = 'bg-amounts';
      amountsEl.textContent = rupiahFmt(item.spent) + ' / ' + rupiahFmt(item.amount);
      top.appendChild(amountsEl);
    } else {
      var addEl = document.createElement('span');
      addEl.className = 'bg-add-hint';
      addEl.textContent = 'Atur';
      top.appendChild(addEl);
    }
    body.appendChild(top);

    if (isBudgeted) {
      var track = document.createElement('div');
      track.className = 'bg-progress';
      var bar = document.createElement('div');
      bar.className = 'bg-progress-bar ' + barClass(item.pct);
      bar.style.width = Math.min(100, item.pct) + '%';
      track.appendChild(bar);
      body.appendChild(track);

      if (item.pct > 100) {
        var over = document.createElement('span');
        over.className = 'bg-over-label';
        over.textContent = 'Lewat ' + rupiahFmt(item.spent - item.amount);
        body.appendChild(over);
      }
    }

    row.appendChild(icon);
    row.appendChild(body);
    return row;
  }

  // ---------- render halaman dari hasil list ----------

  function render(json) {
    monthLabel.textContent = periodLabel(state.period);

    var budgets = json.budgets || [];
    var unbudgeted = json.unbudgeted || [];

    var totalAmount = 0;
    var totalSpent = 0;
    budgets.forEach(function (b) {
      totalAmount += Number(b.amount) || 0;
      totalSpent += Number(b.spent) || 0;
    });
    totalAmountEl.textContent = rupiahFmt(totalAmount);
    totalSpentEl.textContent = rupiahFmt(totalSpent);
    var totalPct = totalAmount > 0 ? Math.round((totalSpent / totalAmount) * 100) : 0;
    totalBarEl.className = 'bg-progress-bar ' + barClass(totalPct);
    totalBarEl.style.width = Math.min(100, totalPct) + '%';

    listEl.innerHTML = '';
    budgets.forEach(function (b) {
      listEl.appendChild(categoryRowEl(b, true));
    });
    emptyEl.hidden = budgets.length > 0;
    listEl.hidden = budgets.length === 0;
    copyBtn.hidden = budgets.length > 0;

    unbudgetedList.innerHTML = '';
    unbudgeted.forEach(function (c) {
      unbudgetedList.appendChild(categoryRowEl(c, false));
    });
    unbudgetedSection.hidden = unbudgeted.length === 0;
  }

  function load() {
    return api('api/budget.php?a=list', { period: state.period }).then(function (json) {
      render(json);
    }).catch(function (err) {
      toast(err.message);
    });
  }

  document.getElementById('bgPrevMonth').addEventListener('click', function () {
    state.period = shiftPeriod(state.period, -1);
    load();
  });
  document.getElementById('bgNextMonth').addEventListener('click', function () {
    state.period = shiftPeriod(state.period, 1);
    load();
  });

  copyBtn.addEventListener('click', async function () {
    try {
      var json = await api('api/budget.php?a=copy_prev', { period: state.period });
      toast(json.copied > 0 ? 'Tersalin ' + json.copied + ' anggaran dari bulan lalu' : 'Tidak ada anggaran bulan lalu utk disalin');
      load();
    } catch (err) {
      toast(err.message);
    }
  });

  // ---------- sheet set/edit nominal ----------

  function openSheet(id, name, amount, isBudgeted) {
    msgEl.textContent = '';
    form.reset();
    sheetTitle.textContent = name;
    categoryIdInput.value = id;
    amountInput.value = isBudgeted ? amount : '';
    deleteBtn.hidden = !isBudgeted;

    overlay.hidden = false;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { overlay.classList.add('show'); });
    });
    setTimeout(function () { amountInput.focus(); }, 200);
  }

  function closeSheet() {
    overlay.classList.remove('show');
    setTimeout(function () { overlay.hidden = true; }, 180);
  }

  document.getElementById('bgCancel').addEventListener('click', closeSheet);
  overlay.addEventListener('click', function (ev) {
    if (ev.target === overlay) closeSheet();
  });

  listEl.addEventListener('click', function (ev) {
    var row = ev.target.closest('.bg-row');
    if (!row) return;
    openSheet(row.dataset.id, row.dataset.name, row.dataset.amount, true);
  });

  unbudgetedList.addEventListener('click', function (ev) {
    var row = ev.target.closest('.bg-row');
    if (!row) return;
    openSheet(row.dataset.id, row.dataset.name, '', false);
  });

  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    msgEl.textContent = '';
    submitBtn.disabled = true;
    try {
      await api('api/budget.php?a=set', {
        category_id: categoryIdInput.value,
        period: state.period,
        amount: amountInput.value,
      });
      closeSheet();
      toast('Anggaran tersimpan');
      load();
    } catch (err) {
      msgEl.textContent = err.message;
    } finally {
      submitBtn.disabled = false;
    }
  });

  deleteBtn.addEventListener('click', async function () {
    var ok = await lmConfirm('Hapus anggaran "' + sheetTitle.textContent + '"?', 'Konfirmasi');
    if (!ok) return;
    try {
      await api('api/budget.php?a=set', {
        category_id: categoryIdInput.value,
        period: state.period,
        amount: 0,
      });
      closeSheet();
      toast('Anggaran dihapus');
      load();
    } catch (err) {
      msgEl.textContent = err.message;
    }
  });

  // Ditunda ke DOMContentLoaded: script inline ini dicetak SEBELUM
  // assets/app.js (yg mendefinisikan window.api) dimuat oleh pageFooter() --
  // manggil api() langsung di sini (bukan dari handler klik) akan gagal krn
  // api belum ada. DOMContentLoaded aman krn baru terpicu setelah semua
  // <script> sinkron (termasuk app.js di bawah) selesai jalan. Pola sama spt
  // public/transaksi.php.
  document.addEventListener('DOMContentLoaded', load);
})();
</script>
<?php
pageFooter('budget');
