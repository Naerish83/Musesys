<?php
/**
 * SQL-level integration test — runs the real Ledger_Schema / Ledger_Projector / Ledger_Events code
 * against a MySQL database through a small mysqli-backed $wpdb stand-in, with stubbed WC_Order
 * objects. Covers the reconcile-idempotency requirement of LEDGER_SPEC.md §10.2 at the DB level.
 *
 * NOT a substitute for the staging checks (real WooCommerce, real Action Scheduler, concurrency).
 *
 * Run: MUSESYS_LEDGER_DB="host:port:user:pass:dbname" php modules/musesys-ledger/tests/integration_test.php
 * The database is wiped of ledger tables (prefix mlt_) at start.
 */

require __DIR__ . '/harness.php';

require __DIR__ . '/../includes/class-ledger-state-resolver.php';
require __DIR__ . '/../includes/class-ledger-schema.php';
require __DIR__ . '/../includes/class-ledger-events.php';
require __DIR__ . '/../includes/class-ledger-projector.php';
require __DIR__ . '/../includes/class-ledger-scheduler.php';
require __DIR__ . '/../includes/class-ledger-ajax.php';

$m->query('DROP TABLE IF EXISTS mlt_musesys_ledger, mlt_musesys_ledger_events, mlt_musesys_ledger_proposals');
update_option(Ledger_Schema::OPT_INSTALLED, '2026-10-01 00:00:00');
Ledger_Schema::maybe_install();

/* ---------------- fixtures ---------------- */

function order_fixture(int $id): WC_Order {
    $o = new WC_Order($id);
    $o->meta = [
        '_trx_order_number'      => '778812',
        '_purchase_order_number' => 'CH-123456',
        '_muse_logistics_status' => 'backordered',
        '_muse_logistics_date'   => '2026-09-25',   // past → NEEDS_ETA
        '_muse_store_global'     => '029',
    ];
    // Woo line-item IDs are globally unique (the ledger's order_item key relies on it): id*1000 + 101/102.
    $o->items = [$id * 1000 + 101 => new WC_Order_Item_Stub(7, 'Gasket', 5), $id * 1000 + 102 => new WC_Order_Item_Stub(8, 'Motor', 1)];
    return $GLOBALS['__orders'][$id] = $o;
}
function events(): array { return $GLOBALS['wpdb']->get_results('SELECT * FROM mlt_musesys_ledger_events ORDER BY id'); }
function n_events(): int { return (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM mlt_musesys_ledger_events'); }
function rows(int $oid): array {
    $out = [];
    foreach ($GLOBALS['wpdb']->get_results("SELECT * FROM mlt_musesys_ledger WHERE order_id = $oid ORDER BY order_item_id") as $r) $out[(int) $r['order_item_id'] - $oid * 1000] = $r; // keyed 101/102
    return $out;
}
/** Checksum of all ledger rows excluding *_at bookkeeping columns (§10.2 step 1). */
function checksum(): string {
    $rows = $GLOBALS['wpdb']->get_results('SELECT * FROM mlt_musesys_ledger ORDER BY id');
    foreach ($rows as &$r) foreach (['projected_at', 'resolved_at'] as $k) unset($r[$k]);
    return sha1(json_encode($rows));
}
function project($id, $opts = []) { ok(Ledger_Projector::project($id, $opts), "project($id) returned false"); }

/* ---------------- scenarios ---------------- */

t('schema installs; unique dedupe_key index exists', function () use ($wpdb) {
    $idx = $wpdb->get_results("SHOW INDEX FROM mlt_musesys_ledger_events WHERE Key_name = 'dedupe_key'");
    eq('0', $idx[0]['Non_unique']);
    eq(Ledger_Schema::VERSION, get_option(Ledger_Schema::OPT_SCHEMA));
});

t('first projection: 2 rows, baseline events, NEEDS_ETA', function () {
    order_fixture(500);
    project(500);
    $r = rows(500);
    eq(2, count($r));
    eq('NEEDS_ETA', $r[101]['state']);
    eq('1', $r[101]['actionable']);
    eq('2026-09-25', $r[101]['eta']);
    eq('observed', $r[101]['confirmed_at_source']);
    eq(['trx_assigned', 'eta_changed', 'state_changed', 'state_changed'], array_column(events(), 'action'));
});

t('reconcile idempotency: project ×2, resolve_all ×2, resolver-upgrade pass → nothing appended, checksum stable', function () {
    $n = n_events(); $c = checksum();
    project(500); project(500);
    Ledger_Projector::resolve_all(false);
    Ledger_Projector::resolve_all(false);
    Ledger_Projector::resolve_all(true, ['cause' => ['cause' => 'resolver_upgrade', 'from_v' => 1, 'to_v' => 2]]);
    eq($n, n_events());
    eq($c, checksum());
});

t('dedupe_key: a replayed generated event is ignored by the database', function () {
    $e = events()[0];
    $n = n_events();
    Ledger_Events::append_generated(['order_id' => 500, 'user_id' => 0, 'source' => 'projector', 'action' => $e['action'], 'payload' => [], 'dedupe_key' => $e['dedupe_key']]);
    eq($n, n_events());
    eq(false, Ledger_Events::append_generated(['order_id' => 500, 'source' => 'projector', 'action' => 'x', 'payload' => []]), 'no key → refused');
});

t('UACP-style ETA change → one eta_changed + state_changed to ON_TRACK; replay → none', function () {
    $GLOBALS['__orders'][500]->meta['_muse_logistics_date'] = '2099-01-15';
    $n = n_events();
    project(500);
    $new = array_slice(events(), $n);
    eq(['eta_changed', 'state_changed', 'state_changed'], array_column($new, 'action'));
    eq('ON_TRACK', rows(500)[101]['state']);
    $n = n_events();
    project(500);
    eq($n, n_events());
});

t('ledger eta_set attribution → one eta_set (ledger_ui); follow-up hook projection → none', function () {
    $GLOBALS['__orders'][500]->meta['_muse_logistics_date'] = '2099-02-01';
    $n = n_events();
    project(500, ['source' => 'ledger_ui', 'user_id' => 42, 'attribution' => ['action' => 'eta_set', 'payload' => ['notified_customer' => false, 'sc_expected' => true]]]);
    $new = array_slice(events(), $n);
    eq(['eta_set'], array_column($new, 'action'));
    eq('ledger_ui', $new[0]['source']);
    eq('42', $new[0]['user_id']);
    eq(true, json_decode($new[0]['payload_json'], true)['sc_expected']);
    $n = n_events();
    project(500, ['user_id' => 42]);
    eq($n, n_events());
});

t('race: a competing pass already inserted this transition → this pass collapses to the same single event', function () use ($wpdb) {
    // Competing pass B read the same stored rows and inserted its eta event first (same dedupe_key),
    // but has not yet updated the rows. Our pass must not add a second event for the same transition.
    $old  = rows(500)[101];
    $prev = (int) $wpdb->get_var("SELECT MAX(id) FROM mlt_musesys_ledger_events WHERE order_id = 500 AND action IN ('eta_changed','eta_set')");
    $key  = Ledger_Projector::eta_key(500, $prev,
        ['logistics_status' => $old['logistics_status'], 'eta' => $old['eta']],
        ['logistics_status' => 'backordered', 'eta' => '2099-03-01']);
    Ledger_Events::append_generated(['order_id' => 500, 'user_id' => 0, 'source' => 'projector', 'action' => 'eta_changed', 'payload' => ['by' => 'pass_b'], 'dedupe_key' => $key]);
    $GLOBALS['__orders'][500]->meta['_muse_logistics_date'] = '2099-03-01';
    project(500);
    $eta_events = (int) $wpdb->get_var("SELECT COUNT(*) FROM mlt_musesys_ledger_events WHERE dedupe_key = '" . $key . "'");
    eq(1, $eta_events);
    eq('2099-03-01', rows(500)[101]['eta']);
    $n = n_events();
    project(500);
    eq($n, n_events());
});

t('CALL_REQUIRED via stored email ≥ 1 business day old; call after it → NEEDS_ETA', function () use ($wpdb) {
    $o = order_fixture(600);
    $o->meta['_muse_logistics_date'] = '2026-09-01';
    project(600);
    eq('NEEDS_ETA', rows(600)[101]['state']);
    $wpdb->query("INSERT INTO mlt_musesys_ledger_events (order_id, user_id, source, action, payload_json, created_at) VALUES (600, 42, 'ledger_ui', 'email_logged', '{\"text\":\"asked\"}', '2026-09-10 14:00:00')");
    Ledger_Projector::resolve_orders([600]);
    $r = rows(600)[101];
    eq('CALL_REQUIRED', $r['state']);
    eq('2026-09-10 14:00:00', $r['last_email_at']);
    ok(Ledger_Events::append_human(600, null, 'call_logged', 'spoke to vendor', 42));
    Ledger_Projector::resolve_orders([600]);
    eq('NEEDS_ETA', rows(600)[101]['state']);
    eq('called_awaiting_eta', rows(600)[101]['state_reason']);
    $n = n_events();
    Ledger_Projector::resolve_orders([600]);
    eq($n, n_events(), 'resolve replay');
});

t('note never changes state', function () {
    $before = rows(600)[101]['state'];
    ok(Ledger_Events::append_human(600, null, 'note', '<b>hi</b>', 42));
    Ledger_Projector::resolve_orders([600]);
    eq($before, rows(600)[101]['state']);
    $last = array_slice(events(), -1)[0];
    eq('{"text":"hi"}', $last['payload_json'], 'sanitized');
    eq(null, $last['dedupe_key']);
});

t('shipment 2/5 → shipment_recorded, qty_shipped=2, tracking_count=1; replay → none', function () {
    $GLOBALS['__orders'][600]->meta['_muse_shipments'] = json_encode([['carrier' => 'UPS', 'tracking_numbers' => ['1Z999'], 'items' => [600101 => 2], 'date' => '2026-10-01 09:00:00']]);
    $n = n_events();
    project(600);
    $new = array_slice(events(), $n);
    eq(['shipment_recorded'], array_column($new, 'action'));
    $r = rows(600)[101];
    eq('2', $r['qty_shipped']);
    eq('1', $r['tracking_count']);
    ok($new[0]['ledger_id'] === $r['id'], 'line-scoped event carries ledger_id');
    $n = n_events();
    project(600);
    eq($n, n_events());
});

t('full ship of every line → SHIPPED_NOT_CLOSED; order completed → CLOSED', function () {
    $GLOBALS['__orders'][600]->meta['_muse_shipments'] = json_encode([
        ['carrier' => 'UPS', 'tracking_numbers' => ['1Z999'], 'items' => [600101 => 2]],
        ['carrier' => 'UPS', 'tracking_numbers' => ['1Z998'], 'items' => [600101 => 3, 600102 => 1]],
    ]);
    project(600);
    eq('SHIPPED_NOT_CLOSED', rows(600)[101]['state']);
    eq('SHIPPED_NOT_CLOSED', rows(600)[102]['state']);
    $GLOBALS['__orders'][600]->status = 'completed';
    project(600);
    eq('CLOSED', rows(600)[101]['state']);
    eq('0', rows(600)[101]['actionable']);
});

t('closed order never seen before is not tracked', function () {
    $o = order_fixture(700);
    $o->status = 'completed';
    eq(false, Ledger_Projector::project(700));
    eq([], rows(700));
});

t('pre-install order with TRX → confirmed_at backfill_approx = date_created; state event flags it', function () {
    $o = order_fixture(800);
    $o->created = new DateTimeImmutable('2026-09-01 15:00:00', new DateTimeZone('UTC'));
    $o->meta['_muse_logistics_status'] = 'in_stock';
    $o->meta['_muse_logistics_date'] = '';
    project(800, ['source' => 'backfill']);
    $r = rows(800)[101];
    eq('backfill_approx', $r['confirmed_at_source']);
    eq('2026-09-01 15:00:00', $r['confirmed_at']);
    eq('NEEDS_ETA', $r['state']);
    $st = array_values(array_filter(events(), fn($e) => (int) $e['order_id'] === 800 && $e['action'] === 'state_changed'));
    eq(true, json_decode($st[0]['payload_json'], true)['confirmed_at_approx'] ?? null);
    eq('backfill', array_values(array_filter(events(), fn($e) => (int) $e['order_id'] === 800))[0]['source']);
});

t('line removed from order → that row CLOSED, others unaffected', function () {
    unset($GLOBALS['__orders'][800]->items[800102]);
    project(800);
    eq('line_removed', rows(800)[102]['order_status']);
    eq('CLOSED', rows(800)[102]['state']);
    eq('NEEDS_ETA', rows(800)[101]['state']);
});

t('rows_coming_due finds only ON_TRACK rows within 2 bdays; rows_for(true) excludes them', function () {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $eta_inside = (new DateTimeImmutable('now', new DateTimeZone(Ledger_State_Resolver::TZ)))->modify('+1 day')->format('Y-m-d');
    $eta_outside = (new DateTimeImmutable('now', new DateTimeZone(Ledger_State_Resolver::TZ)))->modify('+10 days')->format('Y-m-d');

    // Order 901: ON_TRACK due tomorrow (within cutoff)
    $o1 = order_fixture(901);
    $o1->meta['_muse_logistics_status'] = 'backordered';
    $o1->meta['_muse_logistics_date'] = $eta_inside;
    project(901);

    // Order 902: ON_TRACK due in 10 days (outside cutoff)
    $o2 = order_fixture(902);
    $o2->meta['_muse_logistics_status'] = 'backordered';
    $o2->meta['_muse_logistics_date'] = $eta_outside;
    project(902);

    $cd = Ledger_Ajax::rows_coming_due(null, $now);
    $cd_ids = array_map(fn($r) => (int) $r['order_id'], $cd);
    ok(in_array(901, $cd_ids, true), 'Order 901 due tomorrow must be in rows_coming_due');
    ok(!in_array(902, $cd_ids, true), 'Order 902 due in 10 days must NOT be in rows_coming_due');

    // Verify rows_for(true) excludes both ON_TRACK orders (unchanged agent work queue behavior)
    $agent_rows = Ledger_Ajax::rows_for(true);
    $agent_order_ids = array_map(fn($r) => (int) $r['order_id'], $agent_rows);
    ok(!in_array(901, $agent_order_ids, true), 'Order 901 must NOT be in rows_for(true)');
    ok(!in_array(902, $agent_order_ids, true), 'Order 902 must NOT be in rows_for(true)');
});

t('final global idempotency sweep over every order', function () {
    $n = n_events(); $c = checksum();
    foreach ([500, 600, 800, 901, 902] as $id) { project($id); project($id); }
    Ledger_Projector::resolve_all(true);
    eq($n, n_events());
    eq($c, checksum());
});

done();
