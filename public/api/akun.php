<?php
// Endpoint akun: ?a=list|create|update|archive. Semua POST, semua wajib login+CSRF.

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/balance.php';

const AKUN_TYPES = ['cash', 'bank', 'ewallet', 'other'];

/**
 * Validasi & normalisasi input name/type dari $_POST, dipakai bareng oleh
 * action create & update. Gagal validasi -> apiErr (menghentikan eksekusi).
 */
function akunReadNameType(): array
{
    $name = post('name', '');
    $type = post('type', 'cash');

    if ($name === '' || mb_strlen($name) > 100) {
        apiErr('Nama akun wajib diisi (maks 100 karakter)');
    }
    if (!in_array($type, AKUN_TYPES, true)) {
        apiErr('Jenis akun tidak valid');
    }

    return [$name, $type];
}

/**
 * Validasi & normalisasi saldo awal dari $_POST. Hanya dipakai saat create --
 * initial_balance tidak bisa diubah lewat update (sudah ada transaksi
 * berjalan dari saldo itu, ubah diam-diam akan bikin saldo drift).
 */
function akunReadInitial(): float
{
    $initial = post('initial_balance', 0);
    if (!is_numeric($initial)) {
        apiErr('Saldo awal harus berupa angka');
    }
    return (float) $initial;
}

$user = requireLoginApi();
$spaceId = currentSpaceId();
$action = get('a', '');

try {
    switch ($action) {
        case 'list':
            $accounts = [];
            foreach (spaceBalances($spaceId) as $id => $account) {
                $accounts[] = array_merge(['id' => $id], $account);
            }

            apiOk(['accounts' => $accounts]);
            break;

        case 'create':
            csrf_check();
            [$name, $type] = akunReadNameType();
            $initial = akunReadInitial();

            $stmt = db()->prepare(
                'INSERT INTO accounts (space_id, name, type, initial_balance) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$spaceId, $name, $type, $initial]);
            $id = (int) db()->lastInsertId();

            apiOk(['account' => [
                'id' => $id,
                'name' => $name,
                'type' => $type,
                'balance' => $initial,
                'is_archived' => false,
            ]]);
            break;

        case 'update':
            csrf_check();
            $id = (int) post('id', 0);
            $existing = ownAccount($id);
            [$name, $type] = akunReadNameType();

            // initial_balance sengaja tidak diubah lewat update (lihat
            // akunReadInitial()) -- hanya name & type.
            $stmt = db()->prepare('UPDATE accounts SET name = ?, type = ? WHERE id = ?');
            $stmt->execute([$name, $type, $id]);

            apiOk(['account' => [
                'id' => $id,
                'name' => $name,
                'type' => $type,
                'balance' => accountBalance($id),
                'is_archived' => (bool) $existing['is_archived'],
            ]]);
            break;

        case 'archive':
            csrf_check();
            $id = (int) post('id', 0);
            ownAccount($id);

            $stmt = db()->prepare(
                'SELECT COUNT(*) c FROM transactions WHERE account_id = ? OR to_account_id = ?'
            );
            $stmt->execute([$id, $id]);
            $hasTx = ((int) $stmt->fetch()['c']) > 0;

            if ($hasTx) {
                db()->prepare('UPDATE accounts SET is_archived = 1 WHERE id = ?')->execute([$id]);
                apiOk(['archived' => true, 'deleted' => false]);
            } else {
                db()->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
                apiOk(['archived' => false, 'deleted' => true]);
            }
            break;

        default:
            apiErr('Aksi tidak dikenal', 404);
    }
} catch (Throwable $e) {
    apiErr($e);
}
