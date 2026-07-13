'use strict';
// Smoke test renderer dashboard (public/index.php): jalankan SKRIP ASLI dari
// index.php (diekstrak apa adanya, bukan disalin -- anti-drift) di dalam DOM
// shim minimal, umpankan payload ?a=summary yg memuat item upcoming kind='debt'
// (cicilan & non-cicilan) + kind='recurring', lalu buktikan dueRowEl() TIDAK
// throw & mem-blank dashboard. Regresi live: item debt tidak punya next_run,
// versi lama dueRowEl baca shortDateLabel(row.next_run) -> parseDate(undefined)
// .split -> TypeError -> load() catch -> toast() -> SELURUH dashboard blank.
//
// Deteksi throw: throw di render() ditelan try/catch load() lalu diarahkan ke
// toast(err.message). Jadi toast terpanggil == ada throw == FAIL.

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const indexPath = path.join(__dirname, '..', 'public', 'index.php');
const src = fs.readFileSync(indexPath, 'utf8');

// Ekstrak IIFE dashboard apa adanya. Anchor ke `(function () {` .. `})();`
// (BUKAN sekadar <script>...</script> -- ada literal "<script>" di komentar
// PHP header yg bikin match kelewat awal).
const m = src.match(/(\(function \(\) \{[\s\S]*?\}\)\(\);)\s*<\/script>/);
if (!m) {
    console.error('SMOKE FAIL: blok IIFE dashboard tidak ditemukan di public/index.php');
    process.exit(1);
}
const scriptBody = m[1];

// --- DOM shim minimal --------------------------------------------------------
function FakeEl(tag) {
    this.tag = tag || 'div';
    this.children = [];
    this._textContent = '';
    this._innerHTML = '';
    this.className = '';
    this.hidden = false;
    this.style = {};
    this._listeners = {};
    this.classList = {
        _s: {},
        add: function (c) { this._s[c] = true; },
        remove: function (c) { delete this._s[c]; },
        contains: function (c) { return !!this._s[c]; },
    };
}
Object.defineProperty(FakeEl.prototype, 'textContent', {
    get: function () { return this._textContent; },
    set: function (v) { this._textContent = String(v); },
});
Object.defineProperty(FakeEl.prototype, 'innerHTML', {
    get: function () { return this._innerHTML; },
    set: function (v) { this._innerHTML = String(v); if (v === '') { this.children = []; } },
});
FakeEl.prototype.appendChild = function (c) { this.children.push(c); return c; };
FakeEl.prototype.addEventListener = function (t, cb) { (this._listeners[t] = this._listeners[t] || []).push(cb); };

const byId = {};
const toastCalls = [];

const documentShim = {
    _listeners: {},
    getElementById: function (id) { return (byId[id] = byId[id] || new FakeEl('div')); },
    createElement: function (tag) { return new FakeEl(tag); },
    addEventListener: function (t, cb) { (this._listeners[t] = this._listeners[t] || []).push(cb); },
    dispatch: function (t) { (this._listeners[t] || []).forEach(function (cb) { cb(); }); },
};

// Payload ?a=summary yg mengandung item debt (bentuk hasil dbSummaryUpcoming
// core saat ini) + 1 recurring. Sengaja campur cicilan (payable/expense) &
// non-cicilan (receivable/income) supaya kedua cabang dueRowEl kena.
const summary = {
    net_worth: 1234567,
    month: { income: 1000, expense: 400, net: 600 },
    upcoming: [
        { kind: 'recurring', id: 1, date: '2026-07-13', note: 'Listrik', category_name: 'Listrik', category_icon: '⚡', category_color: '#123456', amount: 250000, next_run: '2026-07-13', mode: 'reminder', type: 'expense' },
        { kind: 'debt', id: 2, date: '2026-07-16', label: 'Bank ABC', party: 'Bank ABC', direction: 'payable', type: 'expense', amount: 850000, due_date: '2026-07-16', is_installment: true, badge: 'Cicilan' },
        { kind: 'debt', id: 3, date: '2026-07-14', label: 'Cici', party: 'Cici', direction: 'receivable', type: 'income', amount: 300000, due_date: '2026-07-14', is_installment: false, badge: 'Jatuh tempo' },
    ],
    cashflow: [{ period: '2026-07', income: 0, expense: 0 }],
    top_categories: [],
    portfolio: null,
    pnl: null,
};

const sandbox = {
    document: documentShim,
    // Global dari assets/app.js -- distub.
    api: function () { return Promise.resolve(summary); },
    rupiahFmt: function (n) { return 'Rp' + Number(n).toLocaleString('id-ID'); },
    toast: function (msg) { toastCalls.push(String(msg)); },
    // Global chart dari assets/*.js -- no-op.
    barChart: function () {},
    donutChart: function () {},
    console: console,
    setTimeout: setTimeout,
    Promise: Promise,
};
sandbox.window = sandbox;

try {
    vm.runInNewContext(scriptBody, sandbox, { filename: 'index.php:<script>' });
} catch (e) {
    console.error('SMOKE FAIL: skrip index.php gagal dieksekusi saat load: ' + e.message);
    process.exit(1);
}

// Skrip mendaftar load() ke DOMContentLoaded; picu, lalu tunggu microtask
// (api() Promise) selesai sebelum assert.
documentShim.dispatch('DOMContentLoaded');

setTimeout(function () {
    const fails = [];

    if (toastCalls.length !== 0) {
        fails.push('render() melempar (toast terpanggil): ' + JSON.stringify(toastCalls));
    }

    const dueList = byId['dbDueList'];
    if (!dueList || dueList.children.length !== summary.upcoming.length) {
        fails.push('dueList children = ' + (dueList ? dueList.children.length : 'null') + ', harusnya ' + summary.upcoming.length);
    } else {
        // Telusuri tiap baris: el.children = [icon, body, amount];
        // body.children = [title, meta]; meta.children = [badge, dateLabel].
        summary.upcoming.forEach(function (item, i) {
            const row = dueList.children[i];
            const meta = row.children[1].children[1];
            const badge = meta.children[0];
            const dateLabel = meta.children[1];
            const amount = row.children[2];

            if (!dateLabel.textContent) {
                fails.push('item[' + i + '] (' + item.kind + '): date label KOSONG');
            }
            if (item.kind === 'debt' && badge.textContent !== item.badge) {
                fails.push('item[' + i + '] debt: badge "' + badge.textContent + '" != "' + item.badge + '"');
            }
            const wantSign = item.type === 'income' ? '+' : '-';
            if (amount.textContent.charAt(0) !== wantSign) {
                fails.push('item[' + i + ']: tanda amount "' + amount.textContent.charAt(0) + '" != "' + wantSign + '"');
            }
        });
        // Verifikasi tanggal debt cicilan (16 Jul) benar-benar terformat.
        const debtRow = dueList.children[1];
        const debtDate = debtRow.children[1].children[1].children[1].textContent;
        if (debtDate !== '16 Jul') {
            fails.push('debt cicilan date label "' + debtDate + '" != "16 Jul"');
        }
    }

    if (fails.length > 0) {
        console.error('SMOKE FAIL:\n  - ' + fails.join('\n  - '));
        process.exit(1);
    }
    console.log('SMOKE OK: renderUpcoming/dueRowEl tidak throw dgn item debt; ' + dueList.children.length + ' baris ter-render benar');
    process.exit(0);
}, 0);
