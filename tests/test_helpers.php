<?php
require_once __DIR__ . '/bootstrap.php';

assertSame('Rp 0', rupiah(0), 'rupiah(0)');
assertSame('Rp 1.234.567', rupiah(1234567), 'rupiah(1234567)');
assertSame('-Rp 5.000', rupiah(-5000), 'rupiah(-5000)');
assertSame('Rp 2.501', rupiah('2500.75'), 'rupiah("2500.75") dibulatkan');
