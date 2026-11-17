<?php
/**
 * Projector diff / idempotency tests — LEDGER_SPEC.md §10.1b.
 * Run: php modules/musesys-ledger/tests/projector_test.php
 *
 * apply() below plays the role of the WordPress layer: it folds a diff into the stored rows and
 * the event store exactly as project_inner() does (guarded order-level update, INSERT IGNORE on
 * dedupe_key), so "run it twice" can be tested without a database.
 */

define('MUSESYS_LEDGER_TESTING', true);
require __DIR__ . '/../includes/class-ledger-state-resolver.php';
require __DIR__ . '/../includes/class-ledger-projector.php';
require __DIR__ . '/lib.php';

const INSTALLED = '2026-10-01 00:00:00';

function snap(array $o = []): array {
    $shipments = [];
    foreach ($o['shipments'] ?? [] as $i => $s) $shipments[$i] = Ledger_Projector::normalize_shipment($s);
    unset($o['shipments']);
    $base = [
        'order_id'         => 500,
        'order_status'     => 'processing',
        'trx'              => '778812',
        'po_number'        => 'CH-123456',
        'is_drop_ship'     => false,
        'store'            => '029',
        'logistics_status' => 'backordered',
        'eta'              => '2026-10-07',
        'date_created'     => '2026-10-01 12:00:00',
        'lines'            => [
            11 => ['product_id' => 7, 'part_label' => 'P-1 — Gasket', 'qty_ordered' => 5, 'qty_shipped' => 0, 'tracking_count' => 0],
            12 => ['product_id' => 8, 'part_label' => 'P-2 — Motor',  'qty_ordered' => 1, 'qty_shipped' => 0, 'tracking_count' => 0],
        ],
    ];
    return array_replace($base, $o) + ['shipments' => $shipments];
}

/** In-memory store: rows keyed by item_id, events by dedupe_key (unique) + plain list. */
final class Store {
    public $rows = [];
    public $events = [];
    private $next = 1;

    public function ctx(array $extra = []): array {
        $last = ['eta' => 0, 'trx' => 0];
        $ship = [];
        foreach ($this->events as $e) {
            if (in_array($e['action'], ['eta_changed', 'eta_set'], true)) $last['eta'] = max($last['eta'], $e['id']);
            if ($e['action'] === 'trx_assigned') $last['trx'] = max($last['trx'], $e['id']);
            if ($e['action'] === 'shipment_recorded') $ship[$e['dedupe_key']] = true;
        }
        return $extra + ['now' => '2026-10-02 14:00:00', 'installed_at' => INSTALLED, 'source' => 'projector',
                         'user_id' => 0, 'last_ids' => $last, 'ship_keys' => $ship];
    }

    /** Fold a diff in; returns the events actually inserted. */
    public function apply(array $d): array {
        $ok = true;
        if ($this->rows && $d['order_cols']) {
            $g = $d['order_guard'];
            $hit = 0;
            foreach ($this->rows as $iid => $r) {
                if ($r['trx'] === $g['trx'] && $r['eta'] === $g['eta'] && $r['logistics_status'] === $g['logistics_status']) {
                    $this->rows[$iid] = $d['order_cols'] + $r;
                    $hit++;
                }
            }
            $ok = $hit > 0;
        }
        foreach ($d['line_updates'] as $iid => $c) $this->rows[$iid] = $c + $this->rows[$iid];
        foreach ($d['removed'] as $iid) $this->rows[$iid]['order_status'] = 'line_removed';
        if ($d['confirm']) {
            foreach ($this->rows as $iid => $r) if (empty($r['confirmed_at'])) $this->rows[$iid] = $d['confirm'] + $r;
        }
        foreach ($d['inserts'] as $iid => $row) if (!isset($this->rows[$iid])) $this->rows[$iid] = $row + ['state' => ''];
        $inserted = [];
        foreach ($d['events'] as $ev) {
            if (!empty($ev['order_level']) && !$ok) continue;
            if (isset($this->events[$ev['dedupe_key']])) continue; // INSERT IGNORE
            $ev['id'] = $this->next++;
            $this->events[$ev['dedupe_key']] = $ev;
            $inserted[] = $ev;
        }
        return $inserted;
    }

    public function run(array $snap, array $extra = []): array {
        return $this->apply(Ledger_Projector::diff($this->rows, $snap, $this->ctx($extra)));
    }
}

function actions(array $evs): array { return array_map(fn($e) => $e['action'], $evs); }

t('first sight: inserts rows, baseline trx + eta events, confirmed observed', function () {
    $s = new Store();
    $ev = $s->run(snap());
    eq(['trx_assigned', 'eta_changed'], actions($ev));
    eq([11, 12], array_keys($s->rows));
    eq('observed', $s->rows[11]['confirmed_at_source']);
});

t('same snapshot diffed twice → second run returns zero events and zero changes', function () {
    $s = new Store();
    $s->run(snap(['shipments' => [['carrier' => 'UPS', 'tracking_numbers' => ['1Z9'], 'items' => [11 => 2]]]]));
    $snap = snap(['shipments' => [['carrier' => 'UPS', 'tracking_numbers' => ['1Z9'], 'items' => [11 => 2]]], 'lines' => [
        11 => ['product_id' => 7, 'part_label' => 'P-1 — Gasket', 'qty_ordered' => 5, 'qty_shipped' => 2, 'tracking_count' => 1],
        12 => ['product_id' => 8, 'part_label' => 'P-2 — Motor',  'qty_ordered' => 1, 'qty_shipped' => 0, 'tracking_count' => 0],
    ]]);
    $s->run($snap);
    $d = Ledger_Projector::diff($s->rows, $snap, $s->ctx());
    eq([], $d['events']);
    eq([], $d['order_cols']);
    eq([], $d['line_updates']);
    eq([], $d['inserts']);
    eq(null, $d['confirm']);
});

t('ETA A→B, replay, B→A, A→B: one event each, distinct keys for the two A→B', function () {
    $s = new Store();
    $s->run(snap(['eta' => '2026-10-07']));
    $ab1 = $s->run(snap(['eta' => '2026-10-14']));
    eq(['eta_changed'], actions($ab1));
    eq([], $s->run(snap(['eta' => '2026-10-14'])), 'replay');
    eq(['eta_changed'], actions($s->run(snap(['eta' => '2026-10-07']))));
    $ab2 = $s->run(snap(['eta' => '2026-10-14']));
    eq(['eta_changed'], actions($ab2));
    ok($ab1[0]['dedupe_key'] !== $ab2[0]['dedupe_key'], 'second A→B must get a new key');
    eq('2026-10-07', $ab2[0]['payload']['previous_eta']);
});

t('replaying a stale diff (concurrent pass) collapses to one event', function () {
    $s = new Store();
    $s->run(snap());
    $ctx = $s->ctx();
    $d1  = Ledger_Projector::diff($s->rows, snap(['eta' => '2026-10-20']), $ctx);
    $d2  = Ledger_Projector::diff($s->rows, snap(['eta' => '2026-10-20']), $ctx); // computed before d1 applied
    eq(1, count($s->apply($d1)));
    eq(0, count($s->apply($d2)));
});

t('shipment appended → one shipment_recorded per line touched; re-diff → none', function () {
    $s = new Store();
    $s->run(snap());
    $ship = ['carrier' => 'ups', 'tracking_numbers' => [' 1z9 ', '1Z8'], 'items' => [11 => 2, 12 => 1], 'date' => '2026-10-02 09:00:00'];
    $ev = $s->run(snap(['shipments' => [$ship]]));
    eq(['shipment_recorded', 'shipment_recorded'], actions($ev));
    eq(['1Z8', '1Z9'], $ev[0]['payload']['tracking_numbers']);
    eq('UPS', $ev[0]['payload']['carrier']);
    eq([], $s->run(snap(['shipments' => [$ship]])));
});

t('two identical shipment entries at different indexes → two events', function () {
    $s = new Store();
    $s->run(snap());
    $ship = ['carrier' => 'UPS', 'tracking_numbers' => ['1Z9'], 'items' => [11 => 1]];
    eq(2, count($s->run(snap(['shipments' => [$ship, $ship]]))));
});

t('tracking-only shipment → one order-scoped event', function () {
    $s = new Store();
    $s->run(snap());
    $ev = $s->run(snap(['shipments' => [['carrier' => 'FEDEX', 'tracking_numbers' => ['77'], 'items' => []]]]));
    eq(1, count($ev));
    eq(null, $ev[0]['order_item_id']);
});

t('eta_set (UI attribution) shares the projector key; follow-up projection emits nothing', function () {
    $s = new Store();
    $s->run(snap());
    $ctx = $s->ctx();
    $ui  = Ledger_Projector::diff($s->rows, snap(['eta' => '2026-10-21']), $ctx + ['attribution' => ['action' => 'eta_set', 'payload' => ['notified_customer' => false, 'sc_expected' => true]]]);
    $sys = Ledger_Projector::diff($s->rows, snap(['eta' => '2026-10-21']), $ctx);
    eq('eta_set', $ui['events'][0]['action']);
    eq('ledger_ui', $ui['events'][0]['source']);
    eq(true, $ui['events'][0]['payload']['sc_expected']);
    eq($ui['events'][0]['dedupe_key'], $sys['events'][0]['dedupe_key']);
    eq(1, count($s->apply($ui)));
    eq([], $s->run(snap(['eta' => '2026-10-21'])), 'hook/reconcile after the UI change');
});

t('confirmed_at: pre-install order first seen with TRX → backfill_approx = date_created', function () {
    $s = new Store();
    $s->run(snap(['date_created' => '2026-09-01 15:00:00']), ['source' => 'backfill']);
    eq('backfill_approx', $s->rows[11]['confirmed_at_source']);
    eq('2026-09-01 15:00:00', $s->rows[11]['confirmed_at']);
});

t('confirmed_at: pre-install order without TRX, TRX later → observed now, written once', function () {
    $s = new Store();
    $s->run(snap(['trx' => '', 'date_created' => '2026-09-01 15:00:00']));
    eq(null, $s->rows[11]['confirmed_at']);
    $s->run(snap(['date_created' => '2026-09-01 15:00:00']));
    eq('observed', $s->rows[11]['confirmed_at_source']);
    eq('2026-10-02 14:00:00', $s->rows[11]['confirmed_at']);
    $s->run(snap(['trx' => '778813', 'date_created' => '2026-09-01 15:00:00']), ['now' => '2026-10-09 10:00:00']);
    eq('2026-10-02 14:00:00', $s->rows[11]['confirmed_at'], 'TRX correction must not move confirmed_at');
});

t('new line added later inherits confirmed_at; removed line flagged', function () {
    $s = new Store();
    $s->run(snap());
    $lines = snap()['lines'];
    unset($lines[12]);
    $lines[13] = ['product_id' => 9, 'part_label' => 'P-3', 'qty_ordered' => 2, 'qty_shipped' => 0, 'tracking_count' => 0];
    $d = Ledger_Projector::diff($s->rows, snap(['lines' => $lines]), $s->ctx(['now' => '2026-10-05 10:00:00']));
    eq([12], $d['removed']);
    eq($s->rows[11]['confirmed_at'], $d['inserts'][13]['confirmed_at']);
    eq([], $d['events']);
});

t('state_event: changed resolver version with same state → null; change → keyed event', function () {
    $row = ['id' => 3, 'order_item_id' => 11, 'state' => 'NEEDS_ETA'];
    eq(null, Ledger_Projector::state_event($row, ['state' => 'NEEDS_ETA', 'reason' => 'eta_passed'], 9, ['cause' => ['cause' => 'resolver_upgrade', 'from_v' => 1, 'to_v' => 2]]));
    $ev = Ledger_Projector::state_event($row, ['state' => 'CALL_REQUIRED', 'reason' => 'no_response_1bd'], 9, ['cause' => ['cause' => 'resolver_upgrade', 'from_v' => 1, 'to_v' => 2]]);
    eq('state:3:9:NEEDS_ETA>CALL_REQUIRED', $ev['dedupe_key']);
    eq('resolver_upgrade', $ev['payload']['cause']);
    $first = Ledger_Projector::state_event(['id' => 3, 'order_item_id' => 11, 'state' => ''], ['state' => 'ON_TRACK', 'reason' => 'eta_future'], 0);
    eq(null, $first['payload']['from']);
});

done();
