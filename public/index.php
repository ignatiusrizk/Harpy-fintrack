<?php
// Dashboard: sapaan + ringkasan agregat SATU panggilan (public/api/dashboard.php
// ?a=summary) -- kartu Net Worth (tap -> akun.php), Bulan Ini, Tagihan
// Mendatang (maks 5, link recurring.php, sembunyi kalau kosong), bar chart
// arus kas 6 bulan (charts.js barChart()), donut top kategori expense bulan
// ini (charts.js donutChart(), sembunyi kalau belum ada expense), Portfolio
// ringkas (link investasi.php, sembunyi kalau belum ada aset), Laba/Rugi
// bulan ini (HANYA ruang type business). Semua data dimuat via JS (pola sama
// spt public/investasi.php dst.) -- halaman ini cuma shell + <script>, plus
// sapaan/tanggal yg sudah bisa dirender langsung dari server (tidak perlu
// tunggu fetch).

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';

$user = requireLogin();

const DB_HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
const DB_BULAN = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];
$today = getdate();
$tanggalIndonesia = DB_HARI[$today['wday']] . ', ' . $today['mday'] . ' ' . DB_BULAN[$today['mon']] . ' ' . $today['year'];

pageHeader('Dashboard', $user);
?>
<div id="dbRoot" class="db-loading">

<section class="card">
  <p class="greet">Halo, <strong><?= e($user['name']) ?></strong> 👋</p>
  <p class="greet-sub"><?= e($tanggalIndonesia) ?></p>
</section>

<a href="akun.php" class="card db-tap-card">
  <p class="balance-label">Net Worth</p>
  <p class="balance-value" id="dbNetWorth">Rp 0</p>
  <p class="balance-note">Total seluruh akun &amp; investasi Anda</p>
</a>

<section class="card">
  <p class="tx-summary-period">Bulan Ini</p>
  <div class="db-month-row">
    <div class="db-month-col">
      <p class="tx-summary-label">Masuk</p>
      <p class="tx-summary-value tx-amount-income" id="dbMonthIncome">Rp 0</p>
    </div>
    <div class="db-month-col db-month-col-center">
      <p class="tx-summary-label">Keluar</p>
      <p class="tx-summary-value tx-amount-expense" id="dbMonthExpense">Rp 0</p>
    </div>
    <div class="db-month-col db-month-col-right">
      <p class="tx-summary-label">Selisih</p>
      <p class="tx-summary-value" id="dbMonthNet">Rp 0</p>
    </div>
  </div>
</section>

<section class="card" id="dbDueCard" hidden>
  <p class="tx-summary-period">Tagihan Mendatang</p>
  <div class="db-due-list" id="dbDueList"></div>
  <a href="recurring.php" class="db-card-link">Lihat semua →</a>
</section>

<section class="card">
  <p class="tx-summary-period">Arus Kas 6 Bulan</p>
  <div class="db-cashflow-wrap"><div id="dbCashflowChart"></div></div>
  <div class="db-chart-legend">
    <span class="db-chart-legend-item"><span class="db-chart-legend-dot" style="background:#16A34A"></span>Masuk</span>
    <span class="db-chart-legend-item"><span class="db-chart-legend-dot" style="background:#E11D48"></span>Keluar</span>
  </div>
</section>

<section class="card" id="dbCatCard" hidden>
  <p class="tx-summary-period">Top Kategori Bulan Ini</p>
  <div class="pt-donut-wrap">
    <div class="pt-donut-svg-holder" id="dbCatDonut"></div>
    <div class="pt-legend" id="dbCatLegend"></div>
  </div>
</section>

<a href="investasi.php" class="card db-tap-card" id="dbPtCard" hidden>
  <p class="balance-label">Portfolio</p>
  <p class="balance-value" id="dbPtValue">Rp 0</p>
  <p class="pt-total-gain" id="dbPtGain">–</p>
</a>

<section class="card" id="dbPnlCard" hidden>
  <p class="tx-summary-period">Laba/Rugi Bulan Ini</p>
  <p class="balance-value" id="dbPnlNet">Rp 0</p>
  <div class="db-month-row">
    <div class="db-month-col">
      <p class="tx-summary-label">Pendapatan</p>
      <p class="tx-summary-value tx-amount-income" id="dbPnlIncome">Rp 0</p>
    </div>
    <div class="db-month-col db-month-col-right">
      <p class="tx-summary-label">Beban</p>
      <p class="tx-summary-value tx-amount-expense" id="dbPnlExpense">Rp 0</p>
    </div>
  </div>
</section>

</div>

<script src="assets/charts.js"></script>
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
    return d.getDate() + ' ' + MONTH_SHORT[d.getMonth()];
  }

  function signClass(v) {
    return v > 0 ? 'pt-positive' : (v < 0 ? 'pt-negative' : '');
  }

  var root = document.getElementById('dbRoot');
  var netWorthEl = document.getElementById('dbNetWorth');

  var monthIncomeEl = document.getElementById('dbMonthIncome');
  var monthExpenseEl = document.getElementById('dbMonthExpense');
  var monthNetEl = document.getElementById('dbMonthNet');

  var dueCard = document.getElementById('dbDueCard');
  var dueList = document.getElementById('dbDueList');

  var cashflowChartEl = document.getElementById('dbCashflowChart');

  var catCard = document.getElementById('dbCatCard');
  var catDonut = document.getElementById('dbCatDonut');
  var catLegend = document.getElementById('dbCatLegend');

  var ptCard = document.getElementById('dbPtCard');
  var ptValueEl = document.getElementById('dbPtValue');
  var ptGainEl = document.getElementById('dbPtGain');

  var pnlCard = document.getElementById('dbPnlCard');
  var pnlNetEl = document.getElementById('dbPnlNet');
  var pnlIncomeEl = document.getElementById('dbPnlIncome');
  var pnlExpenseEl = document.getElementById('dbPnlExpense');

  function renderMonth(month) {
    monthIncomeEl.textContent = rupiahFmt(month.income);
    monthExpenseEl.textContent = rupiahFmt(month.expense);
    monthNetEl.textContent = rupiahFmt(month.net);
    monthNetEl.className = 'tx-summary-value ' + signClass(month.net);
  }

  function dueRowEl(row) {
    var el = document.createElement('div');
    el.className = 'db-due-row';

    var icon = document.createElement('span');
    icon.className = 'db-due-icon';
    icon.style.background = (row.category_color || '#94A3B8') + '22';
    icon.textContent = row.category_icon || '🏷️';

    var body = document.createElement('span');
    body.className = 'db-due-body';
    var title = document.createElement('span');
    title.className = 'db-due-title';
    title.textContent = row.note || row.category_name;
    var meta = document.createElement('span');
    meta.className = 'db-due-meta';
    var badge = document.createElement('span');
    badge.className = 'rc-badge' + (row.mode === 'auto' ? ' rc-badge-auto' : '');
    badge.textContent = row.mode === 'auto' ? 'Otomatis' : 'Pengingat';
    var dateLabel = document.createElement('span');
    dateLabel.textContent = shortDateLabel(row.next_run);
    meta.appendChild(badge);
    meta.appendChild(dateLabel);
    body.appendChild(title);
    body.appendChild(meta);

    var amount = document.createElement('span');
    amount.className = 'db-due-amount ' + (row.type === 'income' ? 'tx-amount-income' : 'tx-amount-expense');
    amount.textContent = (row.type === 'income' ? '+' : '-') + rupiahFmt(row.amount);

    el.appendChild(icon);
    el.appendChild(body);
    el.appendChild(amount);
    return el;
  }

  function renderUpcoming(list) {
    if (!list || list.length === 0) {
      dueCard.hidden = true;
      return;
    }
    dueCard.hidden = false;
    dueList.innerHTML = '';
    list.slice(0, 5).forEach(function (row) { dueList.appendChild(dueRowEl(row)); });
  }

  function renderCashflow(cashflow) {
    var labels = (cashflow || []).map(function (c) {
      var m = parseInt(c.period.split('-')[1], 10);
      return MONTH_SHORT[m - 1];
    });
    var incomeValues = (cashflow || []).map(function (c) { return c.income; });
    var expenseValues = (cashflow || []).map(function (c) { return c.expense; });

    barChart(cashflowChartEl, labels, [
      { label: 'Masuk', color: '#16A34A', values: incomeValues },
      { label: 'Keluar', color: '#E11D48', values: expenseValues },
    ]);
  }

  function renderTopCategories(list) {
    if (!list || list.length === 0) {
      catCard.hidden = true;
      return;
    }
    catCard.hidden = false;

    var items = list.map(function (c) {
      return { name: c.name, color: c.color || '#94A3B8', value: c.amount };
    });
    donutChart(catDonut, items);

    catLegend.innerHTML = '';
    list.forEach(function (c) {
      var item = document.createElement('div');
      item.className = 'pt-legend-item';
      var dot = document.createElement('span');
      dot.className = 'pt-legend-dot';
      dot.style.background = c.color || '#94A3B8';
      var label = document.createElement('span');
      label.className = 'pt-legend-label';
      label.textContent = (c.icon ? c.icon + ' ' : '') + c.name;
      var amount = document.createElement('span');
      amount.className = 'pt-legend-pct';
      amount.textContent = rupiahFmt(c.amount);
      item.appendChild(dot);
      item.appendChild(label);
      item.appendChild(amount);
      catLegend.appendChild(item);
    });
  }

  function renderPortfolio(pt) {
    if (!pt) {
      ptCard.hidden = true;
      return;
    }
    ptCard.hidden = false;
    ptValueEl.textContent = rupiahFmt(pt.value);
    var sign = pt.gain > 0 ? '+' : '';
    ptGainEl.textContent = sign + rupiahFmt(pt.gain) + ' (' + sign + pt.gain_pct.toFixed(1) + '%)';
    ptGainEl.className = 'pt-total-gain ' + signClass(pt.gain);
  }

  function renderPnl(pnl) {
    if (!pnl) {
      pnlCard.hidden = true;
      return;
    }
    pnlCard.hidden = false;
    pnlNetEl.textContent = rupiahFmt(pnl.net);
    pnlNetEl.className = 'balance-value ' + signClass(pnl.net);
    pnlIncomeEl.textContent = rupiahFmt(pnl.income);
    pnlExpenseEl.textContent = rupiahFmt(pnl.expense);
  }

  function render(data) {
    netWorthEl.textContent = rupiahFmt(data.net_worth);
    renderMonth(data.month);
    renderUpcoming(data.upcoming);
    renderCashflow(data.cashflow);
    renderTopCategories(data.top_categories);
    renderPortfolio(data.portfolio);
    renderPnl(data.pnl);
  }

  async function load() {
    try {
      var json = await api('api/dashboard.php?a=summary', {});
      render(json);
    } catch (err) {
      toast(err.message);
    } finally {
      root.classList.remove('db-loading');
    }
  }

  // Ditunda ke DOMContentLoaded: script inline ini dicetak SEBELUM
  // assets/app.js (yg mendefinisikan window.api) dimuat oleh pageFooter().
  // Pola sama spt public/investasi.php dst.
  document.addEventListener('DOMContentLoaded', function () {
    load();
  });
})();
</script>
<?php
pageFooter('dashboard');
