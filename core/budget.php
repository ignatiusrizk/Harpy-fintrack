<?php
// Budget: anggaran bulanan per kategori expense. Semua fungsi validasi gagal
// -> apiErr() (menghentikan eksekusi), pola sama dengan core/ lain.
//
// ATURAN SUB-KATEGORI (dipakai di budgetStatus): kategori expense hanya sub 1
// level (lihat core/kategori.php). Kalau budget dipasang di kategori ROOT,
// expense semua ANAK-nya ikut dihitung ke spent budget root itu -- KECUALI
// anak yang punya baris budget SENDIRI di period yang sama; expense anak itu
// lalu murni masuk ke budget anak sendiri, TIDAK dobel dihitung lagi ke
// total spent induknya. Kategori anak yang belum ber-budget sendiri juga
// TIDAK muncul sbg baris terpisah di budgetStatus (expense-nya sudah
// terwakili di baris induk).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Validasi format period 'YYYY-MM' (bulan 01-12). Gagal -> apiErr.
 */
function bgValidatePeriod(string $period): void
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
        apiErr('Periode tidak valid (format YYYY-MM)');
    }
}

/**
 * Status budget per kategori expense yang PUNYA baris budget di $period.
 * Return array node ['category_id','name','icon','color','amount','spent','pct'],
 * pct dibulatkan (int), 0 kalau amount 0. Urut nama A-Z. Lihat komentar di
 * atas file utk aturan spent sub-kategori.
 */
function budgetStatus(int $spaceId, string $period): array
{
    bgValidatePeriod($period);
    $pdo = db();

    $budStmt = $pdo->prepare('SELECT category_id, amount FROM budgets WHERE space_id = ? AND period = ?');
    $budStmt->execute([$spaceId, $period]);
    $budgets = [];
    foreach ($budStmt->fetchAll() as $b) {
        $budgets[(int) $b['category_id']] = (float) $b['amount'];
    }
    if (count($budgets) === 0) {
        return [];
    }

    $catStmt = $pdo->prepare(
        "SELECT id, name, icon, color, parent_id FROM categories WHERE space_id = ? AND type = 'expense'"
    );
    $catStmt->execute([$spaceId]);
    $categories = [];
    foreach ($catStmt->fetchAll() as $c) {
        $id = (int) $c['id'];
        $categories[$id] = [
            'id' => $id,
            'name' => $c['name'],
            'icon' => $c['icon'],
            'color' => $c['color'],
            'parent_id' => $c['parent_id'] !== null ? (int) $c['parent_id'] : null,
        ];
    }

    $spendStmt = $pdo->prepare(
        "SELECT category_id, COALESCE(SUM(amount), 0) total FROM transactions
         WHERE space_id = ? AND type = 'expense' AND category_id IS NOT NULL
           AND DATE_FORMAT(tx_date, '%Y-%m') = ?
         GROUP BY category_id"
    );
    $spendStmt->execute([$spaceId, $period]);
    $spendByCategory = [];
    foreach ($spendStmt->fetchAll() as $s) {
        $spendByCategory[(int) $s['category_id']] = (float) $s['total'];
    }

    $result = [];
    foreach ($budgets as $categoryId => $amount) {
        $cat = $categories[$categoryId] ?? null;
        if ($cat === null) {
            // Kategori terhapus, atau bukan lagi type expense -- defensif,
            // baris budget-nya (harusnya sudah CASCADE hapus) dilewati saja.
            continue;
        }

        $spent = $spendByCategory[$categoryId] ?? 0.0;

        if ($cat['parent_id'] === null) {
            // Root: tambahkan expense tiap anak yg TIDAK punya baris budget
            // sendiri di period ini (supaya tidak dobel dgn budget anak itu).
            foreach ($categories as $childId => $child) {
                if ($child['parent_id'] === $categoryId && !isset($budgets[$childId])) {
                    $spent += $spendByCategory[$childId] ?? 0.0;
                }
            }
        }

        $pct = $amount > 0 ? (int) round($spent / $amount * 100) : 0;

        $result[] = [
            'category_id' => $categoryId,
            'name' => $cat['name'],
            'icon' => $cat['icon'],
            'color' => $cat['color'],
            'amount' => $amount,
            'spent' => $spent,
            'pct' => $pct,
        ];
    }

    usort($result, fn ($a, $b) => strcmp($a['name'], $b['name']));

    return $result;
}

/**
 * Kategori expense $spaceId yang BELUM punya baris budget di $period --
 * dipakai utk seksi "Belum dianggarkan" di halaman. Urut nama A-Z.
 */
function unbudgetedCategories(int $spaceId, string $period): array
{
    bgValidatePeriod($period);

    $stmt = db()->prepare(
        "SELECT c.id, c.name, c.icon, c.color, c.parent_id FROM categories c
         WHERE c.space_id = ? AND c.type = 'expense'
           AND c.id NOT IN (SELECT category_id FROM budgets WHERE space_id = ? AND period = ?)
         ORDER BY c.name ASC"
    );
    $stmt->execute([$spaceId, $spaceId, $period]);
    $rows = $stmt->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'icon' => $r['icon'],
            'color' => $r['color'],
            'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
        ];
    }
    return $out;
}

/**
 * Set (upsert) atau hapus budget kategori $categoryId di $period. $categoryId
 * harus milik $spaceId & type expense (gagal -> apiErr 404/400). $amountRaw
 * kosong/null/0 -> baris budget dihapus (kalau ada). Amount negatif -> ditolak.
 * Return ['deleted'=>bool,'category_id'=>int,'period'=>string,'amount'?=>float].
 */
function setBudget(int $spaceId, int $categoryId, string $period, $amountRaw): array
{
    bgValidatePeriod($period);

    $stmt = db()->prepare('SELECT id, space_id, type FROM categories WHERE id = ?');
    $stmt->execute([$categoryId]);
    $cat = $stmt->fetch();
    if ($cat === false || (int) $cat['space_id'] !== $spaceId) {
        apiErr('Kategori tidak ditemukan', 404);
    }
    if ($cat['type'] !== 'expense') {
        apiErr('Budget hanya berlaku untuk kategori pengeluaran');
    }

    $isEmpty = $amountRaw === null || $amountRaw === '';
    if (!$isEmpty && !is_numeric($amountRaw)) {
        apiErr('Nominal budget tidak valid');
    }
    $amount = $isEmpty ? 0.0 : round((float) $amountRaw, 2);
    if ($amount < 0) {
        apiErr('Nominal budget tidak boleh negatif');
    }

    $pdo = db();

    if ($amount === 0.0) {
        $pdo->prepare('DELETE FROM budgets WHERE space_id = ? AND category_id = ? AND period = ?')
            ->execute([$spaceId, $categoryId, $period]);
        return ['deleted' => true, 'category_id' => $categoryId, 'period' => $period];
    }

    $pdo->prepare(
        'INSERT INTO budgets (space_id, category_id, period, amount) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE amount = VALUES(amount)'
    )->execute([$spaceId, $categoryId, $period, $amount]);

    return ['deleted' => false, 'category_id' => $categoryId, 'period' => $period, 'amount' => $amount];
}

/**
 * Salin semua baris budget $spaceId dari period SEBELUM $period ke $period.
 * Kategori yg di $period sudah punya baris budget DI-SKIP (tidak ditimpa).
 * Return jumlah baris yang benar-benar tersalin.
 */
function copyPrevBudgets(int $spaceId, string $period): int
{
    bgValidatePeriod($period);
    $prevPeriod = date('Y-m', strtotime($period . '-01 -1 month'));

    $pdo = db();
    $stmt = $pdo->prepare('SELECT category_id, amount FROM budgets WHERE space_id = ? AND period = ?');
    $stmt->execute([$spaceId, $prevPeriod]);
    $prevBudgets = $stmt->fetchAll();

    $checkStmt = $pdo->prepare('SELECT COUNT(*) c FROM budgets WHERE space_id = ? AND category_id = ? AND period = ?');
    $insStmt = $pdo->prepare('INSERT INTO budgets (space_id, category_id, period, amount) VALUES (?, ?, ?, ?)');

    $copied = 0;
    foreach ($prevBudgets as $b) {
        $checkStmt->execute([$spaceId, $b['category_id'], $period]);
        if ((int) $checkStmt->fetch()['c'] > 0) {
            continue; // sudah ada budget di period ini -> skip, jangan timpa
        }
        $insStmt->execute([$spaceId, $b['category_id'], $period, $b['amount']]);
        $copied++;
    }

    return $copied;
}
