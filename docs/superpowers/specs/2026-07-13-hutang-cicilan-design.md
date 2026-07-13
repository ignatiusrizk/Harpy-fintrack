# Harpy FinTrack — Modul Hutang/Piutang & Cicilan (v1.1) — Design Spec

**Tanggal:** 2026-07-13 (WIB)
**Status:** Disetujui user (sesi brainstorming)
**Basis:** v1 sudah selesai & ter-merge ke `main`. Ini modul tambahan, mengikuti pola v1.

## 1. Ringkasan

Modul untuk melacak **utang saya (payable)**, **piutang (receivable)**, dan **cicilan berjadwal** dalam satu entitas terpadu. Pembayaran/penerimaan otomatis menjadi transaksi (saldo akun konsisten), sisa pokok dihitung on-the-fly, dan saldo utang/piutang ikut memengaruhi Net Worth. Jatuh tempo menumpang kartu "Tagihan Mendatang" dashboard yang sudah ada.

## 2. Keputusan (dari brainstorming)

- Cakupan: **Hutang + Piutang + Cicilan** (satu tabel `debts` dengan `direction`).
- Alur kas: pembayaran **membuat transaksi otomatis** (pilih akun sumber/tujuan).
- Bunga: **tidak dihitung** — user isi nominal cicilan tetap (tenor × nominal).
- Net worth: **ikut** (− sisa utang, + sisa piutang) untuk ruang personal.
- Kas awal saat pembuatan: **opsional** (checkbox "dana masuk/keluar ke akun").
- Pengingat: **menumpang** kartu Tagihan Mendatang dashboard (due ≤ 7 hari).

## 3. Skema Data

### 3.1 Tabel baru `debts`
| Kolom | Tipe | Ket |
|---|---|---|
| id | BIGINT PK AI | |
| space_id | BIGINT FK→spaces ON DELETE CASCADE | scope |
| direction | ENUM('payable','receivable') | utang saya / piutang |
| party | VARCHAR(100) | nama pihak (orang/lembaga) |
| principal | DECIMAL(15,2) | pokok awal |
| note | VARCHAR(255) NULL | |
| start_date | DATE | tanggal mulai |
| due_date | DATE NULL | jatuh tempo (non-cicilan) |
| is_installment | TINYINT(1) default 0 | |
| installment_count | INT NULL | jumlah tenor (cicilan) |
| installment_amount | DECIMAL(15,2) NULL | nominal per cicilan |
| frequency | ENUM('weekly','monthly','yearly') NULL | irama cicilan |
| next_due | DATE NULL | jatuh tempo cicilan berikutnya |
| status | ENUM('active','settled') default 'active' | |
| created_at | TIMESTAMP default CURRENT_TIMESTAMP | |

Index: `debts(space_id, status)`, `debts(space_id, next_due)`.

### 3.2 Tabel baru `debt_payments`
| Kolom | Tipe | Ket |
|---|---|---|
| id | BIGINT PK AI | |
| debt_id | BIGINT FK→debts ON DELETE CASCADE | |
| amount | DECIMAL(15,2) | > 0 |
| pay_date | DATE | |
| transaction_id | BIGINT FK→transactions ON DELETE SET NULL | transaksi kas terkait (bila ada) |
| note | VARCHAR(255) NULL | |

Index: `debt_payments(debt_id)`.

### 3.3 Kolom baru di `transactions`
- `debt_id BIGINT NULL`, FK→debts **ON DELETE SET NULL**. Menautkan transaksi pembayaran/pencairan ke utangnya (analog `goal_id`/`recurring_id` yang sudah ada).

### 3.4 Aturan turunan
- **Sisa (outstanding)** = `principal − Σ debt_payments.amount` (dihitung, tanpa kolom saldo). Berlaku untuk kedua arah; "payment" pada receivable = penerimaan cicilan/pelunasan.
- **Progress** = `(principal − outstanding) / principal`. Untuk cicilan: `cicilan_terbayar = COUNT(debt_payments)` vs `installment_count` (tampilan "n dari m").
- **Auto-settle:** saat outstanding ≤ 0 → `status='settled'`. Juga bisa manual "tandai lunas".

## 4. Kategori on-demand (per ruang, pola `glCategory` di goals)

Dibuat saat pertama dibutuhkan via helper `debtCategory($spaceId, $key)`:
- Pembayaran utang → expense **"Bayar Utang/Cicilan"**.
- Penerimaan piutang → income **"Terima Piutang"**.
- Kas awal payable ("dana masuk") → income **"Pencairan Pinjaman"**.
- Kas awal receivable ("dana keluar") → expense **"Beri Pinjaman"**.

## 5. Core: `core/hutang.php`

Fungsi (semua PDO prepared; mutasi multi-tabel dibungkus transaksi + `SELECT ... FOR UPDATE` pada baris debt, pola `depositGoal`):

- `debtOutstanding(int $debtId): float` — principal − Σ payments.
- `debtSummary(int $spaceId): array` — list debts (kedua arah) + outstanding, progress, next_due/due_date, badge due; dikelompok payable/receivable + total sisa per arah.
- `debtNetWorth(int $userId): float` — Σ(receivable outstanding) − Σ(payable outstanding) untuk semua ruang **personal** user, status active. Dipanggil `netWorth()` via `function_exists` (pola `portfolioValue`).
- `createDebt(int $spaceId, array $data): array` — validasi: direction, party (1–100), principal > 0, start_date `checkdate`, due_date opsional valid; bila cicilan: installment_count ≥ 1, installment_amount > 0, frequency valid, `next_due` = start_date (anchor). Opsi `disburse` (bool) + `account_id`: bila true, buat transaksi kas awal (payable→income "Pencairan Pinjaman", receivable→expense "Beri Pinjaman") tertaut `debt_id`, dalam transaksi yang sama. Return debt row.
- `payDebt(int $debtId, int $accountId, $amountRaw, ?string $date): array` — di bawah FOR UPDATE: tolak bila settled; tolak amount > outstanding (+epsilon); buat transaksi (payable→expense "Bayar Utang/Cicilan", receivable→income "Terima Piutang") dari `accountId`, buat `debt_payments` (transaction_id terisi); bila cicilan → `next_due = advanceNextRun(next_due, frequency)` (fungsi teruji dari `core/recurring.php`, di-`require_once`); auto-settle bila outstanding ≤ 0.
- `updateDebt(int $debtId, array $data): array` — ubah party/note/due_date; **tidak** mengubah principal bila sudah ada payment (tolak, jaga konsistensi). ownDebt.
- `deleteDebt(int $debtId): void` — dalam transaksi: hapus `debt_payments` (CASCADE saat delete debts), transaksi kas tertaut **tidak** dihapus (`transactions.debt_id` SET NULL via FK). ownDebt.
- `settleDebt(int $debtId): array` — set status settled manual.
- `debtHistory(int $debtId): array` — daftar payments (+ transaksi terkait), urut terbaru, LIMIT 50.

`ownDebt(int $debtId): array` ditambah di `core/helpers.php` (pola `ownGoal`: JOIN spaces → user_id session, gagal → apiErr 404).

## 6. Integrasi lintas modul

- **`core/balance.php` `netWorth()`**: tambah `debtNetWorth($userId)` bila `function_exists`. `require_once core/hutang.php` di atas balance.php (pola portfolio).
- **`core/transaksi.php`**: `createTransaction` dukung key `debt_id` (validasi milik space). `updateTransaction`/`deleteTransaction`: **tolak** bila transaksi punya `debt_id` (pesan mengarah ke halaman Hutang) — mengikuti persis guard goal (`txRejectIfGoalLinked`), diperluas jadi `txRejectIfLinked` mencakup goal_id & debt_id.
- **`core/dashboard.php` `dbSummaryUpcoming`**: gabungkan debts due — cicilan aktif `next_due ≤ today+7` dan non-cicilan `due_date ≤ today+7` — ke daftar "Tagihan Mendatang", dengan penanda `kind:'debt'` + badge "Cicilan"/"Jatuh tempo". `today` = PHP `date('Y-m-d')` WIB (bukan NOW()).

## 7. API `public/api/hutang.php` (`?a=`)

`requireLoginApi()` di atas; `csrf_check()` pada semua mutasi; `apiErr` generik.
- `list` → debtSummary(currentSpaceId()).
- `create` → createDebt.
- `pay` → payDebt.
- `update` → updateDebt.
- `delete` → deleteDebt.
- `settle` → settleDebt.
- `history` → debtHistory (ownDebt).

## 8. UI `public/hutang.php` (dari menu Lainnya, `$active='lainnya'`)

- Header + total ringkas: "Total Utang Rp X · Total Piutang Rp Y".
- Dua seksi: **Utang** (payable) & **Piutang** (receivable). Kartu: pihak, sisa/pokok, progress bar, badge jatuh tempo (mis. "Jatuh tempo 3 hari" / "Cicilan 4/12"). Seksi **Lunas** (collapsed) di bawah.
- Tap kartu → sheet detail: ringkas + riwayat pembayaran, tombol **Bayar/Terima** (sheet: akun sumber/tujuan, nominal default = installment_amount bila cicilan, tanggal), **Edit**, **Tandai lunas**, **Hapus** (lmConfirm; jelaskan transaksi kas tidak ikut terhapus).
- FAB **+**: sheet tambah — segmented **Utang/Piutang**, pihak, pokok, tanggal mulai, jatuh tempo opsional (date custom); toggle **Cicilan** → jumlah tenor + nominal/cicilan + frekuensi (select custom); toggle **"Dana masuk/keluar ke akun"** → pilih akun (default mati).
- Tambah entri **Hutang** ke menu grid `public/lainnya.php`.
- Dialog custom, `rupiah()/rupiahFmt()`, XSS via `e()`/`textContent`, header `no-store`.

## 9. Keamanan

`ownDebt` di setiap akses per-id; scope `currentSpaceId()`; PDO prepared; CSRF; apiErr generik; mutasi multi-tabel transaksional + FOR UPDATE (anti dobel-bayar). Tombol Bayar/Terima disable saat request (pola submitBtn). Idempotensi bayar cicilan via `expected_next_due` opsional (pola recurring confirm) — bila dikirim, tolak 409 saat next_due sudah bergeser.

## 10. Testing (`tests/test_hutang.php`)

- debtOutstanding = principal − Σ payments; auto-settle saat lunas.
- payDebt payable → transaksi expense + saldo akun turun + outstanding turun; receivable → income + saldo naik.
- pay > outstanding ditolak; pay ke debt settled ditolak.
- cicilan: next_due maju sesuai frequency (anchor via advanceNextRun); progress n/m.
- disburse saat create → transaksi kas awal benar (payable income / receivable expense).
- debtNetWorth: −payable +receivable; netWorth() berubah sesuai.
- deleteDebt → payments hilang, transaksi kas tetap ada dgn debt_id NULL.
- transaksi tertaut debt_id ditolak saat diedit/dihapus dari modul Transaksi; setelah debt dihapus (debt_id NULL) transaksi bisa diedit lagi.
- ownDebt user lain → 404; cross-space account saat pay ditolak.

## 11. Di luar scope v1.1

Perhitungan bunga/anuitas otomatis, denda keterlambatan, jadwal amortisasi rinci, ekspor CSV khusus utang, pembayaran sebagian yang membagi ke pokok+bunga, notifikasi push.
