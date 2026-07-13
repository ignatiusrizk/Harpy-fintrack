// Chart vanilla (tanpa lib eksternal), dipakai public/index.php (dashboard):
// barChart() utk arus kas 6 bulan, donutChart() utk top kategori pengeluaran.
// Pola SVG dibangun manual lewat DOM (createElementNS), SAMA gaya dgn donut
// alokasi di public/investasi.php. investasi.php SENGAJA TIDAK direfactor
// pakai file ini -- bentuk datanya beda (alokasi per TYPE_COLORS map yg
// dipetakan dari `type`, bukan item yg sudah bawa `color` sendiri spt di
// sini) dan halaman itu sudah live/teruji; risiko regresi lebih besar drpd
// manfaat ekstraksi kecil. Dicatat di laporan Task 10.
(function () {
  'use strict';

  var SVG_NS = 'http://www.w3.org/2000/svg';

  function resolveEl(target) {
    return typeof target === 'string' ? document.getElementById(target) : target;
  }

  /**
   * Singkat nominal rupiah utk label chart (bukan rupiahFmt() penuh --
   * terlalu panjang utk label di atas bar/di legend chart mobile):
   * >=1.000.000 -> "1,2jt" (1 desimal, koma, tanpa ",0" kalau bulat);
   * >=1.000 -> "500rb" (dibulatkan ke ribuan terdekat); selain itu angka
   * penuh dibulatkan.
   */
  function chartAbbr(n) {
    var num = Math.abs(Number(n) || 0);
    if (num >= 1000000) {
      var jt = num / 1000000;
      var s = jt.toFixed(1);
      if (s.slice(-2) === '.0') s = s.slice(0, -2);
      return s.replace('.', ',') + 'jt';
    }
    if (num >= 1000) {
      return Math.round(num / 1000) + 'rb';
    }
    return String(Math.round(num));
  }
  window.chartAbbr = chartAbbr;

  /**
   * Bar chart grouped, N label x M seri (dashboard: 6 bulan x 2 seri
   * income/expense). container: elemen atau id-nya (isinya di-clear & diisi
   * <svg>). labels: string[] (mis. ['Feb','Mar',...]). series: [{label,
   * color, values: number[]}] -- values sejajar index dgn labels. Sumbu Y
   * auto-scale dari nilai maksimum semua seri; max 0 (semua kosong, mis. user
   * baru) -> tetap render sumbu & label bulan, semua bar tinggi 0 (tidak
   * div/0, tidak error).
   */
  window.barChart = function (container, labels, series) {
    var el = resolveEl(container);
    if (!el) return;
    el.innerHTML = '';

    var n = labels.length;
    if (n === 0) return;

    var width = Math.max(280, n * 56);
    var height = 170;
    var padTop = 24;
    var padBottom = 26;
    var chartH = height - padTop - padBottom;
    var groupW = width / n;
    var barW = Math.min(16, groupW / (series.length + 1.5));
    var gap = 4;

    var max = 0;
    series.forEach(function (s) {
      (s.values || []).forEach(function (v) {
        if (v > max) max = v;
      });
    });
    var safeMax = max > 0 ? max : 1;

    var svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
    svg.setAttribute('class', 'bar-chart-svg');
    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');

    var base = document.createElementNS(SVG_NS, 'line');
    base.setAttribute('x1', 0);
    base.setAttribute('y1', height - padBottom);
    base.setAttribute('x2', width);
    base.setAttribute('y2', height - padBottom);
    base.setAttribute('class', 'bar-chart-axis');
    svg.appendChild(base);

    for (var i = 0; i < n; i++) {
      var groupsWidth = series.length * barW + (series.length - 1) * gap;
      var groupX = i * groupW + (groupW - groupsWidth) / 2;

      series.forEach(function (s, si) {
        var v = (s.values && s.values[i]) || 0;
        var h = (v / safeMax) * chartH;
        var x = groupX + si * (barW + gap);
        var y = height - padBottom - h;

        var rect = document.createElementNS(SVG_NS, 'rect');
        rect.setAttribute('x', x.toFixed(1));
        rect.setAttribute('y', y.toFixed(1));
        rect.setAttribute('width', barW.toFixed(1));
        rect.setAttribute('height', (v > 0 ? Math.max(h, 2) : 0).toFixed(1));
        rect.setAttribute('rx', 3);
        rect.setAttribute('fill', s.color);
        svg.appendChild(rect);

        if (v > 0) {
          var valueLabel = document.createElementNS(SVG_NS, 'text');
          valueLabel.setAttribute('x', (x + barW / 2).toFixed(1));
          valueLabel.setAttribute('y', (y - 4).toFixed(1));
          valueLabel.setAttribute('text-anchor', 'middle');
          valueLabel.setAttribute('class', 'bar-chart-value');
          valueLabel.textContent = chartAbbr(v);
          svg.appendChild(valueLabel);
        }
      });

      var monthLabel = document.createElementNS(SVG_NS, 'text');
      monthLabel.setAttribute('x', (i * groupW + groupW / 2).toFixed(1));
      monthLabel.setAttribute('y', height - 8);
      monthLabel.setAttribute('text-anchor', 'middle');
      monthLabel.setAttribute('class', 'bar-chart-label');
      monthLabel.textContent = labels[i];
      svg.appendChild(monthLabel);
    }

    el.appendChild(svg);
  };

  /**
   * Donut chart generik: container (elemen/id) + items [{name,color,value}].
   * Render <svg> donut ke dalam container -- legend TIDAK dibangun di sini
   * (beda pemakai butuh kolom kanan legend beda: persen alokasi vs nominal
   * kategori), pemanggil membangunnya sendiri dari array items yg sama.
   * Item dgn value<=0 dilewati (tidak menyumbang lingkaran, cegah dasharray
   * 0-length yg tidak perlu).
   */
  window.donutChart = function (container, items) {
    var el = resolveEl(container);
    if (!el) return;
    el.innerHTML = '';

    var list = items || [];
    var total = 0;
    list.forEach(function (it) {
      total += Number(it.value) || 0;
    });

    var size = 140, radius = 52, stroke = 20, cx = size / 2, cy = size / 2;
    var circumference = 2 * Math.PI * radius;

    var svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + size + ' ' + size);
    svg.setAttribute('class', 'donut-chart-svg');

    var track = document.createElementNS(SVG_NS, 'circle');
    track.setAttribute('cx', cx);
    track.setAttribute('cy', cy);
    track.setAttribute('r', radius);
    track.setAttribute('fill', 'none');
    track.setAttribute('stroke', '#EEF2F3');
    track.setAttribute('stroke-width', stroke);
    svg.appendChild(track);

    var visible = list.filter(function (it) {
      return (Number(it.value) || 0) > 0;
    });

    var offset = 0;
    visible.forEach(function (it) {
      var value = Number(it.value) || 0;
      var frac = total > 0 ? value / total : 0;
      if (frac <= 0) return;
      var segLen = frac * circumference;

      var circle = document.createElementNS(SVG_NS, 'circle');
      circle.setAttribute('cx', cx);
      circle.setAttribute('cy', cy);
      circle.setAttribute('r', radius);
      circle.setAttribute('fill', 'none');
      circle.setAttribute('stroke', it.color || '#94A3B8');
      circle.setAttribute('stroke-width', stroke);
      circle.setAttribute('stroke-linecap', visible.length === 1 ? 'butt' : 'round');
      circle.setAttribute('stroke-dasharray', segLen.toFixed(2) + ' ' + Math.max(0, circumference - segLen).toFixed(2));
      circle.setAttribute('stroke-dashoffset', (-offset).toFixed(2));
      circle.setAttribute('transform', 'rotate(-90 ' + cx + ' ' + cy + ')');
      svg.appendChild(circle);
      offset += segLen;
    });

    el.appendChild(svg);
  };
})();
