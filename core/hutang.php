<?php
// Hutang/Piutang & Cicilan: satu entitas `debts` (direction payable/
// receivable) + `debt_payments` (riwayat bayar/terima). Task 1 (skema +
// kalkulasi inti): debtOutstanding() dihitung on-the-fly (tanpa kolom saldo),
// debtCategory() pola on-demand sama persis dgn glCategory() (core/goals.php)
// tapi 4 key. ownDebt() ada di core/helpers.php (pola ownGoal/ownAsset).
// Task 2 (di bawah): create/pay/update/delete/settle debt + summary/history/
// netWorth. Reuse txAccountInSpace()/createTransaction() dari
// core/transaksi.php & advanceNextRun() dari core/recurring.php -- hutang
// engine TIDAK pernah insert ke transactions secara manual, sama pola dgn
// core/goals.php.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/transaksi.php';
require_once __DIR__ . '/recurring.php';

/**
 * Mapping key debtCategory() -> [nama kategori, type kategori]. Dipakai jg
 * oleh task berikutnya (createDebt/payDebt) supaya satu sumber kebenaran.
 */
const DEBT_CATEGORY_MAP = [
    'pay' => ['Bayar Utang/Cicilan', 'expense'],
    'receive' => ['Terima Piutang', 'income'],
    'disburse_in' => ['Pencairan Pinjaman', 'income'],
    'disburse_out' => ['Beri Pinjaman', 'expense'],
];

/**
 * Sisa pokok debt $debtId = principal - Σ debt_payments.amount. Berlaku utk
 * kedua arah (payable/receivable) -- "payment" pada receivable = penerimaan
 * cicilan/pelunasan dari pihak lain. TIDAK memvalidasi kepemilikan (murni
 * kalkulasi) -- pemanggil yg butuh proteksi akses pakai ownDebt() dulu.
 */
function debtOutstanding(int $debtId): float
{
    $stmt = db()->prepare(
        'SELECT d.principal, COALESCE(SUM(dp.amount), 0) paid
         FROM debts d LEFT JOIN debt_payments dp ON dp.debt_id = d.id
         WHERE d.id = ?
         GROUP BY d.id'
    );
    $stmt->execute([$debtId]);
    $row = $stmt->fetch();
    if ($row === false) {
        apiErr('Tidak ditemukan', 404);
    }
    return round((float) $row['principal'] - (float) $row['paid'], 2);
}

/**
 * Cari (atau buat on-demand) kategori khusus modul hutang milik $spaceId
 * sesuai $key (lihat DEBT_CATEGORY_MAP). Pola sama persis dgn glCategory()
 * (core/goals.php:23) -- SELECT by (space_id, name, type), buat kalau belum
 * ada. Return category_id (int).
 */
function debtCategory(int $spaceId, string $key): int
{
    if (!isset(DEBT_CATEGORY_MAP[$key])) {
        apiErr('Kategori hutang tidak valid');
    }
    [$name, $type] = DEBT_CATEGORY_MAP[$key];

    $stmt = db()->prepare(
        'SELECT id FROM categories WHERE space_id = ? AND name = ? AND type = ? LIMIT 1'
    );
    $stmt->execute([$spaceId, $name, $type]);
    $row = $stmt->fetch();
    if ($row !== false) {
        return (int) $row['id'];
    }

    $ins = db()->prepare(
        "INSERT INTO categories (space_id, name, type, icon, color) VALUES (?, ?, ?, '💳', '#F59E0B')"
    );
    $ins->execute([$spaceId, $name, $type]);
    return (int) db()->lastInsertId();
}

const DEBT_DIRECTIONS = ['payable', 'receivable'];
const DEBT_FREQUENCIES = ['weekly', 'monthly', 'yearly'];

/**
 * Validasi & normalisasi tanggal 'Y-m-d' dgn checkdate() (bukan cuma
 * strtotime) supaya tanggal kalender palsu spt 2026-02-30 ditolak, bukan diam-
 * diam digeser. Kosong -> null (kalau $required false) atau apiErr (kalau
 * $required true, default hari ini kalau $defaultToday true).
 */
function debtParseDate(?string $raw, string $label, bool $required, bool $defaultToday = false): ?string
{
    $s = trim((string) $raw);
    if ($s === '') {
        if ($defaultToday) {
            return date('Y-m-d');
        }
        if ($required) {
            apiErr($label . ' wajib diisi');
        }
        return null;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        apiErr($label . ' tidak valid');
    }
    return $s;
}

/**
 * Validasi & normalisasi input createDebt (dipakai HANYA saat create -- update
 * punya aturan sendiri utk principal, lihat updateDebt()). Aturan: direction
 * payable|receivable; party 1-100 karakter; principal > 0; note opsional maks
 * 255; start_date wajib (default hari ini) & due_date opsional, keduanya
 * checkdate(); is_installment (truthy) -> installment_count >= 1,
 * installment_amount > 0, frequency dari daftar valid, next_due = start_date.
 * Gagal -> apiErr. Return array siap-insert.
 */
function debtValidate(array $data): array
{
    $direction = $data['direction'] ?? '';
    if (!in_array($direction, DEBT_DIRECTIONS, true)) {
        apiErr('Arah hutang tidak valid');
    }

    $party = trim((string) ($data['party'] ?? ''));
    if ($party === '' || mb_strlen($party) > 100) {
        apiErr('Nama pihak wajib diisi (maks 100 karakter)');
    }

    $principal = $data['principal'] ?? null;
    if (!is_numeric($principal) || (float) $principal <= 0) {
        apiErr('Pokok harus lebih dari 0');
    }
    $principal = round((float) $principal, 2);

    $note = trim((string) ($data['note'] ?? ''));
    if (mb_strlen($note) > 255) {
        apiErr('Catatan maksimal 255 karakter');
    }
    $note = $note === '' ? null : $note;

    $startDate = debtParseDate($data['start_date'] ?? '', 'Tanggal mulai', true, true);
    $dueDate = debtParseDate($data['due_date'] ?? '', 'Tanggal jatuh tempo', false);

    $isInstallment = !empty($data['is_installment']);
    $installmentCount = null;
    $installmentAmount = null;
    $frequency = null;
    $nextDue = null;

    if ($isInstallment) {
        $installmentCount = (int) ($data['installment_count'] ?? 0);
        if ($installmentCount < 1) {
            apiErr('Jumlah cicilan minimal 1');
        }

        $installmentAmount = $data['installment_amount'] ?? null;
        if (!is_numeric($installmentAmount) || (float) $installmentAmount <= 0) {
            apiErr('Nominal cicilan harus lebih dari 0');
        }
        $installmentAmount = round((float) $installmentAmount, 2);

        $frequency = $data['frequency'] ?? '';
        if (!in_array($frequency, DEBT_FREQUENCIES, true)) {
            apiErr('Frekuensi cicilan tidak valid');
        }

        $nextDue = $startDate;
    }

    return [
        'direction' => $direction, 'party' => $party, 'principal' => $principal, 'note' => $note,
        'start_date' => $startDate, 'due_date' => $dueDate, 'is_installment' => $isInstallment,
        'installment_count' => $installmentCount, 'installment_amount' => $installmentAmount,
        'frequency' => $frequency, 'next_due' => $nextDue,
    ];
}

/**
 * Buat hutang/piutang baru di $spaceId. Opsi $data['disburse'] (truthy) +
 * $data['account_id'] -- buat transaksi kas awal SEKALIGUS tertaut debt_id
 * dalam transaksi DB yg sama (payable = terima dana -> income "Pencairan
 * Pinjaman"; receivable = kasih pinjaman -> expense "Beri Pinjaman"), nominal
 * = principal penuh. Akun divalidasi milik $spaceId (txAccountInSpace) SEBELUM
 * transaksi DB dibuka -- gagal cepat tanpa perlu rollback. Insert debt sendiri
 * tidak butuh FOR UPDATE (baris baru, belum ada yg bisa berebut) -- transaksi
 * DB di sini murni menjaga insert debts + insert transactions atomik (semua-
 * atau-tidak-sama-sekali kalau disburse gagal di tengah jalan). Return row
 * debt (id + field ternormalisasi) + status awal 'active' + outstanding =
 * principal.
 */
function createDebt(int $spaceId, array $data): array
{
    $v = debtValidate($data);

    $disburse = !empty($data['disburse']);
    $accountId = (int) ($data['account_id'] ?? 0);
    if ($disburse) {
        txAccountInSpace($accountId, $spaceId);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO debts (space_id, direction, party, principal, note, start_date, due_date, is_installment, installment_count, installment_amount, frequency, next_due)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $spaceId, $v['direction'], $v['party'], $v['principal'], $v['note'],
            $v['start_date'], $v['due_date'], $v['is_installment'] ? 1 : 0,
            $v['installment_count'], $v['installment_amount'], $v['frequency'], $v['next_due'],
        ]);
        $id = (int) $pdo->lastInsertId();

        if ($disburse) {
            $key = $v['direction'] === 'payable' ? 'disburse_in' : 'disburse_out';
            $type = $v['direction'] === 'payable' ? 'income' : 'expense';
            $categoryId = debtCategory($spaceId, $key);

            createTransaction($spaceId, [
                'account_id' => $accountId,
                'category_id' => $categoryId,
                'type' => $type,
                'amount' => $v['principal'],
                'tx_date' => $v['start_date'],
                'note' => ($v['direction'] === 'payable' ? 'Pinjaman dari ' : 'Pinjaman ke ') . $v['party'],
                'debt_id' => $id,
            ]);
        }

        $pdo->commit();
        return array_merge(
            ['id' => $id, 'space_id' => $spaceId, 'status' => 'active', 'outstanding' => $v['principal']],
            $v
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Bayar (payable) / terima (receivable) cicilan/pelunasan hutang $debtId dari
 * $accountId (harus milik space debt ini, divalidasi txAccountInSpace()
 * SEBELUM transaksi DB dibuka). Kepemilikan divalidasi via ownDebt() dulu
 * (404 kalau bukan milik user sesi), lalu baris debt DIKUNCI ULANG
 * (SELECT...FOR UPDATE dalam transaksi DB) -- pola sama persis dgn
 * depositGoal()/confirmRecurring() -- supaya dua pembayaran nyaris bersamaan
 * ke debt yg sama tidak dobel-posting dgn outstanding/next_due yg sudah basi.
 *
 * Ditolak (apiErr, rollback): debt sudah settled; amount <= 0 atau amount >
 * outstanding + 1e-6 (toleransi pembulatan); utk debt cicilan, kalau
 * $expectedNextDue dikirim (bukan null) & sudah != next_due baris saat ini ->
 * 409 (request duplikat/basi, mis. dobel-tap tombol bayar -- sama pola dgn
 * confirmRecurring()/skipRecurring()).
 *
 * Sukses: buat transaksi kas (payable -> expense "Bayar Utang/Cicilan",
 * receivable -> income "Terima Piutang") tertaut debt_id, insert 1 baris
 * debt_payments (transaction_id terisi). Debt cicilan -> next_due dimajukan
 * advanceNextRun() dgn ANCHOR start_date (bukan next_due lama -- lihat
 * dokumentasi advanceNextRun(), core/recurring.php:42). Outstanding baru
 * <= 1e-6 -> auto-settle (status = 'settled'). Return
 * ['outstanding'=>float,'status'=>string,'next_due'=>?string].
 */
function payDebt(int $debtId, int $accountId, $amountRaw, ?string $date, ?string $expectedNextDue = null): array
{
    $existing = ownDebt($debtId);
    $spaceId = (int) $existing['space_id'];

    if (!is_numeric($amountRaw) || (float) $amountRaw <= 0) {
        apiErr('Nominal harus lebih dari 0');
    }
    $amount = round((float) $amountRaw, 2);

    $payDate = debtParseDate($date ?? '', 'Tanggal', true, true);

    txAccountInSpace($accountId, $spaceId);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM debts WHERE id = ? FOR UPDATE');
        $stmt->execute([$debtId]);
        $d = $stmt->fetch();
        if ($d === false) {
            $pdo->rollBack();
            apiErr('Tidak ditemukan', 404);
        }
        if ($d['status'] === 'settled') {
            $pdo->rollBack();
            apiErr('Hutang/piutang ini sudah lunas');
        }

        $isInstallment = (int) $d['is_installment'] === 1;
        if ($isInstallment && $expectedNextDue !== null && $expectedNextDue !== $d['next_due']) {
            $pdo->rollBack();
            apiErr('Jadwal cicilan ini sudah berubah, muat ulang halaman', 409);
        }

        // Aman dihitung SETELAH lock -- satu-satunya jalur insert debt_payments
        // adalah fungsi ini sendiri, jadi pembayaran nyaris bersamaan ke debt yg
        // sama otomatis serial menunggu FOR UPDATE di atas selesai commit dulu.
        $outstanding = debtOutstanding($debtId);
        if ($amount > $outstanding + 1e-6) {
            $pdo->rollBack();
            apiErr('Nominal melebihi sisa hutang/piutang (' . rupiah($outstanding) . ')');
        }

        $key = $d['direction'] === 'payable' ? 'pay' : 'receive';
        $type = $d['direction'] === 'payable' ? 'expense' : 'income';
        $categoryId = debtCategory($spaceId, $key);

        $tx = createTransaction($spaceId, [
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'type' => $type,
            'amount' => $amount,
            'tx_date' => $payDate,
            'note' => ($d['direction'] === 'payable' ? 'Bayar utang: ' : 'Terima piutang: ') . $d['party'],
            'debt_id' => $debtId,
        ]);

        $pdo->prepare(
            'INSERT INTO debt_payments (debt_id, amount, pay_date, transaction_id) VALUES (?, ?, ?, ?)'
        )->execute([$debtId, $amount, $payDate, $tx['id']]);

        $newOutstanding = round($outstanding - $amount, 2);
        if ($newOutstanding < 0) {
            $newOutstanding = 0.0;
        }

        $nextDue = $d['next_due'];
        if ($isInstallment) {
            $nextDue = advanceNextRun($d['next_due'], $d['frequency'], $d['start_date']);
        }

        $status = $newOutstanding <= 1e-6 ? 'settled' : 'active';

        $pdo->prepare('UPDATE debts SET next_due = ?, status = ? WHERE id = ?')
            ->execute([$nextDue, $status, $debtId]);

        $pdo->commit();
        return ['outstanding' => $newOutstanding, 'status' => $status, 'next_due' => $nextDue];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Update hutang/piutang $debtId: party/note/due_date SELALU bisa diubah;
 * principal HANYA boleh diubah kalau BELUM ADA pembayaran sama sekali
 * (COUNT debt_payments = 0) -- kalau sudah ada payment & principal yg dikirim
 * beda dari yg tersimpan -> apiErr 400 "Tidak bisa ubah pokok, sudah ada
 * pembayaran" (mengubah pokok stlh ada histori bayar bikin outstanding
 * historis jadi tidak masuk akal). Kepemilikan divalidasi via ownDebt().
 * Return row hasil (field ternormalisasi + outstanding terkini).
 */
function updateDebt(int $debtId, array $data): array
{
    $existing = ownDebt($debtId);
    $spaceId = (int) $existing['space_id'];

    $party = trim((string) ($data['party'] ?? ''));
    if ($party === '' || mb_strlen($party) > 100) {
        apiErr('Nama pihak wajib diisi (maks 100 karakter)');
    }

    $note = trim((string) ($data['note'] ?? ''));
    if (mb_strlen($note) > 255) {
        apiErr('Catatan maksimal 255 karakter');
    }
    $note = $note === '' ? null : $note;

    $dueDate = debtParseDate($data['due_date'] ?? '', 'Tanggal jatuh tempo', false);

    $principal = (float) $existing['principal'];
    if (array_key_exists('principal', $data) && $data['principal'] !== null && $data['principal'] !== '') {
        if (!is_numeric($data['principal']) || (float) $data['principal'] <= 0) {
            apiErr('Pokok harus lebih dari 0');
        }
        $newPrincipal = round((float) $data['principal'], 2);

        if (abs($newPrincipal - $principal) > 1e-6) {
            $paidStmt = db()->prepare('SELECT COUNT(*) c FROM debt_payments WHERE debt_id = ?');
            $paidStmt->execute([$debtId]);
            $paidCount = (int) $paidStmt->fetch()['c'];
            if ($paidCount > 0) {
                apiErr('Tidak bisa ubah pokok, sudah ada pembayaran');
            }
            $principal = $newPrincipal;
        }
    }

    db()->prepare('UPDATE debts SET party = ?, note = ?, due_date = ?, principal = ? WHERE id = ?')
        ->execute([$party, $note, $dueDate, $principal, $debtId]);

    return [
        'id' => $debtId, 'space_id' => $spaceId, 'party' => $party, 'note' => $note,
        'due_date' => $dueDate, 'principal' => $principal, 'outstanding' => debtOutstanding($debtId),
    ];
}

/**
 * Hapus hutang/piutang $debtId. Kepemilikan divalidasi via ownDebt().
 * debt_payments ikut terhapus otomatis (fk_debt_payments_debt ON DELETE
 * CASCADE). Transaksi kas yg pernah tertaut debt ini TIDAK ikut terhapus
 * (fk_transactions_debt ON DELETE SET NULL -- riwayat kas tetap ada, cuma
 * tautannya lepas), sama pola dgn deleteGoal()/deleteRecurring().
 */
function deleteDebt(int $debtId): void
{
    ownDebt($debtId);
    db()->prepare('DELETE FROM debts WHERE id = ?')->execute([$debtId]);
}

/**
 * Tandai hutang/piutang $debtId lunas manual (status = 'settled') tanpa lewat
 * payDebt() -- mis. dilunasi tunai di luar aplikasi, atau piutang diikhlaskan.
 * Kepemilikan divalidasi via ownDebt().
 */
function settleDebt(int $debtId): array
{
    ownDebt($debtId);
    db()->prepare("UPDATE debts SET status = 'settled' WHERE id = ?")->execute([$debtId]);
    return ['id' => $debtId, 'status' => 'settled'];
}

/**
 * Ringkasan seluruh hutang/piutang $spaceId, dikelompokkan per arah. Tiap
 * item: id, party, principal, outstanding, progress_pct (0-100, dibulatkan &
 * di-clamp -- porsi yg SUDAH dibayar/diterima), is_installment,
 * installment_count, paid_count (COUNT debt_payments), next_due, due_date,
 * status. Urutan tiap arah: aktif dulu, settled di akhir (lalu id ASC).
 * total_payable/total_receivable = Σ outstanding item BERSTATUS AKTIF saja
 * (item settled outstanding-nya ~0, tapi difilter eksplisit spy jelas).
 * Return {payable:[...], receivable:[...], total_payable, total_receivable}.
 */
function debtSummary(int $spaceId): array
{
    $stmt = db()->prepare(
        "SELECT d.*, COUNT(dp.id) paid_count, COALESCE(SUM(dp.amount), 0) paid
         FROM debts d LEFT JOIN debt_payments dp ON dp.debt_id = d.id
         WHERE d.space_id = ?
         GROUP BY d.id
         ORDER BY (d.status = 'settled') ASC, d.id ASC"
    );
    $stmt->execute([$spaceId]);
    $rows = $stmt->fetchAll();

    $out = ['payable' => [], 'receivable' => [], 'total_payable' => 0.0, 'total_receivable' => 0.0];

    foreach ($rows as $r) {
        $principal = (float) $r['principal'];
        $paid = (float) $r['paid'];
        $outstanding = round($principal - $paid, 2);
        $progressPct = $principal > 0 ? (int) round(min(100, max(0, $paid / $principal * 100))) : 0;

        $item = [
            'id' => (int) $r['id'],
            'party' => $r['party'],
            'principal' => $principal,
            'outstanding' => $outstanding,
            'progress_pct' => $progressPct,
            'is_installment' => (bool) $r['is_installment'],
            'installment_count' => $r['installment_count'] !== null ? (int) $r['installment_count'] : null,
            'paid_count' => (int) $r['paid_count'],
            'next_due' => $r['next_due'],
            'due_date' => $r['due_date'],
            'status' => $r['status'],
        ];

        $bucket = $r['direction'] === 'payable' ? 'payable' : 'receivable';
        $out[$bucket][] = $item;
        if ($r['status'] === 'active') {
            $out['total_' . $bucket] += $outstanding;
        }
    }

    $out['total_payable'] = round($out['total_payable'], 2);
    $out['total_receivable'] = round($out['total_receivable'], 2);

    return $out;
}

/**
 * Net worth tambahan dari hutang/piutang milik $userId: Σ outstanding
 * receivable (piutang = aset, menambah net worth) − Σ outstanding payable
 * (hutang = kewajiban, mengurangi net worth), HANYA debt berstatus 'active' di
 * ruang type='personal' user (pola sama dgn netWorth() core/balance.php:97 --
 * hanya space personal, bukan shared). Dipanggil terpisah oleh pemanggil yg
 * butuh (belum di-wire ke netWorth() -- lihat brief Task 2, "belum" wiring
 * lintas file spesifik menyusul kalau memang dibutuhkan UI).
 */
function debtNetWorth(int $userId): float
{
    $stmt = db()->prepare(
        "SELECT d.principal, d.direction, COALESCE(SUM(dp.amount), 0) paid
         FROM debts d
         JOIN spaces s ON s.id = d.space_id
         LEFT JOIN debt_payments dp ON dp.debt_id = d.id
         WHERE s.user_id = ? AND s.type = 'personal' AND d.status = 'active'
         GROUP BY d.id"
    );
    $stmt->execute([$userId]);

    $total = 0.0;
    foreach ($stmt->fetchAll() as $r) {
        $outstanding = round((float) $r['principal'] - (float) $r['paid'], 2);
        $total += $r['direction'] === 'receivable' ? $outstanding : -$outstanding;
    }
    return round($total, 2);
}

/**
 * Riwayat pembayaran hutang/piutang $debtId, urut TERBARU dulu (pay_date
 * DESC, id DESC -- id sbg tie-breaker kalau beberapa pembayaran di tanggal yg
 * sama), dibatasi 50 baris (halaman detail debt, bukan laporan lengkap).
 * LEFT JOIN transactions.note (dialiaskan tx_note) -- transaction_id bisa NULL
 * kalau transaksi kas induknya sudah dihapus manual lewat modul Transaksi
 * biasa (fk_debt_payments_tx ON DELETE SET NULL), riwayat bayar sendiri tetap
 * ada. Kepemilikan divalidasi via ownDebt().
 */
function debtHistory(int $debtId): array
{
    ownDebt($debtId);

    $stmt = db()->prepare(
        'SELECT dp.*, t.note tx_note
         FROM debt_payments dp LEFT JOIN transactions t ON t.id = dp.transaction_id
         WHERE dp.debt_id = ?
         ORDER BY dp.pay_date DESC, dp.id DESC
         LIMIT 50'
    );
    $stmt->execute([$debtId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['debt_id'] = (int) $r['debt_id'];
        $r['amount'] = (float) $r['amount'];
        $r['transaction_id'] = $r['transaction_id'] !== null ? (int) $r['transaction_id'] : null;
    }
    unset($r);

    return $rows;
}
