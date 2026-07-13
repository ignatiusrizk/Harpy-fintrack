<?php
// Halaman Investasi (portfolio manual, Task 9): kartu total portfolio (value +
// gain hijau/merah), donut SVG alokasi per jenis aset (manual, tanpa lib) +
// legend, list aset (tap -> sheet detail: posisi, avg/last price, tombol
// Beli/Jual/Update Harga, riwayat 10 terakhir + hapus per baris, Edit/Hapus
// aset), FAB tambah aset. PENTING: transaksi investasi (beli/jual) TIDAK
// menyentuh tabel transactions/akun -- dana dianggap berasal/keluar dari luar
// aplikasi, dijelaskan di sheet Beli/Jual (lihat #ptTradeHint). Semua data
// dimuat via JS (pola sama spt public/goals.php & public/recurring.php) --
// halaman ini cuma shell + <script>. Diakses dari menu Lainnya -- $active
// tetap 'lainnya'.

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';

$user = requireLogin();

pageHeader('Investasi', $user);
?>
<section class="card pt-total-card">
  <p class="balance-label">Total Portfolio</p>
  <p class="balance-value" id="ptTotalValue">Rp 0</p>
  <p class="pt-total-gain" id="ptTotalGain">–</p>
  <p class="balance-note" id="ptTotalCostNote">Modal: Rp 0</p>
</section>

<section class="card pt-alloc-card" id="ptAllocCard" hidden>
  <p class="bg-section-title" style="margin-top:0">Alokasi Aset</p>
  <div class="pt-donut-wrap">
    <div class="pt-donut-svg-holder" id="ptDonutHolder"></div>
    <div class="pt-legend" id="ptLegend"></div>
  </div>
</section>

<p class="bg-section-title">Daftar Aset</p>
<section class="pt-list" id="ptList"></section>
<p class="pt-empty" id="ptEmpty" hidden>Belum ada aset investasi. Tambah lewat tombol + di bawah.</p>

<button type="button" class="fab" id="fabAdd" aria-label="Tambah aset">+</button>

<!-- Sheet: tambah/edit aset -->
<div class="sheet-overlay" id="ptAssetSheetOverlay" hidden>
  <div class="sheet">
    <h2 class="sheet-title" id="ptAssetSheetTitle">Tambah Aset</h2>
    <form id="ptAssetForm">
      <input type="hidden" id="ptAssetId" value="">

      <label class="field">
        <span>Nama Aset</span>
        <input type="text" id="ptAssetName" maxlength="100" placeholder="mis. BBCA, Reksa Dana ABC" required>
      </label>

      <label class="field">
        <span>Jenis</span>
        <select id="ptAssetType">
          <option value="stock">Saham</option>
          <option value="mutual_fund">Reksa Dana</option>
          <option value="gold">Emas</option>
          <option value="crypto">Crypto</option>
          <option value="deposit">Deposito</option>
          <option value="other">Lainnya</option>
        </select>
      </label>

      <label class="field">
        <span>Kode (opsional)</span>
        <input type="text" id="ptAssetCode" maxlength="30" placeholder="mis. BBCA">
      </label>

      <label class="field">
        <span>Label Unit</span>
        <input type="text" id="ptAssetUnitLabel" maxlength="20" placeholder="unit">
      </label>

      <p class="sheet-msg" id="ptAssetSheetMsg"></p>
      <div class="sheet-actions">
        <span class="sheet-actions-spacer"></span>
        <button type="button" class="btn-secondary" id="ptAssetCancel">Batal</button>
        <button type="submit" class="btn-primary" id="ptAssetSubmit">Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- Sheet: detail aset (posisi + aksi + riwayat) -->
<div class="sheet-overlay" id="ptDetailSheetOverlay" hidden>
  <div class="sheet pt-detail-sheet">
    <h2 class="sheet-title" id="ptDetailTitle">Aset</h2>
    <p class="pt-detail-sub" id="ptDetailSub"></p>

    <div class="pt-stat-grid">
      <div class="pt-stat"><span>Posisi</span><strong id="ptDetailUnits">0</strong></div>
      <div class="pt-stat"><span>Avg. Beli</span><strong id="ptDetailAvg">Rp 0</strong></div>
      <div class="pt-stat"><span>Harga Terakhir</span><strong id="ptDetailLast">Rp 0</strong></div>
      <div class="pt-stat"><span>Modal</span><strong id="ptDetailCost">Rp 0</strong></div>
      <div class="pt-stat"><span>Nilai Kini</span><strong id="ptDetailValue">Rp 0</strong></div>
      <div class="pt-stat"><span>Gain/Loss</span><strong id="ptDetailGain">Rp 0</strong></div>
    </div>

    <div class="pt-detail-actions">
      <button type="button" class="btn-secondary" id="ptDetailBuy">Beli</button>
      <button type="button" class="btn-secondary" id="ptDetailSell">Jual</button>
      <button type="button" class="btn-secondary" id="ptDetailPrice">Update Harga</button>
    </div>

    <p class="bg-section-title">Riwayat Terakhir</p>
    <div class="pt-hist-list" id="ptHistList"></div>
    <p class="pt-hist-empty" id="ptHistEmpty" hidden>Belum ada transaksi.</p>

    <p class="bg-section-title">Riwayat Harga</p>
    <div class="pt-price-list" id="ptPriceList"></div>
    <p class="pt-hist-empty" id="ptPriceEmpty" hidden>Belum ada harga manual.</p>

    <div class="sheet-actions pt-detail-footer">
      <button type="button" class="btn-danger-link" id="ptDetailDelete">Hapus Aset</button>
      <span class="sheet-actions-spacer"></span>
      <button type="button" class="btn-secondary" id="ptDetailEdit">Edit Aset</button>
      <button type="button" class="btn-primary" id="ptDetailClose">Tutup</button>
    </div>
  </div>
</div>

<!-- Sheet: beli/jual -->
<div class="sheet-overlay" id="ptTradeSheetOverlay" hidden>
  <div class="sheet">
    <h2 class="sheet-title" id="ptTradeSheetTitle">Beli</h2>
    <form id="ptTradeForm">
      <input type="hidden" id="ptTradeAssetId" value="">
      <input type="hidden" id="ptTradeSide" value="buy">

      <label class="field">
        <span id="ptTradeUnitsLabel">Unit</span>
        <input type="number" id="ptTradeUnits" min="0.00000001" step="0.00000001" placeholder="0" required>
      </label>

      <label class="field">
        <span>Harga per Unit</span>
        <input type="text" id="ptTradePrice" class="amount-input" placeholder="0" inputmode="numeric" required>
      </label>

      <label class="field">
        <span>Fee (opsional)</span>
        <input type="text" id="ptTradeFee" class="amount-input" placeholder="0" inputmode="numeric">
      </label>

      <label class="field">
        <span>Tanggal</span>
        <input type="date" id="ptTradeDate">
      </label>

      <p class="pt-trade-hint" id="ptTradeHint"></p>
      <p class="sheet-msg" id="ptTradeSheetMsg"></p>
      <div class="sheet-actions">
        <span class="sheet-actions-spacer"></span>
        <button type="button" class="btn-secondary" id="ptTradeCancel">Batal</button>
        <button type="submit" class="btn-primary" id="ptTradeSubmit">Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- Sheet: update harga -->
<div class="sheet-overlay" id="ptPriceSheetOverlay" hidden>
  <div class="sheet">
    <h2 class="sheet-title">Update Harga</h2>
    <form id="ptPriceForm">
      <input type="hidden" id="ptPriceAssetId" value="">

      <label class="field">
        <span>Harga per Unit</span>
        <input type="text" id="ptPricePrice" class="amount-input" placeholder="0" inputmode="numeric" required>
      </label>

      <label class="field">
        <span>Tanggal</span>
        <input type="date" id="ptPriceDate">
      </label>

      <p class="sheet-msg" id="ptPriceSheetMsg"></p>
      <div class="sheet-actions">
        <span class="sheet-actions-spacer"></span>
        <button type="button" class="btn-secondary" id="ptPriceCancel">Batal</button>
        <button type="submit" class="btn-primary" id="ptPriceSubmit">Simpan</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  'use strict';

  var TYPE_LABELS = { stock: 'Saham', mutual_fund: 'Reksa Dana', gold: 'Emas', crypto: 'Crypto', deposit: 'Deposito', other: 'Lainnya' };
  var TYPE_COLORS = { stock: '#3B82F6', mutual_fund: '#8B5CF6', gold: '#EAB308', crypto: '#F97316', deposit: '#16A34A', other: '#94A3B8' };

  var today = (function () {
    var d = new Date();
    var pad = function (n) { return String(n).length < 2 ? '0' + n : String(n); };
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  })();

  function unitFmt(n) {
    // Tampilkan unit tanpa nol desimal berlebih (mis. 15.00000000 -> "15",
    // 0.50500000 -> "0.505") -- data mentah tetap presisi penuh dari server.
    var num = Number(n) || 0;
    var s = num.toFixed(8).replace(/0+$/, '').replace(/\.$/, '');
    return s === '' ? '0' : s;
  }

  function gainClass(v) {
    return v > 0 ? 'pt-positive' : (v < 0 ? 'pt-negative' : '');
  }

  function gainText(gain, pct) {
    var sign = gain > 0 ? '+' : '';
    return sign + rupiahFmt(gain) + ' (' + sign + pct.toFixed(1) + '%)';
  }

  // ---------- kartu total + donut ----------

  var totalValueEl = document.getElementById('ptTotalValue');
  var totalGainEl = document.getElementById('ptTotalGain');
  var totalCostNoteEl = document.getElementById('ptTotalCostNote');
  var allocCard = document.getElementById('ptAllocCard');
  var donutHolder = document.getElementById('ptDonutHolder');
  var legendEl = document.getElementById('ptLegend');

  function renderTotals(summary) {
    totalValueEl.textContent = rupiahFmt(summary.total_value);
    totalGainEl.textContent = gainText(summary.total_gain, summary.total_gain_pct);
    totalGainEl.className = 'pt-total-gain ' + gainClass(summary.total_gain);
    totalCostNoteEl.textContent = 'Modal: ' + rupiahFmt(summary.total_cost);
  }

  function buildDonutSvg(allocation, totalValue) {
    var svgNS = 'http://www.w3.org/2000/svg';
    var size = 140, radius = 52, stroke = 20, cx = size / 2, cy = size / 2;
    var circumference = 2 * Math.PI * radius;

    var svg = document.createElementNS(svgNS, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + size + ' ' + size);
    svg.setAttribute('class', 'pt-donut-svg');

    var track = document.createElementNS(svgNS, 'circle');
    track.setAttribute('cx', cx);
    track.setAttribute('cy', cy);
    track.setAttribute('r', radius);
    track.setAttribute('fill', 'none');
    track.setAttribute('stroke', '#EEF2F3');
    track.setAttribute('stroke-width', stroke);
    svg.appendChild(track);

    var offset = 0;
    allocation.forEach(function (a) {
      var frac = totalValue > 0 ? (a.value / totalValue) : 0;
      if (frac <= 0) return;
      var segLen = frac * circumference;
      var circle = document.createElementNS(svgNS, 'circle');
      circle.setAttribute('cx', cx);
      circle.setAttribute('cy', cy);
      circle.setAttribute('r', radius);
      circle.setAttribute('fill', 'none');
      circle.setAttribute('stroke', TYPE_COLORS[a.type] || '#94A3B8');
      circle.setAttribute('stroke-width', stroke);
      circle.setAttribute('stroke-linecap', allocation.length === 1 ? 'butt' : 'round');
      circle.setAttribute('stroke-dasharray', segLen.toFixed(2) + ' ' + Math.max(0, circumference - segLen).toFixed(2));
      circle.setAttribute('stroke-dashoffset', (-offset).toFixed(2));
      circle.setAttribute('transform', 'rotate(-90 ' + cx + ' ' + cy + ')');
      svg.appendChild(circle);
      offset += segLen;
    });

    return svg;
  }

  function renderAllocation(allocation, totalValue) {
    if (!allocation || allocation.length === 0) {
      allocCard.hidden = true;
      return;
    }
    allocCard.hidden = false;

    donutHolder.innerHTML = '';
    donutHolder.appendChild(buildDonutSvg(allocation, totalValue));

    legendEl.innerHTML = '';
    allocation.forEach(function (a) {
      var item = document.createElement('div');
      item.className = 'pt-legend-item';
      var dot = document.createElement('span');
      dot.className = 'pt-legend-dot';
      dot.style.background = TYPE_COLORS[a.type] || '#94A3B8';
      var label = document.createElement('span');
      label.className = 'pt-legend-label';
      label.textContent = TYPE_LABELS[a.type] || a.type;
      var pct = document.createElement('span');
      pct.className = 'pt-legend-pct';
      pct.textContent = a.pct.toFixed(1) + '%';
      item.appendChild(dot);
      item.appendChild(label);
      item.appendChild(pct);
      legendEl.appendChild(item);
    });
  }

  // ---------- list aset ----------

  var listEl = document.getElementById('ptList');
  var emptyEl = document.getElementById('ptEmpty');
  var assetsCache = [];

  function assetRowEl(row) {
    var el = document.createElement('div');
    el.className = 'pt-asset-row';
    el.dataset.id = row.id;

    var dot = document.createElement('span');
    dot.className = 'pt-asset-dot';
    dot.style.background = TYPE_COLORS[row.type] || '#94A3B8';

    var body = document.createElement('span');
    body.className = 'pt-asset-body';
    var name = document.createElement('span');
    name.className = 'pt-asset-name';
    name.textContent = row.name + (row.code ? ' (' + row.code + ')' : '');
    var meta = document.createElement('span');
    meta.className = 'pt-asset-meta';
    meta.textContent = TYPE_LABELS[row.type] + ' · ' + unitFmt(row.units) + ' ' + row.unit_label;
    body.appendChild(name);
    body.appendChild(meta);

    var right = document.createElement('span');
    right.className = 'pt-asset-right';
    var value = document.createElement('span');
    value.className = 'pt-asset-value';
    value.textContent = rupiahFmt(row.value);
    var gain = document.createElement('span');
    gain.className = 'pt-asset-gain ' + gainClass(row.gain);
    var sign = row.gain > 0 ? '+' : '';
    gain.textContent = sign + row.gain_pct.toFixed(1) + '%';
    right.appendChild(value);
    right.appendChild(gain);

    el.appendChild(dot);
    el.appendChild(body);
    el.appendChild(right);

    el.addEventListener('click', function () { openDetailSheet(row.id); });
    return el;
  }

  function renderList(assets) {
    listEl.innerHTML = '';
    assets.forEach(function (row) { listEl.appendChild(assetRowEl(row)); });
    emptyEl.hidden = assets.length > 0;
  }

  function render(summary) {
    renderTotals(summary);
    renderAllocation(summary.allocation, summary.total_value);
    renderList(summary.assets);
  }

  function load() {
    return api('api/investasi.php?a=list', {}).then(function (json) {
      assetsCache = (json.summary && json.summary.assets) || [];
      render(json.summary || { total_value: 0, total_cost: 0, total_gain: 0, total_gain_pct: 0, allocation: [], assets: [] });
    }).catch(function (err) {
      toast(err.message);
    });
  }

  function findAsset(id) {
    var found = null;
    assetsCache.forEach(function (a) { if (String(a.id) === String(id)) found = a; });
    return found;
  }

  // ---------- sheet tambah/edit aset ----------

  var assetOverlay = document.getElementById('ptAssetSheetOverlay');
  var assetForm = document.getElementById('ptAssetForm');
  var assetSheetTitle = document.getElementById('ptAssetSheetTitle');
  var assetMsgEl = document.getElementById('ptAssetSheetMsg');
  var assetIdInput = document.getElementById('ptAssetId');
  var assetNameInput = document.getElementById('ptAssetName');
  var assetTypeSelect = document.getElementById('ptAssetType');
  var assetCodeInput = document.getElementById('ptAssetCode');
  var assetUnitLabelInput = document.getElementById('ptAssetUnitLabel');
  var assetSubmitBtn = document.getElementById('ptAssetSubmit');

  function openAssetSheet(mode, row) {
    assetMsgEl.textContent = '';
    assetForm.reset();

    if (mode === 'edit' && row) {
      assetSheetTitle.textContent = 'Edit Aset';
      assetIdInput.value = row.id;
      assetNameInput.value = row.name;
      assetTypeSelect.value = row.type;
      assetCodeInput.value = row.code || '';
      assetUnitLabelInput.value = row.unit_label;
    } else {
      assetSheetTitle.textContent = 'Tambah Aset';
      assetIdInput.value = '';
      assetNameInput.value = '';
      assetTypeSelect.value = 'stock';
      assetCodeInput.value = '';
      assetUnitLabelInput.value = '';
    }

    assetOverlay.hidden = false;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { assetOverlay.classList.add('show'); });
    });
  }

  function closeAssetSheet() {
    assetOverlay.classList.remove('show');
    setTimeout(function () { assetOverlay.hidden = true; }, 180);
  }

  document.getElementById('fabAdd').addEventListener('click', function () {
    openAssetSheet('add', null);
  });
  document.getElementById('ptAssetCancel').addEventListener('click', closeAssetSheet);
  assetOverlay.addEventListener('click', function (ev) {
    if (ev.target === assetOverlay) closeAssetSheet();
  });

  assetForm.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    assetMsgEl.textContent = '';
    assetSubmitBtn.disabled = true;

    var payload = {
      name: assetNameInput.value.trim(),
      type: assetTypeSelect.value,
      code: assetCodeInput.value.trim(),
      unit_label: assetUnitLabelInput.value.trim(),
    };

    var action;
    if (assetIdInput.value) {
      action = 'update_asset';
      payload.id = assetIdInput.value;
    } else {
      action = 'create_asset';
    }

    try {
      await api('api/investasi.php?a=' + action, payload);
      closeAssetSheet();
      closeDetailSheet();
      toast('Aset tersimpan');
      load();
    } catch (err) {
      assetMsgEl.textContent = err.message;
    } finally {
      assetSubmitBtn.disabled = false;
    }
  });

  // ---------- sheet detail ----------

  var detailOverlay = document.getElementById('ptDetailSheetOverlay');
  var detailTitle = document.getElementById('ptDetailTitle');
  var detailSub = document.getElementById('ptDetailSub');
  var detailUnits = document.getElementById('ptDetailUnits');
  var detailAvg = document.getElementById('ptDetailAvg');
  var detailLast = document.getElementById('ptDetailLast');
  var detailCost = document.getElementById('ptDetailCost');
  var detailValue = document.getElementById('ptDetailValue');
  var detailGain = document.getElementById('ptDetailGain');
  var histList = document.getElementById('ptHistList');
  var histEmpty = document.getElementById('ptHistEmpty');
  var priceList = document.getElementById('ptPriceList');
  var priceEmpty = document.getElementById('ptPriceEmpty');
  var currentDetailAsset = null;

  function openDetailSheet(assetId) {
    var row = findAsset(assetId);
    if (!row) return;
    currentDetailAsset = row;

    detailTitle.textContent = row.name;
    detailSub.textContent = TYPE_LABELS[row.type] + (row.code ? ' · ' + row.code : '') + ' · ' + row.unit_label;
    detailUnits.textContent = unitFmt(row.units) + ' ' + row.unit_label;
    detailAvg.textContent = rupiahFmt(row.avg_price);
    detailLast.textContent = rupiahFmt(row.last_price);
    detailCost.textContent = rupiahFmt(row.cost);
    detailValue.textContent = rupiahFmt(row.value);
    detailGain.textContent = gainText(row.gain, row.gain_pct);
    detailGain.className = gainClass(row.gain);

    histList.innerHTML = '';
    histEmpty.hidden = true;
    priceList.innerHTML = '';
    priceEmpty.hidden = true;
    loadHistory(assetId);

    detailOverlay.hidden = false;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { detailOverlay.classList.add('show'); });
    });
  }

  function closeDetailSheet() {
    detailOverlay.classList.remove('show');
    setTimeout(function () { detailOverlay.hidden = true; }, 180);
  }

  function histRowEl(t) {
    var el = document.createElement('div');
    el.className = 'pt-hist-row';

    var badge = document.createElement('span');
    badge.className = 'pt-hist-side ' + (t.side === 'buy' ? 'pt-hist-buy' : 'pt-hist-sell');
    badge.textContent = t.side === 'buy' ? 'Beli' : 'Jual';

    var body = document.createElement('span');
    body.className = 'pt-hist-body';
    var line1 = document.createElement('span');
    // Fee beli ikut menambah modal (avg cost) -> tampil "+fee". Fee jual
    // TIDAK memengaruhi perhitungan modal & gain (formula avg cost per spec)
    // -> tampil netral "(fee RpX, catatan)" supaya tidak terkesan dihitung.
    var feeLabel = '';
    if (t.fee > 0) {
      feeLabel = t.side === 'buy'
        ? ' (+fee ' + rupiahFmt(t.fee) + ')'
        : ' (fee ' + rupiahFmt(t.fee) + ', catatan)';
    }
    line1.textContent = unitFmt(t.units) + ' × ' + rupiahFmt(t.price_per_unit) + feeLabel;
    var line2 = document.createElement('span');
    line2.className = 'pt-hist-date';
    line2.textContent = t.tx_date;
    body.appendChild(line1);
    body.appendChild(line2);

    var delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.className = 'pt-hist-del';
    delBtn.setAttribute('aria-label', 'Hapus transaksi');
    delBtn.textContent = '✕';
    delBtn.addEventListener('click', function () { deleteTrade(t.id); });

    el.appendChild(badge);
    el.appendChild(body);
    el.appendChild(delBtn);
    return el;
  }

  function priceRowEl(p) {
    var el = document.createElement('div');
    el.className = 'pt-hist-row';

    var body = document.createElement('span');
    body.className = 'pt-hist-body';
    var line1 = document.createElement('span');
    line1.textContent = rupiahFmt(p.price_per_unit);
    var line2 = document.createElement('span');
    line2.className = 'pt-hist-date';
    line2.textContent = p.priced_at;
    body.appendChild(line1);
    body.appendChild(line2);

    el.appendChild(body);
    return el;
  }

  function loadHistory(assetId) {
    return api('api/investasi.php?a=history', { asset_id: assetId }).then(function (json) {
      var trades = (json.trades || []).slice(0, 10);
      histList.innerHTML = '';
      trades.forEach(function (t) { histList.appendChild(histRowEl(t)); });
      histEmpty.hidden = trades.length > 0;

      var prices = (json.prices || []).slice(0, 10);
      priceList.innerHTML = '';
      prices.forEach(function (p) { priceList.appendChild(priceRowEl(p)); });
      priceEmpty.hidden = prices.length > 0;
    }).catch(function (err) {
      toast(err.message);
    });
  }

  async function deleteTrade(tradeId) {
    var ok = await lmConfirm('Hapus transaksi ini? Posisi & modal akan dihitung ulang.', 'Konfirmasi');
    if (!ok) return;
    try {
      await api('api/investasi.php?a=delete_trade', { id: tradeId });
      toast('Transaksi dihapus');
      await load();
      var refreshed = findAsset(currentDetailAsset.id);
      if (refreshed) openDetailSheet(refreshed.id);
    } catch (err) {
      toast(err.message);
    }
  }

  document.getElementById('ptDetailClose').addEventListener('click', closeDetailSheet);
  detailOverlay.addEventListener('click', function (ev) {
    if (ev.target === detailOverlay) closeDetailSheet();
  });

  document.getElementById('ptDetailEdit').addEventListener('click', function () {
    openAssetSheet('edit', currentDetailAsset);
  });

  document.getElementById('ptDetailDelete').addEventListener('click', async function () {
    var ok = await lmConfirm('Hapus aset "' + currentDetailAsset.name + '"? Hanya bisa dihapus kalau belum punya riwayat transaksi/harga.', 'Konfirmasi');
    if (!ok) return;
    try {
      await api('api/investasi.php?a=delete_asset', { id: currentDetailAsset.id });
      closeDetailSheet();
      toast('Aset dihapus');
      load();
    } catch (err) {
      toast(err.message);
    }
  });

  document.getElementById('ptDetailBuy').addEventListener('click', function () {
    openTradeSheet('buy', currentDetailAsset);
  });
  document.getElementById('ptDetailSell').addEventListener('click', function () {
    openTradeSheet('sell', currentDetailAsset);
  });
  document.getElementById('ptDetailPrice').addEventListener('click', function () {
    openPriceSheet(currentDetailAsset);
  });

  // ---------- sheet beli/jual ----------

  var tradeOverlay = document.getElementById('ptTradeSheetOverlay');
  var tradeForm = document.getElementById('ptTradeForm');
  var tradeSheetTitle = document.getElementById('ptTradeSheetTitle');
  var tradeMsgEl = document.getElementById('ptTradeSheetMsg');
  var tradeAssetIdInput = document.getElementById('ptTradeAssetId');
  var tradeSideInput = document.getElementById('ptTradeSide');
  var tradeUnitsInput = document.getElementById('ptTradeUnits');
  var tradeUnitsLabel = document.getElementById('ptTradeUnitsLabel');
  var tradePriceInput = document.getElementById('ptTradePrice');
  document.addEventListener('DOMContentLoaded', function () { attachRupiahInput(tradePriceInput); });
  var tradeFeeInput = document.getElementById('ptTradeFee');
  document.addEventListener('DOMContentLoaded', function () { attachRupiahInput(tradeFeeInput); });
  var tradeDateInput = document.getElementById('ptTradeDate');
  var tradeHintEl = document.getElementById('ptTradeHint');
  var tradeSubmitBtn = document.getElementById('ptTradeSubmit');

  function openTradeSheet(side, row) {
    tradeMsgEl.textContent = '';
    tradeForm.reset();

    tradeAssetIdInput.value = row.id;
    tradeSideInput.value = side;
    tradeUnitsLabel.textContent = 'Unit (' + row.unit_label + ')';
    tradeUnitsInput.value = '';
    tradePriceInput.value = '';
    tradeFeeInput.value = '';
    tradeDateInput.value = today;

    if (side === 'buy') {
      tradeSheetTitle.textContent = 'Beli — ' + row.name;
      tradeSubmitBtn.textContent = 'Catat Pembelian';
      tradeHintEl.textContent = 'Pembelian ini TIDAK memotong saldo akun manapun -- dana dianggap berasal dari luar aplikasi. Catat pengeluarannya secara terpisah di halaman Transaksi kalau ingin saldo akun ikut berkurang.';
    } else {
      tradeSheetTitle.textContent = 'Jual — ' + row.name;
      tradeSubmitBtn.textContent = 'Catat Penjualan';
      tradeHintEl.textContent = 'Penjualan ini TIDAK menambah saldo akun manapun -- dana hasil jual dianggap masuk dari luar aplikasi. Maks ' + unitFmt(row.units) + ' ' + row.unit_label + ' (posisi saat ini). Fee jual dicatat sebagai catatan saja -- tidak memengaruhi perhitungan modal & gain.';
    }

    tradeOverlay.hidden = false;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { tradeOverlay.classList.add('show'); });
    });
    setTimeout(function () { tradeUnitsInput.focus(); }, 200);
  }

  function closeTradeSheet() {
    tradeOverlay.classList.remove('show');
    setTimeout(function () { tradeOverlay.hidden = true; }, 180);
  }

  document.getElementById('ptTradeCancel').addEventListener('click', closeTradeSheet);
  tradeOverlay.addEventListener('click', function (ev) {
    if (ev.target === tradeOverlay) closeTradeSheet();
  });

  tradeForm.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    tradeMsgEl.textContent = '';
    tradeSubmitBtn.disabled = true;

    var payload = {
      asset_id: tradeAssetIdInput.value,
      side: tradeSideInput.value,
      units: tradeUnitsInput.value,
      price_per_unit: rupiahInputValue(tradePriceInput),
      fee: rupiahInputValue(tradeFeeInput) || 0,
      tx_date: tradeDateInput.value,
    };

    try {
      await api('api/investasi.php?a=trade', payload);
      closeTradeSheet();
      toast(payload.side === 'buy' ? 'Pembelian tercatat' : 'Penjualan tercatat');
      await load();
      var refreshed = findAsset(payload.asset_id);
      if (refreshed) openDetailSheet(refreshed.id);
    } catch (err) {
      tradeMsgEl.textContent = err.message;
    } finally {
      tradeSubmitBtn.disabled = false;
    }
  });

  // ---------- sheet update harga ----------

  var priceOverlay = document.getElementById('ptPriceSheetOverlay');
  var priceForm = document.getElementById('ptPriceForm');
  var priceMsgEl = document.getElementById('ptPriceSheetMsg');
  var priceAssetIdInput = document.getElementById('ptPriceAssetId');
  var pricePriceInput = document.getElementById('ptPricePrice');
  document.addEventListener('DOMContentLoaded', function () { attachRupiahInput(pricePriceInput); });
  var priceDateInput = document.getElementById('ptPriceDate');
  var priceSubmitBtn = document.getElementById('ptPriceSubmit');

  function openPriceSheet(row) {
    priceMsgEl.textContent = '';
    priceForm.reset();
    priceAssetIdInput.value = row.id;
    setRupiahInput(pricePriceInput, Math.round(Number(row.last_price)) || '');
    priceDateInput.value = today;

    priceOverlay.hidden = false;
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { priceOverlay.classList.add('show'); });
    });
    setTimeout(function () { pricePriceInput.focus(); }, 200);
  }

  function closePriceSheet() {
    priceOverlay.classList.remove('show');
    setTimeout(function () { priceOverlay.hidden = true; }, 180);
  }

  document.getElementById('ptPriceCancel').addEventListener('click', closePriceSheet);
  priceOverlay.addEventListener('click', function (ev) {
    if (ev.target === priceOverlay) closePriceSheet();
  });

  priceForm.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    priceMsgEl.textContent = '';
    priceSubmitBtn.disabled = true;

    var payload = {
      asset_id: priceAssetIdInput.value,
      price_per_unit: rupiahInputValue(pricePriceInput),
      priced_at: priceDateInput.value,
    };

    try {
      await api('api/investasi.php?a=set_price', payload);
      closePriceSheet();
      toast('Harga tersimpan');
      await load();
      var refreshed = findAsset(payload.asset_id);
      if (refreshed) openDetailSheet(refreshed.id);
    } catch (err) {
      priceMsgEl.textContent = err.message;
    } finally {
      priceSubmitBtn.disabled = false;
    }
  });

  // ---------- init ----------
  // Ditunda ke DOMContentLoaded: script inline ini dicetak SEBELUM
  // assets/app.js (yg mendefinisikan window.api) dimuat oleh pageFooter().
  // Pola sama spt public/transaksi.php & public/budget.php.

  document.addEventListener('DOMContentLoaded', function () {
    load();
  });
})();
</script>
<?php
pageFooter('lainnya');
