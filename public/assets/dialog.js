// Dialog custom promise-based (ganti alert/confirm/prompt native).
// lmAlert(msg, title?) -> Promise<void>
// lmConfirm(msg, title?) -> Promise<boolean>
// lmPrompt(msg, default?) -> Promise<string|null>
//
// Semua node dibangun via DOM API (createElement + textContent/value) — tidak
// ada concat HTML string untuk nilai dinamis, jadi tidak ada celah escaping
// (termasuk attribute-breakout lewat kutip di lmPrompt default value).
// Escape menutup dialog: alert -> resolve, confirm -> false, prompt -> null.
(function () {
  'use strict';

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = String(text);
    return node;
  }

  function button(label, className) {
    var btn = el('button', 'lm-btn ' + className, label);
    btn.type = 'button';
    return btn;
  }

  /**
   * Buat overlay + dialog berisi children, pasang handler tutup (klik backdrop
   * & tombol Escape memanggil onDismiss). Return { overlay, close(cb) }.
   */
  function openDialog(children, onDismiss) {
    var overlay = el('div', 'lm-overlay');
    var dialog = el('div', 'lm-dialog');
    children.forEach(function (c) { dialog.appendChild(c); });
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);

    var closed = false;

    function onKeydown(ev) {
      if (ev.key === 'Escape') {
        ev.preventDefault();
        onDismiss();
      }
    }
    document.addEventListener('keydown', onKeydown);

    overlay.addEventListener('click', function (ev) {
      if (ev.target === overlay) onDismiss();
    });

    // Trigger transisi masuk di frame berikutnya.
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        overlay.classList.add('show');
      });
    });

    function close(done) {
      if (closed) return;
      closed = true;
      document.removeEventListener('keydown', onKeydown);
      overlay.classList.remove('show');
      setTimeout(function () {
        overlay.remove();
        done();
      }, 180);
    }

    return { overlay: overlay, close: close };
  }

  window.lmAlert = function (msg, title) {
    return new Promise(function (resolve) {
      var ok = button('OK', 'lm-btn-primary');
      var actions = el('div', 'lm-actions');
      actions.appendChild(ok);

      var d = openDialog(
        [el('h2', 'lm-title', title || 'Info'), el('p', 'lm-msg', msg), actions],
        function () { d.close(resolve); }
      );

      ok.addEventListener('click', function () { d.close(resolve); });
    });
  };

  window.lmConfirm = function (msg, title) {
    return new Promise(function (resolve) {
      var cancel = button('Batal', 'lm-btn-ghost');
      var ok = button('Ya', 'lm-btn-primary');
      var actions = el('div', 'lm-actions');
      actions.appendChild(cancel);
      actions.appendChild(ok);

      var d = openDialog(
        [el('h2', 'lm-title', title || 'Konfirmasi'), el('p', 'lm-msg', msg), actions],
        function () { d.close(function () { resolve(false); }); }
      );

      ok.addEventListener('click', function () { d.close(function () { resolve(true); }); });
      cancel.addEventListener('click', function () { d.close(function () { resolve(false); }); });
    });
  };

  window.lmPrompt = function (msg, def) {
    return new Promise(function (resolve) {
      var input = el('input', 'lm-input');
      input.type = 'text';
      input.value = def == null ? '' : String(def);

      var cancel = button('Batal', 'lm-btn-ghost');
      var ok = button('OK', 'lm-btn-primary');
      var actions = el('div', 'lm-actions');
      actions.appendChild(cancel);
      actions.appendChild(ok);

      var d = openDialog(
        [el('h2', 'lm-title', msg), input, actions],
        function () { d.close(function () { resolve(null); }); }
      );

      setTimeout(function () {
        input.focus();
        input.select();
      }, 200);

      ok.addEventListener('click', function () { d.close(function () { resolve(input.value); }); });
      cancel.addEventListener('click', function () { d.close(function () { resolve(null); }); });
      input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          d.close(function () { resolve(input.value); });
        }
      });
    });
  };
})();
