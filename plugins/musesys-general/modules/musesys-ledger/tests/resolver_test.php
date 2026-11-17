<?php
/**
 * Resolver unit tests — LEDGER_SPEC.md §10.1.
 * Run: php modules/musesys-ledger/tests/resolver_test.php
 */

define('MUSESYS_LEDGER_TESTING', true);
require __DIR__ . '/../includes/class-ledger-state-resolver.php';
require __DIR__ . '/lib.php';

const R = 'Ledger_State_Resolver';

/** ET wall-clock → UTC 'Y-m-d H:i:s' */
function et(string $s): string {
    return (new DateTimeImmutable($s, new DateTimeZone('America/New_York')))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
function now_et(string $s): DateTimeImmutable {
    return new DateTimeImmutable($s, new DateTimeZone('America/New_York'));
}
function row(array $o = []): array {
    return $o + [
        'order_status'        => 'processing',
        'trx'                 => '778812',
        'po_number'           => 'CH-123456',
        'is_drop_ship'        => 0,
        'qty_ordered'         => 5,
        'qty_shipped'         => 0,
        'logistics_status'    => 'backordered',
        'eta'                 => '2026-10-07',
        'confirmed_at'        => et('2026-09-28 10:00'),
        'confirmed_at_source' => 'observed',
    ];
}
function email(string $et): array { return ['action' => 'email_logged', 'created_at' => et($et)]; }
function call(string $et): array  { return ['action' => 'call_logged',  'created_at' => et($et)]; }
function note(string $et): array  { return ['action' => 'note',         'created_at' => et($et)]; }

// 2026-10-01 is a Thursday. 2026-10-02 Fri, 10-03 Sat, 10-05 Mon.

t('backordered, future ETA → ON_TRACK', function () {
    $r = Ledger_State_Resolver::resolve(row(), [], now_et('2026-10-01 09:00'));
    eq('ON_TRACK', $r['state']);
    eq(0, $r['actionable']);
});

t('ETA passed, no contact → NEEDS_ETA, due_at = ETA 23:59:59 ET', function () {
    $r = Ledger_State_Resolver::resolve(row(['eta' => '2026-09-29']), [], now_et('2026-10-01 09:00'));
    eq('NEEDS_ETA', $r['state']);
    eq('eta_passed', $r['reason']);
    eq(et('2026-09-29 23:59:59'), $r['due_at']);
    eq(1, $r['actionable']);
    eq(2, $r['overdue_bdays']); // Wed 9/30, Thu 10/1
});

t('backordered, no ETA → NEEDS_ETA immediately', function () {
    $r = Ledger_State_Resolver::resolve(row(['eta' => '', 'confirmed_at' => et('2026-10-01 08:00')]), [], now_et('2026-10-01 09:00'));
    eq('NEEDS_ETA', $r['state']);
    eq('no_eta', $r['reason']);
    eq(null, $r['due_at']);
});

t('in stock, confirmed today → ON_TRACK', function () {
    $r = Ledger_State_Resolver::resolve(row(['logistics_status' => 'in_stock', 'eta' => '', 'confirmed_at' => et('2026-10-01 08:00')]), [], now_et('2026-10-01 15:00'));
    eq('ON_TRACK', $r['state']);
    eq('ships_today', $r['reason']);
});

t('in stock, confirmed yesterday, unshipped → NEEDS_ETA', function () {
    $r = Ledger_State_Resolver::resolve(row(['logistics_status' => 'in_stock', 'eta' => '', 'confirmed_at' => et('2026-09-30 08:00')]), [], now_et('2026-10-01 09:00'));
    eq('NEEDS_ETA', $r['state']);
    eq('not_shipped_same_day', $r['reason']);
    eq(et('2026-09-30 23:59:59'), $r['due_at']);
});

t('empty logistics status is treated as in_stock', function () {
    $r = Ledger_State_Resolver::resolve(row(['logistics_status' => '', 'eta' => '', 'confirmed_at' => et('2026-09-30 08:00')]), [], now_et('2026-10-01 09:00'));
    eq('NEEDS_ETA', $r['state']);
});

t('email before expiry does not start the call clock', function () {
    $r = Ledger_State_Resolver::resolve(row(['eta' => '2026-09-29']), [email('2026-09-28 09:00')], now_et('2026-10-01 09:00'));
    eq('NEEDS_ETA', $r['state']);
    eq('eta_passed', $r['reason']);
});

t('email after expiry, < 1 business day → NEEDS_ETA', function () {
    $r = Ledger_State_Resolver::resolve(row(['eta' => '2026-09-29']), [email('2026-09-30 09:42')], now_et('2026-10-01 09:41'));
    eq('NEEDS_ETA', $r['state']);
    eq('emailed_awaiting_response', $r['reason']);
});

t('email after expiry, ≥ 1 business day → CALL_REQUIRED', function () {
    $r = Ledger_State_Resolver::resolve(row(['eta' => '2026-09-29']), [email('2026-09-30 09:42')], now_et('2026-10-01 09:42'));
    eq('CALL_REQUIRED', $r['state']);
    eq(1, $r['actionable']);
});

t('email Friday 15:00 → CALL_REQUIRED Monday 15:00, not Saturday', function () {
    $c = [email('2026-10-02 15:00')];
    $row = row(['eta' => '2026-10-01']);
    eq('NEEDS_ETA', Ledger_State_Resolver::resolve($row, $c, now_et('2026-10-03 16:00'))['state']);
    eq('NEEDS_ETA', Ledger_State_Resolver::resolve($row, $c, now_et('2026-10-05 14:59'))['state']);
    eq('CALL_REQUIRED', Ledger_State_Resolver::resolve($row, $c, now_et('2026-10-05 15:00'))['state']);
});

t('call after the email → NEEDS_ETA (called_awaiting_eta)', function () {
    $r = Ledger_State_Resolver::resolve(row(['eta' => '2026-09-29']), [email('2026-09-30 09:00'), call('2026-10-01 10:00')], now_et('2026-10-01 11:00'));
    eq('NEEDS_ETA', $r['state']);
    eq('called_awaiting_eta', $r['reason']);
});

t('call before the email → still CALL_REQUIRED', function () {
    $r = Ledger_State_Resolver::resolve(row(['eta' => '2026-09-25']), [call('2026-09-28 10:00'), email('2026-09-29 09:00')], now_et('2026-10-01 11:00'));
    eq('CALL_REQUIRED', $r['state']);
});

t('new email after a call starts a new cycle', function () {
    $c = [email('2026-09-28 09:00'), call('2026-09-29 09:00'), email('2026-09-29 10:00')];
    eq('CALL_REQUIRED', Ledger_State_Resolver::resolve(row(['eta' => '2026-09-25']), $c, now_et('2026-10-01 11:00'))['state']);
});

t('new future ETA while CALL_REQUIRED → ON_TRACK', function () {
    $c = [email('2026-09-30 09:00')];
    eq('CALL_REQUIRED', Ledger_State_Resolver::resolve(row(['eta' => '2026-09-29']), $c, now_et('2026-10-01 11:00'))['state']);
    eq('ON_TRACK', Ledger_State_Resolver::resolve(row(['eta' => '2026-10-08']), $c, now_et('2026-10-01 11:00'))['state']);
});

t('fully shipped on an open order → SHIPPED_NOT_CLOSED', function () {
    $r = Ledger_State_Resolver::resolve(row(['qty_shipped' => 5]), [], now_et('2026-10-01 09:00'));
    eq('SHIPPED_NOT_CLOSED', $r['state']);
    eq('order_open', $r['reason']);
    eq(1, $r['actionable']);
    $r = Ledger_State_Resolver::resolve(row(['qty_shipped' => 5, 'is_drop_ship' => 1, 'po_number' => '']), [], now_et('2026-10-01 09:00'));
    eq('missing_po', $r['reason']);
});

t('partial (2/5) with ETA passed → NEEDS_ETA, actionable', function () {
    $r = Ledger_State_Resolver::resolve(row(['qty_shipped' => 2, 'eta' => '2026-09-29']), [], now_et('2026-10-01 09:00'));
    eq('NEEDS_ETA', $r['state']);
    eq(1, $r['actionable']);
});

t('TRX empty → MISSING_TRX regardless of other inputs', function () {
    foreach ([row(['trx' => '']), row(['trx' => '  ', 'qty_shipped' => 5]), row(['trx' => '', 'logistics_status' => 'obsolete'])] as $rw) {
        eq('MISSING_TRX', Ledger_State_Resolver::resolve($rw, [email('2026-09-29 09:00')], now_et('2026-10-01 09:00'))['state']);
    }
});

t('replaced / obsolete → EXCEPTION', function () {
    eq('EXCEPTION', Ledger_State_Resolver::resolve(row(['logistics_status' => 'replaced']), [], now_et('2026-10-01'))['state']);
    eq('EXCEPTION', Ledger_State_Resolver::resolve(row(['logistics_status' => 'obsolete']), [], now_et('2026-10-01'))['state']);
});

t('TRX 99999 → CLOSED', function () {
    eq('CLOSED', Ledger_State_Resolver::resolve(row(['trx' => '99999']), [], now_et('2026-10-01'))['state']);
});

t('non-open order status → CLOSED', function () {
    foreach (['completed', 'cancelled', 'refunded', 'line_removed'] as $s) {
        $r = Ledger_State_Resolver::resolve(row(['order_status' => $s]), [], now_et('2026-10-01'));
        eq('CLOSED', $r['state']);
        eq(0, $r['actionable']);
    }
});

t('in stock with future ETA → instock_future_date, due_at from ETA', function () {
    $r = Ledger_State_Resolver::resolve(row(['logistics_status' => 'in_stock', 'eta' => '2026-10-08', 'confirmed_at' => et('2026-09-20 10:00')]), [], now_et('2026-10-01 09:00'));
    eq('ON_TRACK', $r['state']);
    eq(['instock_future_date'], $r['data_flags']);
    eq(et('2026-10-08 23:59:59'), $r['due_at']);
});

t('notes never change the output', function () {
    $now  = now_et('2026-10-01 11:00');
    $base = [email('2026-09-30 09:00')];
    $with = array_merge($base, [note('2026-09-30 12:00'), note('2026-10-01 10:00')]);
    foreach ([row(['eta' => '2026-09-29']), row(), row(['eta' => ''])] as $rw) {
        eq(Ledger_State_Resolver::resolve($rw, $base, $now), Ledger_State_Resolver::resolve($rw, $with, $now));
    }
});

t('backfilled confirmed_at → confirmed_at_approx flag when due_at depends on it', function () {
    $r = Ledger_State_Resolver::resolve(row(['logistics_status' => 'in_stock', 'eta' => '', 'confirmed_at_source' => 'backfill_approx', 'confirmed_at' => et('2026-09-01 10:00')]), [], now_et('2026-10-01'));
    eq(true, $r['confirmed_at_approx']);
    $r = Ledger_State_Resolver::resolve(row(['eta' => '2026-09-29', 'confirmed_at_source' => 'backfill_approx']), [], now_et('2026-10-01'));
    eq(false, $r['confirmed_at_approx']); // backordered with ETA does not depend on it
});

t('add_bdays / bdays_between', function () {
    eq('2026-10-05 00:00', Ledger_State_Resolver::add_bdays(now_et('2026-10-03 11:00'), 0)->format('Y-m-d H:i'));
    eq('2026-10-06 00:00', Ledger_State_Resolver::add_bdays(now_et('2026-10-04 11:00'), 1)->format('Y-m-d H:i'));
    eq('2026-10-12 09:00', Ledger_State_Resolver::add_bdays(now_et('2026-10-05 09:00'), 5)->format('Y-m-d H:i'));
    eq(1, Ledger_State_Resolver::bdays_between(now_et('2026-10-02 23:59'), now_et('2026-10-05 08:00')));
    eq(0, Ledger_State_Resolver::bdays_between(now_et('2026-10-02 23:59'), now_et('2026-10-04 08:00')));
});

done();
