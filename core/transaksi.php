<?php
// Transaksi: create/update/delete tervalidasi + list terfilter dgn agregat.
// Semua fungsi validasi gagal -> apiErr() (menghentikan eksekusi), pola sama
// dengan helper lain di core/. spaceId SELALU dipercaya dari pemanggil
// (currentSpaceId() sesi utk create, space milik transaksi itu sendiri utk
// update/delete via ownTransaction()) — tidak pernah dari input klien mentah.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

const TX_TYPES = ['income', 'expense', 'transfer'];
const TX_PER_PAGE = 50;
// Batas wajar hasil export.php (public/export.php) -- CSV dgn puluhan ribu
// baris tetap terkirim (tidak reject total), tapi dipotong & diberi catatan
// di baris terakhir (lihat txListForExport()) drpd memori/response membengkak
// tak terbatas kalau user filter rentang tanggal sangat luas.
const TX_EXPORT_LIMIT = 10000;

/**
 * Pastikan akun ada & benar-benar milik $spaceId (bukan sekadar milik user --
 * ini yg menolak transfer lintas-space walau kedua akun sama-sama milik user
 * yg sama). Gagal -> apiErr 404. Return row akun (id, space_id, name, type).
 */
function txAccountInSpace(int $accountId, int $spaceId): array
{
    $stmt = db()->prepare('SELECT id, space_id, name, type FROM accounts WHERE id = ?');
    $stmt->execute([$accountId]);
    $row = $stmt->fetch();
    if ($row === false || (int) $row['space_id'] !== $spaceId) {
        apiErr('Akun tidak ditemukan', 404);
    }
    return $row;
}

/**
 * Pastikan kategori ada & milik $spaceId. Gagal -> apiErr 404. Return row
 * kategori (id, space_id, type, name).
 */
function txCategoryInSpace(int $categoryId, int $spaceId): array
{
    $stmt = db()->prepare('SELECT id, space_id, type, name FROM categories WHERE id = ?');
    $stmt->execute([$categoryId]);
    $row = $stmt->fetch();
    if ($row === false || (int) $row['space_id'] !== $spaceId) {
        apiErr('Kategori tidak ditemukan', 404);
    }
    return $row;
}

/**
 * Pastikan goal ada & milik $spaceId. Dipakai HANYA saat $data['goal_id']
 * diisi oleh pemanggil tepercaya (core/goals.php) di createTransaction --
 * endpoint transaksi biasa (public/api/transaksi.php) tidak pernah mengisi
 * key ini dari input klien, sama pola dgn recurring_id. Gagal -> apiErr 404.
 */
function txGoalInSpace(int $goalId, int $spaceId): void
{
    $stmt = db()->prepare('SELECT id FROM goals WHERE id = ? AND space_id = ?');
    $stmt->execute([$goalId, $spaceId]);
    if ($stmt->fetch() === false) {
        apiErr('Goal tidak ditemukan', 404);
    }
}

/**
 * Pastikan debt (hutang/piutang) ada & milik $spaceId. Dipakai HANYA saat
 * $data['debt_id'] diisi oleh pemanggil tepercaya (core/hutang.php) di
 * createTransaction -- sama pola dgn txGoalInSpace(). Gagal -> apiErr 404.
 * Return row debt.
 */
function txDebtInSpace(int $debtId, int $spaceId): array
{
    $stmt = db()->prepare('SELECT id FROM debts WHERE id = ? AND space_id = ?');
    $stmt->execute([$debtId, $spaceId]);
    $row = $stmt->fetch();
    if ($row === false) {
        apiErr('Tidak ditemukan', 404);
    }
    return $row;
}

/**
 * Validasi & normalisasi input transaksi (dipakai bareng create & update).
 * Aturan: amount > 0; income/expense wajib category_id milik space & type
 * cocok; transfer wajib to_account_id != account_id, keduanya milik
 * $spaceId yg sama, category_id dipaksa null. Gagal -> apiErr (menghentikan
 * eksekusi). Return array siap-insert: account_id, category_id, type,
 * amount, tx_date, note, to_account_id.
 */
function txValidate(int $spaceId, array $data): array
{
    $type = $data['type'] ?? '';
    if (!in_array($type, TX_TYPES, true)) {
        apiErr('Jenis transaksi tidak valid');
    }

    $amount = $data['amount'] ?? null;
    if (!is_numeric($amount) || (float) $amount <= 0) {
        apiErr('Nominal harus lebih dari 0');
    }
    $amount = round((float) $amount, 2);

    $accountId = (int) ($data['account_id'] ?? 0);
    txAccountInSpace($accountId, $spaceId);

    $txDate = trim((string) ($data['tx_date'] ?? ''));
    $txDate = $txDate === '' ? date('Y-m-d') : $txDate;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $txDate) || strtotime($txDate) === false) {
        apiErr('Tanggal tidak valid');
    }

    $note = trim((string) ($data['note'] ?? ''));
    if (mb_strlen($note) > 255) {
        apiErr('Catatan maksimal 255 karakter');
    }
    $note = $note === '' ? null : $note;

    $categoryId = null;
    $toAccountId = null;

    if ($type === 'transfer') {
        $toAccountId = (int) ($data['to_account_id'] ?? 0);
        if ($toAccountId <= 0) {
            apiErr('Akun tujuan wajib diisi untuk transfer');
        }
        if ($toAccountId === $accountId) {
            apiErr('Akun tujuan harus berbeda dari akun sumber');
        }
        txAccountInSpace($toAccountId, $spaceId);
    } else {
        $categoryId = (int) ($data['category_id'] ?? 0);
        if ($categoryId <= 0) {
            apiErr('Kategori wajib dipilih');
        }
        $category = txCategoryInSpace($categoryId, $spaceId);
        if ($category['type'] !== $type) {
            apiErr('Kategori tidak sesuai jenis transaksi');
        }
    }

    return [
        'account_id' => $accountId,
        'category_id' => $categoryId,
        'type' => $type,
        'amount' => $amount,
        'tx_date' => $txDate,
        'note' => $note,
        'to_account_id' => $toAccountId,
    ];
}

/**
 * Buat transaksi baru di $spaceId (dipercaya dari pemanggil, mis.
 * currentSpaceId() sesi). Return row hasil (id + field ternormalisasi).
 *
 * $data['recurring_id'] opsional -- dipakai HANYA oleh core/recurring.php
 * (pseudo-cron auto-post & confirm) utk menandai transaksi ini hasil posting
 * recurring tertentu. $data['goal_id'] opsional -- dipakai HANYA oleh
 * core/goals.php (deposit/withdraw) utk menautkan transaksi ini ke goal
 * tertentu, divalidasi milik $spaceId via txGoalInSpace(). $data['debt_id']
 * opsional -- dipakai HANYA oleh core/hutang.php (createDebt disburse/
 * payDebt) utk menautkan transaksi ini ke hutang/piutang tertentu, divalidasi
 * milik $spaceId via txDebtInSpace() (defense-in-depth, sama pola dgn
 * goal_id -- pemanggil tepercaya sudah pegang row debt via ownDebt()/spaceId
 * debt itu sendiri, jadi ini tidak pernah gagal utk mereka). Endpoint API
 * (public/api/transaksi.php) tidak pernah mengisi ketiga key ini dari input
 * klien -- txReadInput() tidak membacanya dari post(), jadi klien tidak bisa
 * memalsukan tautan ke recurring/goal/debt milik orang lain lewat endpoint
 * transaksi biasa.
 */
function createTransaction(int $spaceId, array $data): array
{
    $v = txValidate($spaceId, $data);

    $recurringId = !empty($data['recurring_id']) ? (int) $data['recurring_id'] : null;

    $goalId = !empty($data['goal_id']) ? (int) $data['goal_id'] : null;
    if ($goalId !== null) {
        txGoalInSpace($goalId, $spaceId);
    }

    $debtId = !empty($data['debt_id']) ? (int) $data['debt_id'] : null;
    if ($debtId !== null) {
        txDebtInSpace($debtId, $spaceId);
    }

    $stmt = db()->prepare(
        'INSERT INTO transactions (space_id, account_id, category_id, type, amount, tx_date, note, to_account_id, recurring_id, goal_id, debt_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $spaceId, $v['account_id'], $v['category_id'], $v['type'],
        $v['amount'], $v['tx_date'], $v['note'], $v['to_account_id'], $recurringId, $goalId, $debtId,
    ]);
    $id = (int) db()->lastInsertId();

    return array_merge(
        ['id' => $id, 'space_id' => $spaceId, 'recurring_id' => $recurringId, 'goal_id' => $goalId, 'debt_id' => $debtId],
        $v
    );
}

/**
 * Pesan penolakan seragam utk update/delete transaksi yg tertaut goal
 * (goal_id terisi) -- lihat txRejectIfLinked().
 */
const TX_GOAL_LINKED_MSG = 'Transaksi ini terkait target tabungan. Kelola lewat halaman Goals (Setor/Tarik).';

/**
 * Pesan penolakan seragam utk update/delete transaksi yg tertaut hutang/
 * piutang (debt_id terisi) -- lihat txRejectIfLinked().
 */
const TX_DEBT_LINKED_MSG = 'Transaksi ini terkait utang/cicilan. Kelola lewat halaman Hutang.';

/**
 * Tolak (apiErr, menghentikan eksekusi) kalau transaksi $existing tertaut
 * goal (goal_id terisi) ATAU hutang/piutang (debt_id terisi). Transaksi hasil
 * depositGoal()/withdrawGoal() punya goal_entries yg mengagregasi saved goal
 * (lihat listGoals()) -- kalau transaksi ini diedit/dihapus lewat modul
 * Transaksi biasa, goal_entries TIDAK ikut disesuaikan (skema
 * fk_goal_entries_transaction cuma SET NULL transaction_id, entry amount-nya
 * SENDIRI tetap ada), jadi saved goal jadi basi/tidak sinkron dgn saldo akun
 * sebenarnya. Sama pola utk transaksi hasil createDebt(disburse)/payDebt() --
 * baris debt_payments (kalau ada) & outstanding/next_due debt TIDAK ikut
 * disesuaikan kalau transaksi kas-nya diedit/dihapus lewat modul Transaksi
 * biasa (fk_debt_payments_tx cuma SET NULL transaction_id). Satu-satunya
 * jalur aman utk membalik/menghapus setoran/pembayaran adalah goals.php
 * (withdraw/delete) & hutang.php (deleteDebt), yg menjaga entitas terkait
 * tetap konsisten. Transaksi yg goal_id/debt_id-nya sudah NULL (mis. setelah
 * goal/debt induknya dihapus -- lihat deleteGoal()/deleteDebt(), FK SET NULL)
 * LOLOS cek ini & bisa diedit/dihapus normal spt transaksi biasa. Goal dicek
 * lebih dulu (pesan lebih spesifik kalau kedua key entah bagaimana terisi
 * sekaligus, yg seharusnya tidak pernah terjadi -- satu transaksi hanya
 * pernah dibuat oleh salah satu modul).
 */
function txRejectIfLinked(array $existing): void
{
    if ($existing['goal_id'] !== null) {
        apiErr(TX_GOAL_LINKED_MSG);
    }
    if ($existing['debt_id'] !== null) {
        apiErr(TX_DEBT_LINKED_MSG);
    }
}

/**
 * Update transaksi $id. Kepemilikan divalidasi via ownTransaction() (apiErr
 * 404 kalau bukan milik user session) -- space transaksi itu sendiri (bukan
 * space aktif sesi) yg dipakai utk revalidasi akun/kategori, konsisten dgn
 * pola akun.php (edit tidak bergantung ruang aktif saat ini). Transaksi
 * tertaut goal/debt ditolak -- lihat txRejectIfLinked().
 */
function updateTransaction(int $id, array $data): array
{
    $existing = ownTransaction($id);
    txRejectIfLinked($existing);
    $spaceId = (int) $existing['space_id'];
    $v = txValidate($spaceId, $data);

    $stmt = db()->prepare(
        'UPDATE transactions SET account_id = ?, category_id = ?, type = ?, amount = ?, tx_date = ?, note = ?, to_account_id = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $v['account_id'], $v['category_id'], $v['type'],
        $v['amount'], $v['tx_date'], $v['note'], $v['to_account_id'], $id,
    ]);

    return array_merge(['id' => $id, 'space_id' => $spaceId], $v);
}

/**
 * Hapus transaksi $id. Kepemilikan divalidasi via ownTransaction(). Transaksi
 * tertaut goal/debt ditolak -- lihat txRejectIfLinked().
 */
function deleteTransaction(int $id): void
{
    $existing = ownTransaction($id);
    txRejectIfLinked($existing);
    db()->prepare('DELETE FROM transactions WHERE id = ?')->execute([$id]);
}

/**
 * Bangun klausa WHERE + params filter transaksi $spaceId (from,to DATE;
 * account_id -- cocok baik sbg sumber maupun tujuan transfer; category_id;
 * type; q LIKE note). Dipakai bareng oleh listTransactions() (halaman
 * transaksi, terpaginasi) & txListForExport() (export.php, tidak
 * terpaginasi) -- SATU sumber aturan filter supaya keduanya selalu
 * konsisten. Return [whereSql, params].
 */
function txBuildWhere(int $spaceId, array $filters): array
{
    $where = ['t.space_id = ?'];
    $params = [$spaceId];

    if (!empty($filters['from'])) {
        $where[] = 't.tx_date >= ?';
        $params[] = $filters['from'];
    }
    if (!empty($filters['to'])) {
        $where[] = 't.tx_date <= ?';
        $params[] = $filters['to'];
    }
    if (!empty($filters['account_id'])) {
        $where[] = '(t.account_id = ? OR t.to_account_id = ?)';
        $params[] = (int) $filters['account_id'];
        $params[] = (int) $filters['account_id'];
    }
    if (!empty($filters['category_id'])) {
        $where[] = 't.category_id = ?';
        $params[] = (int) $filters['category_id'];
    }
    if (!empty($filters['type']) && in_array($filters['type'], TX_TYPES, true)) {
        $where[] = 't.type = ?';
        $params[] = $filters['type'];
    }
    if (!empty($filters['q'])) {
        // Escape wildcard LIKE (\, %, _) di INPUT USER supaya literal "%"/"_"
        // dlm catatan dicari apa adanya, bukan diperlakukan sbg wildcard --
        // parameterized query sudah aman dari injeksi, ini murni soal makna
        // pencarian. ESCAPE '\' eksplisit (bukan andalkan default MySQL) biar
        // benar walau sql_mode NO_BACKSLASH_ESCAPES suatu saat aktif.
        $escapedQ = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']);
        $where[] = "t.note LIKE ? ESCAPE '\\\\'";
        $params[] = '%' . $escapedQ . '%';
    }

    return [implode(' AND ', $where), $params];
}

/**
 * List transaksi $spaceId dgn filter opsional (lihat txBuildWhere(); page
 * 1-based, TX_PER_PAGE/hal). Return
 * ['rows'=>..., 'total_rows'=>int, 'total_income'=>float, 'total_expense'=>float]
 * -- agregat & total_rows dihitung atas SELURUH hasil filter (bukan cuma
 * halaman aktif).
 */
function listTransactions(int $spaceId, array $filters): array
{
    [$whereSql, $params] = txBuildWhere($spaceId, $filters);
    $pdo = db();

    $aggStmt = $pdo->prepare(
        "SELECT COUNT(*) total_rows,
                COALESCE(SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END), 0) total_income,
                COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END), 0) total_expense
         FROM transactions t
         WHERE {$whereSql}"
    );
    $aggStmt->execute($params);
    $agg = $aggStmt->fetch();

    $page = max(1, (int) ($filters['page'] ?? 1));
    $offset = ($page - 1) * TX_PER_PAGE;

    // LIMIT/OFFSET diinterpolasi langsung (bukan parameter binding) -- aman
    // krn sudah dipaksa (int) di atas, dan PDO native prepares (emulate off)
    // tidak selalu menerima LIMIT/OFFSET sbg parameter bertipe.
    $rowStmt = $pdo->prepare(
        "SELECT t.*, a.name account_name, a.type account_type,
                c.name category_name, c.icon category_icon, c.color category_color,
                ta.name to_account_name
         FROM transactions t
         JOIN accounts a ON a.id = t.account_id
         LEFT JOIN categories c ON c.id = t.category_id
         LEFT JOIN accounts ta ON ta.id = t.to_account_id
         WHERE {$whereSql}
         ORDER BY t.tx_date DESC, t.id DESC
         LIMIT {$offset}, " . TX_PER_PAGE
    );
    $rowStmt->execute($params);
    $rows = $rowStmt->fetchAll();

    return [
        'rows' => $rows,
        'total_rows' => (int) $agg['total_rows'],
        'total_income' => (float) $agg['total_income'],
        'total_expense' => (float) $agg['total_expense'],
    ];
}

/**
 * List transaksi $spaceId dgn filter SAMA persis dgn listTransactions()
 * (lewat txBuildWhere() bareng, jadi aturan filter selalu konsisten) TAPI
 * TANPA pagination -- dipakai public/export.php (CSV, butuh seluruh baris
 * cocok filter sekaligus, bukan per-halaman). Dibatasi $limit baris
 * terbaru dulu (ORDER BY tx_date DESC, id DESC, sama spt listTransactions())
 * -- query minta $limit+1 baris supaya bisa tahu apakah hasil SEBENARNYA
 * lebih banyak dari $limit tanpa query COUNT(*) terpisah; baris ke-(limit+1)
 * dibuang, bukan bagian data. Return ['rows'=>..., 'truncated'=>bool].
 */
function txListForExport(int $spaceId, array $filters, int $limit = TX_EXPORT_LIMIT): array
{
    [$whereSql, $params] = txBuildWhere($spaceId, $filters);
    $limit = max(1, $limit);
    $pdo = db();

    // Limit diinterpolasi langsung (bukan parameter binding) -- sama alasan
    // spt listTransactions(): sudah dipaksa int di atas & aman dari injeksi.
    $rowStmt = $pdo->prepare(
        "SELECT t.*, a.name account_name, a.type account_type,
                c.name category_name, c.icon category_icon, c.color category_color,
                ta.name to_account_name
         FROM transactions t
         JOIN accounts a ON a.id = t.account_id
         LEFT JOIN categories c ON c.id = t.category_id
         LEFT JOIN accounts ta ON ta.id = t.to_account_id
         WHERE {$whereSql}
         ORDER BY t.tx_date DESC, t.id DESC
         LIMIT " . ($limit + 1)
    );
    $rowStmt->execute($params);
    $rows = $rowStmt->fetchAll();

    $truncated = count($rows) > $limit;
    if ($truncated) {
        $rows = array_slice($rows, 0, $limit);
    }

    return ['rows' => $rows, 'truncated' => $truncated];
}
