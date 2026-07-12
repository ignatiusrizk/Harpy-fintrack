<?php
// Halaman Laporan: tab Bulanan|Tahunan(+Laba-Rugi kalau space aktif business),
// selector periode (chevron bulan utk Bulanan/Laba-Rugi, chevron tahun utk
// Tahunan), kartu Total, seksi Per Kategori (expense/beban dulu -- collapsible
// parent->anak lewat tap baris induk, pct dari total type-nya sendiri -- lalu
// income/pendapatan), seksi Per Akun (disembunyikan di tab Laba-Rugi, API pnl
// tidak punya per_akun), tombol Export CSV (buka export.php GET biasa sesuai
// rentang tanggal periode aktif, BUKAN lewat window.api() -- lihat komentar
// public/export.php). Semua data dimuat via JS -- halaman ini cuma shell +
// <script>, pola sama spt public/budget.php.

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/laporan.php';
require_once __DIR__ . '/../core/layout.php';

$user = requireLogin();
$spaceId = currentSpaceId();
$isBusiness = laporanSpaceType($spaceId) === 'business';

pageHeader('Laporan', $user);
?>
<div class="segmented" id="lpTabs">
  <button type="button" class="segmented-btn active" data-tab="monthly">Bulanan</button>
  <button type="button" class="segmented-btn" data-tab="yearly">Tahunan</button>
<?php if ($isBusiness): ?>
  <button type="button" class="segmented-btn" data-tab="pnl">Laba-Rugi</button>
<?php endif; ?>
</div>

<?php if ($isBusiness): ?>
<!-- Sub-toggle mode Laba-Rugi: laporanPnl() menerima period YYYY-MM ATAU
     year YYYY -- toggle ini menentukan param mana yg dikirim + arah chevron
     periode. HANYA tampil saat tab Laba-Rugi aktif. -->
<div class="segmented lp-pnl-mode" id="lpPnlMode" hidden>
  <button type="button" class="segmented-btn active" data-mode="monthly">Bulanan</button>
  <button type="button" class="segmented-btn" data-mode="yearly">Tahunan</button>
</div>
<?php endif; ?>

<div class="bg-month-nav">
  <button type="button" class="bg-month-btn" id="lpPrev" aria-label="Periode sebelumnya">‹</button>
  <span class="bg-month-label" id="lpPeriodLabel">-</span>
  <button type="button" class="bg-month-btn" id="lpNext" aria-label="Periode berikutnya">›</button>
</div>

<section class="card lp-total-card">
  <div class="lp-total-row">
    <span class="bg-total-label" id="lpTotalIncomeLabel">Pemasukan</span>
    <span class="lp-total-value lp-total-income" id="lpTotalIncome">Rp 0</span>
  </div>
  <div class="lp-total-row">
    <span class="bg-total-label" id="lpTotalExpenseLabel">Pengeluaran</span>
    <span class="lp-total-value lp-total-expense" id="lpTotalExpense">Rp 0</span>
  </div>
  <div class="lp-total-row lp-total-net-row">
    <span class="bg-total-label" id="lpNetLabel">Selisih</span>
    <span class="lp-net-value" id="lpNet">Rp 0</span>
  </div>
</section>

<button type="button" class="btn-secondary lp-export-btn" id="lpExportBtn">⬇ Export CSV</button>

<p class="bg-section-title" id="lpExpenseTitle">Pengeluaran per Kategori</p>
<section class="bg-list" id="lpExpenseList"></section>
<p class="bg-empty" id="lpExpenseEmpty" hidden>Belum ada pengeluaran pada periode ini.</p>

<p class="bg-section-title" id="lpIncomeTitle">Pemasukan per Kategori</p>
<section class="bg-list" id="lpIncomeList"></section>
<p class="bg-empty" id="lpIncomeEmpty" hidden>Belum ada pemasukan pada periode ini.</p>

<section id="lpAkunSection">
  <p class="bg-section-title">Per Akun</p>
  <div class="lp-akun-table-wrap">
    <table class="lp-akun-table">
      <thead>
        <tr><th>Akun</th><th>Masuk</th><th>Keluar</th><th>Net</th></tr>
      </thead>
      <tbody id="lpAkunBody"></tbody>
    </table>
  </div>
  <p class="bg-empty" id="lpAkunEmpty" hidden>Belum ada akun.</p>
</section>

<script>
(function () {
  'use strict';

  var MONTH_NAMES = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

  function pad2(n) { return String(n).length < 2 ? '0' + n : String(n); }

  var now = new Date();
  var state = {
    tab: 'monthly',
    period: now.getFullYear() + '-' + pad2(now.getMonth() + 1),
    year: now.getFullYear(),
    pnlMode: 'monthly', // mode sub-toggle tab Laba-Rugi: 'monthly'|'yearly'
  };

  // Tampilan tahunan aktif? true utk tab Tahunan, ATAU tab Laba-Rugi dgn
  // sub-toggle Tahunan -- dipakai bareng oleh label periode, arah chevron,
  // rentang export, & payload API.
  function isYearlyView() {
    return state.tab === 'yearly' || (state.tab === 'pnl' && state.pnlMode === 'yearly');
  }

  function shiftPeriod(period, delta) {
    var parts = period.split('-');
    var y = parseInt(parts[0], 10);
    var m = parseInt(parts[1], 10) - 1 + delta;
    y += Math.floor(m / 12);
    m = ((m % 12) + 12) % 12;
    return y + '-' + pad2(m + 1);
  }

  function periodLabel(period) {
    var parts = period.split('-');
    return MONTH_NAMES[parseInt(parts[1], 10) - 1] + ' ' + parts[0];
  }

  function lastDayOfPeriod(period) {
    var parts = period.split('-');
    var y = parseInt(parts[0], 10);
    var m = parseInt(parts[1], 10);
    return pad2(new Date(y, m, 0).getDate());
  }

  function currentRange() {
    if (isYearlyView()) {
      return { from: state.year + '-01-01', to: state.year + '-12-31' };
    }
    return { from: state.period + '-01', to: state.period + '-' + lastDayOfPeriod(state.period) };
  }

  // ---------- tab & selector periode ----------

  var tabsEl = document.getElementById('lpTabs');
  var periodLabelEl = document.getElementById('lpPeriodLabel');
  var pnlModeEl = document.getElementById('lpPnlMode'); // null di space personal

  function updateNavLabel() {
    periodLabelEl.textContent = isYearlyView() ? String(state.year) : periodLabel(state.period);
  }

  function applyTabLabels() {
    var isPnl = state.tab === 'pnl';
    if (pnlModeEl) pnlModeEl.hidden = !isPnl;
    document.getElementById('lpAkunSection').hidden = isPnl;
    document.getElementById('lpTotalIncomeLabel').textContent = isPnl ? 'Pendapatan' : 'Pemasukan';
    document.getElementById('lpTotalExpenseLabel').textContent = isPnl ? 'Beban' : 'Pengeluaran';
    document.getElementById('lpNetLabel').textContent = isPnl ? 'Laba Bersih' : 'Selisih';
    document.getElementById('lpExpenseTitle').textContent = isPnl ? 'Beban per Kategori' : 'Pengeluaran per Kategori';
    document.getElementById('lpIncomeTitle').textContent = isPnl ? 'Pendapatan per Kategori' : 'Pemasukan per Kategori';
  }

  tabsEl.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.segmented-btn');
    if (!btn || btn.classList.contains('active')) return;
    Array.prototype.forEach.call(tabsEl.children, function (b) { b.classList.toggle('active', b === btn); });
    state.tab = btn.dataset.tab;
    applyTabLabels();
    updateNavLabel();
    load();
  });

  if (pnlModeEl) {
    pnlModeEl.addEventListener('click', function (ev) {
      var btn = ev.target.closest('.segmented-btn');
      if (!btn || btn.classList.contains('active')) return;
      Array.prototype.forEach.call(pnlModeEl.children, function (b) { b.classList.toggle('active', b === btn); });
      state.pnlMode = btn.dataset.mode;
      updateNavLabel();
      load();
    });
  }

  document.getElementById('lpPrev').addEventListener('click', function () {
    if (isYearlyView()) { state.year -= 1; } else { state.period = shiftPeriod(state.period, -1); }
    updateNavLabel();
    load();
  });
  document.getElementById('lpNext').addEventListener('click', function () {
    if (isYearlyView()) { state.year += 1; } else { state.period = shiftPeriod(state.period, 1); }
    updateNavLabel();
    load();
  });

  // ---------- render: per kategori (collapsible parent->anak) ----------

  function categoryItemEl(item, totalForType) {
    var hasChildren = (item.children || []).length > 0;
    var wrap = document.createElement('div');
    wrap.className = 'lp-cat-item';

    var row = document.createElement('div');
    row.className = 'bg-row' + (hasChildren ? ' lp-row-parent' : '');

    var icon = document.createElement('span');
    icon.className = 'bg-icon';
    icon.style.background = (item.color || '#94A3B8') + '22';
    icon.textContent = item.icon || (item.category_id === null ? '❓' : '🏷️');

    var body = document.createElement('div');
    body.className = 'bg-body';

    var top = document.createElement('div');
    top.className = 'bg-row-top';

    var nameWrap = document.createElement('span');
    nameWrap.className = 'lp-name-wrap';
    var chev = null;
    if (hasChildren) {
      chev = document.createElement('span');
      chev.className = 'lp-chevron';
      chev.textContent = '›';
      nameWrap.appendChild(chev);
    }
    var nameEl = document.createElement('span');
    nameEl.className = 'bg-name';
    nameEl.textContent = item.name;
    nameWrap.appendChild(nameEl);
    top.appendChild(nameWrap);

    var amountsEl = document.createElement('span');
    amountsEl.className = 'bg-amounts';
    var pct = totalForType > 0 ? Math.round((Number(item.amount) / totalForType) * 100) : 0;
    amountsEl.textContent = rupiahFmt(item.amount) + ' · ' + pct + '%';
    top.appendChild(amountsEl);

    body.appendChild(top);
    row.appendChild(icon);
    row.appendChild(body);
    wrap.appendChild(row);

    if (hasChildren) {
      var childrenWrap = document.createElement('div');
      childrenWrap.className = 'lp-children';
      childrenWrap.hidden = true;
      item.children.forEach(function (child) {
        var childRow = document.createElement('div');
        childRow.className = 'lp-child-row';
        var childName = document.createElement('span');
        childName.className = 'lp-child-name';
        childName.textContent = (child.icon || '🏷️') + ' ' + child.name;
        var childAmount = document.createElement('span');
        childAmount.className = 'lp-child-amount';
        childAmount.textContent = rupiahFmt(child.amount);
        childRow.appendChild(childName);
        childRow.appendChild(childAmount);
        childrenWrap.appendChild(childRow);
      });
      wrap.appendChild(childrenWrap);

      row.addEventListener('click', function () {
        childrenWrap.hidden = !childrenWrap.hidden;
        chev.classList.toggle('lp-chevron-open', !childrenWrap.hidden);
      });
    }

    return wrap;
  }

  function renderCategoryList(listId, emptyId, items, totalForType) {
    var listEl = document.getElementById(listId);
    var emptyEl = document.getElementById(emptyId);
    listEl.innerHTML = '';
    items.forEach(function (item) { listEl.appendChild(categoryItemEl(item, totalForType)); });
    emptyEl.hidden = items.length > 0;
    listEl.hidden = items.length === 0;
  }

  // ---------- render: per akun ----------

  function renderAkun(rows) {
    var tbody = document.getElementById('lpAkunBody');
    var emptyEl = document.getElementById('lpAkunEmpty');
    tbody.innerHTML = '';
    rows.forEach(function (r) {
      var tr = document.createElement('tr');

      var tdName = document.createElement('td');
      tdName.textContent = r.name;

      var tdMasuk = document.createElement('td');
      tdMasuk.className = 'lp-akun-in';
      tdMasuk.textContent = rupiahFmt(r.masuk);

      var tdKeluar = document.createElement('td');
      tdKeluar.className = 'lp-akun-out';
      tdKeluar.textContent = rupiahFmt(r.keluar);

      var tdNet = document.createElement('td');
      tdNet.className = Number(r.net) < 0 ? 'lp-akun-net-neg' : 'lp-akun-net-pos';
      tdNet.textContent = rupiahFmt(r.net);

      tr.appendChild(tdName);
      tr.appendChild(tdMasuk);
      tr.appendChild(tdKeluar);
      tr.appendChild(tdNet);
      tbody.appendChild(tr);
    });
    document.querySelector('.lp-akun-table-wrap').hidden = rows.length === 0;
    emptyEl.hidden = rows.length > 0;
  }

  // ---------- render utama: normalisasi struktur monthly/yearly vs pnl ----------

  function render(report) {
    var expenseItems, incomeItems, totalIncome, totalExpense, netVal, akunRows;

    if (state.tab === 'pnl') {
      expenseItems = report.expense || [];
      incomeItems = report.income || [];
      totalExpense = expenseItems.reduce(function (s, i) { return s + Number(i.amount); }, 0);
      totalIncome = incomeItems.reduce(function (s, i) { return s + Number(i.amount); }, 0);
      netVal = Number(report.net) || 0;
      akunRows = [];
    } else {
      var perKategori = report.per_kategori || { income: [], expense: [] };
      expenseItems = perKategori.expense || [];
      incomeItems = perKategori.income || [];
      totalIncome = Number(report.total.income) || 0;
      totalExpense = Number(report.total.expense) || 0;
      netVal = Number(report.total.net) || 0;
      akunRows = report.per_akun || [];
    }

    document.getElementById('lpTotalIncome').textContent = rupiahFmt(totalIncome);
    document.getElementById('lpTotalExpense').textContent = rupiahFmt(totalExpense);
    var netEl = document.getElementById('lpNet');
    netEl.textContent = rupiahFmt(netVal);
    netEl.className = 'lp-net-value ' + (netVal < 0 ? 'lp-net-neg' : 'lp-net-pos');

    renderCategoryList('lpExpenseList', 'lpExpenseEmpty', expenseItems, totalExpense);
    renderCategoryList('lpIncomeList', 'lpIncomeEmpty', incomeItems, totalIncome);
    renderAkun(akunRows);
  }

  function load() {
    var action = state.tab;
    // ?a=pnl menerima SALAH SATU period/year (lihat api/laporan.php) --
    // tampilan tahunan (tab Tahunan atau pnl mode tahunan) kirim year.
    var payload = isYearlyView() ? { year: state.year } : { period: state.period };
    return api('api/laporan.php?a=' + action, payload).then(function (json) {
      render(json.report);
    }).catch(function (err) {
      toast(err.message);
    });
  }

  // ---------- export CSV ----------

  document.getElementById('lpExportBtn').addEventListener('click', function () {
    var range = currentRange();
    var url = 'export.php?from=' + encodeURIComponent(range.from) + '&to=' + encodeURIComponent(range.to);
    window.open(url, '_blank');
  });

  // ---------- init ----------
  // Ditunda ke DOMContentLoaded: script inline ini dicetak SEBELUM
  // assets/app.js (yg mendefinisikan window.api) dimuat oleh pageFooter().
  // Pola sama spt public/budget.php.

  document.addEventListener('DOMContentLoaded', function () {
    applyTabLabels();
    updateNavLabel();
    load();
  });
})();
</script>
<?php
pageFooter('lainnya');
