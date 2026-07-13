<?php
// Test dashboard: deret 6 bulan arus kas (dbSummaryCashflowMonths) -- pure
// function, tanpa DB. Regression utk bug overflow day-of-month: versi lama
// pakai strtotime("-N months") relatif HARI INI (termasuk tanggalnya), jadi
// dari tanggal 29-31 mundur bulan bisa overflow ke bulan berikutnya (31 Agu
// -2 bulan = "31 Jun" -> 1 Jul): period dobel + bulan hilang, transaksi
// bulan yg hilang terbuang diam-diam dari chart. Fix: anchor tanggal 1.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../core/dashboard.php';

// -- Kasus normal: pertengahan bulan --
assertSame(
    ['2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07'],
    dbSummaryCashflowMonths('2026-07-12'),
    'cashflow months: 12 Jul 2026 -> Feb..Jul urut & lengkap'
);

// -- Regression inti: 31 Agu (bug lama: Aug, Jul, Jul, May, May, Mar --
//    Jun & Apr hilang krn overflow "31 Jun"/"31 Apr" ke bulan berikutnya) --
$aug31 = dbSummaryCashflowMonths('2026-08-31');
assertSame(
    ['2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08'],
    $aug31,
    'cashflow months: 31 Agu 2026 -> Mar..Agu lengkap (tanpa overflow)'
);
assertSame(6, count(array_unique($aug31)), 'cashflow months: 31 Agu 2026 -> 6 period unik');

// -- Tanggal 30 & 29 (30 Mar mundur lewat Feb yg cuma 28 hari) --
assertSame(
    ['2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03'],
    dbSummaryCashflowMonths('2026-03-30'),
    'cashflow months: 30 Mar 2026 -> Okt 2025..Mar 2026 (lewat Feb pendek, tanpa bolong)'
);

// -- 31 Des: lintas tahun mundur --
assertSame(
    ['2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12'],
    dbSummaryCashflowMonths('2026-12-31'),
    'cashflow months: 31 Des 2026 -> Jul..Des'
);

// -- 31 Jan: deret mundur lintas tahun ke Agu tahun sebelumnya --
assertSame(
    ['2025-08', '2025-09', '2025-10', '2025-11', '2025-12', '2026-01'],
    dbSummaryCashflowMonths('2026-01-31'),
    'cashflow months: 31 Jan 2026 -> Agu 2025..Jan 2026 lintas tahun'
);

// -- 29 Feb tahun kabisat --
assertSame(
    ['2027-09', '2027-10', '2027-11', '2027-12', '2028-01', '2028-02'],
    dbSummaryCashflowMonths('2028-02-29'),
    'cashflow months: 29 Feb 2028 (kabisat) -> Sep 2027..Feb 2028'
);
