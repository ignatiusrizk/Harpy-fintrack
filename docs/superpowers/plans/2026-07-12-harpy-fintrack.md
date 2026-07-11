# Harpy FinTrack Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bangun Harpy FinTrack — web app keuangan pribadi multi-user (PHP 8 + MySQL, Bahasa Indonesia, mobile-first) dengan transaksi, multi-akun, budget, recurring/tagihan, goals, investasi manual, ruang usaha, dashboard & laporan.

**Architecture:** PHP murni pola LaMaSy — halaman PHP per fitur + endpoint AJAX JSON di `public/api/`, core modules di `core/`. Semua query PDO prepared, di-scope `user_id` dari session dan `space_id` tervalidasi. Saldo/posisi dihitung on-the-fly. Pseudo-cron recurring jalan saat request user.

**Tech Stack:** PHP 8.5, MariaDB lokal (dev: db `fintracker`, user `fintracker`/`fintracker_dev`, socket default localhost), vanilla JS + CSS (tanpa framework), Chart rendering via `<canvas>` manual atau SVG sederhana (tanpa CDN eksternal).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-12-harpy-fintrack-design.md` — baca dulu.
- Bahasa UI: Indonesia. Format uang: `Rp 1.234.567` (helper `rupiah()`).
- SEMUA query ber-scope `user_id` dari session; resource selalu divalidasi rantai kepemilikan resource → space → user. TIDAK PERNAH terima `user_id` dari klien.
- Mutasi (POST) wajib CSRF token; klien kirim header `X-CSRF-Token` (kapitalisasi persis ini).
- Error API: pakai `apiErr($e)` — log server, JSON `{ok:false, error:"Terjadi kesalahan"}` generik. Jangan echo `$e->getMessage()`.
- Nominal `DECIMAL(15,2)`; units aset `DECIMAL(20,8)`. Mata uang IDR saja.
- Timezone `date_default_timezone_set('Asia/Jakarta')` di bootstrap; tanggal transaksi kolom `DATE`; jangan bandingkan datetime tulisan PHP dgn `NOW()` MySQL.
- Halaman dinamis kirim header `Cache-Control: no-store`.
- Dialog & konfirmasi pakai komponen custom (lmDialog), BUKAN `alert/confirm/prompt` native.
- Test CLI: `php tests/run.php` menjalankan semua `tests/test_*.php`; tiap file test exit non-zero saat gagal.
- Dev server: `php -S localhost:8081 -t public` dari root repo.
- Commit sering, pesan `feat|fix|test|docs(scope): ...` bahasa Indonesia ringkas.

---

### Task 1: Skeleton repo, schema DB, core bootstrap

**Files:**
- Create: `db/schema.sql`, `core/config.php` (gitignored) + `core/config.example.php`, `core/db.php`, `core/helpers.php`, `tests/run.php`, `tests/bootstrap.php`, `tests/test_helpers.php`, `.gitignore`, `README.md`
- Create: `public/assets/` (kosong dulu)

**Interfaces (Produces):**
- `db()` → PDO singleton (ERRMODE_EXCEPTION, FETCH_ASSOC).
- `rupiah(float|string $n): string` → `Rp 1.234.567` (bulatkan ke rupiah, minus → `-Rp 5.000`).
- `apiOk(array $data=[]): never` → echo JSON `{ok:true, ...data}` + exit. `apiErr(Throwable|string $e, int $http=500): never` → log + JSON `{ok:false,error:...}` generik (kalau string, tampilkan string itu dgn http 400).
- `post(string $k, $default=null)` / `get(string $k, $default=null)` — input helper trim.
- Schema: 13 tabel persis spec §4 + tabel `login_attempts (id, email, ip, attempted_at)`.

**Steps:**

- [ ] **1. Tulis `db/schema.sql`** — 14 tabel utf8mb4, InnoDB, FK ON DELETE CASCADE dari anak ke space, `spaces.user_id` FK ke users. Kolom persis spec §4. Index: `transactions(space_id, tx_date)`, `transactions(account_id)`, `budgets` UNIQUE `(space_id, category_id, period)`, `recurrings(space_id, is_active, next_run)`, `login_attempts(email, attempted_at)`.
- [ ] **2. Load schema:** `/opt/homebrew/bin/mariadb --no-defaults fintracker < db/schema.sql` lalu verifikasi `SHOW TABLES` = 14 tabel.
- [ ] **3. Tulis core:** `config.example.php` (return array host/name/user/pass), `config.php` versi dev, `db.php`, `helpers.php` (fungsi di atas + `e()` htmlspecialchars).
- [ ] **4. Tulis test runner** `tests/run.php` (glob `test_*.php`, jalankan tiap file via `passthru(PHP_BINARY.' '.$f, $code)`, rekap PASS/FAIL, exit max code) + `tests/bootstrap.php` (require core, fungsi `assertSame($exp,$got,$label)` yang echo ✓/✗ dan set exit code) + `tests/test_helpers.php` (kasus `rupiah`: 0, 1234567, -5000, "2500.75"→Rp 2.501).
- [ ] **5. Run:** `php tests/run.php` → semua PASS. Commit `feat(core): skeleton, schema, helpers`.

### Task 2: Auth multi-user + spaces + seed kategori

**Files:**
- Create: `core/auth.php`, `core/seed.php`, `public/login.php`, `public/register.php`, `public/logout.php`, `public/api/auth.php`, `tests/test_auth.php`
- Modify: `core/helpers.php` (csrf_token()/csrf_check())

**Interfaces (Produces):**
- `core/auth.php`: `requireLogin(): array` (redirect ke login.php kalau belum; return user row; juga panggil pseudo-cron hook kalau ada), `requireLoginApi(): array` (JSON 401), `currentSpaceId(): int` (dari `$_SESSION['space_id']`, validasi milik user, fallback space pertama), `attemptLogin($email,$pass): bool` (cek rate limit: max 5 gagal/15 menit per email+ip → `apiErr('Terlalu banyak percobaan...',429)`), `registerUser($name,$email,$pass): int`.
- `core/seed.php`: `createSpaceWithDefaults(int $userId, string $name, string $type): int` — buat space + seed kategori default (expense: Makan & Minum, Transportasi, Belanja, Tagihan & Utilitas, Kesehatan, Pendidikan, Hiburan, Rumah Tangga, Tabungan Goal, Lainnya; income: Gaji, Bonus, Hasil Investasi, Lainnya) masing-masing dgn icon emoji & warna hex; return space id.
- `csrf_token(): string` (session), `csrf_check(): void` (baca header `X-CSRF-Token`, mismatch → apiErr 419). Semua endpoint api POST wajib panggil.
- API `public/api/auth.php` action via `?a=`: `register`, `login`, `logout`.
- Session cookie httponly+samesite Lax; `session_regenerate_id(true)` saat login.
- Remember-me: checkbox "Ingat saya" saat login → cookie `ft_remember` `selector:validator` 30 hari (validator di-hash sha256 ke `remember_tokens`); `requireLogin` tanpa session tapi ada cookie valid → auto-login + rotasi token; logout hapus token+cookie.

**Steps:**

- [ ] **1. Test dulu** `tests/test_auth.php`: registerUser buat user + space Pribadi + ≥14 kategori seed; attemptLogin benar/salah; rate limit menolak percobaan ke-6; cleanup data test (email `test+auth@ft.local`).
- [ ] **2. Run → FAIL** (fungsi belum ada).
- [ ] **3. Implement** `core/auth.php`, `core/seed.php`, csrf helpers.
- [ ] **4. Run → PASS.**
- [ ] **5. Halaman** login.php & register.php: form sederhana mobile-first (style inline dulu, dipoles Task 3), fetch ke api/auth.php, redirect ke `index.php`. logout.php hapus session+cookie.
- [ ] **6. Smoke manual:** `php -S localhost:8081 -t public` + `curl` register/login (cek JSON ok:true). Commit `feat(auth): register/login multi-user, space & kategori default`.

### Task 3: UI shell — layout, bottom nav, dialog custom

**Files:**
- Create: `core/layout.php`, `public/assets/app.css`, `public/assets/app.js`, `public/assets/dialog.js`, `public/index.php` (placeholder dashboard)

**Interfaces (Produces):**
- `pageHeader(string $title, array $user): void` — html head (viewport, no-store sudah dikirim requireLogin), header app (judul + space switcher dropdown custom), buka `<main>`.
- `pageFooter(string $active): void` — tutup main + bottom nav 5 item: `index.php` (Dashboard 🏠), `transaksi.php` (📒), tombol tengah `+` (buka sheet tambah transaksi — di Task 5 baru berfungsi, sementara arahkan ke transaksi.php), `budget.php` (🎯), `lainnya.php` (menu: Akun, Kategori, Recurring, Goals, Investasi, Laporan, Pengaturan, Logout).
- `dialog.js`: `lmAlert(msg,title?)`, `lmConfirm(msg,title?) → Promise<bool>`, `lmPrompt(msg,default?) → Promise<string|null>` — overlay div, animasi ringan, promise-based.
- `app.js`: `api(url, data?) → Promise<json>` (POST JSON + header X-CSRF-Token dari meta tag; lempar error dgn pesan server), `rupiahFmt(n)`, `toast(msg)`.
- CSS: variabel `--teal:#1FC0CB` (aksen keluarga Harpy), light theme bersih, kartu rounded-16, bottom nav fixed, safe-area inset, max-width 480px centered di desktop.

**Steps:**

- [ ] **1. Implement semua file di atas.** index.php sementara: sapaan + saldo placeholder.
- [ ] **2. Verifikasi visual:** buka `http://localhost:8081/login.php` & index via browser/gstack — nav tampil, dialog jalan (tombol tes sementara di lainnya.php).
- [ ] **3. Commit** `feat(ui): shell layout, bottom nav, dialog custom`.

### Task 4: Akun + balance engine

**Files:**
- Create: `core/balance.php`, `public/akun.php`, `public/api/akun.php`, `tests/test_balance.php`

**Interfaces (Produces):**
- `core/balance.php`: `accountBalance(int $accountId): float`; `spaceBalances(int $spaceId): array` `[account_id => ['name','type','balance']]` (1 query agregat, bukan N+1); `netWorth(int $userId): float` (Σ saldo semua akun semua space personal user + `portfolioValue($userId)` — panggil kalau fungsi ada (`function_exists`), sampai Task 10 hanya akun).
- Rumus saldo: initial + income − expense − transfer keluar + transfer masuk (baris `type='transfer'` dgn `to_account_id`).
- API `api/akun.php` `?a=`: `list` (dgn saldo), `create`, `update`, `archive` (kalau punya transaksi → is_archived=1, kalau tidak → DELETE). Field: name (wajib), type (cash|bank|ewallet|other), initial_balance (default 0).
- Validasi kepemilikan: helper `ownSpace(int $spaceId)` & `ownAccount(int $id)` (JOIN sampai user_id session; gagal → apiErr 404) — taruh di `core/helpers.php`, dipakai semua task berikut. Tambahkan juga `ownCategory`, `ownTransaction`, dst. saat dibutuhkan dgn pola sama.

**Steps:**

- [ ] **1. Test** `tests/test_balance.php`: seed user+space+2 akun (initial 100rb & 0), income 50rb, expense 20rb, transfer 30rb A→B → saldo A=100rb, B=30rb; spaceBalances cocok; user lain TIDAK bisa `ownAccount` akun ini (harus throw/404). Cleanup.
- [ ] **2. Run → FAIL. 3. Implement. 4. Run → PASS.**
- [ ] **5. Halaman** akun.php: list kartu akun (ikon per type, saldo, badge arsip), FAB tambah, sheet form (nama/jenis/saldo awal), edit & arsip via lmConfirm.
- [ ] **6. Commit** `feat(akun): CRUD akun + balance engine`.

### Task 5: Kategori + Transaksi

**Files:**
- Create: `public/kategori.php`, `public/api/kategori.php`, `public/transaksi.php`, `public/api/transaksi.php`, `tests/test_transaksi.php`

**Interfaces (Produces):**
- API kategori `?a=`: `list` (tree parent→anak per type), `create` (name,type,icon,color,parent_id), `update`, `delete` (tolak jika dipakai transaksi/budget → pesan jelas).
- API transaksi `?a=`: `list` (filter: `from`,`to` (DATE), `account_id`, `category_id`, `type`, `q` di note; paging `page` 50/hal; return rows + total agregat masuk/keluar utk filter aktif), `create`, `update`, `delete`. `create` type=transfer wajib `to_account_id` beda akun satu space, tanpa kategori; income/expense wajib `category_id`. Amount > 0.
- transaksi.php: header ringkas bulan berjalan (masuk/keluar), filter bar (chips: bulan ini default, custom range pakai date custom), list dikelompok per tanggal, tiap baris ikon kategori + note + amount berwarna (hijau/merah/abu transfer), tap → sheet edit. Sheet tambah (dipanggil juga dari tombol `+` bottom nav via `?add=1`): segmented income/expense/transfer, amount pad besar, pilih akun & kategori (sheet picker custom), tanggal (default hari ini), note.

**Steps:**

- [ ] **1. Test** `tests/test_transaksi.php`: create income/expense/transfer valid; transfer ke akun sendiri ditolak; transfer lintas space ditolak; amount ≤0 ditolak; delete kategori terpakai ditolak; filter list by category & range benar. Cleanup.
- [ ] **2. FAIL → 3. Implement API → 4. PASS.**
- [ ] **5. Halaman** kategori.php & transaksi.php sesuai kontrak di atas; sambungkan tombol `+` nav.
- [ ] **6. Smoke gstack:** login → tambah 3 transaksi → cek list & saldo akun berubah. Commit `feat(transaksi): kategori + pencatatan transaksi & transfer`.

### Task 6: Budget

**Files:**
- Create: `core/budget.php`, `public/budget.php`, `public/api/budget.php`, `tests/test_budget.php`

**Interfaces (Produces):**
- `budgetStatus(int $spaceId, string $period): array` — per kategori expense yang punya budget di period: `['category_id','name','icon','color','amount','spent','pct']`; `spent` = Σ expense kategori itu (termasuk sub-kategori: anak dihitung ke parent kalau budget di parent) di bulan itu.
- API `?a=`: `list` (=budgetStatus + kategori tanpa budget), `set` (category_id, period 'YYYY-MM', amount; amount 0 = hapus), `copy_prev` (salin semua budget bulan sebelumnya ke period; yang sudah ada di-skip).
- budget.php: selector bulan (chevron kiri/kanan), total budget vs total spent, list progress bar per kategori — hijau, kuning ≥80%, merah >100% + label "Lewat Rp X".

**Steps:**

- [ ] **1. Test:** set budget 500rb Makan, expense 400rb → pct 80; expense sub-kategori masuk parent; copy_prev tidak menimpa. Cleanup.
- [ ] **2. FAIL → 3. Implement → 4. PASS → 5. Halaman → 6. Commit** `feat(budget): anggaran bulanan per kategori`.

### Task 7: Recurring & tagihan (pseudo-cron)

**Files:**
- Create: `core/recurring.php`, `public/recurring.php`, `public/api/recurring.php`, `tests/test_recurring.php`
- Modify: `core/auth.php` (requireLogin panggil `runRecurringForUser($userId)` maks 1×/15 menit per session)

**Interfaces (Produces):**
- `advanceNextRun(string $nextRun, string $frequency): string` — daily +1d, weekly +7d, monthly +1 bulan (anchor day; 31 Jan → 28/29 Feb pakai "last day" clamp), yearly +1 thn.
- `runRecurringForUser(int $userId): int` — utk semua recurring aktif user dgn `next_run <= today(WIB)`: mode `auto` → insert transaksi (recurring_id diisi) & maju next_run, loop catch-up **maks 12 posting per recurring per run**; mode `reminder` → tidak posting, biarkan (UI yang menampilkan due). Return jumlah posting.
- API `?a=`: `list` (aktif + nonaktif, tampilkan next_run & due reminder), `create`/`update` (account, category, type income|expense, amount, note, frequency, start date → next_run awal, mode), `toggle`, `delete`, `confirm` (utk mode reminder: posting transaksi sekarang + maju next_run), `skip` (maju next_run tanpa posting).
- Dashboard (Task 9) baca "tagihan mendatang": recurring aktif `next_run <= today+7`.

**Steps:**

- [ ] **1. Test:** advanceNextRun kasus 2026-01-31 monthly → 2026-02-28; catch-up recurring telat 100 hari daily → tepat 12 posting; mode reminder tidak auto-post; confirm posting & maju. Cleanup.
- [ ] **2. FAIL → 3. Implement → 4. PASS.**
- [ ] **5. Halaman** recurring.php: seksi "Menunggu konfirmasi" (reminder due, tombol Catat/Lewati), list template dgn badge frekuensi & next_run, form sheet. Hook pseudo-cron di requireLogin.
- [ ] **6. Commit** `feat(recurring): transaksi berulang & tagihan, pseudo-cron`.

### Task 8: Goals (target tabungan)

**Files:**
- Create: `public/goals.php`, `public/api/goals.php`, `tests/test_goals.php`

**Interfaces (Produces):**
- API `?a=`: `list` (dgn `saved` = Σ goal_entries.amount, pct), `create` (name, target_amount, target_date?), `update`, `delete` (entri ikut terhapus, transaksi terkait TIDAK dihapus — goal_id di transaksi jadi NULL), `deposit` (goal_id, account_id, amount, date → buat transaksi expense kategori "Tabungan Goal" milik space + goal_entry terkait; kategori dicari by name, buat jika belum ada), `withdraw` (kebalikan: transaksi income "Tabungan Goal" + entry minus), `finish` (is_done=1).
- goals.php: kartu goal dgn ring/bar progress, sisa hari ke target_date, tombol Setor/Tarik (sheet pilih akun+nominal), goal selesai pindah seksi bawah.

**Steps:**

- [ ] **1. Test:** deposit bikin transaksi + entry, saved & pct benar; withdraw mengurangi; delete goal null-kan goal_id transaksi. Cleanup.
- [ ] **2. FAIL → 3. Implement → 4. PASS → 5. Halaman → 6. Commit** `feat(goals): target tabungan dgn setor/tarik`.

### Task 9: Investasi (portfolio manual)

**Files:**
- Create: `core/portfolio.php`, `public/investasi.php`, `public/api/investasi.php`, `tests/test_portfolio.php`

**Interfaces (Produces):**
- `assetPosition(int $assetId): array` — `['units','cost','avg_price','last_price','value','gain','gain_pct']`. Average cost: beli menambah cost = units×price+fee; jual mengurangi cost proporsional (avg cost method); jual > posisi ditolak di API.
- `portfolioSummary(int $spaceId): array` — list posisi per aset + total value/cost/gain + alokasi per type (persen). `portfolioValue(int $userId): float` — Σ value semua space user (dipakai netWorth; setelah task ini netWorth otomatis menyertakannya via function_exists hook Task 4).
- API `?a=`: `list` (=portfolioSummary), `create_asset` (name, type stock|mutual_fund|gold|crypto|deposit|other, code?, unit_label default "unit"), `update_asset`, `delete_asset` (tolak kalau punya transaksi), `trade` (asset_id, side buy|sell, units, price_per_unit, fee default 0, tx_date), `delete_trade`, `set_price` (asset_id, price_per_unit → insert asset_prices), `history` (trades + prices per aset).
- investasi.php: kartu total portfolio (value, gain hijau/merah, %), donut alokasi per jenis (SVG), list aset (posisi, nilai, gain%) → detail sheet (riwayat, tombol Beli/Jual/Update Harga).
- Catatan: transaksi investasi TIDAK menyentuh tabel transactions/akun (dana dianggap eksternal) — jelaskan di UI ("catat pembelian dari akun secara terpisah bila ingin saldo akun ikut berkurang").

**Steps:**

- [ ] **1. Test:** beli 10@1000 fee 10 → cost 10.010; beli 10@1200 → avg 1.100,5; jual 5 → cost berkurang proporsional; set_price 1300 → value & gain benar; jual 100 unit (over) ditolak. Cleanup.
- [ ] **2. FAIL → 3. Implement → 4. PASS → 5. Halaman → 6. Commit** `feat(investasi): portfolio manual avg-cost + harga manual`.

### Task 10: Dashboard

**Files:**
- Modify: `public/index.php`
- Create: `public/api/dashboard.php`, `public/assets/charts.js`

**Interfaces (Produces):**
- API `?a=summary`: bulan berjalan (income, expense, selisih), saldo per akun, net worth (netWorth()), tagihan ≤7 hari (dari recurring), arus kas 6 bulan terakhir `[{period,income,expense}]`, top 6 kategori expense bulan ini `[{name,icon,color,amount}]`, ringkasan portfolio (value, gain_pct) kalau ada aset.
- `charts.js`: `barChart(canvas, labels, series)` dua bar per bulan (hijau income/merah expense) & `donutChart(svgEl, items)` — vanilla, tanpa lib.
- index.php: kartu net worth (tap → detail per akun), kartu bulan ini, kartu tagihan mendatang (link recurring.php), bar chart 6 bulan, donut kategori, kartu portfolio (link investasi.php). Ruang usaha aktif → tambah kartu "Laba/Rugi bulan ini".

**Steps:**

- [ ] **1. Implement API + charts + halaman** (logika agregat sudah teruji di task sebelumnya; test tambahan tak wajib).
- [ ] **2. Verifikasi gstack:** dashboard render semua kartu dgn data seed manual. Commit `feat(dashboard): ringkasan, grafik arus kas & kategori`.

### Task 11: Laporan + export CSV + laba-rugi usaha

**Files:**
- Create: `public/laporan.php`, `public/api/laporan.php`, `public/export.php`, `tests/test_laporan.php`

**Interfaces (Produces):**
- API `?a=monthly` (period YYYY-MM) & `?a=yearly` (year): per kategori (income & expense terpisah, sub digulung ke parent + rincian), per akun (masuk/keluar/net), total. `?a=pnl` (space business; period atau year): pendapatan per kategori − beban per kategori = laba bersih.
- `export.php?from=&to=&...` (filter sama dgn list transaksi): stream CSV `tanggal;jenis;kategori;akun;jumlah;catatan` (Content-Disposition attachment, delimiter `;`, UTF-8 BOM biar rapi di Excel).
- laporan.php: tab Bulanan|Tahunan (+ tab Laba-Rugi kalau space business), selector periode, tabel per kategori & per akun, tombol Export CSV.

**Steps:**

- [ ] **1. Test:** agregat monthly benar (sub→parent), pnl = income−expense per period, CSV stream berisi baris sesuai filter (tangkap via output buffering di test). Cleanup.
- [ ] **2. FAIL → 3. Implement → 4. PASS → 5. Halaman → 6. Commit** `feat(laporan): bulanan/tahunan, laba-rugi usaha, export CSV`.

### Task 12: Pengaturan + kelola ruang + halaman Lainnya

**Files:**
- Create: `public/pengaturan.php`, `public/api/pengaturan.php`, `public/lainnya.php`
- Modify: `core/layout.php` (space switcher berfungsi penuh)

**Interfaces (Produces):**
- API `?a=`: `profile` (update name), `password` (verifikasi lama, min 8), `space_create` (name, type — panggil createSpaceWithDefaults), `space_rename`, `space_delete` (tolak kalau satu-satunya; konfirmasi ganda di UI; CASCADE hapus isi), `space_switch` (set session space_id tervalidasi).
- lainnya.php: menu grid semua fitur + info versi.
- Space switcher di header: sheet daftar ruang (badge Pribadi/Usaha) + tombol "+ Ruang usaha baru".

**Steps:**

- [ ] **1. Implement + smoke manual** (ganti password, buat ruang usaha, pindah ruang → data terpisah).
- [ ] **2. Commit** `feat(pengaturan): profil, password, kelola ruang`.

### Task 13: E2E smoke + hardening pass

**Files:**
- Create: `tests/e2e-checklist.md` (hasil), perbaikan bug yang ketemu.

**Steps:**

- [ ] **1. E2E via gstack (user baru penuh):** register → onboarding kosong → buat akun Bank & Cash → 5 transaksi (incl. transfer) → budget + lihat progress → recurring auto & reminder (set next_run kemarin via SQL, reload → auto-post & reminder muncul) → goal setor → aset beli + set harga → dashboard & laporan konsisten → buat ruang Usaha → catat pendapatan/beban → laba-rugi benar → export CSV → logout/login lagi.
- [ ] **2. Hardening checklist:** akses api tanpa login → 401 JSON; CSRF salah → 419; user B akses resource user A via id → 404; SQLi coba `' OR 1=1` di filter; XSS note `<script>` ter-escape; mobile viewport 375px tanpa overflow-x (cek 6 kategori overflow: tabel/tab bar/grid metrik/kartu list/toolbar/grid inline).
- [ ] **3. Run `php tests/run.php` penuh → semua PASS.** Perbaiki temuan, commit `fix(e2e): temuan smoke test`, tulis hasil di `tests/e2e-checklist.md`, commit.
