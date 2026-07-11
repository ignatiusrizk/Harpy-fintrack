# Harpy FinTrack — Design Spec

**Tanggal:** 2026-07-12 (WIB)
**Status:** Disetujui user (via sesi brainstorming)

## 1. Ringkasan

Harpy FinTrack adalah aplikasi web pencatat keuangan pribadi **multi-user** (siapa pun bisa daftar), berbahasa Indonesia, mobile-first. Fitur mencakup pencatatan transaksi, multi-akun, budget, transaksi berulang & tagihan, target tabungan, portfolio investasi manual, serta ruang terpisah untuk pembukuan usaha kecil.

## 2. Keputusan Arsitektur

- **Stack:** PHP 8 murni + MySQL (tanpa framework), pola halaman-per-fitur + endpoint AJAX JSON — konsisten dengan pola LaMaSy yang sudah dikuasai user dan infra Hostinger.
- **Lokasi kode:** repo baru `~/Documents/fintracker`, terpisah total dari LaMaSy.
- **Isolasi data:** SEMUA query di-scope `user_id` dari session (tidak pernah dari input klien) — pola sama dengan `tenant_id` LaMaSy. Data per-fitur di-scope `space_id` yang divalidasi kepemilikannya.
- **DB access:** PDO + prepared statements. Nominal `DECIMAL(15,2)`. Mata uang IDR saja di v1.
- **Auth:** session PHP, password `password_hash()`, CSRF token (header `X-CSRF-Token` — huruf persis ini, kompatibel Hostinger), rate-limit percobaan login, remember-me token.
- **Error handling:** pola `apiErr($e)` — log detail di server, pesan generik ke klien.
- **Waktu:** app timezone Asia/Jakarta; kolom tanggal transaksi pakai `DATE` (bukan timestamp) agar bebas masalah selisih UTC. Jangan bandingkan datetime tulisan PHP dengan `NOW()` MySQL.
- **Pseudo-cron:** posting recurring & pengingat tagihan dijalankan saat ada request user (guard di bootstrap), bukan cron server — pola sama dengan LaMaSy.
- **UI:** mobile-first, bottom nav (Dashboard, Transaksi, tombol + tambah cepat, Budget, Lainnya). Dialog custom (bukan alert/confirm native) dan kontrol select/date custom, header `no-store` untuk halaman dinamis.

## 3. Konsep Inti: Ruang (Space)

- Setiap user punya ≥1 ruang. Ruang `Pribadi` (type `personal`) dibuat otomatis saat registrasi.
- User bisa menambah ruang `Usaha` (type `business`) tanpa batas wajar.
- Semua entitas (akun, kategori, transaksi, budget, recurring, goal, aset) milik satu ruang.
- Transfer antar akun hanya di dalam satu ruang (laporan tetap bersih).
- Ruang usaha mendapat laporan **laba-rugi sederhana** (pendapatan − pengeluaran per kategori, per periode). Bukan akuntansi double-entry.
- Switcher ruang selalu tampak di header; ruang aktif disimpan di session.

## 4. Skema Data (13 tabel)

| Tabel | Kolom kunci |
|---|---|
| `users` | id, name, email (unique), password_hash, created_at |
| `remember_tokens` | id, user_id, selector, validator_hash, expires_at |
| `spaces` | id, user_id, name, type ENUM(personal,business), created_at |
| `accounts` | id, space_id, name, type ENUM(cash,bank,ewallet,other), initial_balance, is_archived |
| `categories` | id, space_id, name, type ENUM(income,expense), icon, color, parent_id NULL |
| `transactions` | id, space_id, account_id, category_id NULL, type ENUM(income,expense,transfer), amount, tx_date DATE, note, to_account_id NULL (transfer), recurring_id NULL, goal_id NULL, created_at |
| `budgets` | id, space_id, category_id, period CHAR(7) 'YYYY-MM', amount |
| `recurrings` | id, space_id, account_id, category_id, type, amount, note, frequency ENUM(daily,weekly,monthly,yearly), day_of/anchor date, next_run DATE, mode ENUM(auto,reminder), is_active |
| `goals` | id, space_id, name, target_amount, target_date NULL, is_done |
| `goal_entries` | id, goal_id, transaction_id NULL, amount, entry_date |
| `assets` | id, space_id, name, type ENUM(stock,mutual_fund,gold,crypto,deposit,other), code NULL, unit_label |
| `asset_transactions` | id, asset_id, side ENUM(buy,sell), units DECIMAL(20,8), price_per_unit, fee, tx_date |
| `asset_prices` | id, asset_id, price_per_unit, priced_at |

Aturan turunan:
- **Saldo akun** = initial_balance + Σ income − Σ expense − Σ transfer keluar + Σ transfer masuk. Dihitung on-the-fly (bukan kolom saldo tersimpan) — hindari drift.
- **Transfer** = 1 baris transactions (type transfer, account_id = sumber, to_account_id = tujuan), tanpa kategori.
- **Posisi aset** = Σ units beli − Σ units jual; nilai = posisi × harga terbaru; gain/loss = nilai − cost basis (average cost).
- **Net worth** = Σ saldo semua akun (semua ruang personal) + nilai portfolio.

## 5. Modul & Halaman

1. **Auth:** register.php, login.php, logout, lupa password ditunda ke v1.1 (tidak ada email service dulu) — di UI ditulis "hubungi admin".
2. **Dashboard:** ringkasan bulan berjalan (masuk/keluar/selisih), saldo per akun, tagihan mendatang ≤7 hari, grafik arus kas 6 bulan (bar), pengeluaran per kategori bulan ini (donut), net worth.
3. **Transaksi:** daftar (infinite scroll / paging, kelompok per tanggal), filter (rentang tanggal, akun, kategori, jenis, teks), form tambah/edit cepat, hapus dengan konfirmasi.
4. **Akun:** CRUD, arsip (bukan hapus jika sudah ada transaksi), saldo per akun, riwayat.
5. **Kategori:** CRUD + seed default saat ruang dibuat (≈10 expense, ≈4 income), ikon & warna, sub-kategori 1 level.
6. **Budget:** set per kategori per bulan, salin dari bulan lalu, progress bar (hijau/kuning ≥80%/merah >100%).
7. **Recurring & tagihan:** CRUD template; mode `auto` (langsung posting saat jatuh tempo via pseudo-cron) atau `reminder` (muncul sebagai tagihan menunggu → user konfirmasi jadi transaksi). `next_run` maju sesuai frekuensi; catch-up maksimal 12 posting per run.
8. **Goals:** CRUD, setor dari akun (membuat transaksi expense kategori khusus "Tabungan Goal" + goal_entry), progress %, tandai selesai.
9. **Investasi:** CRUD aset, catat beli/jual, update harga manual, ringkasan portfolio (nilai, gain/loss nominal & %, alokasi per jenis — donut).
10. **Laporan:** bulanan & tahunan (per kategori, per akun), laba-rugi untuk ruang usaha, export CSV (transaksi terfilter).
11. **Pengaturan:** profil, ganti password, kelola ruang.

## 6. Struktur Direktori

```
fintracker/
  public/            # docroot
    index.php        # dashboard
    login.php, register.php, transaksi.php, akun.php, kategori.php,
    budget.php, recurring.php, goals.php, investasi.php, laporan.php,
    pengaturan.php
    api/             # endpoint AJAX JSON (satu file per resource)
    assets/          # css, js, ikon
  core/
    db.php           # koneksi PDO (config via env/file di luar git)
    auth.php         # guard session, CSRF, rate-limit
    helpers.php      # apiErr, formatRupiah, csrf, dll.
    balance.php      # kalkulasi saldo/net-worth
    recurring.php    # engine pseudo-cron posting
    portfolio.php    # kalkulasi posisi/gain-loss
  db/
    schema.sql       # skema penuh + seed kategori default
  tests/             # script test PHP CLI per modul
  docs/superpowers/specs/
```

## 7. Keamanan

- `user_id` & `space_id` aktif selalu dari session; setiap akses resource memvalidasi rantai kepemilikan (resource → space → user).
- Prepared statements semua query; output di-escape (`htmlspecialchars`).
- CSRF token wajib untuk semua request mutasi.
- Rate-limit login (per email+IP, tabel/log sederhana), password minimal 8 karakter.
- Header `no-store` di halaman dinamis.

## 8. Testing

- **Unit (PHP CLI, `tests/`):** kalkulasi saldo (termasuk transfer), progress budget, advance `next_run` recurring (termasuk catch-up), average-cost gain/loss, isolasi user/space (query negatif).
- **E2E smoke (gstack):** register → buat transaksi → transfer → budget → goal → aset → cek dashboard & laporan.

## 9. Di Luar Scope v1

- Harga investasi otomatis via API, multi-currency, lupa-password via email, push notification, PWA/offline, attachment foto struk, akuntansi double-entry, sharing ruang antar user, aplikasi native.
