<?php
// Laporan: agregat bulanan/tahunan per kategori (sub->parent digulung +
// rincian anak) & per akun, laba-rugi ruang usaha, + builder CSV export.
// Semua fungsi baca-saja (tidak ada create/update/delete) -- tidak ada guard
// kepemilikan spt core/ lain, spaceId dipercaya dari pemanggil (currentSpaceId()
// sesi), sama pola dgn core/dashboard.php & core/budget.php.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Validasi format period 'YYYY-MM' (bulan 01-12). Gagal -> apiErr.
 * Pola & regex sama persis dgn bgValidatePeriod() (core/budget.php).
 */
function laporanValidatePeriod(string $period): void
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
        apiErr('Periode tidak valid (format YYYY-MM)');
    }
}

/**
 * Validasi tahun wajar (2000-2100 -- rentang lebar tapi tetap menolak input
 * ngawur spt 0 atau 99999 yg bisa bikin query DATE_FORMAT aneh). Gagal -> apiErr.
 */
function laporanValidateYear(int $year): void
{
    if ($year < 2000 || $year > 2100) {
        apiErr('Tahun tidak valid');
    }
}

/**
 * Type ruang $spaceId ('personal'|'business'). Space tidak ditemukan ->
 * 'personal' (defensif, akan ditolak oleh caller yg butuh business spt
 * laporanPnl() -- bukan apiErr di sini krn fungsi ini juga dipakai murni utk
 * cek, bukan operasi yg butuh integritas ketat). Query mandiri (bukan pakai
 * dbSummarySpaceType() core/dashboard.php) supaya core/laporan.php tidak ikut
 * menyeret require_once balance.php/portfolio.php yg lebih berat & tak perlu
 * di sini.
 */
function laporanSpaceType(int $spaceId): string
{
    $stmt = db()->prepare('SELECT type FROM spaces WHERE id = ?');
    $stmt->execute([$spaceId]);
    $row = $stmt->fetch();
    return $row !== false ? $row['type'] : 'personal';
}

/**
 * Breakdown transaksi $spaceId di rentang [$from,$to] (inklusif) per
 * kategori, dipisah income/expense, TRANSFER DI-EXCLUDE sepenuhnya (transfer
 * bukan pendapatan/beban, hanya perpindahan dana antar akun -- muncul di
 * laporanAccountBreakdown() saja). Aturan sub-kategori: kategori anak (1
 * level, lihat core/kategori.php) SELALU digulung ke induk (amount induk =
 * expense/income langsung induk + SEMUA expense/income anak-anaknya, TIDAK
 * ada pengecualian spt budgetStatus() -- laporan ini murni "kemana uang
 * mengalir", bukan status anggaran per-kategori), sekaligus rincian tiap anak
 * tetap tersimpan di 'children' induknya. Transaksi tanpa kategori (category_id
 * NULL, hanya bisa terjadi lewat kategori yg dihapus -- FK ON DELETE SET NULL,
 * krn createTransaction() sendiri MEWAJIBKAN category_id) dikumpulkan jadi
 * satu item semu "Tanpa kategori" (category_id null) per type. Item diurutkan
 * amount terbesar dulu (root & children masing-masing) -- laporan pengeluaran/
 * pemasukan lazimnya dibaca dari yg paling besar. Return
 * ['income'=>[...item], 'expense'=>[...item]], tiap item
 * {category_id,name,icon,color,amount,children:[{category_id,name,icon,color,amount}]}.
 */
function laporanCategoryBreakdown(int $spaceId, string $from, string $to): array
{
    $pdo = db();

    $stmt = $pdo->prepare(
        "SELECT category_id, type, COALESCE(SUM(amount), 0) amount
         FROM transactions
         WHERE space_id = ? AND type IN ('income', 'expense') AND tx_date BETWEEN ? AND ?
         GROUP BY category_id, type"
    );
    $stmt->execute([$spaceId, $from, $to]);
    $sums = $stmt->fetchAll();

    $catStmt = $pdo->prepare('SELECT id, name, type, icon, color, parent_id FROM categories WHERE space_id = ?');
    $catStmt->execute([$spaceId]);
    $categories = [];
    foreach ($catStmt->fetchAll() as $c) {
        $categories[(int) $c['id']] = [
            'id' => (int) $c['id'],
            'name' => $c['name'],
            'icon' => $c['icon'],
            'color' => $c['color'],
            'parent_id' => $c['parent_id'] !== null ? (int) $c['parent_id'] : null,
        ];
    }

    $rootsByType = ['income' => [], 'expense' => []];
    $uncategorized = ['income' => 0.0, 'expense' => 0.0];

    foreach ($sums as $row) {
        $amount = (float) $row['amount'];
        if ($amount == 0.0) {
            continue;
        }
        $type = $row['type'];
        $categoryId = $row['category_id'] !== null ? (int) $row['category_id'] : null;

        if ($categoryId === null) {
            $uncategorized[$type] += $amount;
            continue;
        }

        $cat = $categories[$categoryId] ?? null;
        if ($cat === null) {
            // Defensif: kategori sudah tidak ada tapi entah bagaimana masih
            // tersisa di grouping (tidak seharusnya terjadi -- FK SET NULL
            // seharusnya sudah membuat category_id NULL saat kategori
            // dihapus). Lewati drpd menampilkan baris rusak.
            continue;
        }

        if ($cat['parent_id'] === null) {
            $rootId = $categoryId;
            $rootCat = $cat;
        } else {
            $rootId = $cat['parent_id'];
            $rootCat = $categories[$rootId] ?? null;
            if ($rootCat === null) {
                continue;
            }
        }

        if (!isset($rootsByType[$type][$rootId])) {
            $rootsByType[$type][$rootId] = [
                'category_id' => $rootId,
                'name' => $rootCat['name'],
                'icon' => $rootCat['icon'],
                'color' => $rootCat['color'],
                'amount' => 0.0,
                'children' => [],
            ];
        }
        $rootsByType[$type][$rootId]['amount'] += $amount;

        if ($cat['parent_id'] !== null) {
            $rootsByType[$type][$rootId]['children'][] = [
                'category_id' => $categoryId,
                'name' => $cat['name'],
                'icon' => $cat['icon'],
                'color' => $cat['color'],
                'amount' => $amount,
            ];
        }
    }

    $byAmountDesc = fn (array $a, array $b) => $b['amount'] <=> $a['amount'];

    $result = ['income' => [], 'expense' => []];
    foreach (['income', 'expense'] as $type) {
        $items = array_values($rootsByType[$type]);
        if ($uncategorized[$type] > 0.0) {
            $items[] = [
                'category_id' => null,
                'name' => 'Tanpa kategori',
                'icon' => null,
                'color' => null,
                'amount' => $uncategorized[$type],
                'children' => [],
            ];
        }
        foreach ($items as &$item) {
            usort($item['children'], $byAmountDesc);
        }
        unset($item);
        usort($items, $byAmountDesc);
        $result[$type] = $items;
    }

    return $result;
}

/**
 * Breakdown transaksi $spaceId di rentang [$from,$to] per akun: masuk
 * (income + transfer MASUK sbg tujuan) & keluar (expense + transfer KELUAR
 * sbg sumber). Beda dgn laporanCategoryBreakdown(): transfer DIHITUNG di
 * sini (bukan di-exclude) krn dari sudut pandang akun, transfer tetap
 * memindahkan saldo masuk/keluar walau bukan pendapatan/beban sesungguhnya.
 * SEMUA akun $spaceId ikut muncul (termasuk arsip & yg tanpa transaksi di
 * rentang ini, sbg baris 0) -- tabel "Per Akun" laporan lazimnya menunjukkan
 * semua akun, bukan cuma yg aktif di periode ini. Return
 * [{account_id,name,masuk,keluar,net}], urut id ASC.
 */
function laporanAccountBreakdown(int $spaceId, string $from, string $to): array
{
    $stmt = db()->prepare(
        "SELECT
            a.id account_id,
            a.name,
            COALESCE(out_agg.masuk, 0) + COALESCE(in_agg.masuk, 0) masuk,
            COALESCE(out_agg.keluar, 0) keluar
         FROM accounts a
         LEFT JOIN (
             SELECT account_id,
                    SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) masuk,
                    SUM(CASE WHEN type IN ('expense', 'transfer') THEN amount ELSE 0 END) keluar
             FROM transactions
             WHERE space_id = ? AND tx_date BETWEEN ? AND ?
             GROUP BY account_id
         ) out_agg ON out_agg.account_id = a.id
         LEFT JOIN (
             SELECT to_account_id, SUM(amount) masuk
             FROM transactions
             WHERE space_id = ? AND type = 'transfer' AND to_account_id IS NOT NULL
               AND tx_date BETWEEN ? AND ?
             GROUP BY to_account_id
         ) in_agg ON in_agg.to_account_id = a.id
         WHERE a.space_id = ?
         ORDER BY a.id ASC"
    );
    $stmt->execute([$spaceId, $from, $to, $spaceId, $from, $to, $spaceId]);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $masuk = (float) $r['masuk'];
        $keluar = (float) $r['keluar'];
        $out[] = [
            'account_id' => (int) $r['account_id'],
            'name' => $r['name'],
            'masuk' => $masuk,
            'keluar' => $keluar,
            'net' => $masuk - $keluar,
        ];
    }
    return $out;
}

/**
 * Total income/expense/net $spaceId di rentang [$from,$to] (transfer tidak
 * dihitung, sama spt total_income/total_expense listTransactions()).
 */
function laporanTotals(int $spaceId, string $from, string $to): array
{
    $stmt = db()->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) income,
            COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) expense
         FROM transactions
         WHERE space_id = ? AND tx_date BETWEEN ? AND ?"
    );
    $stmt->execute([$spaceId, $from, $to]);
    $row = $stmt->fetch();

    $income = (float) $row['income'];
    $expense = (float) $row['expense'];

    return ['income' => $income, 'expense' => $expense, 'net' => $income - $expense];
}

/**
 * Laporan bulan $period ('YYYY-MM') $spaceId: per_kategori, per_akun, total.
 * Lihat laporanCategoryBreakdown()/laporanAccountBreakdown()/laporanTotals()
 * utk detail aturan tiap bagian.
 */
function laporanMonthly(int $spaceId, string $period): array
{
    laporanValidatePeriod($period);
    $from = $period . '-01';
    $to = date('Y-m-t', strtotime($from));

    return [
        'per_kategori' => laporanCategoryBreakdown($spaceId, $from, $to),
        'per_akun' => laporanAccountBreakdown($spaceId, $from, $to),
        'total' => laporanTotals($spaceId, $from, $to),
    ];
}

/**
 * Laporan tahun $year $spaceId: per_kategori, per_akun, total -- rentang
 * tanggal $year-01-01 s/d $year-12-31, hasilnya SAMA persis dgn menjumlah 12
 * laporanMonthly() bulan di tahun itu (data sumbernya sama, non-overlap per
 * bulan) tapi lewat satu query rentang drpd 12 query terpisah.
 */
function laporanYearly(int $spaceId, int $year): array
{
    laporanValidateYear($year);
    $from = sprintf('%04d-01-01', $year);
    $to = sprintf('%04d-12-31', $year);

    return [
        'per_kategori' => laporanCategoryBreakdown($spaceId, $from, $to),
        'per_akun' => laporanAccountBreakdown($spaceId, $from, $to),
        'total' => laporanTotals($spaceId, $from, $to),
    ];
}

/**
 * Laba-rugi $spaceId di $periodOrYear -- format 'YYYY-MM' (bulanan) ATAU
 * 'YYYY' (tahunan), dideteksi otomatis dari bentuk string (dokumentasi param
 * API: public/api/laporan.php ?a=pnl menerima SALAH SATU `period` atau
 * `year`, diteruskan apa adanya ke sini). HANYA utk ruang type='business' --
 * ruang personal tidak punya konsep laba/rugi usaha, ditolak apiErr 400
 * (dicek LEBIH DULU sblm parsing format, supaya ruang personal dgn param
 * ngawur tetap dapat pesan yg jelas soal type, bukan soal format). Struktur
 * return {income:[...item], expense:[...item], net} -- item sama persis
 * dgn laporanCategoryBreakdown() (sub->parent digulung + rincian anak),
 * net = total income - total expense periode itu (transfer tidak dihitung,
 * sama spt laporanTotals()).
 */
function laporanPnl(int $spaceId, string $periodOrYear): array
{
    if (laporanSpaceType($spaceId) !== 'business') {
        apiErr('Laba-rugi hanya tersedia untuk ruang usaha', 400);
    }

    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodOrYear)) {
        $from = $periodOrYear . '-01';
        $to = date('Y-m-t', strtotime($from));
    } elseif (preg_match('/^\d{4}$/', $periodOrYear)) {
        $year = (int) $periodOrYear;
        laporanValidateYear($year);
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
    } else {
        apiErr('Periode tidak valid (format YYYY-MM atau YYYY)');
    }

    $cat = laporanCategoryBreakdown($spaceId, $from, $to);
    $totals = laporanTotals($spaceId, $from, $to);

    return [
        'income' => $cat['income'],
        'expense' => $cat['expense'],
        'net' => $totals['net'],
    ];
}

// ==================== Export CSV (public/export.php) ====================
// Header CSV (Indonesia, delimiter ';'): tanggal;jenis;kategori;akun;jumlah;catatan

const LAPORAN_CSV_HEADER = ['tanggal', 'jenis', 'kategori', 'akun', 'jumlah', 'catatan'];
const LAPORAN_CSV_TYPE_LABELS = ['income' => 'Pemasukan', 'expense' => 'Pengeluaran', 'transfer' => 'Transfer'];

/**
 * Escape satu field CSV delimiter ';' (pola RFC 4180 dgn delimiter diganti):
 * field yg mengandung delimiter ';', tanda kutip '"', atau newline dibungkus
 * tanda kutip ganda, tanda kutip internal digandakan. Field "aman" (tanpa
 * karakter itu) dikembalikan apa adanya (tanpa kutip) -- CSV lebih ringkas &
 * gampang diperiksa manual.
 *
 * Mitigasi CSV/formula injection (OWASP): field yg DIAWALI =,+,-,@ (atau
 * tab/CR di posisi awal) dianggap FORMULA oleh Excel/Sheets saat file
 * dibuka -- kolom catatan/nama kategori/akun berasal dari input bebas teks
 * user (mis. catatan transaksi "=cmd|'/c calc'!A1" kalau sengaja/tidak
 * sengaja ditulis begitu), dieksport apa adanya, lalu dibuka lagi di Excel
 * bisa MENGEKSEKUSI formula itu. Dibubuhi apostrof di depan supaya field
 * dipaksa jadi teks polos, bukan formula -- dilakukan SEBELUM cek
 * delimiter/kutip di atas supaya apostrof yg ditambahkan ikut bikin field
 * "tidak aman lagi" kalau perlu dibungkus kutip juga.
 */
function csvEscapeField(string $v): string
{
    if (preg_match('/^[=+\-@\t\r]/', $v)) {
        $v = "'" . $v;
    }
    if (preg_match('/[;"\r\n]/', $v)) {
        return '"' . str_replace('"', '""', $v) . '"';
    }
    return $v;
}

/**
 * Satu baris CSV delimiter ';' + akhiri CRLF (standar CSV, aman dibuka Excel).
 */
function csvBuildRow(array $fields): string
{
    return implode(';', array_map('csvEscapeField', $fields)) . "\r\n";
}

/**
 * Ubah satu row transaksi (hasil txListForExport() -- sudah JOIN nama
 * akun/kategori/akun tujuan) jadi 6 field CSV export sesuai urutan
 * LAPORAN_CSV_HEADER. Transfer: kolom akun jadi "AkunSumber → AkunTujuan",
 * kolom kategori dikosongkan (transfer tidak punya kategori pendapatan/beban).
 * Jumlah dicetak angka polos 2 desimal titik (bukan format Rupiah "Rp x.xxx")
 * -- CSV dibuka spreadsheet, kolom numerik mentah lebih berguna drpd string
 * berformat (bisa langsung dijumlah/pivot).
 */
function txToCsvFields(array $row): array
{
    $isTransfer = $row['type'] === 'transfer';
    $akun = $isTransfer
        ? ($row['account_name'] . ' → ' . $row['to_account_name'])
        : $row['account_name'];
    $kategori = $isTransfer ? '' : (string) ($row['category_name'] ?? '');

    return [
        (string) $row['tx_date'],
        LAPORAN_CSV_TYPE_LABELS[$row['type']] ?? $row['type'],
        $kategori,
        $akun,
        sprintf('%.2f', (float) $row['amount']),
        (string) ($row['note'] ?? ''),
    ];
}

/**
 * Stream (echo langsung, bukan return string besar -- hemat memori utk
 * puluhan ribu baris) CSV export transaksi: BOM UTF-8 (biar Excel Windows
 * baca karakter non-ASCII, mis. nama kategori/catatan, dgn benar) + header +
 * satu baris per $rows (lewat txToCsvFields()) + kalau $truncated, satu baris
 * catatan penutup (kolom lain kosong) menjelaskan hasil dipotong $limit baris.
 * Dipanggil dari public/export.php SETELAH header() Content-Type/
 * Content-Disposition dikirim.
 */
function streamTransactionsCsv(array $rows, bool $truncated, int $limit): void
{
    echo "\xEF\xBB\xBF"; // BOM UTF-8
    echo csvBuildRow(LAPORAN_CSV_HEADER);

    foreach ($rows as $row) {
        echo csvBuildRow(txToCsvFields($row));
    }

    if ($truncated) {
        echo csvBuildRow([
            '', '', '', '', '',
            "CATATAN: hasil dipotong {$limit} baris terbaru -- persempit rentang tanggal/filter utk export lengkap",
        ]);
    }
}
