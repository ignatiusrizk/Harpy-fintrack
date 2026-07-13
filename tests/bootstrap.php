<?php
// Bootstrap test CLI: require core, sediakan assertSame sederhana.

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';

$GLOBALS['__test_exit_code'] = 0;

function assertSame($expected, $actual, string $label): void
{
    if ($expected === $actual) {
        echo "\xE2\x9C\x93 {$label}\n"; // ✓
        return;
    }
    echo "\xE2\x9C\x97 {$label} — expected: " . var_export($expected, true)
        . ', got: ' . var_export($actual, true) . "\n"; // ✗
    $GLOBALS['__test_exit_code'] = 1;
}

register_shutdown_function(static function (): void {
    exit($GLOBALS['__test_exit_code']);
});
