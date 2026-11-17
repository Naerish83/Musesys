<?php
/**
 * Morning digest tests (pure parts).
 * Run: php modules/musesys-ledger/tests/digest_test.php
 */

define('MUSESYS_LEDGER_TESTING', true);
require __DIR__ . '/../includes/class-ledger-state-resolver.php';
require __DIR__ . '/../includes/class-ledger-digest.php';
require __DIR__ . '/lib.php';

function et_at(string $s): DateTimeImmutable { return new DateTimeImmutable($s, new DateTimeZone('America/New_York')); }
function f(DateTimeImmutable $d): string { return $d->setTimezone(new DateTimeZone('America/New_York'))->format('D Y-m-d H:i T'); }

// 2026-10-01 is a Thursday.

t('before 07:00 on a weekday → same day 07:00', function () {
    eq('Thu 2026-10-01 07:00 EDT', f(Ledger_Digest::next_send_at(et_at('2026-10-01 05:30'))));
});

t('at/after 07:00 → next weekday', function () {
    eq('Fri 2026-10-02 07:00 EDT', f(Ledger_Digest::next_send_at(et_at('2026-10-01 07:00'))));
    eq('Mon 2026-10-05 07:00 EDT', f(Ledger_Digest::next_send_at(et_at('2026-10-02 07:00:01'))));
    eq('Mon 2026-10-05 07:00 EDT', f(Ledger_Digest::next_send_at(et_at('2026-10-03 06:00'))));
});

t('stays at 07:00 local across the DST change (Nov 1 2026)', function () {
    $fri = Ledger_Digest::next_send_at(et_at('2026-10-30 08:00'));
    eq('Mon 2026-11-02 07:00 EST', f($fri));
    eq(12, (int) $fri->setTimezone(new DateTimeZone('UTC'))->format('G'), '07:00 EST = 12:00 UTC');
    eq(11, (int) Ledger_Digest::next_send_at(et_at('2026-10-01 05:00'))->setTimezone(new DateTimeZone('UTC'))->format('G'), '07:00 EDT = 11:00 UTC');
});

t('previous business day: Thu → Wed; Mon → Fri', function () {
    [$a, $b] = Ledger_Digest::previous_business_day(et_at('2026-10-01 07:00'));
    eq('Wed 2026-09-30 00:00 EDT', f($a));
    eq('Thu 2026-10-01 00:00 EDT', f($b));
    [$a, $b] = Ledger_Digest::previous_business_day(et_at('2026-10-05 07:00'));
    eq('Fri 2026-10-02 00:00 EDT', f($a));
    eq('Sat 2026-10-03 00:00 EDT', f($b));
});

t('render: all six tiles, numbers, link (button + plain), escaped', function () {
    $s = ['NEEDS_ETA' => 84, 'CALL_REQUIRED' => 0, 'partial' => 2, 'SHIPPED_NOT_CLOSED' => 20, 'late2' => 67, 'actions_prev' => 31];
    $url = 'https://portal.example/wp-admin/admin.php?page=musesys-ledger&x="<y>';
    $h = Ledger_Digest::render($s, $url, 'Wed Sep 30', et_at('2026-10-01 07:00'));
    foreach (['Needs ETA', 'Call required', 'Partial', 'Shipped / not closed', '2+ bd late', 'Actions Wed Sep 30'] as $label) {
        ok(strpos($h, $label) !== false, "missing tile $label");
    }
    foreach (['>84<', '>0<', '>2<', '>20<', '>67<', '>31<'] as $n) ok(strpos($h, $n) !== false, "missing count $n");
    eq(2, substr_count($h, 'href="https://portal.example/wp-admin/admin.php?page=musesys-ledger&amp;x=&quot;&lt;y&gt;"'));
    ok(strpos($h, '"<y>') === false, 'url must be escaped');
    ok(strpos($h, 'Open the Ledger') !== false);
});

t('subject', function () {
    eq('Ledger — 84 need ETA, 0 calls required (Thu Oct 1)',
        Ledger_Digest::subject(['NEEDS_ETA' => 84, 'CALL_REQUIRED' => 0], et_at('2026-10-01 07:00')));
});

done();
