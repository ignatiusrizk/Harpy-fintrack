<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/seed.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/kategori.php';
require_once __DIR__ . '/../core/transaksi.php';
require_once __DIR__ . '/../core/recurring.php';

// Data test tetap — dibersihkan sebelum & sesudah.
$testEmail = 'test+recurring@ft.local';
$otherEmail = 'test+recurring-other@ft.local';

function cleanupTestRecurring(string $email): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user === false) {
        return;
    }
    // Urutan hapus manual (sama alasan spt test lain): recurrings & transactions
    // dulu sebelum cascade users -> spaces -> accounts/categories, supaya tidak
    // kena FK RESTRICT (recurrings.account_id/category_id, transactions.account_id).
    $pdo->prepare(
        'DELETE r FROM recurrings r JOIN spaces s ON s.id = r.space_id WHERE s.user_id = ?'
    )->execute([$user['id']]);
    $pdo->prepare(
        'DELETE t FROM transactions t JOIN spaces s ON s.id = t.space_id WHERE s.user_id = ?'
    )->execute([$user['id']]);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
}

/**
 * Jalankan potongan kode PHP di subprocess terpisah (core sudah di-require)
 * supaya apiErr() yg exit() tidak mematikan proses test utama.
 */
function runSub(string $code): array
{
    $preamble = 'require ' . var_export(__DIR__ . '/../core/db.php', true) . ';'
        . 'require ' . var_export(__DIR__ . '/../core/helpers.php', true) . ';'
        . 'require ' . var_export(__DIR__ . '/../core/kategori.php', true) . ';'
        . 'require ' . var_export(__DIR__ . '/../core/transaksi.php', true) . ';'
        . 'require ' . var_export(__DIR__ . '/../core/recurring.php', true) . ';'
        . 'session_start();';
    $output = shell_exec(PHP_BINARY . ' -r ' . escapeshellarg($preamble . $code));
    $json = json_decode((string) $output, true);
    return is_array($json) ? $json : ['ok' => null, 'raw' => $output];
}

// updateRecurring/toggleRecurring/deleteRecurring/confirmRecurring/skipRecurring
// lewat ownRecurring() -- subprocess WAJIB set session user_id dulu, kalau
// tidak gagalnya krn "Tidak ditemukan" (ownership) duluan, bukan aturan
// bisnis yg sedang diuji.
$sessAs = function (int $uid): string {
    return '$_SESSION["user_id"] = ' . var_export($uid, true) . '; ';
};

function categoryId(int $spaceId, string $name, string $type): int
{
    $stmt = db()->prepare('SELECT id FROM categories WHERE space_id = ? AND name = ? AND type = ? LIMIT 1');
    $stmt->execute([$spaceId, $name, $type]);
    $row = $stmt->fetch();
    return $row === false ? 0 : (int) $row['id'];
}

function addDaysStr(string $date, int $days): string
{
    $sign = $days >= 0 ? '+' : '';
    return date('Y-m-d', strtotime($date . ' ' . $sign . $days . ' days'));
}

function txCountFor(int $recurringId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) c FROM transactions WHERE recurring_id = ?');
    $stmt->execute([$recurringId]);
    return (int) $stmt->fetch()['c'];
}

function recurringRow(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM recurrings WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

ensureSession();

cleanupTestRecurring($testEmail);
cleanupTestRecurring($otherEmail);

// --- setup: 2 user + space + akun + kategori seed --------------------------

$userId = registerUser('Test Recurring', $testEmail, 'password123');
$otherUserId = registerUser('Test Recurring Other', $otherEmail, 'password123');

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM spaces WHERE user_id = ? ORDER BY id ASC LIMIT 1');
$stmt->execute([$userId]);
$spaceId = (int) $stmt->fetch()['id'];
$stmt->execute([$otherUserId]);
$otherSpaceId = (int) $stmt->fetch()['id'];

$insAcc = $pdo->prepare('INSERT INTO accounts (space_id, name, type, initial_balance) VALUES (?, ?, ?, ?)');
$insAcc->execute([$spaceId, 'Dompet', 'cash', 1000000]);
$accountId = (int) $pdo->lastInsertId();
$insAcc->execute([$otherSpaceId, 'Dompet Lain', 'cash', 0]);
$otherAccountId = (int) $pdo->lastInsertId();

$makanId = categoryId($spaceId, 'Makan & Minum', 'expense');
$belanjaId = categoryId($spaceId, 'Belanja', 'expense');
$gajiId = categoryId($spaceId, 'Gaji', 'income');
assertSame(true, $makanId > 0 && $belanjaId > 0 && $gajiId > 0, 'setup: kategori seed default ditemukan');

$otherGajiId = categoryId($otherSpaceId, 'Gaji', 'income');
assertSame(true, $otherGajiId > 0, 'setup: kategori seed space lain ditemukan');

$_SESSION['user_id'] = $userId;

$today = date('Y-m-d');

// ==================== advanceNextRun (fungsi murni) ====================

assertSame('2026-02-01', advanceNextRun('2026-01-31', 'daily'), 'advanceNextRun: daily +1 hari');
assertSame('2026-07-08', advanceNextRun('2026-07-01', 'weekly'), 'advanceNextRun: weekly +7 hari');
assertSame('2026-02-15', advanceNextRun('2026-01-15', 'monthly'), 'advanceNextRun: monthly tanpa anchor -> fallback ke hari next_run sendiri');

// Skenario kunci brief: anchor 31 -> 31 Jan -> 28 Feb -> 31 Mar (anchor day
// TETAP dipakai tiap panggilan, bukan diturunkan dari next_run hasil clamp
// sebelumnya -- kalau diturunkan dari next_run, panggilan kedua akan salah
// jadi 28 Mar, bukan 31 Mar).
$step1 = advanceNextRun('2026-01-31', 'monthly', '2026-01-31');
assertSame('2026-02-28', $step1, 'advanceNextRun: 31 Jan 2026 monthly anchor 31 -> 28 Feb 2026 (2026 bukan kabisat)');
$step2 = advanceNextRun($step1, 'monthly', '2026-01-31');
assertSame('2026-03-31', $step2, 'advanceNextRun: dari 28 Feb (anchor 31 tetap dipakai) -> 31 Mar 2026, bukan 28 Mar');

assertSame('2025-02-28', advanceNextRun('2024-02-29', 'yearly', '2024-02-29'), 'advanceNextRun: 29 Feb 2024 yearly -> 28 Feb 2025 (2025 bukan kabisat)');

$json = runSub('advanceNextRun("2026-01-01", "aneh");');
assertSame(false, $json['ok'] ?? null, 'advanceNextRun: frequency tidak dikenal -> ditolak');

// ==================== createRecurring: validasi gagal (subprocess) ====================

$farFuture = addDaysStr($today, 400);

$json = runSub('createRecurring(' . var_export($spaceId, true) . ', ' . var_export([
    'account_id' => $accountId, 'category_id' => $makanId, 'type' => 'aneh',
    'amount' => 10000, 'note' => '', 'frequency' => 'monthly', 'mode' => 'auto', 'start_date' => $farFuture,
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createRecurring: type tidak valid -> ditolak');

$json = runSub('createRecurring(' . var_export($spaceId, true) . ', ' . var_export([
    'account_id' => $accountId, 'category_id' => $makanId, 'type' => 'expense',
    'amount' => 0, 'note' => '', 'frequency' => 'monthly', 'mode' => 'auto', 'start_date' => $farFuture,
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createRecurring: amount 0 -> ditolak');

$json = runSub('createRecurring(' . var_export($spaceId, true) . ', ' . var_export([
    'account_id' => $accountId, 'category_id' => $gajiId, 'type' => 'expense',
    'amount' => 10000, 'note' => '', 'frequency' => 'monthly', 'mode' => 'auto', 'start_date' => $farFuture,
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createRecurring: kategori income dipakai utk type expense -> ditolak');

$json = runSub('createRecurring(' . var_export($spaceId, true) . ', ' . var_export([
    'account_id' => $accountId, 'category_id' => $makanId, 'type' => 'expense',
    'amount' => 10000, 'note' => '', 'frequency' => 'tak-ada', 'mode' => 'auto', 'start_date' => $farFuture,
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createRecurring: frequency tidak valid -> ditolak');

$json = runSub('createRecurring(' . var_export($spaceId, true) . ', ' . var_export([
    'account_id' => $accountId, 'category_id' => $makanId, 'type' => 'expense',
    'amount' => 10000, 'note' => '', 'frequency' => 'monthly', 'mode' => 'tak-ada', 'start_date' => $farFuture,
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createRecurring: mode tidak valid -> ditolak');

$json = runSub('createRecurring(' . var_export($spaceId, true) . ', ' . var_export([
    'account_id' => $otherAccountId, 'category_id' => $makanId, 'type' => 'expense',
    'amount' => 10000, 'note' => '', 'frequency' => 'monthly', 'mode' => 'auto', 'start_date' => $farFuture,
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createRecurring: akun milik space lain -> ditolak');

// ==================== createRecurring/update/toggle/delete/list: sukses ====================

$rec = createRecurring($spaceId, [
    'account_id' => $accountId, 'category_id' => $makanId, 'type' => 'expense',
    'amount' => 15000, 'note' => 'Langganan test', 'frequency' => 'monthly', 'mode' => 'auto',
    'start_date' => $farFuture,
]);
assertSame(true, $rec['id'] > 0, 'createRecurring: sukses, id > 0');
$recId = (int) $rec['id'];
assertSame($farFuture, $rec['anchor_date'], 'createRecurring: anchor_date = start_date');
assertSame($farFuture, $rec['next_run'], 'createRecurring: next_run awal = start_date');
assertSame(true, $rec['is_active'], 'createRecurring: is_active default true');

$listed = listRecurrings($spaceId);
$found = null;
foreach ($listed as $row) {
    if ((int) $row['id'] === $recId) {
        $found = $row;
        break;
    }
}
assertSame(true, $found !== null, 'listRecurrings: recurring baru muncul');
assertSame(false, $found !== null ? $found['due'] : true, 'listRecurrings: next_run jauh di masa depan -> due false');

$upd = updateRecurring($recId, [
    'account_id' => $accountId, 'category_id' => $belanjaId, 'type' => 'expense',
    'amount' => 25000, 'note' => 'Langganan test (revisi)', 'frequency' => 'weekly', 'mode' => 'reminder',
]);
assertSame(25000.0, (float) $upd['amount'], 'updateRecurring: amount berubah');
assertSame('weekly', $upd['frequency'], 'updateRecurring: frequency berubah');
assertSame('reminder', $upd['mode'], 'updateRecurring: mode berubah');
assertSame($farFuture, $upd['next_run'], 'updateRecurring: next_run TIDAK direset oleh update (bukan start_date baru)');

$toggled = toggleRecurring($recId);
assertSame(false, $toggled['is_active'], 'toggleRecurring: aktif -> nonaktif');
$toggled2 = toggleRecurring($recId);
assertSame(true, $toggled2['is_active'], 'toggleRecurring: nonaktif -> aktif lagi');

// ownRecurring: user lain gagal (404) di semua mutasi

$json = runSub($sessAs($otherUserId) . 'updateRecurring(' . var_export($recId, true) . ', ' . var_export([
    'account_id' => $accountId, 'category_id' => $belanjaId, 'type' => 'expense',
    'amount' => 1000, 'note' => '', 'frequency' => 'monthly', 'mode' => 'auto',
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'updateRecurring: user lain -> ditolak (ownRecurring)');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'Tidak ditemukan'), 'updateRecurring: pesan "Tidak ditemukan"');

$json = runSub($sessAs($otherUserId) . 'toggleRecurring(' . var_export($recId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'toggleRecurring: user lain -> ditolak');

$json = runSub($sessAs($otherUserId) . 'deleteRecurring(' . var_export($recId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'deleteRecurring: user lain -> ditolak');

deleteRecurring($recId);
$listedAfterDelete = listRecurrings($spaceId);
$stillThere = false;
foreach ($listedAfterDelete as $row) {
    if ((int) $row['id'] === $recId) {
        $stillThere = true;
    }
}
assertSame(false, $stillThere, 'deleteRecurring: recurring hilang dari list setelah dihapus');

// ==================== runRecurringForUser: catch-up mode auto (maks 12) ====================

$overdue = addDaysStr($today, -100);
$catchup = createRecurring($spaceId, [
    'account_id' => $accountId, 'category_id' => $makanId, 'type' => 'expense',
    'amount' => 50000, 'note' => 'Catch-up test', 'frequency' => 'daily', 'mode' => 'auto',
    'start_date' => $today,
]);
$catchupId = (int) $catchup['id'];
$pdo->prepare('UPDATE recurrings SET next_run = ? WHERE id = ?')->execute([$overdue, $catchupId]);

$posted = runRecurringForUser($userId);
assertSame(12, $posted, 'runRecurringForUser: catch-up daily telat 100 hari -> tepat 12 posting (cap)');
assertSame(12, txCountFor($catchupId), 'runRecurringForUser: 12 transaksi tercatat dgn recurring_id yg benar');

$afterCatchup = recurringRow($catchupId);
$expectedNextRun = addDaysStr($overdue, 12);
assertSame($expectedNextRun, $afterCatchup['next_run'], 'runRecurringForUser: next_run maju tepat 12 hari dari next_run awal (overdue)');
assertSame(true, $afterCatchup['next_run'] <= $today, 'runRecurringForUser: masih due setelah cap (belum full catch-up, sisa nunggu run berikutnya)');

// Nonaktifkan supaya tidak ikut kehitung di pemanggilan runRecurringForUser berikutnya.
$pdo->prepare('UPDATE recurrings SET is_active = 0 WHERE id = ?')->execute([$catchupId]);

// Panggilan kedua tidak memproses krn sudah nonaktif.
$postedAgain = runRecurringForUser($userId);
assertSame(0, $postedAgain, 'runRecurringForUser: recurring nonaktif tidak diproses lagi');
assertSame(12, txCountFor($catchupId), 'runRecurringForUser: jumlah transaksi tidak nambah setelah dinonaktifkan');

// ==================== mode reminder: due TIDAK auto-post ====================

$yesterday = addDaysStr($today, -1);
$reminder = createRecurring($spaceId, [
    'account_id' => $accountId, 'category_id' => $gajiId, 'type' => 'income',
    'amount' => 200000, 'note' => 'Gaji test', 'frequency' => 'weekly', 'mode' => 'reminder',
    'start_date' => $today,
]);
$reminderId = (int) $reminder['id'];
$pdo->prepare('UPDATE recurrings SET next_run = ? WHERE id = ?')->execute([$yesterday, $reminderId]);

$postedReminder = runRecurringForUser($userId);
assertSame(0, $postedReminder, 'runRecurringForUser: mode reminder due -> TIDAK ikut posting');
assertSame(0, txCountFor($reminderId), 'runRecurringForUser: mode reminder due -> 0 transaksi tercatat');
$reminderAfter = recurringRow($reminderId);
assertSame($yesterday, $reminderAfter['next_run'], 'runRecurringForUser: mode reminder due -> next_run TIDAK maju (dibiarkan utk UI)');

$listedReminder = listRecurrings($spaceId);
$dueFound = null;
foreach ($listedReminder as $row) {
    if ((int) $row['id'] === $reminderId) {
        $dueFound = $row;
    }
}
assertSame(true, $dueFound !== null && $dueFound['due'], 'listRecurrings: reminder due=true krn next_run <= today & mode reminder');

// ==================== confirm: posting 1x & maju ====================

$json = runSub($sessAs($otherUserId) . 'confirmRecurring(' . var_export($reminderId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'confirmRecurring: user lain -> ditolak');

$confirmed = confirmRecurring($reminderId);
assertSame(1, txCountFor($reminderId), 'confirmRecurring: 1 transaksi tercatat');
$expectedNextAfterConfirm = advanceNextRun($yesterday, 'weekly');
assertSame($expectedNextAfterConfirm, $confirmed['next_run'], 'confirmRecurring: next_run maju sesuai advanceNextRun (weekly +7 dari next_run lama)');
$reminderAfterConfirm = recurringRow($reminderId);
assertSame($expectedNextAfterConfirm, $reminderAfterConfirm['next_run'], 'confirmRecurring: next_run tersimpan di DB');

// confirmRecurring hanya utk mode reminder -- mode auto ditolak. Pakai
// recurring auto AKTIF baru (bukan $catchupId -- itu sudah dinonaktifkan di
// atas, akan kena cek "tidak aktif" duluan, bukan cek mode yg mau diuji).
$autoActive = createRecurring($spaceId, [
    'account_id' => $accountId, 'category_id' => $makanId, 'type' => 'expense',
    'amount' => 10000, 'note' => '', 'frequency' => 'monthly', 'mode' => 'auto',
    'start_date' => $farFuture,
]);
$autoActiveId = (int) $autoActive['id'];
$json = runSub($sessAs($userId) . 'confirmRecurring(' . var_export($autoActiveId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'confirmRecurring: recurring mode auto -> ditolak');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'pengingat'), 'confirmRecurring: pesan sebut mode pengingat');
deleteRecurring($autoActiveId);

// confirmRecurring recurring nonaktif -> ditolak.
toggleRecurring($reminderId); // nonaktifkan
$json = runSub($sessAs($userId) . 'confirmRecurring(' . var_export($reminderId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'confirmRecurring: recurring nonaktif -> ditolak');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'tidak aktif'), 'confirmRecurring: pesan sebut "tidak aktif"');
toggleRecurring($reminderId); // aktifkan lagi utk kebersihan (tidak dipakai lagi setelah ini)

// ==================== skip: maju TANPA posting ====================

$skipRec = createRecurring($spaceId, [
    'account_id' => $accountId, 'category_id' => $belanjaId, 'type' => 'expense',
    'amount' => 75000, 'note' => 'Tagihan test', 'frequency' => 'monthly', 'mode' => 'reminder',
    'start_date' => $today,
]);
$skipId = (int) $skipRec['id'];
$pdo->prepare('UPDATE recurrings SET next_run = ? WHERE id = ?')->execute([$yesterday, $skipId]);

$json = runSub($sessAs($otherUserId) . 'skipRecurring(' . var_export($skipId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'skipRecurring: user lain -> ditolak');

$skipped = skipRecurring($skipId);
assertSame(0, txCountFor($skipId), 'skipRecurring: TIDAK posting transaksi');
$expectedSkipNext = advanceNextRun($yesterday, 'monthly', $today);
assertSame($expectedSkipNext, $skipped['next_run'], 'skipRecurring: next_run maju sesuai advanceNextRun (anchor = anchor_date)');
assertSame(true, $skipped['next_run'] > $today, 'skipRecurring: next_run monthly dari kemarin -> jelas lewat hari ini (tidak due lagi)');

// ==================== skip: guard mode/aktif (sama dgn confirm) ====================

// skip pada recurring mode AUTO aktif -> ditolak (tanpa guard ini, ?a=skip
// langsung bisa diam-diam memajukan next_run recurring auto = menekan
// transaksi yg seharusnya diposting pseudo-cron).
$autoForSkip = createRecurring($spaceId, [
    'account_id' => $accountId, 'category_id' => $makanId, 'type' => 'expense',
    'amount' => 5000, 'note' => 'Auto skip-guard test', 'frequency' => 'monthly', 'mode' => 'auto',
    'start_date' => $farFuture,
]);
$autoForSkipId = (int) $autoForSkip['id'];
$json = runSub($sessAs($userId) . 'skipRecurring(' . var_export($autoForSkipId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'skipRecurring: recurring mode auto -> ditolak');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'pengingat'), 'skipRecurring: pesan sebut mode pengingat');
$autoUnchanged = recurringRow($autoForSkipId);
assertSame($farFuture, $autoUnchanged['next_run'], 'skipRecurring: next_run recurring auto TIDAK bergeser setelah ditolak');
deleteRecurring($autoForSkipId);

// skip pada recurring NONAKTIF -> ditolak. $skipId (reminder) dinonaktifkan dulu.
toggleRecurring($skipId);
$json = runSub($sessAs($userId) . 'skipRecurring(' . var_export($skipId, true) . ');');
assertSame(false, $json['ok'] ?? null, 'skipRecurring: recurring nonaktif -> ditolak');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'tidak aktif'), 'skipRecurring: pesan sebut "tidak aktif"');
toggleRecurring($skipId); // aktifkan lagi

// ==================== confirm/skip: expected_next_run (idempoten dobel-klik) ====================

$dupRec = createRecurring($spaceId, [
    'account_id' => $accountId, 'category_id' => $gajiId, 'type' => 'income',
    'amount' => 111000, 'note' => 'Dobel-klik test', 'frequency' => 'monthly', 'mode' => 'reminder',
    'start_date' => $today,
]);
$dupId = (int) $dupRec['id'];
$pdo->prepare('UPDATE recurrings SET next_run = ? WHERE id = ?')->execute([$yesterday, $dupId]);

// Klik pertama: expected_next_run = nilai yg "tampil di kartu" (kemarin) -> sukses.
$dup1 = confirmRecurring($dupId, $yesterday);
assertSame(1, txCountFor($dupId), 'confirmRecurring(expected): klik pertama posting 1 transaksi');

// Klik kedua (request duplikat dgn expected BASI yg sama): next_run baris
// sudah maju -> ditolak 409, TIDAK ada posting kedua, next_run tidak bergeser lagi.
$json = runSub($sessAs($userId) . 'confirmRecurring(' . var_export($dupId, true) . ', ' . var_export($yesterday, true) . ');');
assertSame(false, $json['ok'] ?? null, 'confirmRecurring(expected basi): request duplikat -> ditolak');
assertSame(true, isset($json['error']) && str_contains($json['error'], 'sudah dicatat'), 'confirmRecurring(expected basi): pesan sebut "sudah dicatat"');
assertSame(1, txCountFor($dupId), 'confirmRecurring(expected basi): tetap 1 transaksi (tidak dobel-posting)');
$dupRow = recurringRow($dupId);
assertSame($dup1['next_run'], $dupRow['next_run'], 'confirmRecurring(expected basi): next_run tidak bergeser lagi');

// skip dgn expected basi -> juga ditolak (dobel-tap Lewati tidak melompati 2 periode).
$json = runSub($sessAs($userId) . 'skipRecurring(' . var_export($dupId, true) . ', ' . var_export($yesterday, true) . ');');
assertSame(false, $json['ok'] ?? null, 'skipRecurring(expected basi): request duplikat -> ditolak');
$dupRow = recurringRow($dupId);
assertSame($dup1['next_run'], $dupRow['next_run'], 'skipRecurring(expected basi): next_run tidak bergeser');

// skip dgn expected COCOK (nilai next_run sekarang) -> sukses maju 1 periode.
$dupSkip = skipRecurring($dupId, $dup1['next_run']);
assertSame(advanceNextRun($dup1['next_run'], 'monthly', $today), $dupSkip['next_run'], 'skipRecurring(expected cocok): maju 1 periode normal');
assertSame(1, txCountFor($dupId), 'skipRecurring(expected cocok): tetap tanpa posting baru');

// ==================== createRecurring: start_date kalender palsu ditolak ====================

$json = runSub('createRecurring(' . var_export($spaceId, true) . ', ' . var_export([
    'account_id' => $accountId, 'category_id' => $makanId, 'type' => 'expense',
    'amount' => 10000, 'note' => '', 'frequency' => 'monthly', 'mode' => 'auto', 'start_date' => '2026-02-30',
], true) . ');');
assertSame(false, $json['ok'] ?? null, 'createRecurring: start_date 2026-02-30 (tanggal kalender palsu) -> ditolak');

// ==================== runRecurringForUser: tidak menyentuh space user lain ====================

// frequency 'weekly' (bukan 'daily') sengaja dipilih supaya "next_run =
// kemarin" berarti PERSIS 1 occurrence yg due (loop catch-up berhenti stlh 1x
// krn next_run maju +7 hari, jauh lewat hari ini) -- gampang diverifikasi.
$otherRec = createRecurring($otherSpaceId, [
    'account_id' => $otherAccountId, 'category_id' => $otherGajiId, 'type' => 'income',
    'amount' => 500000, 'note' => 'Punya user lain', 'frequency' => 'weekly', 'mode' => 'auto',
    'start_date' => $today,
]);
$otherRecId = (int) $otherRec['id'];
$pdo->prepare('UPDATE recurrings SET next_run = ? WHERE id = ?')->execute([$yesterday, $otherRecId]);

$postedForWrongUser = runRecurringForUser($userId);
assertSame(0, $postedForWrongUser, 'runRecurringForUser: user A dipanggil, tidak ada lagi recurring due milik user A (reminder tidak diproses, semua auto sudah beres/nonaktif)');
assertSame(0, txCountFor($otherRecId), 'runRecurringForUser(userId): recurring milik user LAIN tidak ikut tersentuh');
$otherRecUnchanged = recurringRow($otherRecId);
assertSame($yesterday, $otherRecUnchanged['next_run'], 'runRecurringForUser(userId): next_run recurring user lain tidak berubah');

$postedForOtherUser = runRecurringForUser($otherUserId);
assertSame(1, $postedForOtherUser, 'runRecurringForUser(otherUserId): recurring miliknya sendiri diproses normal (1 hari telat -> 1 posting)');
assertSame(1, txCountFor($otherRecId), 'runRecurringForUser(otherUserId): 1 transaksi tercatat');

// --- cleanup ---------------------------------------------------------------

cleanupTestRecurring($testEmail);
cleanupTestRecurring($otherEmail);

$stmt = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE email IN (?, ?)');
$stmt->execute([$testEmail, $otherEmail]);
assertSame(0, (int) $stmt->fetch()['c'], 'cleanup: user test terhapus');
