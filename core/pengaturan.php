<?php
// Pengaturan: profil (nama), ganti password, kelola ruang (create/rename/
// delete/switch). Semua fungsi validasi gagal -> apiErr() (menghentikan
// eksekusi), pola sama dgn core/ lain. Kepemilikan ruang divalidasi via
// ownSpace() (core/helpers.php) yg baca $_SESSION['user_id'] -- bukan dari
// parameter, jadi tidak bisa dilewati.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/seed.php';

const PGT_SPACE_TYPES = ['personal', 'business'];
const PGT_PASSWORD_MIN_LEN = 8;

/**
 * Update nama profil user $userId. name 1-100 char (trim). Return row user
 * (id, name, email).
 */
function profileUpdate(int $userId, string $name): array
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 100) {
        apiErr('Nama wajib diisi (maks 100 karakter)');
    }

    db()->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $userId]);

    $stmt = db()->prepare('SELECT id, name, email FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

/**
 * Ganti password user $userId: verifikasi $old cocok hash tersimpan (salah
 * -> apiErr), validasi $new (min PGT_PASSWORD_MIN_LEN karakter), simpan hash
 * baru. Session (di request ini) TIDAK disentuh -- user tetap login sbg
 * dirinya sendiri setelah ganti password (pemanggil/API tidak perlu
 * re-login), tapi session id DIREGENERASI (mitigasi session fixation --
 * session id lama yg mungkin bocor sebelum ganti password jadi tidak
 * berlaku, pola sama dgn logInAs() di core/auth.php). Semua remember_tokens
 * milik user ini juga DIHAPUS -- cookie "ingat saya" yg mungkin
 * bocor/tercuri sebelum password diganti jadi tidak berlaku lagi (validator
 * token tidak terkait hash password, jadi tanpa ini token lama akan tetap
 * bisa auto-login walau password sudah diganti).
 */
function passwordChange(int $userId, string $old, string $new): void
{
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if ($row === false || !password_verify($old, $row['password_hash'])) {
        apiErr('Password lama salah', 422);
    }
    if (mb_strlen($new) < PGT_PASSWORD_MIN_LEN) {
        apiErr('Password baru minimal ' . PGT_PASSWORD_MIN_LEN . ' karakter');
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
    db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$userId]);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/**
 * Validasi & normalisasi nama/type ruang, dipakai bareng spaceCreate() &
 * spaceRename(). Gagal -> apiErr.
 */
function pgtValidateSpaceName(string $name): string
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 50) {
        apiErr('Nama ruang wajib diisi (maks 50 karakter)');
    }
    return $name;
}

/**
 * List ruang milik $userId + ringkasan (jumlah akun, jumlah transaksi),
 * diurutkan id ASC (ruang tertua duluan -- sama urutan dgn fallback
 * currentSpaceId()).
 */
function spaceList(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT s.id, s.name, s.type,
                (SELECT COUNT(*) FROM accounts a WHERE a.space_id = s.id) account_count,
                (SELECT COUNT(*) FROM transactions t WHERE t.space_id = s.id) tx_count
         FROM spaces s WHERE s.user_id = ? ORDER BY s.id ASC'
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'type' => $r['type'],
            'account_count' => (int) $r['account_count'],
            'tx_count' => (int) $r['tx_count'],
        ];
    }
    return $out;
}

/**
 * Buat ruang baru utk $userId (createSpaceWithDefaults(), seed kategori
 * ikut) lalu auto-switch $_SESSION['space_id'] ke ruang baru itu.
 */
function spaceCreate(int $userId, string $name, string $type): array
{
    $name = pgtValidateSpaceName($name);
    if (!in_array($type, PGT_SPACE_TYPES, true)) {
        apiErr('Jenis ruang tidak valid');
    }

    $id = createSpaceWithDefaults($userId, $name, $type);

    ensureSession();
    $_SESSION['space_id'] = $id;

    return ['id' => $id, 'name' => $name, 'type' => $type, 'account_count' => 0, 'tx_count' => 0];
}

/**
 * Ganti nama ruang $spaceId. Kepemilikan divalidasi via ownSpace() (bukan
 * milik user session -> apiErr 404).
 */
function spaceRename(int $spaceId, string $name): array
{
    $existing = ownSpace($spaceId);
    $name = pgtValidateSpaceName($name);

    db()->prepare('UPDATE spaces SET name = ? WHERE id = ?')->execute([$name, $spaceId]);

    return ['id' => $spaceId, 'name' => $name, 'type' => $existing['type']];
}

/**
 * Hapus ruang $spaceId. Kepemilikan divalidasi via ownSpace(). Ditolak
 * (apiErr) kalau ini satu-satunya ruang milik user -- akun tidak boleh
 * berakhir tanpa ruang sama sekali (currentSpaceId() akan selalu error).
 *
 * DELETE FROM spaces polos TIDAK cukup: transactions.account_id/
 * to_account_id RESTRICT ke accounts, dan recurrings.account_id/category_id
 * RESTRICT ke accounts/categories -- InnoDB tidak menjamin urutan cascade
 * antar tabel anak, jadi cascade spaces->accounts kepentok RESTRICT dari
 * transactions/recurrings yg belum ikut terhapus (ERROR 1451, direproduksi
 * di DB dev). Karena itu transactions & recurrings dihapus EKSPLISIT lebih
 * dulu -- ini jalur RESTRICT satu-satunya di skema (goal_entries, budgets,
 * asset_transactions, asset_prices semuanya CASCADE/SET NULL) -- baru
 * DELETE spaces yg meng-cascade accounts/categories/budgets/goals(+entries)/
 * assets(+trades & prices). JOIN accounts/categories dipakai (bukan cuma
 * WHERE space_id) sbg jaring defensif kalau ada baris lintas-space yg
 * menunjuk akun/kategori ruang ini.
 *
 * Seluruh urutan (cek satu-satunya -> deletes -> pindah session) dibungkus
 * satu transaksi DB (pola try/rollback sama dgn depositGoal() di
 * core/goals.php) -- gagal di tengah tidak meninggalkan ruang setengah
 * kosong. Kalau ruang yg dihapus adalah ruang aktif (session), session
 * dipindah ke ruang tertua yg tersisa.
 */
function spaceDelete(int $spaceId): void
{
    $existing = ownSpace($spaceId);
    $userId = (int) $existing['user_id'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM spaces WHERE user_id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $count = (int) $stmt->fetch()['c'];
        if ($count <= 1) {
            $pdo->rollBack();
            apiErr('Tidak bisa menghapus satu-satunya ruang Anda');
        }

        // 1. transactions dulu (RESTRICT ke accounts via account_id &
        //    to_account_id; goal_entries.transaction_id ikut SET NULL).
        $pdo->prepare(
            'DELETE t FROM transactions t JOIN accounts a ON a.id = t.account_id WHERE a.space_id = ?'
        )->execute([$spaceId]);
        $pdo->prepare(
            'DELETE t FROM transactions t JOIN accounts a ON a.id = t.to_account_id WHERE a.space_id = ?'
        )->execute([$spaceId]);
        $pdo->prepare('DELETE FROM transactions WHERE space_id = ?')->execute([$spaceId]);

        // 2. recurrings (RESTRICT ke accounts & categories; transaksi yg
        //    pernah menunjuk recurring ruang ini sudah terhapus di langkah 1).
        $pdo->prepare(
            'DELETE r FROM recurrings r JOIN accounts a ON a.id = r.account_id WHERE a.space_id = ?'
        )->execute([$spaceId]);
        $pdo->prepare(
            'DELETE r FROM recurrings r JOIN categories c ON c.id = r.category_id WHERE c.space_id = ?'
        )->execute([$spaceId]);
        $pdo->prepare('DELETE FROM recurrings WHERE space_id = ?')->execute([$spaceId]);

        // 3. spaces -- cascade accounts, categories, budgets, goals
        //    (+goal_entries), assets (+asset_transactions & asset_prices).
        $pdo->prepare('DELETE FROM spaces WHERE id = ?')->execute([$spaceId]);

        ensureSession();
        $activeSpaceId = (int) ($_SESSION['space_id'] ?? 0);
        if ($activeSpaceId === $spaceId) {
            $stmt = $pdo->prepare('SELECT id FROM spaces WHERE user_id = ? ORDER BY id ASC LIMIT 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            // $row selalu ada (count > 1 sebelum delete -> minimal 1 tersisa).
            $_SESSION['space_id'] = (int) $row['id'];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Pindah ruang aktif ke $spaceId. Kepemilikan divalidasi via ownSpace()
 * (bukan milik user session -> apiErr 404, mencegah pindah ke ruang user
 * lain).
 */
function spaceSwitch(int $spaceId): array
{
    $existing = ownSpace($spaceId);

    ensureSession();
    $_SESSION['space_id'] = $spaceId;

    return ['id' => $spaceId, 'name' => $existing['name'], 'type' => $existing['type']];
}
