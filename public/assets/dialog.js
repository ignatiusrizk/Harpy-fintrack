// Dialog custom promise-based (ganti alert/confirm/prompt native).
// lmAlert(msg, title?) -> Promise<void>
// lmConfirm(msg, title?) -> Promise<boolean>
// lmPrompt(msg, default?) -> Promise<string|null>
(function () {
  'use strict';

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = String(s == null ? '' : s);
    return d.innerHTML;
  }

  function buildOverlay(innerHtml) {
    var overlay = document.createElement('div');
    overlay.className = 'lm-overlay';
    overlay.innerHTML = '<div class="lm-dialog">' + innerHtml + '</div>';
    document.body.appendChild(overlay);
    // Trigger transisi masuk di frame berikutnya.
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        overlay.classList.add('show');
      });
    });
    return overlay;
  }

  function closeOverlay(overlay, done) {
    overlay.classList.remove('show');
    setTimeout(function () {
      overlay.remove();
      done();
    }, 180);
  }

  window.lmAlert = function (msg, title) {
    return new Promise(function (resolve) {
      var overlay = buildOverlay(
        '<h2 class="lm-title">' + escapeHtml(title || 'Info') + '</h2>' +
        '<p class="lm-msg">' + escapeHtml(msg) + '</p>' +
        '<div class="lm-actions"><button type="button" class="lm-btn lm-btn-primary" data-act="ok">OK</button></div>'
      );

      function finish() {
        closeOverlay(overlay, function () { resolve(); });
      }

      overlay.querySelector('[data-act="ok"]').addEventListener('click', finish);
      overlay.addEventListener('click', function (ev) {
        if (ev.target === overlay) finish();
      });
    });
  };

  window.lmConfirm = function (msg, title) {
    return new Promise(function (resolve) {
      var overlay = buildOverlay(
        '<h2 class="lm-title">' + escapeHtml(title || 'Konfirmasi') + '</h2>' +
        '<p class="lm-msg">' + escapeHtml(msg) + '</p>' +
        '<div class="lm-actions">' +
        '<button type="button" class="lm-btn lm-btn-ghost" data-act="cancel">Batal</button>' +
        '<button type="button" class="lm-btn lm-btn-primary" data-act="ok">Ya</button>' +
        '</div>'
      );

      function finish(result) {
        closeOverlay(overlay, function () { resolve(result); });
      }

      overlay.querySelector('[data-act="ok"]').addEventListener('click', function () { finish(true); });
      overlay.querySelector('[data-act="cancel"]').addEventListener('click', function () { finish(false); });
      overlay.addEventListener('click', function (ev) {
        if (ev.target === overlay) finish(false);
      });
    });
  };

  window.lmPrompt = function (msg, def) {
    return new Promise(function (resolve) {
      var overlay = buildOverlay(
        '<h2 class="lm-title">' + escapeHtml(msg) + '</h2>' +
        '<input type="text" class="lm-input" value="' + escapeHtml(def || '') + '">' +
        '<div class="lm-actions">' +
        '<button type="button" class="lm-btn lm-btn-ghost" data-act="cancel">Batal</button>' +
        '<button type="button" class="lm-btn lm-btn-primary" data-act="ok">OK</button>' +
        '</div>'
      );

      var input = overlay.querySelector('.lm-input');
      setTimeout(function () {
        input.focus();
        input.select();
      }, 200);

      function finish(result) {
        closeOverlay(overlay, function () { resolve(result); });
      }

      overlay.querySelector('[data-act="ok"]').addEventListener('click', function () { finish(input.value); });
      overlay.querySelector('[data-act="cancel"]').addEventListener('click', function () { finish(null); });
      overlay.addEventListener('click', function (ev) {
        if (ev.target === overlay) finish(null);
      });
      input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          finish(input.value);
        }
      });
    });
  };
})();
