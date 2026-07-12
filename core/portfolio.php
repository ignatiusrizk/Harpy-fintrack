<?php
// Investasi (portfolio manual): aset (assets) + transaksi beli/jual
// (asset_transactions, avg cost method) + harga manual (asset_prices, tidak
// ada feed harga otomatis). SEMUA transaksi investasi TIDAK menyentuh
// transactions/accounts -- dana dianggap berasal/keluar dari luar aplikasi
// (lihat catatan di public/investasi.php). Semua fungsi validasi gagal ->
// apiErr() (menghentikan eksekusi), pola sama dgn core/ lain.
//
// Presisi angka: units DECIMAL(20,8), price_per_unit/fee DECIMAL(15,2). Kami
// pakai float PHP biasa (bukan bcmath) -- double 64-bit punya ~15-17 digit
// signifikan, jauh lebih dari cukup utk skala nominal & unit aplikasi ini
// (bukan HFT/quant). Setiap perbandingan "posisi cukup/tidak" (jual > posisi,
// guard posisi akhir negatif) pakai epsilon PT_EPSILON supaya sisa desimal
// hasil operasi float (mis. 0.1+0.2) tidak salah ditolak/diterima.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

const PT_ASSET_TYPES = ['stock', 'mutual_fund', 'gold', 'crypto', 'deposit', 'other'];
const PT_EPSILON = 0.00000001; // 1e-8, granularitas units DECIMAL(20,8)

/**
 * Validasi & normalisasi input aset (name, type, code opsional, unit_label
 * default "unit"), dipakai bareng create & update. Gagal -> apiErr.
 */
function ptValidateAsset(array $data): array
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) {
        apiErr('Nama aset wajib diisi (maks 100 karakter)');
    }

    $type = trim((string) ($data['type'] ?? ''));
    if (!in_array($type, PT_ASSET_TYPES, true)) {
        apiErr('Jenis aset tidak valid');
    }

    $code = trim((string) ($data['code'] ?? ''));
    if (mb_strlen($code) > 30) {
        apiErr('Kode aset maks 30 karakter');
    }
    $code = $code === '' ? null : $code;

    $unitLabel = trim((string) ($data['unit_label'] ?? ''));
    if (mb_strlen($unitLabel) > 20) {
        apiErr('Label unit maks 20 karakter');
    }
    $unitLabel = $unitLabel === '' ? 'unit' : $unitLabel;

    return ['name' => $name, 'type' => $type, 'code' => $code, 'unit_label' => $unitLabel];
}

/**
 * Validasi tanggal 'Y-m-d' (checkdate, bukan cuma strtotime -- pola sama spt
 * glValidate() core/goals.php). String kosong -> hari ini. Gagal -> apiErr.
 */
function ptValidateDate(?string $date): string
{
    $date = trim((string) ($date ?? ''));
    if ($date === '') {
        return date('Y-m-d');
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)
        || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        apiErr('Tanggal tidak valid');
    }
    return $date;
}

/**
 * Buat aset baru di $spaceId. Return row hasil (id + field ternormalisasi).
 */
function createAsset(int $spaceId, array $data): array
{
    $v = ptValidateAsset($data);

    $stmt = db()->prepare(
        'INSERT INTO assets (space_id, name, type, code, unit_label) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$spaceId, $v['name'], $v['type'], $v['code'], $v['unit_label']]);
    $id = (int) db()->lastInsertId();

    return array_merge(['id' => $id, 'space_id' => $spaceId], $v);
}

/**
 * Update aset $id (nama/type/code/unit_label). Kepemilikan divalidasi via
 * ownAsset().
 */
function updateAsset(int $id, array $data): array
{
    $existing = ownAsset($id);
    $spaceId = (int) $existing['space_id'];
    $v = ptValidateAsset($data);

    $stmt = db()->prepare('UPDATE assets SET name = ?, type = ?, code = ?, unit_label = ? WHERE id = ?');
    $stmt->execute([$v['name'], $v['type'], $v['code'], $v['unit_label'], $id]);

    return array_merge(['id' => $id, 'space_id' => $spaceId], $v);
}

/**
 * Hapus aset $id. Kepemilikan divalidasi via ownAsset(). Ditolak (apiErr) kalau
 * aset masih punya riwayat transaksi (asset_transactions) ATAU riwayat harga
 * (asset_prices) -- beda dgn akun (yg boleh diarsipkan diam-diam), aset
 * investasi TIDAK diarsipkan, jadi hapus HARUS eksplisit ditolak kalau ada
 * data terkait supaya riwayat gain/loss tidak hilang tanpa sadar.
 */
function deleteAsset(int $id): void
{
    ownAsset($id);

    $stmt = db()->prepare('SELECT COUNT(*) c FROM asset_transactions WHERE asset_id = ?');
    $stmt->execute([$id]);
    if ((int) $stmt->fetch()['c'] > 0) {
        apiErr('Tidak bisa hapus aset yang sudah punya riwayat transaksi (beli/jual). Hapus dulu semua riwayat transaksinya.');
    }

    $stmt = db()->prepare('SELECT COUNT(*) c FROM asset_prices WHERE asset_id = ?');
    $stmt->execute([$id]);
    if ((int) $stmt->fetch()['c'] > 0) {
        apiErr('Tidak bisa hapus aset yang sudah punya riwayat harga.');
    }

    db()->prepare('DELETE FROM assets WHERE id = ?')->execute([$id]);
}

/**
 * Hitung posisi aset $assetId dgn metode avg cost, proses asset_transactions
 * urut tx_date lalu id (ASC) -- ini urutan yg dipakai utk menentukan avg cost
 * berjalan, BUKAN urutan tampil (UI/riwayat pakai DESC, lihat assetHistory()).
 *
 * buy:  units += u ; cost += u*price + fee
 * sell: cost -= cost_sebelum * (u_jual / units_sebelum) [proporsional avg
 *       cost] ; units -= u_jual
 *
 * Kalau units_sebelum <= 0 saat sell (data historis sudah tidak konsisten,
 * mis. gara-gara deleteAssetTrade() menghapus buy yg jadi tumpuan sell
 * berikutnya -- dibiarkan per desain, lihat deleteAssetTrade()), proporsi
 * dianggap 0 (bukan div-by-zero) supaya cost tidak berubah & units tetap
 * berkurang penuh (bisa negatif -- itu sinyal utk UI/guard, bukan dicegah di
 * sini).
 *
 * avg_price = units>0 ? cost/units : 0
 * last_price = asset_prices terbaru (priced_at DESC, id DESC) ?? avg_price
 * value = units * last_price ; gain = value - cost
 * gain_pct = cost>0 ? gain/cost*100 : 0
 */
function assetPosition(int $assetId): array
{
    $stmt = db()->prepare(
        'SELECT side, units, price_per_unit, fee FROM asset_transactions WHERE asset_id = ? ORDER BY tx_date ASC, id ASC'
    );
    $stmt->execute([$assetId]);

    $units = 0.0;
    $cost = 0.0;
    foreach ($stmt->fetchAll() as $row) {
        $u = (float) $row['units'];
        $price = (float) $row['price_per_unit'];
        $fee = (float) $row['fee'];

        if ($row['side'] === 'buy') {
            $units += $u;
            $cost += $u * $price + $fee;
        } else {
            $proportion = $units > PT_EPSILON ? ($u / $units) : 0.0;
            $cost -= $cost * $proportion;
            $units -= $u;
        }
    }

    $units = round($units, 8);
    $cost = round($cost, 2);
    $avgPrice = $units > PT_EPSILON ? $cost / $units : 0.0;

    $priceStmt = db()->prepare(
        'SELECT price_per_unit FROM asset_prices WHERE asset_id = ? ORDER BY priced_at DESC, id DESC LIMIT 1'
    );
    $priceStmt->execute([$assetId]);
    $priceRow = $priceStmt->fetch();
    $lastPrice = $priceRow !== false ? (float) $priceRow['price_per_unit'] : $avgPrice;

    $value = round($units * $lastPrice, 2);
    $gain = round($value - $cost, 2);
    $gainPct = abs($cost) > PT_EPSILON ? ($gain / $cost * 100) : 0.0;

    return [
        'units' => $units,
        'cost' => $cost,
        'avg_price' => $avgPrice,
        'last_price' => $lastPrice,
        'value' => $value,
        'gain' => $gain,
        'gain_pct' => $gainPct,
    ];
}

/**
 * Catat transaksi beli/jual aset $assetId. Kepemilikan divalidasi via
 * ownAsset(). side='sell' ditolak (apiErr) kalau $unitsRaw melebihi POSISI
 * TOTAL SAAT INI (bukan posisi historis per tanggal -- tx_date backdated yg
 * bikin urutan kronologis "negatif di tengah jalan" TIDAK divalidasi di sini,
 * hanya guard saldo akhir; dokumentasi keputusan ini ada di brief Task 9 &
 * README delete/trade). Baris asset dikunci (SELECT ... FOR UPDATE) selama
 * transaksi DB supaya dua jual nyaris bersamaan tidak dobel-lolos cek posisi
 * yg sama (pola sama spt depositGoal()/withdrawGoal() core/goals.php).
 */
function tradeAsset(int $assetId, string $side, $unitsRaw, $priceRaw, $feeRaw, ?string $txDate): array
{
    $existing = ownAsset($assetId);

    if (!in_array($side, ['buy', 'sell'], true)) {
        apiErr('Sisi transaksi tidak valid');
    }
    if (!is_numeric($unitsRaw) || (float) $unitsRaw <= 0) {
        apiErr('Unit harus lebih dari 0');
    }
    $units = round((float) $unitsRaw, 8);

    if (!is_numeric($priceRaw) || (float) $priceRaw < 0) {
        apiErr('Harga per unit tidak valid');
    }
    $price = round((float) $priceRaw, 2);

    $feeRaw = $feeRaw ?? 0;
    if (!is_numeric($feeRaw) || (float) $feeRaw < 0) {
        apiErr('Fee tidak valid');
    }
    $fee = round((float) $feeRaw, 2);

    $txDate = ptValidateDate($txDate);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM assets WHERE id = ? FOR UPDATE');
        $lock->execute([$assetId]);
        if ($lock->fetch() === false) {
            $pdo->rollBack();
            apiErr('Tidak ditemukan', 404);
        }

        if ($side === 'sell') {
            $pos = assetPosition($assetId);
            if ($units > $pos['units'] + PT_EPSILON) {
                $pdo->rollBack();
                apiErr('Unit jual (' . $units . ') melebihi posisi saat ini (' . $pos['units'] . ' ' . $existing['unit_label'] . ')');
            }
        }

        $ins = $pdo->prepare(
            'INSERT INTO asset_transactions (asset_id, side, units, price_per_unit, fee, tx_date) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$assetId, $side, $units, $price, $fee, $txDate]);
        $id = (int) $pdo->lastInsertId();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'id' => $id,
        'asset_id' => $assetId,
        'side' => $side,
        'units' => $units,
        'price_per_unit' => $price,
        'fee' => $fee,
        'tx_date' => $txDate,
        'position' => assetPosition($assetId),
    ];
}

/**
 * Hapus baris transaksi (asset_transactions) $tradeId. Kepemilikan divalidasi
 * via rantai asset_transactions -> assets -> ownAsset(). Sengaja TIDAK
 * memvalidasi konsistensi historis penuh (mis. sell yg tadinya <= posisi di
 * tanggalnya, setelah hapus buy tertentu jadi > posisi historis pada tanggal
 * itu -- ini DIBIARKAN per desain, sama spt tradeAsset() cuma cek posisi
 * total saat ini bukan per-tanggal). Yang DIJAGA hanya satu hal: hasil AKHIR
 * (assetPosition() setelah hapus) tidak boleh units negatif -- kalau iya,
 * hapus ditolak (apiErr) & baris tidak jadi terhapus (rollback).
 */
function deleteAssetTrade(int $tradeId): void
{
    $stmt = db()->prepare('SELECT * FROM asset_transactions WHERE id = ?');
    $stmt->execute([$tradeId]);
    $tx = $stmt->fetch();
    if ($tx === false) {
        apiErr('Tidak ditemukan', 404);
    }
    $assetId = (int) $tx['asset_id'];
    ownAsset($assetId);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM assets WHERE id = ? FOR UPDATE');
        $lock->execute([$assetId]);
        $lock->fetch();

        $pdo->prepare('DELETE FROM asset_transactions WHERE id = ?')->execute([$tradeId]);

        $posAfter = assetPosition($assetId);
        if ($posAfter['units'] < -PT_EPSILON) {
            $pdo->rollBack();
            apiErr('Tidak bisa hapus: transaksi ini jadi tumpuan penjualan lain, hasil akhirnya posisi negatif. Hapus dulu transaksi jual yang terkait.');
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
 * Catat harga manual aset $assetId (insert baris baru ke asset_prices --
 * tidak pernah update baris lama, riwayat harga tetap utuh). Kepemilikan
 * divalidasi via ownAsset().
 */
function setAssetPrice(int $assetId, $priceRaw, ?string $pricedAt): array
{
    ownAsset($assetId);

    if (!is_numeric($priceRaw) || (float) $priceRaw < 0) {
        apiErr('Harga tidak valid');
    }
    $price = round((float) $priceRaw, 2);
    $pricedAt = ptValidateDate($pricedAt);

    $stmt = db()->prepare('INSERT INTO asset_prices (asset_id, price_per_unit, priced_at) VALUES (?, ?, ?)');
    $stmt->execute([$assetId, $price, $pricedAt]);
    $id = (int) db()->lastInsertId();

    return [
        'id' => $id,
        'asset_id' => $assetId,
        'price_per_unit' => $price,
        'priced_at' => $pricedAt,
        'position' => assetPosition($assetId),
    ];
}

/**
 * Riwayat transaksi (beli/jual) + harga manual aset $assetId, urut TERBARU
 * dulu (kebalikan urutan yg dipakai assetPosition() utk hitung avg cost).
 * Kepemilikan divalidasi via ownAsset().
 */
function assetHistory(int $assetId): array
{
    ownAsset($assetId);

    $trades = db()->prepare(
        'SELECT id, side, units, price_per_unit, fee, tx_date FROM asset_transactions WHERE asset_id = ? ORDER BY tx_date DESC, id DESC'
    );
    $trades->execute([$assetId]);

    $prices = db()->prepare(
        'SELECT id, price_per_unit, priced_at FROM asset_prices WHERE asset_id = ? ORDER BY priced_at DESC, id DESC'
    );
    $prices->execute([$assetId]);

    return [
        'trades' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'side' => $r['side'],
                'units' => (float) $r['units'],
                'price_per_unit' => (float) $r['price_per_unit'],
                'fee' => (float) $r['fee'],
                'tx_date' => $r['tx_date'],
            ];
        }, $trades->fetchAll()),
        'prices' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'price_per_unit' => (float) $r['price_per_unit'],
                'priced_at' => $r['priced_at'],
            ];
        }, $prices->fetchAll()),
    ];
}

/**
 * Ringkasan portfolio $spaceId: list aset + posisi masing-masing (merge
 * assetPosition()), total value/cost/gain/gain_pct, & alokasi per type
 * (persen dari total value -- type tanpa aset tidak muncul). Dipakai
 * langsung sbg respons ?a=list di public/api/investasi.php.
 */
function portfolioSummary(int $spaceId): array
{
    $stmt = db()->prepare('SELECT id, name, type, code, unit_label FROM assets WHERE space_id = ? ORDER BY id ASC');
    $stmt->execute([$spaceId]);

    $assets = [];
    $totalValue = 0.0;
    $totalCost = 0.0;
    $byType = [];

    foreach ($stmt->fetchAll() as $row) {
        $pos = assetPosition((int) $row['id']);
        $assets[] = array_merge([
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'code' => $row['code'],
            'unit_label' => $row['unit_label'],
        ], $pos);

        $totalValue += $pos['value'];
        $totalCost += $pos['cost'];
        $byType[$row['type']] = ($byType[$row['type']] ?? 0.0) + $pos['value'];
    }

    $totalValue = round($totalValue, 2);
    $totalCost = round($totalCost, 2);
    $totalGain = round($totalValue - $totalCost, 2);
    $totalGainPct = abs($totalCost) > PT_EPSILON ? ($totalGain / $totalCost * 100) : 0.0;

    $allocation = [];
    foreach ($byType as $type => $value) {
        $value = round($value, 2);
        $pct = $totalValue > PT_EPSILON ? ($value / $totalValue * 100) : 0.0;
        $allocation[] = ['type' => $type, 'value' => $value, 'pct' => $pct];
    }
    usort($allocation, static fn ($a, $b) => $b['value'] <=> $a['value']);

    return [
        'assets' => $assets,
        'total_value' => $totalValue,
        'total_cost' => $totalCost,
        'total_gain' => $totalGain,
        'total_gain_pct' => $totalGainPct,
        'allocation' => $allocation,
    ];
}

/**
 * Nilai portfolio total user $userId: Σ value SEMUA aset di SEMUA space milik
 * user (personal & business -- beda dgn netWorth() yg cuma jumlah akun space
 * personal, lihat komentar core/balance.php). Dipanggil oleh netWorth() lewat
 * function_exists() hook (Task 4) -- core/balance.php require_once file ini
 * supaya hook itu selalu menyala setelah Task 9.
 */
function portfolioValue(int $userId): float
{
    $stmt = db()->prepare(
        'SELECT a.id FROM assets a JOIN spaces s ON s.id = a.space_id WHERE s.user_id = ?'
    );
    $stmt->execute([$userId]);

    $total = 0.0;
    foreach ($stmt->fetchAll() as $row) {
        $total += assetPosition((int) $row['id'])['value'];
    }
    return round($total, 2);
}
