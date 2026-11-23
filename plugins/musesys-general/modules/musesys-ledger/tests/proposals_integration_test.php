<?php
/**
 * SQL-level test of proposed updates (LEDGER_SPEC.md §15): the real Ledger_Schema / Ledger_Proposals /
 * Ledger_Projector / Ledger_Events code against MySQL, with stubbed WC_Order objects and a stand-in
 * for the ship-lines endpoint.
 *
 * NOT a substitute for staging: the REST routes, the AJAX handlers, the in-process ship-lines call
 * and the screen are not exercised here.
 *
 * Run: MUSESYS_LEDGER_DB="host:port:user:pass:dbname" php modules/musesys-ledger/tests/proposals_integration_test.php
 * The database is wiped of ledger tables (prefix mlt_) at start.
 */

require __DIR__ . '/harness.php';

require __DIR__ . '/../includes/class-ledger-state-resolver.php';
require __DIR__ . '/../includes/class-ledger-schema.php';
require __DIR__ . '/../includes/class-ledger-events.php';
require __DIR__ . '/../includes/class-ledger-projector.php';
require __DIR__ . '/../includes/class-ledger-scheduler.php';
require __DIR__ . '/../includes/class-ledger-proposals.php';

$m->query('DROP TABLE IF EXISTS mlt_musesys_ledger, mlt_musesys_ledger_events, mlt_musesys_ledger_proposals');
update_option(Ledger_Schema::OPT_INSTALLED, '2026-10-01 00:00:00');
Ledger_Schema::maybe_install();

/* ---------------- fixtures ---------------- */

/** Two lines: Gasket ×5 (item id*1000+101), Motor ×1 (id*1000+102). */
function order_fixture(int $id): WC_Order {
    $o = new WC_Order($id);
    $o->meta = [
        '_trx_order_number'      => '7788' . ($id % 100),
        '_purchase_order_number' => 'CH-1234' . ($id % 100),
        '_muse_logistics_status' => 'backordered',
        '_muse_logistics_date'   => '2026-09-25',
    ];
    $o->items = [$id * 1000 + 101 => new WC_Order_Item_Stub(7, 'Gasket', 5), $id * 1000 + 102 => new WC_Order_Item_Stub(8, 'Motor', 1)];
    $GLOBALS['__orders'][$id] = $o;
    ok(Ledger_Projector::project($id), "project($id)");
    return $o;
}
function proposal(int $order_id, string $ref, array $over = []): array {
    $p = Ledger_Proposals::normalize($over + [
        'source' => 'cairn', 'source_ref' => $ref, 'order_id' => $order_id, 'kind' => 'shipment',
        'carrier' => 'UPS', 'tracking_numbers' => ['1ZTEST' . strtoupper($ref)], 'summary' => 'Vendor shipment notice',
        'evidence' => ['vendor' => 'Test Vendor', 'po' => 'CH-123401'],
    ]);
    ok(is_array($p), is_string($p) ? $p : '');
    return $p;
}
function prop(int $id): array { return Ledger_Proposals::get($id); }
function n_events(?string $action = null): int {
    $w = $action ? " WHERE action = '$action'" : '';
    return (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM mlt_musesys_ledger_events' . $w);
}
function shipped(int $oid): array {
    $out = [];
    foreach ($GLOBALS['wpdb']->get_results("SELECT order_item_id, qty_shipped FROM mlt_musesys_ledger WHERE order_id = $oid ORDER BY order_item_id") as $r) {
        $out[(int) $r['order_item_id'] - $oid * 1000] = (int) $r['qty_shipped'];
    }
    return $out;
}
/** Stand-in for the ship-lines endpoint: appends the shipment to the order, as the real one does. */
function ship_ok(): callable {
    return function (int $oid, array $body) {
        $o     = $GLOBALS['__orders'][$oid];
        $ships = json_decode($o->meta['_muse_shipments'] ?? '[]', true) ?: [];
        $items = [];
        foreach ($body['lines'] as $ln) $items[$ln['line_item_id']] = $ln['ship_qty'];
        $ships[] = ['carrier' => $body['carrier'], 'other_carrier' => $body['other_carrier'], 'tracking_numbers' => $body['tracking_numbers'], 'items' => $items];
        $o->meta['_muse_shipments'] = json_encode($ships);
        $GLOBALS['__last_body'] = $body;
        return ['order_id' => $oid, 'status' => 'partially-shipped', 'shipped' => array_sum($items), 'total' => 6];
    };
}

/* ---------------- schema ---------------- */

echo "schema\n";

t('the proposals table is created with the schema bump and has the idempotency key', function () use ($wpdb) {
    eq('1.1.0', get_option(Ledger_Schema::OPT_SCHEMA));
    $idx = array_column($wpdb->get_results('SHOW INDEX FROM mlt_musesys_ledger_proposals'), 'Non_unique', 'Key_name');
    ok(isset($idx['source_ref']) && (int) $idx['source_ref'] === 0, 'unique (source, source_ref) missing');
    Ledger_Schema::maybe_install(); // second run is a no-op
    eq(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM mlt_musesys_ledger_proposals'));
});

/* ---------------- create ---------------- */

echo "\ncreate\n";

t('filing a proposal stores it as pending and writes one event; the order is untouched', function () {
    $o   = order_fixture(1);
    $res = Ledger_Proposals::create(proposal(1, 'a1'), 77);
    eq(true, $res['created']);
    eq('pending', $res['proposal']['status']);
    eq(77, (int) $res['proposal']['created_by']);
    eq(1, n_events('proposal_received'));
    ok(!isset($o->meta['_muse_shipments']), 'the order must not change when a proposal is filed');
    eq([101 => 0, 102 => 0], shipped(1));
    $GLOBALS['__p1'] = (int) $res['proposal']['id'];
});

t('filing the same source_ref again returns the stored proposal and writes nothing', function () {
    $before = n_events();
    $res    = Ledger_Proposals::create(proposal(1, 'a1', ['tracking_numbers' => ['DIFFERENT']]), 77);
    eq(false, $res['created']);
    eq($GLOBALS['__p1'], (int) $res['proposal']['id']);
    eq(['1ZTESTA1'], json_decode($res['proposal']['payload_json'], true)['tracking_numbers'], 'the stored proposal is not overwritten');
    eq($before, n_events());
    eq(1, Ledger_Proposals::pending_count());
});

t('unknown order, closed order and foreign line are refused', function () {
    eq(404, Ledger_Proposals::create(proposal(999, 'b1'), 77)['status']);
    $o = order_fixture(2);
    $o->status = 'completed';
    Ledger_Projector::project(2);
    eq(409, Ledger_Proposals::create(proposal(2, 'b2'), 77)['status']);
    eq(422, Ledger_Proposals::create(proposal(1, 'b3', ['lines' => [['order_item_id' => 2101, 'qty' => 1]]]), 77)['status']);
    eq(1, Ledger_Proposals::pending_count());
});

t('a proposal whose tracking is already on the order is filed closed, not for review', function () {
    $o = order_fixture(3);
    $o->meta['_muse_shipments'] = json_encode([['carrier' => 'UPS', 'tracking_numbers' => ['1z test c1'], 'items' => [3101 => 1]]]);
    $res = Ledger_Proposals::create(proposal(3, 'c1'), 77);
    eq(true, $res['created']);
    eq('superseded', $res['proposal']['status']);
    eq('Already recorded on the order.', $res['proposal']['status_note']);
});

/* ---------------- reject / withdraw ---------------- */

echo "\nreject and withdraw\n";

t('reject records who and why; the order is untouched; it cannot be decided twice', function () {
    order_fixture(4);
    $id = (int) Ledger_Proposals::create(proposal(4, 'd1'), 77)['proposal']['id'];
    eq(true, Ledger_Proposals::reject($id, 'Wrong order', 5));
    $p = prop($id);
    eq(['rejected', 'Wrong order', 5], [$p['status'], $p['status_note'], (int) $p['decided_by']]);
    ok($p['decided_at'] !== null);
    eq(false, Ledger_Proposals::reject($id, 'again', 6));
    $res = Ledger_Proposals::approve($id, [4101 => 1], true, 6, ship_ok());
    eq([false, 409], [$res['ok'], $res['status']]);
    eq([101 => 0, 102 => 0], shipped(4));
    eq(1, n_events('proposal_rejected'));
});

t('the filing system can withdraw a pending proposal, and only a pending one', function () {
    $id = (int) Ledger_Proposals::create(proposal(4, 'd2'), 77)['proposal']['id'];
    eq(true, Ledger_Proposals::withdraw($id, 'Vendor corrected the notice', 77));
    eq(['superseded', 'Vendor corrected the notice'], [prop($id)['status'], prop($id)['status_note']]);
    eq(false, Ledger_Proposals::withdraw($id, 'again', 77));
    eq(1, n_events('proposal_withdrawn'));
});

/* ---------------- approve ---------------- */

echo "\napprove\n";

t('approve runs the shipment mutation with the confirmed lines and records the outcome', function () {
    $o   = order_fixture(5);
    $id  = (int) Ledger_Proposals::create(proposal(5, 'e1'), 77)['proposal']['id'];
    $res = Ledger_Proposals::approve($id, [5101 => 2, 5102 => 0], true, 9, ship_ok());
    eq(true, $res['ok'], $res['message']);
    $b = $GLOBALS['__last_body'];
    eq([['line_item_id' => 5101, 'ship_qty' => 2]], $b['lines']);
    eq(['UPS', ['1ZTESTE1'], true, false], [$b['carrier'], $b['tracking_numbers'], $b['notify'], $b['closeout']]);
    $p = prop($id);
    eq(['applied', 9], [$p['status'], (int) $p['decided_by']]);
    eq(null, $p['status_note']);
    eq(true, json_decode($p['result_json'], true)['notified']);
    eq([101 => 2, 102 => 0], shipped(5), 'the ledger projection follows the order');
    eq(1, n_events('proposal_approved'));
    eq(1, (int) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM mlt_musesys_ledger_events WHERE order_id = 5 AND action = 'shipment_recorded'"));
});

t('quantities beyond what is left are refused before anything is claimed', function () {
    order_fixture(6);
    $id  = (int) Ledger_Proposals::create(proposal(6, 'f1'), 77)['proposal']['id'];
    $res = Ledger_Proposals::approve($id, [6101 => 6], true, 9, ship_ok());
    eq([false, 400], [$res['ok'], $res['status']]);
    $res = Ledger_Proposals::approve($id, [], true, 9, ship_ok());
    eq([false, 400], [$res['ok'], $res['status']]);
    eq('pending', prop($id)['status']);
    eq([101 => 0, 102 => 0], shipped(6));
    $GLOBALS['__p6'] = $id;
});

t('a failed mutation leaves the proposal waiting with the reason, and each failure is logged once', function () {
    $id   = $GLOBALS['__p6'];
    $fail = fn(int $oid, array $body) => 'Order not found';
    $res  = Ledger_Proposals::approve($id, [6101 => 1], false, 9, $fail);
    eq([false, 502], [$res['ok'], $res['status']]);
    $p = prop($id);
    eq(['pending', 0, null], [$p['status'], (int) $p['decided_by'], $p['decided_at']]);
    eq('Last attempt failed: Order not found', $p['status_note']);
    $res = Ledger_Proposals::approve($id, [6101 => 1], false, 9, function () { throw new RuntimeException('boom'); });
    eq(502, $res['status']);
    eq(2, n_events('proposal_failed'));
    eq([101 => 0, 102 => 0], shipped(6));
    // …and it can still be approved afterwards.
    $res = Ledger_Proposals::approve($id, [6101 => 1], false, 9, ship_ok());
    eq(true, $res['ok'], $res['message']);
    eq('applied', prop($id)['status']);
    eq(false, json_decode(prop($id)['result_json'], true)['notified']);
});

t('if the mutation saved the shipment and then failed, the proposal is applied with a warning', function () {
    order_fixture(7);
    $id   = (int) Ledger_Proposals::create(proposal(7, 'g1'), 77)['proposal']['id'];
    $save = ship_ok();
    $res  = Ledger_Proposals::approve($id, [7101 => 5, 7102 => 1], true, 9, function (int $oid, array $body) use ($save) {
        $save($oid, $body);
        throw new RuntimeException('TRX # is required to complete an order');
    });
    eq(true, $res['ok']);
    $p = prop($id);
    eq('applied', $p['status']);
    ok(str_contains($p['status_note'], 'TRX # is required'), 'warning kept: ' . $p['status_note']);
    eq([101 => 5, 102 => 1], shipped(7));
});

t('two approvals cannot both claim a proposal', function () {
    order_fixture(8);
    $id = (int) Ledger_Proposals::create(proposal(8, 'h1'), 77)['proposal']['id'];
    eq(true, Ledger_Proposals::transition($id, 'pending', 'applying', ['decided_by' => 9, 'decided_at' => gmdate('Y-m-d H:i:s')]));
    eq(false, Ledger_Proposals::transition($id, 'pending', 'applying', ['decided_by' => 10, 'decided_at' => gmdate('Y-m-d H:i:s')]));
    $res = Ledger_Proposals::approve($id, [8101 => 1], true, 10, ship_ok());
    eq([false, 409], [$res['ok'], $res['status']]);
    eq(false, Ledger_Proposals::reject($id, 'no', 10));
    eq(false, Ledger_Proposals::withdraw($id, 'no', 77));
    eq([101 => 0, 102 => 0], shipped(8));
    $GLOBALS['__p8'] = $id;
});

t('tracking a person typed in meanwhile closes the proposal instead of recording it twice', function () {
    $o  = order_fixture(9);
    $id = (int) Ledger_Proposals::create(proposal(9, 'i1'), 77)['proposal']['id'];
    $o->meta['_muse_shipments'] = json_encode([['carrier' => 'UPS', 'tracking_numbers' => ['1ZTESTI1'], 'items' => [9101 => 5]]]);
    $res = Ledger_Proposals::approve($id, [9101 => 1], true, 9, ship_ok());
    eq([false, 409], [$res['ok'], $res['status']]);
    eq('superseded', prop($id)['status']);
    eq(1, count(json_decode($o->meta['_muse_shipments'], true)), 'no second shipment');
});

/* ---------------- settle ---------------- */

echo "\nhousekeeping\n";

t('a claim that never finished is settled from the order: recorded → applied, not recorded → waiting again', function () use ($wpdb) {
    $id = $GLOBALS['__p8'];
    eq(0, Ledger_Proposals::settle(), 'a fresh claim is left alone');
    eq('applying', prop($id)['status']);
    eq(1, Ledger_Proposals::settle(time() + 3600));
    eq(['pending', 0], [prop($id)['status'], (int) prop($id)['decided_by']]);

    Ledger_Proposals::transition($id, 'pending', 'applying', ['decided_by' => 9, 'decided_at' => gmdate('Y-m-d H:i:s')]);
    $GLOBALS['__orders'][8]->meta['_muse_shipments'] = json_encode([['carrier' => 'UPS', 'tracking_numbers' => ['1ZTESTH1'], 'items' => [8101 => 1]]]);
    eq(1, Ledger_Proposals::settle(time() + 3600));
    eq(['applied', 9], [prop($id)['status'], (int) prop($id)['decided_by']]);
});

t('waiting proposals close themselves when the order closes or the tracking appears on it', function () {
    $o  = order_fixture(10);
    $a  = (int) Ledger_Proposals::create(proposal(10, 'j1'), 77)['proposal']['id'];
    $b  = (int) Ledger_Proposals::create(proposal(10, 'j2'), 77)['proposal']['id'];
    $o->meta['_muse_shipments'] = json_encode([['carrier' => 'UPS', 'tracking_numbers' => ['1ZTESTJ1'], 'items' => [10101 => 1]]]);
    eq(1, Ledger_Proposals::settle());
    eq(['superseded', 'pending'], [prop($a)['status'], prop($b)['status']]);
    $o->status = 'completed';
    Ledger_Projector::project(10);
    eq(1, Ledger_Proposals::settle());
    eq(['superseded', 'The order is no longer open.'], [prop($b)['status'], prop($b)['status_note']]);
});

t('replaying create and settle over unchanged data appends nothing', function () use ($wpdb) {
    $events = n_events();
    $sum    = sha1(json_encode($wpdb->get_results('SELECT id, status, status_note, decided_by, result_json FROM mlt_musesys_ledger_proposals ORDER BY id')));
    Ledger_Proposals::create(proposal(1, 'a1'), 77);
    Ledger_Proposals::create(proposal(5, 'e1'), 77);
    eq(0, Ledger_Proposals::settle());
    eq(0, Ledger_Proposals::settle());
    eq($events, n_events());
    eq($sum, sha1(json_encode($wpdb->get_results('SELECT id, status, status_note, decided_by, result_json FROM mlt_musesys_ledger_proposals ORDER BY id'))));
});

/* ---------------- read side ---------------- */

echo "\nreads\n";

t('query filters by status and source, and lists changes oldest-first for a poller', function () {
    $pending = Ledger_Proposals::query(['status' => 'pending']);
    eq(1, count($pending));
    eq('a1', $pending[0]['source_ref']);
    eq([], Ledger_Proposals::query(['status' => 'nonsense']));
    eq([], Ledger_Proposals::query(['source' => 'someone-else']));
    $all = Ledger_Proposals::query(['updated_since' => '2000-01-01 00:00:00', 'limit' => 500]);
    $ts  = array_column($all, 'updated_at');
    $sorted = $ts;
    sort($sorted);
    eq($sorted, $ts);
    eq(0, count(Ledger_Proposals::query(['updated_since' => '2999-01-01 00:00:00'])));
});

t('present() gives the filing system the decision without internal ids', function () {
    $p = Ledger_Proposals::present(prop($GLOBALS['__p6']));
    eq(['applied', 'cairn', 'f1', 6], [$p['status'], $p['source'], $p['source_ref'], $p['order_id']]);
    eq('UPS', $p['payload']['carrier']);
    eq('Test Vendor', $p['evidence']['vendor']);
    ok(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $p['decided_at']) === 1, 'ISO time');
    ok(!array_key_exists('decided_by', $p) && !array_key_exists('created_by', $p), 'no user ids');
});

done();
