<?php
// Recurring & tagihan (pseudo-cron): transaksi berulang (mode auto -> posting
// otomatis) & pengingat tagihan (mode reminder -> user konfirmasi manual).
// Semua fungsi validasi gagal -> apiErr() (menghentikan eksekusi), pola sama
// dengan core/ lain. Reuse txAccountInSpace/txCategoryInSpace/createTransaction
// dari core/transaksi.php -- recurring engine TIDAK pernah insert ke
// transactions secara manual.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/transaksi.php';

const RC_TYPES = ['income', 'expense'];
const RC_FREQUENCIES = ['daily', 'weekly', 'monthly', 'yearly'];
const RC_MODES = ['auto', 'reminder'];

// Batas posting catch-up per recurring per pemanggilan runRecurringForUser()/
// rcRunAutoOne() -- recurring yg lama tidak "dibuka" (mis. app tidak dipakai
// berbulan-bulan) tidak bikin ratusan transaksi sekaligus dalam 1 request.
// Sisa yg belum ke-catch-up akan lanjut di pemanggilan berikutnya (next_run
// masih <= today setelah cap tercapai).
const RC_MAX_CATCHUP = 12;

/**
 * Hitung next_run berikutnya sesuai frequency.
 *
 * - daily: +1 hari dari $nextRun.
 * - weekly: +7 hari dari $nextRun.
 * - monthly & yearly: pakai ANCHOR day (monthly) / anchor month+day (yearly)
 *   dari $anchorDate -- BUKAN diturunkan dari hari $nextRun (yg mungkin
 *   sudah di-clamp bulan sebelumnya). Ini krn deret "31 Jan -> 28 Feb -> 31
 *   Mar" (anchor 31) akan SALAH jadi "31 Jan -> 28 Feb -> 28 Mar" kalau
 *   panggilan kedua menurunkan anchor dari next_run hasil clamp (28) alih-
 *   alih dari anchor asli (31). Karena itu $anchorDate SEHARUSNYA SELALU
 *   diisi = kolom recurrings.anchor_date (tetap sepanjang catch-up loop,
 *   tidak pernah berubah). $anchorDate null hanya fallback utk pemanggil yg
 *   tidak peduli anchor (mis. test murni) -- pakai hari/bulan $nextRun
 *   sendiri sbg anchor.
 *   Hasil: day = min(anchor_day, jumlah hari bulan target). yearly serupa +
 *   clamp 29 Feb -> 28 Feb di tahun non-kabisat.
 */
function advanceNextRun(string $nextRun, string $frequency, ?string $anchorDate = null): string
{
    if (!in_array($frequency, RC_FREQUENCIES, true)) {
        apiErr('Frekuensi tidak valid');
    }

    [$y, $m, $d] = array_map('intval', explode('-', $nextRun));

    if ($frequency === 'daily') {
        return date('Y-m-d', mktime(0, 0, 0, $m, $d + 1, $y));
    }
    if ($frequency === 'weekly') {
        return date('Y-m-d', mktime(0, 0, 0, $m, $d + 7, $y));
    }

    $anchor = $anchorDate ?? $nextRun;
    [$ay, $am, $ad] = array_map('intval', explode('-', $anchor));

    if ($frequency === 'monthly') {
        $ty = $y;
        $tm = $m + 1;
        if ($tm > 12) {
            $tm = 1;
            $ty++;
        }
        $daysInTargetMonth = (int) date('t', mktime(0, 0, 0, $tm, 1, $ty));
        $day = min($ad, $daysInTargetMonth);
        return sprintf('%04d-%02d-%02d', $ty, $tm, $day);
    }

    // yearly
    $ty = $y + 1;
    $daysInTargetMonth = (int) date('t', mktime(0, 0, 0, $am, 1, $ty));
    $day = min($ad, $daysInTargetMonth);
    return sprintf('%04d-%02d-%02d', $ty, $am, $day);
}

/**
 * Validasi & normalisasi input recurring (dipakai bareng create & update).
 * Aturan: type income|expense; amount > 0; account_id milik $spaceId;
 * category_id milik $spaceId & type sama dgn $type; note maks 255; frequency
 * & mode dari daftar valid. Gagal -> apiErr. Return array siap-pakai:
 * account_id, category_id, type, amount, note, frequency, mode.
 */
function rcValidate(int $spaceId, array $data): array
{
    $type = $data['type'] ?? '';
    if (!in_array($type, RC_TYPES, true)) {
        apiErr('Jenis tidak valid');
    }

    $amount = $data['amount'] ?? null;
    if (!is_numeric($amount) || (float) $amount <= 0) {
        apiErr('Nominal harus lebih dari 0');
    }
    $amount = round((float) $amount, 2);

    $accountId = (int) ($data['account_id'] ?? 0);
    txAccountInSpace($accountId, $spaceId);

    $categoryId = (int) ($data['category_id'] ?? 0);
    if ($categoryId <= 0) {
        apiErr('Kategori wajib dipilih');
    }
    $category = txCategoryInSpace($categoryId, $spaceId);
    if ($category['type'] !== $type) {
        apiErr('Kategori tidak sesuai jenis');
    }

    $note = trim((string) ($data['note'] ?? ''));
    if (mb_strlen($note) > 255) {
        apiErr('Catatan maksimal 255 karakter');
    }
    $note = $note === '' ? null : $note;

    $frequency = $data['frequency'] ?? '';
    if (!in_array($frequency, RC_FREQUENCIES, true)) {
        apiErr('Frekuensi tidak valid');
    }

    $mode = $data['mode'] ?? '';
    if (!in_array($mode, RC_MODES, true)) {
        apiErr('Mode tidak valid');
    }

    return [
        'account_id' => $accountId, 'category_id' => $categoryId, 'type' => $type,
        'amount' => $amount, 'note' => $note, 'frequency' => $frequency, 'mode' => $mode,
    ];
}

/**
 * Buat recurring baru di $spaceId. start_date (default hari ini) jadi
 * anchor_date SEKALIGUS next_run awal. Return row hasil (id + field
 * ternormalisasi + anchor_date/next_run/is_active).
 */
function createRecurring(int $spaceId, array $data): array
{
    $v = rcValidate($spaceId, $data);

    $startDate = trim((string) ($data['start_date'] ?? ''));
    $startDate = $startDate === '' ? date('Y-m-d') : $startDate;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || strtotime($startDate) === false) {
        apiErr('Tanggal mulai tidak valid');
    }

    $stmt = db()->prepare(
        'INSERT INTO recurrings (space_id, account_id, category_id, type, amount, note, frequency, anchor_date, next_run, mode, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $stmt->execute([
        $spaceId, $v['account_id'], $v['category_id'], $v['type'], $v['amount'], $v['note'],
        $v['frequency'], $startDate, $startDate, $v['mode'],
    ]);
    $id = (int) db()->lastInsertId();

    return array_merge(
        ['id' => $id, 'space_id' => $spaceId, 'anchor_date' => $startDate, 'next_run' => $startDate, 'is_active' => true],
        $v
    );
}

/**
 * Update recurring $id. Kepemilikan divalidasi via ownRecurring(). SENGAJA
 * TIDAK mengubah anchor_date/next_run/is_active (pola sama spt
 * initial_balance di akun.php update -- jadwal yg sudah berjalan tidak
 * direset diam-diam lewat form edit; ganti jadwal = hapus & buat ulang).
 */
function updateRecurring(int $id, array $data): array
{
    $existing = ownRecurring($id);
    $spaceId = (int) $existing['space_id'];
    $v = rcValidate($spaceId, $data);

    $stmt = db()->prepare(
        'UPDATE recurrings SET account_id = ?, category_id = ?, type = ?, amount = ?, note = ?, frequency = ?, mode = ? WHERE id = ?'
    );
    $stmt->execute([
        $v['account_id'], $v['category_id'], $v['type'], $v['amount'], $v['note'],
        $v['frequency'], $v['mode'], $id,
    ]);

    return array_merge(
        [
            'id' => $id, 'space_id' => $spaceId,
            'anchor_date' => $existing['anchor_date'], 'next_run' => $existing['next_run'],
            'is_active' => (bool) $existing['is_active'],
        ],
        $v
    );
}

/**
 * Nyala/matikan recurring $id. Kepemilikan divalidasi via ownRecurring().
 * Return ['id'=>int,'is_active'=>bool].
 */
function toggleRecurring(int $id): array
{
    $existing = ownRecurring($id);
    $newActive = ((int) $existing['is_active']) === 1 ? 0 : 1;
    db()->prepare('UPDATE recurrings SET is_active = ? WHERE id = ?')->execute([$newActive, $id]);
    return ['id' => $id, 'is_active' => $newActive === 1];
}

/**
 * Hapus recurring $id. Kepemilikan divalidasi via ownRecurring(). Transaksi
 * yg pernah diposting recurring ini TIDAK ikut terhapus (transactions.
 * recurring_id ON DELETE SET NULL -- riwayat tetap ada, cuma tautannya lepas).
 */
function deleteRecurring(int $id): void
{
    ownRecurring($id);
    db()->prepare('DELETE FROM recurrings WHERE id = ?')->execute([$id]);
}

/**
 * List recurring $spaceId (aktif + nonaktif), diurutkan aktif dulu lalu
 * next_run terdekat. Tiap baris dapat field tambahan 'due' (bool) = aktif &
 * mode reminder & next_run <= hari ini -- dipakai UI utk seksi "Menunggu
 * konfirmasi". Recurring mode 'auto' tidak pernah 'due' lewat field ini
 * (auto-post ditangani pseudo-cron, bukan UI konfirmasi manual).
 */
function listRecurrings(int $spaceId): array
{
    $today = date('Y-m-d');

    $stmt = db()->prepare(
        'SELECT r.*, a.name account_name, c.name category_name, c.icon category_icon, c.color category_color
         FROM recurrings r
         JOIN accounts a ON a.id = r.account_id
         JOIN categories c ON c.id = r.category_id
         WHERE r.space_id = ?
         ORDER BY r.is_active DESC, r.next_run ASC, r.id ASC'
    );
    $stmt->execute([$spaceId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['account_id'] = (int) $r['account_id'];
        $r['category_id'] = (int) $r['category_id'];
        $r['amount'] = (float) $r['amount'];
        $r['is_active'] = (bool) $r['is_active'];
        $r['due'] = $r['is_active'] && $r['mode'] === 'reminder' && $r['next_run'] <= $today;
    }
    unset($r);

    return $rows;
}

/**
 * Konfirmasi recurring mode 'reminder' yg due: posting 1 transaksi utk
 * next_run SAAT INI (yg terlama, krn belum di-advance) + maju next_run.
 * Dipanggil berulang lewat tombol "Catat" tiap klik (1 klik = 1 occurrence) --
 * kalau ada beberapa periode yg kelewat, user klik beberapa kali. Kepemilikan
 * divalidasi via ownRecurring(). Ditolak (apiErr) kalau recurring nonaktif
 * atau mode-nya bukan 'reminder' (auto-post ditangani pseudo-cron, bukan
 * endpoint ini). Lock baris (SELECT...FOR UPDATE dalam transaksi DB) supaya
 * dobel-klik/dobel-tab nyaris bersamaan tidak dobel-posting.
 */
function confirmRecurring(int $id): array
{
    $existing = ownRecurring($id);
    if (!$existing['is_active']) {
        apiErr('Recurring tidak aktif');
    }
    if ($existing['mode'] !== 'reminder') {
        apiErr('Hanya recurring mode pengingat yang bisa dikonfirmasi manual');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Re-select dgn lock -- ownRecurring() di atas sudah pastikan baris
        // ini ada & milik user sesi ini; FOR UPDATE di sini murni utk locking
        // (cegah request paralel dobel-posting), bukan re-validasi ownership.
        $stmt = $pdo->prepare('SELECT * FROM recurrings WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if ($r === false || !$r['is_active'] || $r['mode'] !== 'reminder') {
            // State berubah di antara ownRecurring() & lock (race jarang) --
            // batalkan dgn pesan yg sama spt pengecekan di atas.
            $pdo->rollBack();
            apiErr('Recurring tidak bisa dikonfirmasi saat ini');
        }

        $tx = createTransaction((int) $r['space_id'], [
            'account_id' => $r['account_id'],
            'category_id' => $r['category_id'],
            'type' => $r['type'],
            'amount' => $r['amount'],
            'tx_date' => $r['next_run'],
            'note' => $r['note'],
            'recurring_id' => $id,
        ]);
        $nextRun = advanceNextRun($r['next_run'], $r['frequency'], $r['anchor_date']);
        $pdo->prepare('UPDATE recurrings SET next_run = ? WHERE id = ?')->execute([$nextRun, $id]);

        $pdo->commit();
        return ['transaction' => $tx, 'id' => $id, 'next_run' => $nextRun];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Lewati 1 occurrence recurring $id TANPA posting transaksi -- next_run maju
 * spt biasa. Kepemilikan divalidasi via ownRecurring(). Lock baris spt
 * confirmRecurring() (konsistensi, walau resiko race jauh lebih kecil krn
 * tidak ada insert transaksi).
 */
function skipRecurring(int $id): array
{
    $existing = ownRecurring($id);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM recurrings WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if ($r === false) {
            $pdo->rollBack();
            apiErr('Tidak ditemukan', 404);
        }

        $nextRun = advanceNextRun($r['next_run'], $r['frequency'], $r['anchor_date']);
        $pdo->prepare('UPDATE recurrings SET next_run = ? WHERE id = ?')->execute([$nextRun, $id]);

        $pdo->commit();
        return ['id' => $id, 'next_run' => $nextRun];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Proses satu recurring mode 'auto' yg due (dipanggil dari
 * runRecurringForUser() utk tiap id hasil query). Posting berturut-turut
 * lewat createTransaction (recurring_id diisi) selama next_run <= $today,
 * maks RC_MAX_CATCHUP kali. BEGIN..COMMIT + SELECT...FOR UPDATE: kunci baris
 * ini supaya 2 request nyaris bersamaan (mis. 2 tab dibuka bareng) tidak
 * dobel-posting -- request kedua menunggu lock, lalu row sudah is_active/
 * mode/next_run terbaru (bisa saja sudah tidak due lagi kalau request
 * pertama sudah selesai duluan). Return jumlah transaksi yg diposting.
 */
function rcRunAutoOne(int $id, string $today): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM recurrings WHERE id = ? AND is_active = 1 AND mode = 'auto' FOR UPDATE");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if ($r === false) {
            $pdo->commit();
            return 0;
        }

        $nextRun = $r['next_run'];
        $posted = 0;
        while ($nextRun <= $today && $posted < RC_MAX_CATCHUP) {
            createTransaction((int) $r['space_id'], [
                'account_id' => $r['account_id'],
                'category_id' => $r['category_id'],
                'type' => $r['type'],
                'amount' => $r['amount'],
                'tx_date' => $nextRun,
                'note' => $r['note'],
                'recurring_id' => $id,
            ]);
            $nextRun = advanceNextRun($nextRun, $r['frequency'], $r['anchor_date']);
            $posted++;
        }

        if ($posted > 0) {
            $pdo->prepare('UPDATE recurrings SET next_run = ? WHERE id = ?')->execute([$nextRun, $id]);
        }

        $pdo->commit();
        return $posted;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Jalankan pseudo-cron utk semua recurring aktif MODE AUTO milik $userId (via
 * JOIN spaces, lintas semua space user itu) yg next_run <= hari ini (WIB,
 * date() PHP -- BUKAN NOW() MySQL). Mode 'reminder' TIDAK disentuh sama
 * sekali di sini (tidak posting, tidak maju next_run) -- itu murni tanggung
 * jawab UI (badge "due", tombol Catat/Lewati -> confirmRecurring()/
 * skipRecurring()). Return total transaksi yg diposting (dijumlah dari semua
 * recurring due milik user ini).
 */
function runRecurringForUser(int $userId): int
{
    $today = date('Y-m-d');

    $stmt = db()->prepare(
        "SELECT r.id FROM recurrings r JOIN spaces s ON s.id = r.space_id
         WHERE s.user_id = ? AND r.is_active = 1 AND r.mode = 'auto' AND r.next_run <= ?"
    );
    $stmt->execute([$userId, $today]);
    $ids = array_column($stmt->fetchAll(), 'id');

    $posted = 0;
    foreach ($ids as $id) {
        $posted += rcRunAutoOne((int) $id, $today);
    }
    return $posted;
}
