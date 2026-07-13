// Utilitas app: api(), rupiahFmt(), toast(), + interaksi switcher ruang di header.
(function () {
  'use strict';

  /**
   * POST JSON ke url dengan header X-CSRF-Token (dari <meta name="csrf">).
   * Resolve dengan body JSON kalau ok:true, reject Error(pesan server) kalau tidak.
   */
  window.api = function (url, data) {
    var meta = document.querySelector('meta[name="csrf"]');
    var headers = { 'Content-Type': 'application/json' };
    if (meta) {
      headers['X-CSRF-Token'] = meta.content;
    }
    return fetch(url, {
      method: 'POST',
      headers: headers,
      body: JSON.stringify(data || {}),
    }).then(function (res) {
      return res.json().catch(function () {
        throw new Error('Respons server tidak valid');
      }).then(function (json) {
        if (!json || !json.ok) {
          throw new Error((json && json.error) || 'Terjadi kesalahan');
        }
        return json;
      });
    });
  };

  /**
   * Format angka ke Rupiah (mis. 15000 -> "Rp 15.000", -5000 -> "-Rp 5.000").
   */
  window.rupiahFmt = function (n) {
    var num = Number(n) || 0;
    var negatif = num < 0;
    var bulat = Math.round(Math.abs(num));
    var formatted = bulat.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return (negatif ? '-' : '') + 'Rp ' + formatted;
  };

  /**
   * Ambil digit saja dari str lalu kelompokkan ribuan dgn titik (locale ID).
   * String kosong / tanpa digit -> ''.
   * Manual grouping (bukan toLocaleString) supaya aman dari efek desimal/
   * rounding saat string sedang diketik (mis. "1.500.00" belum lengkap).
   */
  window.formatThousands = function (str) {
    var digits = String(str == null ? '' : str).replace(/\D/g, '');
    // Buang leading zero berlebih (tapi biarkan satu "0" tunggal).
    digits = digits.replace(/^0+(?=\d)/, '');
    if (digits === '') return '';
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  };

  /**
   * Pasang listener input di elemen rupiah: reformat value dgn titik ribuan
   * sambil mengetik, jaga posisi kursor tetap wajar, set inputMode numeric.
   * Nilai numerik polos disimpan di dataset.rawValue & bisa diambil via
   * rupiahInputValue().
   */
  window.attachRupiahInput = function (inputEl) {
    if (!inputEl || inputEl.__rupiahAttached) return;
    inputEl.__rupiahAttached = true;
    inputEl.setAttribute('inputmode', 'numeric');
    inputEl.addEventListener('input', function () {
      var before = inputEl.value;
      var selStart = inputEl.selectionStart == null ? before.length : inputEl.selectionStart;
      // Hitung berapa digit ada di sebelah kiri kursor sebelum reformat,
      // supaya kursor bisa ditempatkan kembali di posisi digit yg sama
      // setelah titik pemisah berubah jumlahnya.
      var digitsBeforeCursor = before.slice(0, selStart).replace(/\D/g, '').length;
      var formatted = window.formatThousands(before);
      inputEl.value = formatted;
      inputEl.dataset.rawValue = before.replace(/\D/g, '').replace(/^0+(?=\d)/, '');

      // Cari posisi baru: maju sampai jumlah digit yg sudah dilewati == digitsBeforeCursor.
      var newPos = formatted.length;
      var seen = 0;
      for (var i = 0; i < formatted.length; i++) {
        if (seen >= digitsBeforeCursor) { newPos = i; break; }
        if (/\d/.test(formatted[i])) seen++;
      }
      if (seen < digitsBeforeCursor) newPos = formatted.length;
      inputEl.setSelectionRange(newPos, newPos);
    });
  };

  /**
   * Ambil nilai numerik polos (string digit, tanpa titik) dari input rupiah
   * yg sudah dipasangi attachRupiahInput. Kosong -> ''.
   */
  window.rupiahInputValue = function (inputEl) {
    if (!inputEl) return '';
    return String(inputEl.value || '').replace(/\D/g, '').replace(/^0+(?=\d)/, '');
  };

  /**
   * Isi input rupiah dari nilai numerik (mis. saat buka sheet edit) lalu
   * format langsung (tanpa perlu event input).
   */
  window.setRupiahInput = function (inputEl, numericValue) {
    if (!inputEl) return;
    var digits = String(numericValue == null ? '' : numericValue).replace(/\D/g, '');
    inputEl.value = window.formatThousands(digits);
    inputEl.dataset.rawValue = digits;
  };

  var toastTimer = null;

  /**
   * Tampilkan pesan singkat mengambang di atas bottom nav.
   */
  window.toast = function (msg) {
    var el = document.getElementById('lmToast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'lmToast';
      el.className = 'lm-toast';
      document.body.appendChild(el);
    }
    el.textContent = msg;
    clearTimeout(toastTimer);
    // Restart transisi walau toast sebelumnya masih tampil.
    el.classList.remove('show');
    void el.offsetWidth;
    el.classList.add('show');
    toastTimer = setTimeout(function () {
      el.classList.remove('show');
    }, 2400);
  };

  // Switcher ruang di header: klik nama ruang lain -> POST space_switch lalu
  // reload halaman yang sama supaya semua data (& switcher itu sendiri)
  // konsisten dgn ruang aktif baru. Item ruang yg sedang aktif memang
  // disabled lewat HTML (tidak perlu switch ke ruang yg sama).
  document.addEventListener('click', function (ev) {
    var menu = document.getElementById('spaceMenu');
    if (!menu) return;

    var btn = ev.target.closest('#spaceBtn');
    if (btn) {
      menu.hidden = !menu.hidden;
      return;
    }

    var item = ev.target.closest('.space-item[data-id]');
    if (item && !item.disabled) {
      menu.hidden = true;
      window.api('api/pengaturan.php?a=space_switch', { id: item.dataset.id }).then(function () {
        window.location.reload();
      }).catch(function (err) {
        toast(err.message);
      });
      return;
    }

    if (!menu.hidden && !ev.target.closest('#spaceMenu')) {
      menu.hidden = true;
    }
  });
})();
