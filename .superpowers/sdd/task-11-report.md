# Task 11 Report — Laporan + export CSV + laba-rugi usaha

## Ringkasan

Implementasi laporan agregat bulanan/tahunan per kategori (sub->parent
digulung + rincian anak) & per akun, laba-rugi ruang usaha, dan export CSV
transaksi. Logika bisnis baru di `core/laporan.php`; `core/transaksi.php`
direfactor (extract `txBuildWhere()`) supaya export bisa REUSE aturan
filter yang sama dgn `listTransactions()` tanpa duplikasi.

## File

- **Create** `core/laporan.php`:
  - `laporanMonthly(spaceId, period)` / `laporanYearly(spaceId, year)` →
    `{per_kategori: {income:[...], expense:[...]}, per_akun: [...], total: {...}}`.
    Kategori anak SELALU digulung ke induk (`amount` induk = expense/income
    langsung induk + semua anak), rincian anak tetap tersimpan di
    `children`. Transaksi tanpa kategori (category_id NULL — hanya bisa
    terjadi lewat kategori yg dihapus, FK SET NULL) dikumpulkan jadi item
    semu "Tanpa kategori". Transfer DI-EXCLUDE dari `per_kategori`, tapi
    DIHITUNG di `per_akun` (keluar utk sumber, masuk utk tujuan). Item
    diurutkan amount terbesar dulu.
  - `laporanPnl(spaceId, periodOrYear)` — HANYA space `type=business`
    (selain itu `apiErr(400)`, dicek SEBELUM parsing format); format
    `periodOrYear` dideteksi otomatis via regex (`YYYY-MM` vs `YYYY`).
    Return `{income:[...], expense:[...], net}`.
  - CSV builder: `csvEscapeField()` (RFC 4180 dgn delimiter `;` + mitigasi
    CSV/formula injection OWASP — field diawali `=+-@` dibubuhi apostrof),
    `txToCsvFields()` (transfer → kategori kosong, akun "Sumber → Tujuan"),
    `streamTransactionsCsv()` (echo langsung, BOM UTF-8 + header + baris +
    catatan penutup kalau `$truncated`).
- **Modify** `core/transaksi.php`: extract `txBuildWhere()` dari
  `listTransactions()` (perilaku tidak berubah, `test_transaksi.php` tetap
  PASS tanpa modifikasi) + `txListForExport(spaceId, filters, limit=10000)`
  baru — filter SAMA (lewat `txBuildWhere()` bareng), TANPA pagination,
  fetch `limit+1` baris utk deteksi `truncated` tanpa query `COUNT(*)`
  terpisah.
- **Create** `public/api/laporan.php` — `?a=monthly|yearly|pnl`, POST (pola
  konsisten dgn `budget.php ?a=list`), baca-saja jadi tidak wajib CSRF.
- **Create** `public/export.php` — halaman GET biasa (`requireLogin()`,
  BUKAN `api/`), filter `from/to/account_id/category_id/type/q` sama
  persis dgn `transaksi.php`. Header `Content-Type: text/csv`,
  `Content-Disposition: attachment; filename="transaksi-YYYYMMDD-HHMMSS.csv"`.
- **Create** `public/laporan.php` — tab Bulanan|Tahunan(+Laba-Rugi kalau
  space business), selector periode (chevron bulan/tahun reuse
  `.bg-month-nav`), kartu Total, seksi Per Kategori (collapsible
  parent→anak via tap, reuse `.bg-list/.bg-row`), seksi Per Akun (tabel,
  disembunyikan di tab Laba-Rugi — API pnl tidak punya `per_akun`), tombol
  Export CSV (`window.open('export.php?from=..&to=..')`, BUKAN via
  `window.api()` — GET biasa spy link download).
- **Modify** `public/assets/app.css` — kelas `.lp-*` baru; tabel Per Akun
  dibungkus `.lp-akun-table-wrap{overflow-x:auto}` (wajib per checklist
  audit mobile — tabel 4 kolom overflow di layar sempit, diverifikasi
  scroll bekerja lewat gstack).
- **Create** `tests/test_laporan.php`.

## Test

62 assert di `tests/test_laporan.php`: agregat monthly (sub→parent+anak,
uncategorized, transfer exclude dari per_kategori tapi masuk 2 sisi di
per_akun), format period ditolak, yearly = jumlah 2 laporanMonthly (regresi
matematis, bukan cuma total tunggal), pnl bulanan & tahunan (param `YYYY`),
pnl ruang personal ditolak, format pnl invalid ditolak, CSV (BOM, header,
baris income/transfer/uncategorized, escape `;`/`"`, mitigasi formula
injection `=SUM(...)` → apostrof depan, filter type, truncation
limit+1-trick). `php tests/run.php` → **SEMUA PASS** (10 file).

## Verifikasi gstack (server lokal PHP built-in port 8933, user smoke
`smoke+laporan@ft.local`, data dibersihkan setelahnya)

- Space personal: tab Bulanan angka cocok hitungan manual (Pemasukan
  Rp500.000, Pengeluaran Rp115.000, Selisih Rp385.000); Per Kategori
  Makan & Minum Rp70.000·61% (collapsible, tap → expand "Jajan Rp20.000");
  Per Akun tabel scroll horizontal terbukti bekerja (`scrollWidth 420 >
  clientWidth 350`, kolom Net terlihat setelah scroll).
- Tab Tahunan: label "2026", total sama (satu-satunya bulan berisi data).
- Space business (`Usaha Smoke`, space diaktifkan via session file lokal —
  switcher ruang UI memang belum bisa pindah ruang lain, keterbatasan
  environment sama spt task 10): tab "Laba-Rugi" muncul, Pendapatan
  Rp300.000 / Beban Rp100.000 / Laba Bersih Rp200.000, seksi Per Akun
  tersembunyi (`hidden=true`).
- `export.php` via curl dgn cookie sesi nyata: header `Content-Disposition:
  attachment; filename="transaksi-20260712-193620.csv"`,
  `Cache-Control: no-store`, body diawali BOM (`efbbbf`), 6 baris cocok
  filter tanggal, format transfer "Dompet → Bank" & jenis Indonesia benar.
- Console bersih di semua tab.

## Keterbatasan / catatan

- Pindah ruang aktif ke space business diuji lewat modifikasi file session
  lokal (bukan lewat UI) — switcher ruang UI masih tampilan saja (sama spt
  keterbatasan yg dicatat di task 10), bukan bug fitur ini.
- CSV/formula-injection mitigation (apostrof depan utk field diawali
  `=+-@`) ditambahkan proaktif saat self-review — bukan diminta eksplisit
  di brief, tapi relevan krn kolom catatan/kategori/akun berasal dari
  input bebas teks user & diexport apa adanya.

## Fix pasca-review (1 Important + 1 Minor)

**Important — kolom jumlah CSV desimal titik vs Excel locale Indonesia.**
`txToCsvFields()` (core/laporan.php) semula `sprintf('%.2f')` →
`500000.00`; Excel-ID (list separator `;`, desimal koma) membaca itu sbg
TEKS, padahal delimiter `;` dipilih justru utk locale itu. Fix:
`number_format((float)$amount, 2, ',', '')` → `500000,00` (desimal koma
TANPA pemisah ribuan — koma di dalam field aman krn pemisah kolom `;`).
4 assert CSV di `tests/test_laporan.php` diupdate dari titik ke koma.

**Minor — tab Laba-Rugi hanya bulanan di UI padahal `laporanPnl()` sudah
support tahunan (param `YYYY`, sudah dites).** Ditambah sub-toggle
segmented kecil Bulanan|Tahunan (`#lpPnlMode`, hanya dirender utk space
business & hanya tampil saat tab Laba-Rugi aktif). Refactor kecil di JS:
helper `isYearlyView()` (true utk tab Tahunan ATAU pnl mode tahunan)
dipakai bareng oleh label periode, arah chevron (geser tahun vs bulan),
rentang Export CSV, dan payload API (`{year}` vs `{period}` — `?a=pnl`
memang menerima salah satu). CSS `.lp-pnl-mode` = versi mengecil dari
`.segmented`.

**Verifikasi:** `php tests/test_laporan.php` → semua ✓ (assert koma);
full `php tests/run.php` → SEMUA PASS (10 file). Smoke gstack ulang
(user `smoke+laporan@ft.local` baru, space business via edit session spt
sebelumnya, transaksi usaha di 2 bulan berbeda): tab Laba-Rugi → sub-toggle
muncul, mode Bulanan net Rp200.000; mode Tahunan → label "2026", Pendapatan
Rp450.000 (300rb Jul + 150rb Jan) − Beban Rp100.000 = Laba Bersih Rp350.000
(≠ bulanan, membuktikan param year benar-benar terkirim); chevron di mode
tahunan menggeser TAHUN (2025 → net Rp0 → balik 2026); pindah ke tab
Bulanan utama → sub-toggle tersembunyi; console bersih. Export CSV via curl
cookie sesi nyata → kolom jumlah `100000,00` / `300000,00` / `150000,00`
(koma). Data uji dibersihkan.
