<?php
// Goals (target tabungan): setor/tarik dana ke goal via transaksi expense/
// income berkategori "Tabungan Goal" + goal_entries pencatatan progres. Semua
// fungsi validasi gagal -> apiErr() (menghentikan eksekusi), pola sama dgn
// core/ lain. Reuse txAccountInSpace()/createTransaction() dari
// core/transaksi.php -- goals engine TIDAK pernah insert ke transactions
// secara manual.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/transaksi.php';

const GOAL_CATEGORY_NAME = 'Tabungan Goal';

/**
 * Cari (atau buat on-demand) kategori "Tabungan Goal" $type ('expense' saat
 * deposit, 'income' saat withdraw) milik $spaceId. Kategori expense-nya sudah
 * di-seed core/seed.php utk tiap space baru -- fallback create di sini murni
 * jaga-jaga (mis. user pernah menghapus kategori seed). Kategori income
 * "Tabungan Goal" memang TIDAK di-seed (lihat komentar core/seed.php) --
 * SELALU dibuat on-demand di sini saat withdraw pertama kali di space itu.
 */
function glCategory(int $spaceId, string $type): array
{
    $stmt = db()->prepare(
        'SELECT id, space_id, type, name FROM categories WHERE space_id = ? AND name = ? AND type = ? LIMIT 1'
    );
    $stmt->execute([$spaceId, GOAL_CATEGORY_NAME, $type]);
    $row = $stmt->fetch();
    if ($row !== false) {
        return $row;
    }

    $ins = db()->prepare(
        "INSERT INTO categories (space_id, name, type, icon, color) VALUES (?, ?, ?, '🎯', '#0EA5E9')"
    );
    $ins->execute([$spaceId, GOAL_CATEGORY_NAME, $type]);
    $id = (int) db()->lastInsertId();

    return ['id' => $id, 'space_id' => $spaceId, 'type' => $type, 'name' => GOAL_CATEGORY_NAME];
}

/**
 * Validasi & normalisasi input goal (name, target_amount, target_date
 * opsional), dipakai bareng create & update. Gagal -> apiErr. Return array
 * siap-pakai: name, target_amount, target_date (string 'Y-m-d' atau null).
 */
function glValidate(array $data): array
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) {
        apiErr('Nama goal wajib diisi (maks 100 karakter)');
    }

    $targetAmount = $data['target_amount'] ?? null;
    if (!is_numeric($targetAmount) || (float) $targetAmount <= 0) {
        apiErr('Target nominal harus lebih dari 0');
    }
    $targetAmount = round((float) $targetAmount, 2);

    $targetDate = trim((string) ($data['target_date'] ?? ''));
    if ($targetDate !== '') {
        // checkdate() (bukan cuma strtotime) supaya tanggal kalender palsu
        // spt 2026-02-30 ditolak, bukan diam-diam digeser oleh MySQL/PHP.
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $targetDate, $m)
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            apiErr('Tenggat tidak valid');
        }
    } else {
        $targetDate = null;
    }

    return ['name' => $name, 'target_amount' => $targetAmount, 'target_date' => $targetDate];
}

/**
 * Buat goal baru di $spaceId. Return row hasil (id + field ternormalisasi +
 * is_done/saved/pct awal).
 */
function createGoal(int $spaceId, array $data): array
{
    $v = glValidate($data);

    $stmt = db()->prepare(
        'INSERT INTO goals (space_id, name, target_amount, target_date) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$spaceId, $v['name'], $v['target_amount'], $v['target_date']]);
    $id = (int) db()->lastInsertId();

    return array_merge(
        ['id' => $id, 'space_id' => $spaceId, 'is_done' => false, 'saved' => 0.0, 'pct' => 0],
        $v
    );
}

/**
 * Update goal $id (nama/target/tenggat). Kepemilikan divalidasi via
 * ownGoal(). is_done TIDAK diubah lewat sini (pakai finishGoal()).
 */
function updateGoal(int $id, array $data): array
{
    $existing = ownGoal($id);
    $spaceId = (int) $existing['space_id'];
    $v = glValidate($data);

    $stmt = db()->prepare('UPDATE goals SET name = ?, target_amount = ?, target_date = ? WHERE id = ?');
    $stmt->execute([$v['name'], $v['target_amount'], $v['target_date'], $id]);

    return array_merge(
        ['id' => $id, 'space_id' => $spaceId, 'is_done' => (bool) $existing['is_done']],
        $v
    );
}

/**
 * Hapus goal $id. Kepemilikan divalidasi via ownGoal(). goal_entries ikut
 * terhapus otomatis (fk_goal_entries_goal ON DELETE CASCADE). Transaksi yg
 * pernah tertaut goal ini TIDAK ikut terhapus (fk_transactions_goal ON DELETE
 * SET NULL -- riwayat tetap ada, cuma tautannya lepas), sama pola dgn
 * recurring_id di deleteRecurring().
 */
function deleteGoal(int $id): void
{
    ownGoal($id);
    db()->prepare('DELETE FROM goals WHERE id = ?')->execute([$id]);
}

/**
 * Tandai goal $id selesai (is_done = 1). Kepemilikan divalidasi via
 * ownGoal(). Setelah ini deposit/withdraw ke goal ini ditolak.
 */
function finishGoal(int $id): array
{
    ownGoal($id);
    db()->prepare('UPDATE goals SET is_done = 1 WHERE id = ?')->execute([$id]);
    return ['id' => $id, 'is_done' => true];
}

/**
 * List goals $spaceId + agregat saved (Σ goal_entries.amount, deposit positif
 * + withdraw negatif), pct (0-100, dibulatkan & di-clamp), days_left (null
 * kalau tanpa target_date, bisa negatif kalau sudah lewat tenggat). Urut
 * belum-selesai dulu (seksi "Selesai" di UI dikumpulkan dari is_done=true di
 * ekor list ini), lalu id ASC.
 */
function listGoals(int $spaceId): array
{
    $stmt = db()->prepare(
        'SELECT g.*, COALESCE(SUM(ge.amount), 0) saved
         FROM goals g LEFT JOIN goal_entries ge ON ge.goal_id = g.id
         WHERE g.space_id = ?
         GROUP BY g.id
         ORDER BY g.is_done ASC, g.id ASC'
    );
    $stmt->execute([$spaceId]);
    $rows = $stmt->fetchAll();

    $today = date('Y-m-d');
    $out = [];
    foreach ($rows as $r) {
        $target = (float) $r['target_amount'];
        $saved = (float) $r['saved'];
        $pct = $target > 0 ? (int) round(min(100, max(0, $saved / $target * 100))) : 0;

        $daysLeft = null;
        if ($r['target_date'] !== null) {
            $daysLeft = (int) floor((strtotime($r['target_date']) - strtotime($today)) / 86400);
        }

        $out[] = [
            'id' => (int) $r['id'],
            'space_id' => (int) $r['space_id'],
            'name' => $r['name'],
            'target_amount' => $target,
            'target_date' => $r['target_date'],
            'is_done' => (bool) $r['is_done'],
            'saved' => $saved,
            'pct' => $pct,
            'days_left' => $daysLeft,
        ];
    }
    return $out;
}

/**
 * Setor dana ke goal $goalId: buat transaksi EXPENSE kategori "Tabungan Goal"
 * (dari $accountId, harus milik space goal ini) + goal_entry amount POSITIF,
 * transaction_id terisi. Ditolak (apiErr) kalau goal sudah selesai. Atomik
 * (transaksi + entry satu paket) -- lock baris goal via SELECT...FOR UPDATE
 * dalam transaksi DB supaya dobel-klik/dua tab nyaris bersamaan tidak
 * dobel-setor dgn state is_done yg sudah basi, sama pola dgn
 * confirmRecurring() di core/recurring.php.
 */
function depositGoal(int $goalId, int $accountId, $amountRaw, ?string $date): array
{
    $existing = ownGoal($goalId);
    $spaceId = (int) $existing['space_id'];

    if (!is_numeric($amountRaw) || (float) $amountRaw <= 0) {
        apiErr('Nominal harus lebih dari 0');
    }
    $amount = round((float) $amountRaw, 2);

    $txDate = trim((string) ($date ?? ''));
    $txDate = $txDate === '' ? date('Y-m-d') : $txDate;

    // Validasi akun milik space goal ini SEBELUM membuka transaksi DB --
    // gagal cepat tanpa perlu rollback.
    txAccountInSpace($accountId, $spaceId);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM goals WHERE id = ? FOR UPDATE');
        $stmt->execute([$goalId]);
        $g = $stmt->fetch();
        if ($g === false || (int) $g['is_done'] === 1) {
            $pdo->rollBack();
            apiErr('Goal sudah selesai, tidak bisa disetor lagi');
        }

        $category = glCategory($spaceId, 'expense');

        $tx = createTransaction($spaceId, [
            'account_id' => $accountId,
            'category_id' => $category['id'],
            'type' => 'expense',
            'amount' => $amount,
            'tx_date' => $txDate,
            'note' => 'Setor goal: ' . $g['name'],
            'goal_id' => $goalId,
        ]);

        $ins = $pdo->prepare(
            'INSERT INTO goal_entries (goal_id, transaction_id, amount, entry_date) VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$goalId, $tx['id'], $amount, $txDate]);

        $pdo->commit();
        return ['transaction' => $tx, 'goal_id' => $goalId, 'amount' => $amount];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Tarik dana dari goal $goalId: kebalikan depositGoal() -- transaksi INCOME
 * kategori "Tabungan Goal" (ke $accountId, harus milik space goal ini) +
 * goal_entry amount NEGATIF. Ditolak (apiErr) kalau goal sudah selesai, atau
 * $amountRaw melebihi dana tersimpan saat ini. saved dihitung ULANG di dalam
 * transaksi DB setelah lock baris goal (FOR UPDATE) -- cegah race dua tarikan
 * nyaris bersamaan sama-sama lolos cek "amount <= saved" dgn saved yg sudah
 * basi (over-withdraw).
 */
function withdrawGoal(int $goalId, int $accountId, $amountRaw, ?string $date): array
{
    $existing = ownGoal($goalId);
    $spaceId = (int) $existing['space_id'];

    if (!is_numeric($amountRaw) || (float) $amountRaw <= 0) {
        apiErr('Nominal harus lebih dari 0');
    }
    $amount = round((float) $amountRaw, 2);

    $txDate = trim((string) ($date ?? ''));
    $txDate = $txDate === '' ? date('Y-m-d') : $txDate;

    txAccountInSpace($accountId, $spaceId);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM goals WHERE id = ? FOR UPDATE');
        $stmt->execute([$goalId]);
        $g = $stmt->fetch();
        if ($g === false || (int) $g['is_done'] === 1) {
            $pdo->rollBack();
            apiErr('Goal sudah selesai, tidak bisa ditarik lagi');
        }

        $savedStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) saved FROM goal_entries WHERE goal_id = ?');
        $savedStmt->execute([$goalId]);
        $saved = (float) $savedStmt->fetch()['saved'];

        if ($amount > $saved) {
            $pdo->rollBack();
            apiErr('Nominal tarik melebihi dana tersimpan (' . rupiah($saved) . ')');
        }

        $category = glCategory($spaceId, 'income');

        $tx = createTransaction($spaceId, [
            'account_id' => $accountId,
            'category_id' => $category['id'],
            'type' => 'income',
            'amount' => $amount,
            'tx_date' => $txDate,
            'note' => 'Tarik goal: ' . $g['name'],
            'goal_id' => $goalId,
        ]);

        $ins = $pdo->prepare(
            'INSERT INTO goal_entries (goal_id, transaction_id, amount, entry_date) VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$goalId, $tx['id'], -$amount, $txDate]);

        $pdo->commit();
        return ['transaction' => $tx, 'goal_id' => $goalId, 'amount' => $amount];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
