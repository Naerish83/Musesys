<?php
/** Minimal assertion helpers for the CLI tests (no PHPUnit in this repo). Same as musesys-ledger/tests/lib.php. */

$GLOBALS['__psp_pass'] = 0;
$GLOBALS['__psp_fail'] = 0;

function t(string $name, callable $fn): void {
    try {
        $fn();
        $GLOBALS['__psp_pass']++;
        echo "  ok   $name\n";
    } catch (Throwable $e) {
        $GLOBALS['__psp_fail']++;
        echo "  FAIL $name\n       " . $e->getMessage() . "\n";
    }
}

function eq($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new Exception(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function ok($cond, string $msg = 'assertion failed'): void {
    if (!$cond) throw new Exception($msg);
}

function done(): void {
    $p = $GLOBALS['__psp_pass'];
    $f = $GLOBALS['__psp_fail'];
    echo "\n$p passed, $f failed\n";
    exit($f > 0 ? 1 : 0);
}
