<?php
/**
 * Coming-due predicate tests — LEDGER_SPEC.md.
 * Pure unit tests verifying business-day arithmetic and coming-due predicates.
 * Run: php modules/musesys-ledger/tests/coming_due_test.php
 */

define('MUSESYS_LEDGER_TESTING', true);
define('ABSPATH', true);
if (!function_exists('add_action')) { function add_action() {} }
if (!function_exists('add_filter')) { function add_filter() {} }
if (!function_exists('wp_get_current_user')) { function wp_get_current_user() { return null; } }
if (!function_exists('current_user_can')) { function current_user_can($c) { return false; } }
require __DIR__ . '/../includes/class-ledger-access.php';
require __DIR__ . '/../includes/class-ledger-state-resolver.php';
require __DIR__ . '/../includes/class-ledger-ajax.php';
require __DIR__ . '/lib.php';

/** ET wall-clock → UTC 'Y-m-d H:i:s' */
function et(string $s): string {
    return (new DateTimeImmutable($s, new DateTimeZone('America/New_York')))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function now_et(string $s): DateTimeImmutable {
    return new DateTimeImmutable($s, new DateTimeZone('America/New_York'));
}

// 2026-10-01 is Thursday.
// 2026-10-02 Fri, 10-03 Sat, 10-04 Sun, 10-05 Mon, 10-06 Tue, 10-07 Wed.

t('Thursday covers Thu, Fri and Mon', function () {
    $now = now_et('2026-10-01 10:00');
    $cutoff = Ledger_State_Resolver::coming_due_cutoff($now);
    eq(et('2026-10-05 23:59:59'), $cutoff->format('Y-m-d H:i:s'));

    // Thu, Fri, and Mon rows are included
    ok(Ledger_State_Resolver::is_coming_due(['state' => 'ON_TRACK', 'due_at' => et('2026-10-01 23:59:59')], $now), 'Thu included');
    ok(Ledger_State_Resolver::is_coming_due(['state' => 'ON_TRACK', 'due_at' => et('2026-10-02 23:59:59')], $now), 'Fri included');
    ok(Ledger_State_Resolver::is_coming_due(['state' => 'ON_TRACK', 'due_at' => et('2026-10-05 23:59:59')], $now), 'Mon included');
});

t('Friday 4pm covers through Tue EOD', function () {
    $now = now_et('2026-10-02 16:00');
    $cutoff = Ledger_State_Resolver::coming_due_cutoff($now);
    eq(et('2026-10-06 23:59:59'), $cutoff->format('Y-m-d H:i:s'));

    // Fri, Mon, and Tue rows are included
    ok(Ledger_State_Resolver::is_coming_due(['state' => 'ON_TRACK', 'due_at' => et('2026-10-02 23:59:59')], $now), 'Fri included');
    ok(Ledger_State_Resolver::is_coming_due(['state' => 'ON_TRACK', 'due_at' => et('2026-10-05 23:59:59')], $now), 'Mon included');
    ok(Ledger_State_Resolver::is_coming_due(['state' => 'ON_TRACK', 'due_at' => et('2026-10-06 23:59:59')], $now), 'Tue included');
});

t('Saturday covers through Tue EOD', function () {
    $now = now_et('2026-10-03 12:00');
    $cutoff = Ledger_State_Resolver::coming_due_cutoff($now);
    eq(et('2026-10-06 23:59:59'), $cutoff->format('Y-m-d H:i:s'));

    // Mon and Tue rows are included
    ok(Ledger_State_Resolver::is_coming_due(['state' => 'ON_TRACK', 'due_at' => et('2026-10-05 23:59:59')], $now), 'Mon included');
    ok(Ledger_State_Resolver::is_coming_due(['state' => 'ON_TRACK', 'due_at' => et('2026-10-06 23:59:59')], $now), 'Tue included');
});

t('a row due today is included', function () {
    $now = now_et('2026-10-01 09:30');
    ok(Ledger_State_Resolver::is_coming_due(['state' => 'ON_TRACK', 'due_at' => et('2026-10-01 23:59:59')], $now));
});

t('a row due on the 3rd business day is excluded', function () {
    // Thursday 2026-10-01: 1st=Fri, 2nd=Mon, 3rd=Tue 2026-10-06
    $now = now_et('2026-10-01 10:00');
    ok(!Ledger_State_Resolver::is_coming_due(['state' => 'ON_TRACK', 'due_at' => et('2026-10-06 23:59:59')], $now), '3rd bday from Thu (Tue) excluded');

    // Friday 2026-10-02: 1st=Mon, 2nd=Tue, 3rd=Wed 2026-10-07
    $fri = now_et('2026-10-02 16:00');
    ok(!Ledger_State_Resolver::is_coming_due(['state' => 'ON_TRACK', 'due_at' => et('2026-10-07 23:59:59')], $fri), '3rd bday from Fri (Wed) excluded');

    // Saturday 2026-10-03: 1st=Mon, 2nd=Tue, 3rd=Wed 2026-10-07
    $sat = now_et('2026-10-03 12:00');
    ok(!Ledger_State_Resolver::is_coming_due(['state' => 'ON_TRACK', 'due_at' => et('2026-10-07 23:59:59')], $sat), '3rd bday from Sat (Wed) excluded');
});

t('non-ON_TRACK rows are excluded', function () {
    $now = now_et('2026-10-01 10:00');
    $due = et('2026-10-02 23:59:59'); // tomorrow (well within cutoff)

    $non_on_track = [
        'NEEDS_ETA',
        'CALL_REQUIRED',
        'SHIPPED_NOT_CLOSED',
        'MISSING_TRX',
        'EXCEPTION',
        'CLOSED',
        '',
    ];

    foreach ($non_on_track as $state) {
        ok(!Ledger_State_Resolver::is_coming_due(['state' => $state, 'due_at' => $due], $now), "state $state must be excluded");
    }
});

t('a null due_at is excluded', function () {
    $now = now_et('2026-10-01 10:00');
    ok(!Ledger_State_Resolver::is_coming_due(['state' => 'ON_TRACK', 'due_at' => null], $now), 'null due_at excluded');
    ok(!Ledger_State_Resolver::is_coming_due(['state' => 'ON_TRACK', 'due_at' => ''], $now), 'empty string due_at excluded');
    ok(!Ledger_State_Resolver::is_coming_due(['state' => 'ON_TRACK', 'due_at' => '0000-00-00 00:00:00'], $now), 'zero datetime due_at excluded');
    ok(!Ledger_State_Resolver::is_coming_due(['state' => 'ON_TRACK'], $now), 'missing due_at excluded');
});

t('summarize() excludes coming-due rows and strip counts do not change', function () {
    $rows = [
        ['state' => 'NEEDS_ETA', 'qty_shipped' => 0, 'qty_ordered' => 2, 'actionable' => 1, 'overdue_bdays' => 3],
        ['state' => 'CALL_REQUIRED', 'qty_shipped' => 0, 'qty_ordered' => 1, 'actionable' => 1, 'overdue_bdays' => 1],
        ['state' => 'SHIPPED_NOT_CLOSED', 'qty_shipped' => 5, 'qty_ordered' => 5, 'actionable' => 1, 'overdue_bdays' => 0],
    ];
    $s = Ledger_Ajax::summarize($rows);
    eq(1, $s['NEEDS_ETA']);
    eq(1, $s['CALL_REQUIRED']);
    eq(1, $s['SHIPPED_NOT_CLOSED']);
    eq(0, $s['ON_TRACK']);
    eq(1, $s['late2']);
    ok(!isset($s['coming_due']), 'summarize must not have coming_due key');
});

t('present_row marks coming_due correctly', function () {
    $now = now_et('2026-10-01 10:00');
    $cd_row = [
        'id' => 1, 'order_id' => 100, 'order_item_id' => 101, 'trx' => '12345', 'po_number' => 'PO1',
        'is_drop_ship' => 0, 'store' => '001', 'part_label' => 'Widget', 'qty_ordered' => 2,
        'qty_shipped' => 0, 'tracking_count' => 0, 'logistics_status' => 'backordered',
        'eta' => '2026-10-02', 'confirmed_at' => null, 'confirmed_at_source' => '',
        'state' => 'ON_TRACK', 'state_reason' => 'eta_future', 'due_at' => et('2026-10-02 23:59:59'),
        'overdue_bdays' => 0, 'data_flags' => '', 'last_email_at' => null, 'last_call_at' => null,
        'last_action_at' => null, 'actionable' => 0,
    ];

    $pres = Ledger_Ajax::present_row($cd_row, $now);
    eq(1, $pres['coming_due'], 'ON_TRACK within 2 bdays must be marked coming_due=1');

    $far_row = $cd_row;
    $far_row['due_at'] = et('2026-10-09 23:59:59');
    $pres_far = Ledger_Ajax::present_row($far_row, $now);
    eq(0, $pres_far['coming_due'], 'ON_TRACK beyond 2 bdays must be marked coming_due=0');

    $work_row = $cd_row;
    $work_row['state'] = 'NEEDS_ETA';
    $pres_work = Ledger_Ajax::present_row($work_row, $now);
    eq(0, $pres_work['coming_due'], 'NEEDS_ETA must be marked coming_due=0');
});

done();
