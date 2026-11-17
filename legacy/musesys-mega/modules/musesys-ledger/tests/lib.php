<?php
/** Minimal assertion helpers for the CLI tests (no PHPUnit in this repo). */

$GLOBALS['__ledger_pass'] = 0;
$GLOBALS['__ledger_fail'] = 0;

function t(string $name, callable $fn): void {
    try {
        $fn();
        $GLOBALS['__ledger_pass']++;
        echo "  ok   $name\n";
    } catch (Throwable $e) {
        $GLOBALS['__ledger_fail']++;
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
    $p = $GLOBALS['__ledger_pass'];
    $f = $GLOBALS['__ledger_fail'];
    echo "\n$p passed, $f failed\n";
    exit($f > 0 ? 1 : 0);
}
