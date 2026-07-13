<?php
// Hutang/Piutang & Cicilan: satu entitas `debts` (direction payable/
// receivable) + `debt_payments` (riwayat bayar/terima). Task 1 (skema +
// kalkulasi inti): debtOutstanding() dihitung on-the-fly (tanpa kolom saldo),
// debtCategory() pola on-demand sama persis dgn glCategory() (core/goals.php)
// tapi 4 key. ownDebt() ada di core/helpers.php (pola ownGoal/ownAsset).
// Fungsi create/pay/update/delete/settle debt menyusul di task berikutnya.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

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
