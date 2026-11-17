<?php
/**
 * Audit unit tests — pure part of Ledger_Audit.
 * Run: php modules/musesys-ledger/tests/audit_test.php
 */

define('MUSESYS_LEDGER_TESTING', true);
require __DIR__ . '/../includes/class-ledger-audit.php';
require __DIR__ . '/lib.php';

function ev(int $id, int $oid, string $at, ?string $prev, ?string $new, array $extra = [], int $uid = 7): array {
    return ['id' => $id, 'order_id' => $oid, 'user_id' => $uid, 'action' => 'eta_set', 'created_at' => $at,
            'payload' => ['previous_eta' => $prev, 'new_eta' => $new] + $extra];
}
function contact(int $oid, string $at, string $a = 'email_logged'): array {
    return ['order_id' => $oid, 'action' => $a, 'created_at' => $at];
}
function st(int $oid, string $at, string $from, string $to): array {
    return ['order_id' => $oid, 'created_at' => $at, 'payload' => ['from' => $from, 'to' => $to]];
}

t('validate_eta_basis', function () {
    eq('Select where this ETA came from.', Ledger_Audit::validate_eta_basis('', '', ''));
    eq('Select where this ETA came from.', Ledger_Audit::validate_eta_basis('gut_feeling', '', ''));
    ok(Ledger_Audit::validate_eta_basis('vendor_call', '', '') !== null, 'call needs a contact');
    eq(null, Ledger_Audit::validate_eta_basis('vendor_call', 'Dana @ Hobart', ''));
    ok(Ledger_Audit::validate_eta_basis('estimate', '', 'idk') !== null, 'estimate needs a real note');
    eq(null, Ledger_Audit::validate_eta_basis('estimate', '', 'Vendor lead time is 3 weeks per their site'));
    eq(null, Ledger_Audit::validate_eta_basis('vendor_email', '', ''));
});

t('slip_days', function () {
    eq(7, Ledger_Audit::slip_days('2026-10-01', '2026-10-08'));
    eq(-2, Ledger_Audit::slip_days('2026-10-08', '2026-10-06'));
    eq(null, Ledger_Audit::slip_days(null, '2026-10-08'));
    eq(31, Ledger_Audit::slip_days('2026-10-31', '2026-12-01'));
});

t('basis: declared source beats everything; contact within 24h counts; older contact does not', function () {
    $e = [
        ev(1, 100, '2026-10-01 15:00:00', '2026-09-30', '2026-10-10', ['eta_source' => 'vendor_email']),
        ev(2, 200, '2026-10-01 15:00:00', '2026-09-30', '2026-10-10'),
        ev(3, 300, '2026-10-01 15:00:00', '2026-09-30', '2026-10-10'),
        ev(4, 400, '2026-10-01 15:00:00', '2026-09-30', '2026-10-10', ['eta_source' => 'estimate']),
    ];
    $c = [contact(200, '2026-10-01 09:00:00', 'call_logged'), contact(300, '2026-09-30 14:59:00'), contact(400, '2026-10-01 16:00:00')];
    $r = Ledger_Audit::classify($e, $c, []);
    eq('declared', $r[0]['basis']);
    eq('contact_24h', $r[1]['basis']);
    eq('none', $r[2]['basis'], 'contact 24h+1m before');
    eq('none', $r[3]['basis'], 'estimate with contact AFTER the change');
    eq(true, $r[3]['estimate']);
});

t('burst: 5 changes in 10 minutes by one user flags the 5th onward', function () {
    $e = [];
    for ($i = 0; $i < 6; $i++) $e[] = ev($i + 1, 100 + $i, sprintf('2026-10-01 15:%02d:00', $i * 2), '2026-09-30', '2026-10-14');
    $r = Ledger_Audit::classify($e, [], []);
    eq([false, false, false, false, true, true], array_column($r, 'burst'));
});

t('burst is per user', function () {
    $e = [];
    for ($i = 0; $i < 6; $i++) $e[] = ev($i + 1, 100 + $i, sprintf('2026-10-01 15:%02d:00', $i), '2026-09-30', '2026-10-14', [], $i % 2 ? 7 : 8);
    eq([false, false, false, false, false, false], array_column(Ledger_Audit::classify($e, [], []), 'burst'));
});

t('cleared CALL_REQUIRED / NEEDS_ETA → ON_TRACK within 2 minutes of the change', function () {
    $e = [ev(1, 100, '2026-10-01 15:00:00', '2026-09-30', '2026-10-14'), ev(2, 200, '2026-10-01 15:00:00', '2026-09-30', '2026-10-14')];
    $s = [st(100, '2026-10-01 15:00:01', 'CALL_REQUIRED', 'ON_TRACK'), st(200, '2026-10-01 15:05:00', 'NEEDS_ETA', 'ON_TRACK')];
    $r = Ledger_Audit::classify($e, [], $s);
    eq(true, $r[0]['cleared_call_required']);
    eq(false, $r[1]['cleared_needs_eta'], 'outside the window');
});

t('aggregate', function () {
    $e = [
        ev(1, 100, '2026-10-01 15:00:00', '2026-09-30', '2026-10-07'),
        ev(2, 100, '2026-10-08 15:00:00', '2026-10-07', '2026-10-21'),
        ev(3, 200, '2026-10-08 15:01:00', '2026-10-01', '2026-10-15', ['eta_source' => 'vendor_call', 'eta_contact' => 'Dana']),
        ev(4, 300, '2026-10-08 15:02:00', '2026-10-20', '2026-10-15'), // pulled in, not a push
    ];
    $s = [st(100, '2026-10-08 15:00:00', 'CALL_REQUIRED', 'ON_TRACK')];
    $u = Ledger_Audit::aggregate(Ledger_Audit::classify($e, [], $s))[0];
    eq(4, $u['eta_changes']);
    eq(3, $u['orders_touched']);
    eq(3, $u['pushes']);
    eq(2, $u['unevidenced_pushes']);
    eq(1, $u['repeat_pushed']);
    eq(14, $u['slip_max']);
    eq(round((7 + 14 + 14) / 3, 1), $u['avg_slip']);
    eq(1, $u['cleared_call_required']);
});

t('chronic: 3+ pushes or 14+ days past the first ETA; first ETA comes from the earliest event', function () {
    $open = [
        100 => ['trx' => 'A', 'eta' => '2026-10-28', 'state' => 'ON_TRACK'],
        200 => ['trx' => 'B', 'eta' => '2026-10-20', 'state' => 'ON_TRACK'],
        300 => ['trx' => 'C', 'eta' => '2026-10-16', 'state' => 'NEEDS_ETA'],
    ];
    $eta = [
        ev(1, 100, '2026-09-20 10:00:00', null, '2026-09-25'),             // backfill-style first sight
        ev(2, 100, '2026-09-26 10:00:00', '2026-09-25', '2026-10-28'),
        ev(3, 200, '2026-10-01 10:00:00', null, '2026-10-05'),
        ev(4, 200, '2026-10-06 10:00:00', '2026-10-05', '2026-10-09'),
        ev(5, 200, '2026-10-10 10:00:00', '2026-10-09', '2026-10-13'),
        ev(6, 200, '2026-10-13 10:00:00', '2026-10-13', '2026-10-20'),
        ev(7, 300, '2026-10-10 10:00:00', null, '2026-10-12'),
    ];
    $r = Ledger_Audit::chronic($open, $eta, '2026-10-14');
    eq([100, 200], array_column($r, 'order_id'));
    eq('2026-09-25', $r[0]['first_eta']);
    eq(19, $r[0]['days_past_first']);
    eq(3, $r[1]['pushes']);
});

done();
