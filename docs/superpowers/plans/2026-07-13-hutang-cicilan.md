# Modul Hutang/Piutang & Cicilan — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambah modul Hutang (payable) / Piutang (receivable) / Cicilan ke Harpy FinTrack v1: lacak sisa pokok, pembayaran jadi transaksi otomatis, ikut Net Worth, jatuh tempo muncul di dashboard.

**Architecture:** Ikuti pola v1 persis — 2 tabel baru (`debts`, `debt_payments`) + 1 kolom (`transactions.debt_id`), core logic `core/hutang.php`, endpoint `public/api/hutang.php`, halaman `public/hutang.php`. Kalkulasi sisa on-the-fly. Reuse `advanceNextRun()` (recurring), hook `function_exists` di netWorth (pola portfolio), guard transaksi-tertaut (pola goal), kategori on-demand (pola `glCategory`).

**Tech Stack:** PHP 8.5, MariaDB lokal `fintracker`, vanilla JS/CSS. Spec: `docs/superpowers/specs/2026-07-13-hutang-cicilan-design.md` — baca dulu.

## Global Constraints

- Baca spec sebelum mulai. Bahasa UI Indonesia; uang `rupiah()` (PHP) / `rupiahFmt()` (JS).
- SEMUA query PDO prepared. `user_id`/`space_id` dari SESSION saja (`currentSpaceId()`), tak pernah dari klien. Setiap akses per-id validasi rantai resource→space→user via `ownX` → `apiErr('Tidak ditemukan',404)`.
- Mutasi POST: `requireLoginApi()` (di atas endpoint) + `csrf_check()` per aksi. Klien kirim header `X-CSRF-Token`.
- Error API: `apiErr($e)` — Throwable → JSON generik `{ok:false,error:"Terjadi kesalahan"}`, JANGAN bocorkan getMessage(); string → pesan itu, http default 400.
- Uang `DECIMAL(15,2)`. Tanggal kolom `DATE`; logika tanggal pakai PHP `date()` WIB, JANGAN bandingkan dgn `NOW()` MySQL. Validasi tanggal `checkdate()`.
- Operasi multi-tabel (create+disburse, pay) dibungkus `db()->beginTransaction()/commit()`, `rollBack()` di catch & sebelum apiErr; baris debt dikunci `SELECT ... FOR UPDATE` (pola `depositGoal`, core/goals.php:194+).
- XSS: data user via `e()` (server) / `textContent` (client), JANGAN `innerHTML`.
- DB dev CLI: `/opt/homebrew/bin/mariadb --no-defaults fintracker` (JANGAN `mysql` polos — ~/.my.cnf = server lain).
- Test: tambah `tests/test_hutang.php`; `php tests/run.php` (auto-discover `test_*.php`) SEMUA PASS sebelum tiap commit. Test seed langsung via PDO, set `$_SESSION['user_id']`/`['space_id']` bila memanggil fungsi ber-`ownX` di CLI (ownX memanggil ensureSession→session_start, jadi `@session_start()` dulu baru isi $_SESSION).
- Commit `feat|fix|test(hutang): ...` Indonesia ringkas. Kerjakan dari `/Users/rizky/Documents/fintracker` (branch `main`; buat branch kerja `feat/hutang` di Task 1).

---

### Task 1: Skema DB + core kalkulasi (schema, outstanding, ownDebt, kategori)

**Files:**
- Modify: `db/schema.sql` (tambah tabel `debts`, `debt_payments`, kolom `transactions.debt_id`)
- Create: `core/hutang.php`
- Modify: `core/helpers.php` (tambah `ownDebt`)
- Test: `tests/test_hutang.php`

**Interfaces (Produces):**
- `debtOutstanding(int $debtId): float` — `principal − Σ debt_payments.amount`.
- `ownDebt(int $debtId): array` — JOIN debts→spaces→user_id session; gagal → `apiErr('Tidak ditemukan',404)`; return debt row.
- `debtCategory(int $spaceId, string $key): int` — key ∈ {`pay`,`receive`,`disburse_in`,`disburse_out`} → cari/buat kategori per ruang, return category_id. Mapping: `pay`→expense "Bayar Utang/Cicilan", `receive`→income "Terima Piutang", `disburse_in`→income "Pencairan Pinjaman", `disburse_out`→expense "Beri Pinjaman".

**Steps:**

- [ ] **1. Branch kerja:** `git checkout main && git pull && git checkout -b feat/hutang`.
- [ ] **2. Edit `db/schema.sql`** — tambah setelah tabel `goal_entries` (utf8mb4, InnoDB):

```sql
CREATE TABLE debts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  space_id BIGINT UNSIGNED NOT NULL,
  direction ENUM('payable','receivable') NOT NULL,
  party VARCHAR(100) NOT NULL,
  principal DECIMAL(15,2) NOT NULL,
  note VARCHAR(255) NULL,
  start_date DATE NOT NULL,
  due_date DATE NULL,
  is_installment TINYINT(1) NOT NULL DEFAULT 0,
  installment_count INT NULL,
  installment_amount DECIMAL(15,2) NULL,
  frequency ENUM('weekly','monthly','yearly') NULL,
  next_due DATE NULL,
  status ENUM('active','settled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_debts_space_status (space_id, status),
  KEY idx_debts_space_nextdue (space_id, next_due),
  CONSTRAINT fk_debts_space FOREIGN KEY (space_id) REFERENCES spaces (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE debt_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  debt_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  pay_date DATE NOT NULL,
  transaction_id BIGINT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_debt_payments_debt (debt_id),
  CONSTRAINT fk_debt_payments_debt FOREIGN KEY (debt_id) REFERENCES debts (id) ON DELETE CASCADE,
  CONSTRAINT fk_debt_payments_tx FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Dan tambah kolom + FK ke tabel `transactions` (kolom `debt_id BIGINT UNSIGNED NULL` setelah `goal_id`, dan constraint):
```sql
  debt_id BIGINT UNSIGNED NULL,
  ...
  CONSTRAINT fk_transactions_debt FOREIGN KEY (debt_id) REFERENCES debts (id) ON DELETE SET NULL
```

- [ ] **3. Terapkan ke DB dev (idempoten via ALTER, karena tabel `transactions` sudah ada berisi data):**
```
/opt/homebrew/bin/mariadb --no-defaults fintracker < db/schema.sql   # tabel baru; utk kolom transactions pakai ALTER berikut bila CREATE gagal (tabel sudah ada):
/opt/homebrew/bin/mariadb --no-defaults fintracker -e "ALTER TABLE transactions ADD COLUMN debt_id BIGINT UNSIGNED NULL AFTER goal_id, ADD CONSTRAINT fk_transactions_debt FOREIGN KEY (debt_id) REFERENCES debts(id) ON DELETE SET NULL;"
```
Verifikasi: `SHOW TABLES LIKE 'debt%'` = 2 baris; `SHOW COLUMNS FROM transactions LIKE 'debt_id'` = 1 baris. (schema.sql tetap sumber kebenaran utk fresh install — pastikan `transactions` di schema.sql juga punya kolom+FK debt_id.)

- [ ] **4. Tulis test dulu** `tests/test_hutang.php` (pola `tests/test_goals.php`): bootstrap, `@session_start()` lalu set `$_SESSION['user_id']`/`['space_id']`; buat user+space+akun via PDO; insert `debts` payable principal 1.000.000 + 2 `debt_payments` (300.000, 200.000) → assert `debtOutstanding` == 500.000; assert `debtCategory($sid,'pay')` membuat kategori expense "Bayar Utang/Cicilan" dan idempoten (panggil 2× → id sama); assert `ownDebt` user lain → gagal (jalankan di subprocess yang menangkap exit, pola test_goals). Cleanup.
- [ ] **5. Run → FAIL:** `php tests/test_hutang.php` (fungsi belum ada).
- [ ] **6. Implement `core/hutang.php`** — header `require_once db.php, helpers.php`; `debtOutstanding`, `debtCategory` (pola `glCategory` core/goals.php:23 — SELECT by (space_id,name,type), buat bila belum ada), dan `ownDebt` di `core/helpers.php` (pola `ownAsset` core/helpers.php, JOIN debts→spaces).
- [ ] **7. Run → PASS.** `php tests/run.php` SEMUA PASS.
- [ ] **8. Commit** `feat(hutang): skema debts/debt_payments + core outstanding & ownDebt`.

### Task 2: Core operasi — createDebt, payDebt, updateDebt, deleteDebt, settle, summary, history, netWorth

**Files:**
- Modify: `core/hutang.php`
- Modify: `core/transaksi.php` (dukung `debt_id` di `createTransaction` — lihat Task 3 untuk guard; DI SINI hanya insert support)
- Test: `tests/test_hutang.php`

**Interfaces (Consumes):** `debtOutstanding`, `debtCategory`, `ownDebt` (Task 1); `createTransaction(int $spaceId, array $data): array` (v1, core/transaksi.php:149 — terima key `type,amount,account_id,category_id,tx_date,note`, DAN tambahkan dukungan `debt_id` di sini); `advanceNextRun(string $nextRun, string $frequency, ?string $anchorDate=null): string` (core/recurring.php:42 — `require_once core/recurring.php`); `txAccountInSpace(int $accountId, int $spaceId): array` (core/transaksi.php:24).

**Interfaces (Produces):**
- `createDebt(int $spaceId, array $data): array` — validasi direction∈{payable,receivable}, party 1–100, principal>0, start_date `checkdate`, due_date opsional valid; cicilan (is_installment) → installment_count≥1, installment_amount>0, frequency∈{weekly,monthly,yearly}, next_due=start_date. Opsi `disburse` (truthy) + `account_id`: buat transaksi kas awal tertaut debt_id (payable→income `disburse_in`, receivable→expense `disburse_out`) dalam transaksi DB yg sama. Return debt row + `outstanding`.
- `payDebt(int $debtId, int $accountId, $amountRaw, ?string $date, ?string $expectedNextDue=null): array` — FOR UPDATE baris debt; tolak settled; tolak amount≤0 atau amount>outstanding+1e-6; bila cicilan & `expectedNextDue` dikirim & ≠ next_due baris → `apiErr(...,409)`; buat transaksi (payable→expense `pay`, receivable→income `receive`) dari accountId (validasi `txAccountInSpace` ke space debt) tertaut debt_id; insert `debt_payments` (transaction_id terisi); bila cicilan → `next_due = advanceNextRun(next_due, frequency, start_date)`; auto-settle bila outstanding≤1e-6. Return {outstanding, status, next_due}.
- `updateDebt(int $debtId, array $data): array` — ownDebt; ubah party/note/due_date; principal hanya boleh diubah bila belum ada payment (kalau ada → apiErr 400 "Tidak bisa ubah pokok, sudah ada pembayaran").
- `deleteDebt(int $debtId): void` — ownDebt; `DELETE FROM debts` (payments CASCADE; transaksi kas → debt_id SET NULL via FK).
- `settleDebt(int $debtId): array` — ownDebt; set status='settled'.
- `debtSummary(int $spaceId): array` — `{payable:[...], receivable:[...], total_payable, total_receivable}`; tiap item: id, party, principal, outstanding, progress_pct, is_installment, installment_count, paid_count (COUNT payments), next_due, due_date, status. Aktif dulu, settled di akhir tiap arah.
- `debtNetWorth(int $userId): float` — Σ receivable.outstanding − Σ payable.outstanding, status='active', ruang `type='personal'` user.
- `debtHistory(int $debtId): array` — ownDebt; payments (join transactions.note bila ada) urut `pay_date DESC, id DESC` LIMIT 50.

**Steps:**

- [ ] **1. Test dulu** (perluas `tests/test_hutang.php`): (a) `createDebt` payable + disburse akun → outstanding=principal, ada 1 transaksi income kategori "Pencairan Pinjaman", saldo akun naik; (b) `payDebt` payable 300rb → transaksi expense "Bayar Utang/Cicilan", saldo akun turun 300rb, outstanding turun; pay>outstanding ditolak; pay ke settled ditolak; (c) receivable pay → transaksi income "Terima Piutang", saldo naik; (d) cicilan: createDebt is_installment count 12 amount 850rb freq monthly next_due=start; payDebt → next_due maju 1 bulan (anchor), paid_count=1; expectedNextDue basi → 409, tidak posting; (e) `updateDebt` principal setelah ada payment → ditolak; (f) `deleteDebt` → payments hilang, transaksi kas tetap ada `debt_id` NULL; (g) `debtNetWorth`: 1 payable sisa 500rb + 1 receivable sisa 800rb → +300rb; (h) auto-settle saat dibayar lunas → status settled. Cleanup semua.
- [ ] **2. Run → FAIL.**
- [ ] **3. Tambahkan dukungan `debt_id`** di `createTransaction` (core/transaksi.php): baca `$debtId = !empty($data['debt_id']) ? (int)$data['debt_id'] : null;`, sertakan di INSERT kolom `debt_id` dan return array (pola persis `goal_id` di core/transaksi.php:153-170).
- [ ] **4. Implement** fungsi-fungsi Produces di `core/hutang.php` (require_once recurring.php untuk advanceNextRun). Bungkus createDebt(+disburse) & payDebt dalam transaksi DB + FOR UPDATE.
- [ ] **5. Run → PASS;** `php tests/run.php` SEMUA PASS.
- [ ] **6. Commit** `feat(hutang): create/pay/update/delete/settle + summary, history, netWorth`.

### Task 3: Guard transaksi tertaut + integrasi netWorth & dashboard

**Files:**
- Modify: `core/transaksi.php` (perluas guard goal → juga debt)
- Modify: `core/balance.php` (netWorth + debtNetWorth via function_exists)
- Modify: `core/dashboard.php` (dbSummaryUpcoming + debts due)
- Test: `tests/test_hutang.php`, sanity `tests/test_transaksi.php` tetap PASS

**Interfaces (Consumes):** `debtNetWorth` (Task 2), `debtSummary`/debts table; `txRejectIfGoalLinked(array $existing): void` (core/transaksi.php:192); `netWorth(int $userId): float` (core/balance.php:97); `dbSummaryUpcoming(int $spaceId, string $today): array` (core/dashboard.php:47).

**Steps:**

- [ ] **1. Test dulu**: (a) buat transaksi tertaut debt (via payDebt), lalu `updateTransaction`/`deleteTransaction` transaksi itu → ditolak dgn pesan mengarah ke Hutang; setelah `deleteDebt` (debt_id jadi NULL) transaksi itu bisa dihapus; (b) `netWorth(user)` turun sebesar sisa payable & naik sebesar sisa receivable dibanding tanpa debt; (c) `dbSummaryUpcoming` memuat cicilan next_due≤today+7 dan non-cicilan due_date≤today+7 dgn penanda `kind='debt'`. Cleanup.
- [ ] **2. Run → FAIL.**
- [ ] **3. Perluas guard** di `core/transaksi.php`: rename konsep — buat `txRejectIfLinked(array $existing): void` yang menolak bila `goal_id` ATAU `debt_id` terisi (pesan: goal → "…Goals (Setor/Tarik)."; debt → "Transaksi ini terkait utang/cicilan. Kelola lewat halaman Hutang."). Panggil dari update & delete (ganti pemanggilan `txRejectIfGoalLinked`; boleh biarkan `txRejectIfGoalLinked` sebagai wrapper deprecated atau hapus & ganti semua pemanggil — pilih ganti semua pemanggil ke `txRejectIfLinked`). Pastikan `ownTransaction`/`SELECT t.*` mengembalikan kolom `debt_id`.
- [ ] **4. netWorth**: di `core/balance.php`, setelah blok portfolioValue, tambah:
```php
    if (function_exists('debtNetWorth')) {
        $total += debtNetWorth($userId);
    }
```
dan `require_once __DIR__ . '/hutang.php';` di header balance.php (pola baris 12 portfolio.php) supaya hook selalu ada.
- [ ] **5. dashboard**: di `core/dashboard.php` `dbSummaryUpcoming`, setelah mengumpulkan recurring, query debts space aktif status active dgn `(is_installment=1 AND next_due IS NOT NULL AND next_due<=?) OR (is_installment=0 AND due_date IS NOT NULL AND due_date<=?)` (bind `$until` dua kali), map ke item dgn `kind='debt'`, `label`=party, `amount`=installment_amount (cicilan) / outstanding (non-cicilan), tanggal due, badge "Cicilan"/"Jatuh tempo"; gabung & urut tanggal terdekat. Beri key `kind` juga pada item recurring (`kind='recurring'`) supaya UI bisa bedakan.
- [ ] **6. Run → PASS;** `php tests/run.php` SEMUA PASS (termasuk test_transaksi & test_dashboard lama).
- [ ] **7. Commit** `feat(hutang): guard transaksi tertaut, netWorth & tagihan dashboard`.

### Task 4: API endpoint `public/api/hutang.php`

**Files:**
- Create: `public/api/hutang.php`
- Test: verifikasi via curl (didokumentasikan di report)

**Interfaces (Consumes):** semua fungsi core Task 1–2; `requireLoginApi()`, `currentSpaceId()`, `csrf_check()`, `post()`, `apiOk()`, `apiErr()`.

**Steps:**

- [ ] **1. Implement** `public/api/hutang.php` (pola `public/api/goals.php`): `requireLoginApi()` di atas; `$a = $_GET['a'] ?? ''`; switch:
  - `list` (GET, tanpa csrf) → `apiOk(debtSummary(currentSpaceId()))`.
  - `create` → csrf_check; kumpulkan field dari `post()`; `createDebt(currentSpaceId(), $data)`.
  - `pay` → csrf_check; `ownDebt((int)post('debt_id'))`; `payDebt(id, (int)post('account_id'), post('amount'), post('date'), post('expected_next_due'))`.
  - `update` → csrf_check; ownDebt; `updateDebt`.
  - `delete` → csrf_check; `deleteDebt((int)post('debt_id'))`.
  - `settle` → csrf_check; `settleDebt((int)post('debt_id'))`.
  - `history` → `debtHistory((int)($_GET['debt_id'] ?? 0))` (ownDebt di dalam).
  - default → `apiErr('Aksi tidak dikenal', 400)`.
- [ ] **2. Smoke via curl** (server `php -S localhost:8091 -t public`, cookie jar, ambil token dari `<meta name="csrf">` halaman): register user coba → create debt payable+cicilan → list → pay → history → delete. Cek JSON `ok:true` & angka. Bersihkan user coba dari DB.
- [ ] **3. `php tests/run.php` PASS. Commit** `feat(hutang): endpoint API list/create/pay/update/delete/settle/history`.

### Task 5: UI `public/hutang.php` + menu + E2E

**Files:**
- Create: `public/hutang.php`
- Modify: `public/lainnya.php` (tambah entri menu Hutang)
- Modify: `public/assets/app.css` (style seksi baru, blok berkomentar)
- Test: E2E via gstack, dicatat

**Interfaces (Consumes):** `pageHeader($title,$user)`/`pageFooter('lainnya')` (core/layout.php); JS `api(url,data)`, `rupiahFmt(n)`, `toast(msg)`, `lmConfirm/lmPrompt` (public/assets/app.js, dialog.js); pola sheet + disable-submit di `public/goals.php`; endpoint Task 4.

**Steps:**

- [ ] **1. Implement `public/hutang.php`** (pola `public/goals.php`, `$active='lainnya'`): 
  - Init dalam `DOMContentLoaded` (hindari race app.js — pelajaran v1).
  - Header + ringkas total utang & piutang.
  - Dua seksi Utang/Piutang: kartu (pihak, sisa/pokok via textContent, progress bar, badge due/cicilan n/m). Seksi Lunas collapsed.
  - Tap kartu → sheet detail: riwayat pembayaran (fetch `history`), tombol **Bayar/Terima** (sheet: pilih akun, nominal default installment_amount, tanggal; kirim `expected_next_due` bila cicilan), **Edit**, **Tandai lunas** (lmConfirm), **Hapus** (lmConfirm — teks: "Transaksi kas yang sudah tercatat TIDAK ikut terhapus").
  - FAB **+** → sheet tambah: segmented Utang/Piutang, party, principal, start_date (default today), due_date opsional (date custom); toggle Cicilan → count + amount + frequency (select custom); toggle "Dana masuk/keluar ke akun" (default off) → pilih akun.
  - Semua tombol aksi disable saat request (pola submitBtn goals.php); render angka via `rupiahFmt`, teks via `textContent`.
- [ ] **2. Menu**: di `public/lainnya.php` tambah setelah baris Investasi:
```php
  <a class="menu-item" href="hutang.php"><span class="menu-icon">💳</span><span>Hutang</span></a>
```
- [ ] **3. E2E via gstack** (server `php -S localhost:8092 -t public`, register user baru): buat Utang cicilan (12×850rb, disburse ke akun) → saldo akun naik, kartu progress 0/12 → Bayar 1 cicilan (dari akun) → saldo turun, progress 1/12, next_due maju → buat Piutang (tanpa disburse) → Terima sebagian → cek dashboard: net worth berkurang utang & bertambah piutang, kartu Tagihan Mendatang memuat cicilan; coba edit transaksi pembayaran dari halaman Transaksi → ditolak; hapus utang → transaksi kas tetap ada. Cek mobile 375px tanpa overflow-x. Catat langkah+hasil. Bersihkan data uji.
- [ ] **4. `php tests/run.php` PASS. Commit** `feat(hutang): halaman UI + menu + E2E`.

## Self-Review

- **Spec coverage:** §3 skema → Task 1. §4 kategori on-demand → Task 1 (`debtCategory`). §5 core fungsi → Task 1–2. §6 integrasi (netWorth/transaksi/dashboard) → Task 3. §7 API → Task 4. §8 UI+menu → Task 5. §9 keamanan → Global Constraints + ownDebt/csrf/FOR UPDATE tiap task. §10 testing → test di Task 1–3 + E2E Task 5. Semua tercakup.
- **Konsistensi tipe:** `debtOutstanding/ownDebt/debtCategory/createDebt/payDebt/updateDebt/deleteDebt/settleDebt/debtSummary/debtNetWorth/debtHistory` dipakai konsisten lintas task; `advanceNextRun` 3-arg sesuai core/recurring.php:42; `createTransaction` return array + key `debt_id` sesuai pola goal_id.
- **Placeholder:** tidak ada; tiap step berisi kode/perintah konkret.
- **Catatan migrasi:** `transactions` sudah berisi data → kolom `debt_id` ditambah via ALTER (Task 1 step 3), sekaligus dimasukkan ke schema.sql untuk fresh install.
