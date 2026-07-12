<?php
// Endpoint dashboard: ?a=summary — SATU response agregat utk halaman utama
// (public/index.php). POST, wajib login. Baca-saja (tidak mengubah state) --
// tidak wajib CSRF, pola sama spt public/api/investasi.php ?a=list. Semua
// data utk SPACE AKTIF (currentSpaceId()) KECUALI net_worth (seluruh user,
// lewat netWorth() -- lintas semua ruang personal + portfolio). Logika
// agregat inti (spaceBalances/netWorth/portfolioSummary/listRecurrings) sudah
// teruji di task-task sebelumnya -- file ini murni KOMPOSISI + beberapa query
// agregat baru (bulan berjalan, arus kas 6 bulan, top kategori) yg sengaja
// query langsung di sini (bukan core/ terpisah) krn hanya dipakai dashboard.

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
// core/balance.php require_once core/portfolio.php (netWorth() menyertakan
// portfolioValue() lewat function_exists() hook) -- satu require ini sudah
// membuat spaceBalances/netWorth/portfolioSummary semua tersedia.
require_once __DIR__ . '/../../core/balance.php';

/**
 * Total income/expense/net transaksi $spaceId di $period ('YYYY-MM').
 * Dipakai bareng utk 'month' (space aktif) & 'pnl' (sama persis kalau space
 * aktif type business -- laba/rugi = pendapatan-beban space itu bulan
 * berjalan, tidak ada bedanya secara query dgn ringkasan bulan ini).
 */
function dbSummaryMonthTotals(int $spaceId, string $period): array
{
    $stmt = db()->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) income,
            COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) expense
         FROM transactions
         WHERE space_id = ? AND DATE_FORMAT(tx_date, '%Y-%m') = ?"
    );
    $stmt->execute([$spaceId, $period]);
    $row = $stmt->fetch();

    $income = (float) $row['income'];
    $expense = (float) $row['expense'];

    return ['income' => $income, 'expense' => $expense, 'net' => $income - $expense];
}

/**
 * Tagihan mendatang $spaceId: recurring AKTIF dgn next_run <= today+7 hari,
 * SEMUA mode (auto & reminder) -- auto akan terposting sendiri lewat
 * pseudo-cron, reminder butuh aksi user, UI yg membedakan lewat badge mode.
 * Termasuk yg sudah lewat (next_run <= today, blm sempat diproses cron/user)
 * -- itu justru paling mendesak utk ditampilkan. Urut next_run terdekat dulu.
 */
function dbSummaryUpcoming(int $spaceId, string $today): array
{
    $until = date('Y-m-d', strtotime($today . ' +7 days'));

    $stmt = db()->prepare(
        'SELECT r.id, r.note, r.amount, r.next_run, r.mode, r.type,
                c.name category_name, c.icon category_icon, c.color category_color
         FROM recurrings r
         JOIN categories c ON c.id = r.category_id
         WHERE r.space_id = ? AND r.is_active = 1 AND r.next_run <= ?
         ORDER BY r.next_run ASC, r.id ASC'
    );
    $stmt->execute([$spaceId, $until]);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = [
            'id' => (int) $r['id'],
            'note' => $r['note'],
            'category_name' => $r['category_name'],
            'category_icon' => $r['category_icon'],
            'category_color' => $r['category_color'],
            'amount' => (float) $r['amount'],
            'next_run' => $r['next_run'],
            'mode' => $r['mode'],
            'type' => $r['type'],
        ];
    }
    return $out;
}

/**
 * Arus kas 6 bulan terakhir $spaceId TERMASUK bulan berjalan, SATU query
 * GROUP BY bulan (bukan 6 query terpisah). Bulan tanpa transaksi tetap
 * muncul dgn income/expense 0 (dibangun dulu kerangka 6 bulan, baru ditimpa
 * hasil query) -- supaya bar chart selalu dapat 6 titik data lengkap.
 */
function dbSummaryCashflow(int $spaceId): array
{
    $months = [];
    for ($i = 5; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("-{$i} months"));
        $months[$ym] = ['period' => $ym, 'income' => 0.0, 'expense' => 0.0];
    }

    $startDate = array_key_first($months) . '-01';

    $stmt = db()->prepare(
        "SELECT DATE_FORMAT(tx_date, '%Y-%m') period,
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) expense
         FROM transactions
         WHERE space_id = ? AND tx_date >= ?
         GROUP BY period"
    );
    $stmt->execute([$spaceId, $startDate]);

    foreach ($stmt->fetchAll() as $row) {
        if (isset($months[$row['period']])) {
            $months[$row['period']]['income'] = (float) $row['income'];
            $months[$row['period']]['expense'] = (float) $row['expense'];
        }
    }

    return array_values($months);
}

/**
 * Top 6 kategori expense $spaceId di $period ('YYYY-MM'), urut nominal
 * terbesar. Dikelompokkan per category_id APA ADANYA (tidak menggabung
 * anak->induk spt budgetStatus() -- ini murni "ke mana uang paling banyak
 * keluar", bukan status anggaran).
 */
function dbSummaryTopCategories(int $spaceId, string $period): array
{
    $stmt = db()->prepare(
        "SELECT c.name, c.icon, c.color, SUM(t.amount) amount
         FROM transactions t
         JOIN categories c ON c.id = t.category_id
         WHERE t.space_id = ? AND t.type = 'expense' AND DATE_FORMAT(t.tx_date, '%Y-%m') = ?
         GROUP BY t.category_id
         ORDER BY amount DESC
         LIMIT 6"
    );
    $stmt->execute([$spaceId, $period]);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = [
            'name' => $r['name'],
            'icon' => $r['icon'],
            'color' => $r['color'],
            'amount' => (float) $r['amount'],
        ];
    }
    return $out;
}

/**
 * Type ruang $spaceId ('personal'|'business'). Space tidak ditemukan (tidak
 * seharusnya terjadi -- $spaceId selalu dari currentSpaceId() tervalidasi)
 * -> fallback 'personal' defensif, bukan apiErr (ini murni menentukan tampil/
 * tidaknya kartu Laba/Rugi, bukan operasi yg butuh integritas ketat).
 */
function dbSummarySpaceType(int $spaceId): string
{
    $stmt = db()->prepare('SELECT type FROM spaces WHERE id = ?');
    $stmt->execute([$spaceId]);
    $row = $stmt->fetch();
    return $row !== false ? $row['type'] : 'personal';
}

/**
 * Rakit response ?a=summary lengkap. $userId dipakai HANYA utk netWorth()
 * (lintas semua ruang personal user, beda scope dgn field lain yg semua
 * scoped ke $spaceId aktif).
 */
function dashboardSummary(int $spaceId, int $userId): array
{
    $today = date('Y-m-d');
    $period = date('Y-m');

    $month = dbSummaryMonthTotals($spaceId, $period);

    $accounts = [];
    foreach (spaceBalances($spaceId) as $id => $account) {
        $accounts[] = array_merge(['id' => $id], $account);
    }

    $ptSummary = portfolioSummary($spaceId);
    $portfolio = count($ptSummary['assets']) > 0
        ? [
            'value' => $ptSummary['total_value'],
            'gain' => $ptSummary['total_gain'],
            'gain_pct' => $ptSummary['total_gain_pct'],
        ]
        : null;

    $pnl = dbSummarySpaceType($spaceId) === 'business' ? $month : null;

    return [
        'month' => $month,
        'accounts' => $accounts,
        'net_worth' => netWorth($userId),
        'upcoming' => dbSummaryUpcoming($spaceId, $today),
        'cashflow' => dbSummaryCashflow($spaceId),
        'top_categories' => dbSummaryTopCategories($spaceId, $period),
        'portfolio' => $portfolio,
        'pnl' => $pnl,
    ];
}

$user = requireLoginApi();
$spaceId = currentSpaceId();
$action = get('a', '');

try {
    switch ($action) {
        case 'summary':
            apiOk(dashboardSummary($spaceId, (int) $user['id']));
            break;

        default:
            apiErr('Aksi tidak dikenal', 404);
    }
} catch (Throwable $e) {
    apiErr($e);
}
