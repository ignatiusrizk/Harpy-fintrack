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

  // Switcher ruang di header: tampilan dropdown saja (endpoint pindah ruang
  // menyusul di task lain). Item non-aktif memang disabled lewat HTML.
  document.addEventListener('click', function (ev) {
    var menu = document.getElementById('spaceMenu');
    if (!menu) return;
    var btn = ev.target.closest('#spaceBtn');
    if (btn) {
      menu.hidden = !menu.hidden;
      return;
    }
    if (!menu.hidden && !ev.target.closest('#spaceMenu')) {
      menu.hidden = true;
    }
  });
})();
