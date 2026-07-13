# E2E Smoke + Hardening Checklist — Task 13

Dijalankan: 2026-07-13 (WIB), branch `feat/v1`, PHP built-in server lokal
(`php -S localhost:8090 -t public`), DB dev `fintracker` (MariaDB lokal).
Metode: alur mutasi via `curl` (cookie jar per user, replikasi persis
`window.api()` — form-urlencoded/JSON body + header `X-CSRF-Token`), verifikasi
render/DOM/overflow via Browser pane (gstack-style). User uji dibuat baru
(`e2e_a_*@test.local`, `e2e_b_*@test.local`), **seluruh data uji sudah
dibersihkan dari DB setelah selesai** (lihat bagian Cleanup).

Hasil akhir: **semua langkah E2E PASS, semua item hardening PASS, tidak ada
bug yang perlu diperbaiki** (`php tests/run.php` tetap SEMUA PASS, tanpa
perubahan kode).

## 1. E2E — alur user baru penuh (user A)

| # | Langkah | Hasil |
|---|---|---|
| 1 | Register user baru (`api/auth.php?a=register`) | PASS — user id dibuat, auto-login, redirect ke dashboard |
| 2 | Buat akun Bank BCA (saldo awal 1.000.000) + Dompet Tunai (saldo awal 200.000) | PASS — `api/akun.php?a=create` x2, balance awal langsung tampil di `a=list` |
| 3 | 5 transaksi: income Gaji 5.000.000 (Bank), expense Makan 50.000 (Cash), expense Transportasi 30.000 (Bank), **transfer** 500.000 Bank→Cash, expense Belanja 100.000 (Cash, note `<script>alert(1)</script>` — dipakai sekalian utk uji XSS §2.5) | PASS — 5x `api/transaksi.php?a=create` sukses |
| 4 | Saldo akun setelah 5 transaksi | PASS — Bank = 1.000.000+5.000.000−30.000−500.000 = **5.470.000**; Cash = 200.000−50.000+500.000−100.000 = **550.000** (cek `api/akun.php?a=list`) — cocok exact |
| 5 | Dashboard "bulan ini" (`api/dashboard.php?a=summary`) | PASS — income 5.000.000, expense 180.000, net 4.820.000, net_worth 6.020.000 — cocok exact |
| 6 | Budget kategori Makan & Minum 200.000 utk bulan berjalan | PASS — `api/budget.php?a=set` → `a=list` spent=50.000, pct=25 (cocok: 50rb/200rb) |
| 7 | `copy_prev` ke bulan depan | PASS — `copied:1`, budget bulan depan amount=200.000, spent=0 (belum ada transaksi) |
| 8 | Recurring **auto** (Gaji 3.000.000/bulan, mulai hari ini) + **reminder** (Tagihan Listrik 250.000/bulan, mulai hari ini) dibuat | PASS — `api/recurring.php?a=create` x2 |
| 9 | Set `next_run`+`anchor_date` recurring auto ke **kemarin** via SQL langsung (`UPDATE recurrings SET next_run='kemarin' WHERE id=...`), logout+login (reset throttle pseudo-cron per-sesi), reload `index.php` | PASS — pseudo-cron (`runRecurringForUser()`, dipanggil dari `requireLogin()`) auto-posting 1 transaksi (tx_date=kemarin, `recurring_id` terisi), `next_run` maju otomatis ke bulan berikutnya (`advanceNextRun`, anchor day dipertahankan) |
| 10 | Reminder due muncul di `api/recurring.php?a=list` (`due:true`) → konfirmasi (`a=confirm`) | PASS — transaksi expense 250.000 terpost, `next_run` maju 1 bulan, saldo Bank berkurang sesuai |
| 11 | Goal "Liburan" target 1.000.000 dibuat → setor 300.000 dari Cash | PASS — `saved:300000, pct:30`; transaksi expense kategori "Tabungan Goal" otomatis (note "Setor goal: Liburan"), saldo Cash berkurang 300.000 |
| 12 | Net worth sebelum aset investasi | 8.470.000 (baseline, sebelum beli aset) |
| 13 | Aset "Emas Antam" (gold, 5 gram) dibuat → beli 5 gram @1.000.000 (fee 5.000) → set harga terbaru 1.100.000 | PASS — posisi: cost 5.005.000, avg_price 1.001.000, value @harga baru = 5.500.000, gain 495.000 (9.89%) |
| 14 | Net worth setelah beli aset (`api/dashboard.php?a=summary`) | PASS — **13.970.000** = 8.470.000 + 5.500.000 (nilai portofolio) — cocok exact, `portfolio.value/gain` konsisten dgn `api/investasi.php?a=list` |
| 15 | Buat ruang **Usaha** "Toko Kelontong" (`api/pengaturan.php?a=space_create`, type=business) | PASS — auto-switch session ke ruang baru, `api/akun.php?a=list` langsung kosong (konfirmasi ruang aktif berpindah) |
| 16 | Buat akun Kas Toko (saldo awal 500.000) + kategori "Penjualan" (income) & "Sewa Toko" (expense) khusus ruang usaha | PASS |
| 17 | Catat pendapatan Penjualan 2.000.000 + beban Sewa Toko 800.000 | PASS |
| 18 | Laporan Laba-Rugi (`api/laporan.php?a=pnl`, period bulan berjalan) | PASS — income 2.000.000, expense 800.000, **net 1.200.000** — cocok exact; ditolak (400, pesan jelas) kalau dicoba di ruang personal (lihat `laporanPnl()`, tidak diuji ulang di sini krn sudah dicover `test_laporan.php`) |
| 19 | Export CSV (`export.php`, GET via curl+cookie session, bukan `window.api()`) | PASS — header `Content-Type: text/csv`, `Content-Disposition: attachment`, BOM UTF-8 di awal file, delimiter `;`, desimal koma (`800000,00`), label Indonesia (Pemasukan/Pengeluaran), isi 2 baris sesuai transaksi ruang usaha aktif |
| 20 | Switch balik ke ruang Pribadi (`space_switch`) | PASS — data akun Pribadi (Bank/Cash) utuh, tidak tercampur dgn ruang Usaha |
| 21 | Logout → login lagi (dgn "Ingat saya") | PASS — login sukses, cookie `ft_remember` (selector:validator) ter-set |
| 22 | Verifikasi remember-me murni: cookie sesi (`PHPSESSID`) dibuang, hanya `ft_remember` disisakan, akses `index.php` | PASS — auto-login via `tryRememberLogin()`, halaman Dashboard termuat (bukan redirect ke login) — token dirotasi (validator lama dihapus, cookie baru diterbitkan) |

**Kesimpulan E2E: semua 22 langkah PASS, semua angka (saldo, dashboard,
budget, net worth, laba-rugi, CSV) cocok exact dengan perhitungan manual.**

## 2. Hardening checklist

### 2.1 Akses API tanpa login → 401 JSON

Diuji 10 endpoint (`?a=list`/`summary`/`space_list`/`monthly`, tanpa cookie
sesi sama sekali):

| Endpoint | HTTP | Body |
|---|---|---|
| `api/transaksi.php?a=list` | 401 | `{"ok":false,"error":"Belum login"}` |
| `api/goals.php?a=list` | 401 | idem |
| `api/akun.php?a=list` | 401 | idem |
| `api/kategori.php?a=list` | 401 | idem |
| `api/budget.php?a=list` | 401 | idem |
| `api/recurring.php?a=list` | 401 | idem |
| `api/investasi.php?a=list` | 401 | idem |
| `api/pengaturan.php?a=space_list` | 401 | idem |
| `api/laporan.php?a=monthly` | 401 | idem |
| `api/dashboard.php?a=summary` | 401 | idem |

**PASS semua — `requireLoginApi()` konsisten di semua endpoint.**

### 2.2 CSRF token salah/absent pada mutasi → 419

| Kasus | Endpoint | HTTP | Body |
|---|---|---|---|
| Header absent | `api/akun.php?a=create` | 419 | "Token CSRF tidak valid, muat ulang halaman" |
| Header salah (64 char acak) | `api/akun.php?a=create` | 419 | idem |
| Header salah | `api/transaksi.php?a=create` | 419 | idem |

**PASS — `csrf_check()` (hash_equals) konsisten menolak sebelum mutasi apa pun terjadi.**

### 2.3 IDOR — user B akses resource user A via id → 404

User B (akun terpisah, login session terpisah) mencoba memanipulasi resource
milik user A langsung via id:

| Resource | Aksi | HTTP | Body |
|---|---|---|---|
| Akun (`ownAccount`) | `akun.php?a=update` id akun Bank milik A | 404 | "Tidak ditemukan" |
| Transaksi (`ownTransaction`) | `transaksi.php?a=delete` id transaksi milik A | 404 | idem |
| Goal (`ownGoal`) | `goals.php?a=deposit` goal_id milik A | 404 | idem |
| Aset (`ownAsset`) | `investasi.php?a=set_price` asset_id milik A | 404 | idem |
| Recurring (`ownRecurring`) | `recurring.php?a=toggle` id milik A | 404 | idem |
| Ruang (`ownSpace`) | `pengaturan.php?a=space_switch` id ruang milik A | 404 | idem |
| Kategori (`ownCategory`, via `deleteCategory`) | `kategori.php?a=delete` id kategori milik A | 404 | idem |

**PASS semua 7 resource — tidak ada data user A yang bocor atau termodifikasi oleh user B.**

### 2.4 SQL Injection — filter transaksi

Payload `' OR 1=1 -- ` dicoba di semua parameter filter `api/transaksi.php?a=list`:

| Parameter | Hasil |
|---|---|
| `q` (LIKE note) | `{"ok":true,"rows":[],...}` — 0 baris (payload diperlakukan sbg literal string pencarian, bukan SQL) |
| `from` + `to` (rentang tanggal) | idem — 0 baris, tidak error, tidak bocor lintas user |
| `account_id` + `category_id` + `type` | idem — di-cast/divalidasi (`(int)`/whitelist), tidak menembus query |

Konfirmasi DB tidak terdampak (`SELECT COUNT(*) FROM users` tetap sesuai
jumlah user aktif). **PASS — semua query pakai PDO prepared statement
(`PDO::ATTR_EMULATE_PREPARES => false`), tidak ada string concatenation
input user ke SQL.**

### 2.5 XSS — note transaksi & nama kategori/goal/aset

Payload `<script>alert(1)</script>` disimpan di 4 tempat: note transaksi,
nama kategori, nama goal, nama aset. Diverifikasi via Browser pane (halaman
sungguhan, bukan cuma respons JSON):

| Lokasi | Halaman | Hasil |
|---|---|---|
| Note transaksi | `transaksi.php` | Tampil sbg teks literal `<script>alert(1)</script>` di kartu transaksi — TIDAK dieksekusi |
| Nama kategori | `transaksi.php` (dropdown filter kategori), `kategori.php` | Tampil literal di pilihan kategori — TIDAK dieksekusi |
| Nama goal | `goals.php` | Tampil literal sbg judul kartu goal — TIDAK dieksekusi |
| Nama aset | `investasi.php` | Tampil literal sbg nama aset — TIDAK dieksekusi |

Konfirmasi teknis: `document.querySelectorAll('script').length` = 3 (cuma
file JS aplikasi sendiri — app.js/charts.js/dialog.js — TIDAK bertambah),
`document.body.innerHTML` mengandung `&lt;script&gt;alert(1)&lt;/script&gt;`
(ter-escape jadi entity HTML, bukan tag mentah), console browser bersih
(tidak ada `alert()` terpanggil). Akar penyebab aman: seluruh render
nama/catatan di `app.js`/halaman terkait konsisten pakai `element.textContent
= ...`, TIDAK ADA satupun `innerHTML = row.note/name` di codebase (dicek
`grep -rn innerHTML public/assets/*.js` — hanya 2 pemakaian di `charts.js`,
keduanya utk clear container `''`/SVG generated, bukan data user).

**PASS — semua 4 lokasi ter-escape dgn benar (pola `e()`/htmlspecialchars di
server utk HTML statis + `textContent` di client utk data dinamis).**

### 2.6 Mobile viewport 375px — overflow-x

Browser pane di-resize ke 375×812, diukur `document.documentElement.scrollWidth`
vs `clientWidth` (overflow-x page-level) di 10 halaman utama, sambil login
sbg user A dgn data uji (termasuk nama kategori/goal/aset ber-XSS panjang,
sbg stress-test ekstra utk layout):

| Halaman | scrollWidth | Overflow page-level? | Catatan per-kategori |
|---|---|---|---|
| Dashboard (`index.php`) | 375 | Tidak | grid metrik (Net Worth/Bulan Ini), kartu Top Kategori, chart arus kas — semua muat |
| Transaksi (`transaksi.php`) | 375 | Tidak | toolbar filter (Semua Jenis/Akun/Kategori), kartu list transaksi, tab bar bawah — muat, termasuk saat nama kategori = `<script>...` |
| Akun (`akun.php`) | 375 | Tidak | kartu list akun — muat |
| Kategori (`kategori.php`) | 375 | Tidak | tab bar Pengeluaran/Pemasukan, grid kategori — muat |
| Budget (`budget.php`) | 375 | Tidak | grid metrik + kartu progress — muat |
| Recurring (`recurring.php`) | 375 | Tidak | kartu list recurring (auto & reminder, termasuk badge "due") — muat |
| Goals (`goals.php`) | 375 | Tidak | kartu goal (progress bar, tombol Setor/Tarik) — muat, termasuk nama goal ber-XSS |
| Investasi (`investasi.php`) | 375 | Tidak | kartu ringkasan portofolio, grid alokasi, kartu list aset — muat, termasuk nama aset ber-XSS |
| Laporan (`laporan.php`) | 375 | Tidak* | tab bar Bulanan/Tahunan, grid metrik, kartu per-kategori — muat. *Tabel "Per Akun" (`.lp-akun-table-wrap`) scrollWidth 436 > 375, TAPI kontainernya sendiri `overflow-x:auto` (clientWidth 335 vs scrollWidth 436) — scroll horizontal TERKUNGKUNG di dalam kartu tabel, TIDAK memicu overflow halaman (`document.documentElement.scrollWidth` tetap 375). Ini pola yang benar utk tabel lebar di mobile, bukan bug. |
| Pengaturan (`pengaturan.php`) | 375 | Tidak | seksi Profil/Keamanan/Ruang, toolbar — muat |

**PASS semua 10 halaman — tidak ada overflow-x di level halaman/body. Satu
tabel (Per Akun di Laporan) punya scroll horizontal internal yang disengaja
(`overflow-x:auto`), bukan overflow bug.**

## 3. Temuan & perbaikan

**Tidak ada temuan yang memerlukan perbaikan kode.** Baik alur E2E penuh
maupun seluruh item hardening (401/419/IDOR/SQLi/XSS/mobile overflow) lolos
tanpa perlu perubahan apa pun di `core/`/`public/`. Ini konsisten dengan
Task 1–12 yang masing-masing sudah direview individual sebelum task ini.

Tidak ada temuan besar yang perlu dicatat utk ditangani terpisah.

## 4. Verifikasi akhir

```
$ php tests/run.php
...
==================== Rekap ====================
PASS  test_auth.php
PASS  test_balance.php
PASS  test_budget.php
PASS  test_dashboard.php
PASS  test_goals.php
PASS  test_helpers.php
PASS  test_laporan.php
PASS  test_pengaturan.php
PASS  test_portfolio.php
PASS  test_recurring.php
PASS  test_transaksi.php
=================================================
SEMUA PASS
```

(Tidak ada perubahan kode di task ini, jadi hasil ini sama dgn baseline
sebelum smoke test dimulai — dijalankan ulang setelah cleanup DB utk
konfirmasi tidak ada regresi/data uji yang tertinggal mempengaruhi test.)

## 5. Cleanup data uji

Semua data uji (2 user `e2e_a_*@test.local` / `e2e_b_*@test.local`, 3 ruang,
2 akun+1 akun ruang usaha, 10 transaksi, 2 recurring, 1 goal, 1 aset, 2
kategori kustom) dihapus dari DB dev setelah smoke test selesai. Urutan hapus
mengikuti aturan FK yang sama dgn `spaceDelete()` (transactions & recurrings
dihapus eksplisit dulu krn RESTRICT ke accounts/categories, baru `DELETE FROM
users` yang men-cascade sisanya: spaces→accounts/categories/budgets/goals(+entries)/
assets(+trades&prices), plus `remember_tokens`). Satu baris `login_attempts`
sisa dari sesi kerja SEBELUM task ini (`test+task12-fix@ft.local`, bukan data
uji task ini) turut dibersihkan sbg housekeeping.

Verifikasi pasca-cleanup (tabel-tabel terkait user uji): `users`, `spaces`,
`accounts`, `transactions`, `recurrings`, `goals`, `assets`, `login_attempts`,
`remember_tokens` — semua **0 baris** utk data uji.
