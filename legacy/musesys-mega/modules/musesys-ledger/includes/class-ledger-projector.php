<?php
/**
 * Ledger projector — copies fulfillment truth from the order into musesys_ledger and
 * appends generated events. LEDGER_SPEC.md §3.3, §4.
 *
 * Split in two:
 *   - Pure part (diff, normalize_shipment, state_event, *_key) — no WordPress calls,
 *     tested by tests/projector_test.php.
 *   - WordPress part (project, resolve_orders, resolve_all, hook queue) — loads orders,
 *     applies the diff inside a transaction with guarded updates + INSERT IGNORE events.
 *
 * The projector never writes order data. Bump VERSION when the source→column mapping changes (§4.4).
 */

if (!defined('ABSPATH') && !defined('MUSESYS_LEDGER_TESTING')) exit;

final class Ledger_Projector {
    const VERSION = 1;

    const WATCHED_META = [
        '_trx_order_number', '_purchase_order_number', '_is_drop_ship',
        '_muse_logistics_status', '_muse_logistics_date', '_muse_shipments',
    ];

    /** @var array<int,true> order IDs queued during this request */
    private static $queue = [];
    private static $shutdown_hooked = false;

    /* =====================================================================
     * PURE PART
     * ===================================================================== */

    /**
     * Normalize one `_muse_shipments` entry for fingerprinting (§3.3).
     */
    public static function normalize_shipment(array $s): array {
        $tracking = [];
        foreach ((array) ($s['tracking_numbers'] ?? []) as $tn) {
            $tn = strtoupper(trim((string) $tn));
            if ($tn !== '') $tracking[] = $tn;
        }
        sort($tracking, SORT_STRING);
        $items = [];
        foreach ((array) ($s['items'] ?? []) as $iid => $qty) {
            $qty = (int) $qty;
            if ($qty > 0) $items[(int) $iid] = $qty;
        }
        ksort($items, SORT_NUMERIC);
        return [
            'carrier'          => strtoupper(trim((string) ($s['carrier'] ?? ''))),
            'other_carrier'    => trim((string) ($s['other_carrier'] ?? '')),
            'tracking_numbers' => $tracking,
            'items'            => $items,
            'date'             => (string) ($s['date'] ?? ''),
        ];
    }

    public static function shipment_hash(array $normalized): string {
        $n = $normalized;
        unset($n['date']); // the date is display data, not identity
        return sha1(json_encode($n));
    }

    public static function eta_key(int $order_id, int $prev_id, array $old, array $new): string {
        return 'eta:' . $order_id . ':' . $prev_id . ':' . sha1(
            ($old['logistics_status'] ?? '') . '|' . ($old['eta'] ?? '') . '>' . ($new['logistics_status'] ?? '') . '|' . ($new['eta'] ?? '')
        );
    }

    public static function trx_key(int $order_id, int $prev_id, ?string $old, ?string $new): string {
        return 'trx:' . $order_id . ':' . $prev_id . ':' . sha1((string) $old . '>' . (string) $new);
    }

    public static function ship_key(int $order_id, int $item_id, int $index, string $hash): string {
        return 'ship:' . $order_id . ':' . $item_id . ':' . $index . ':' . $hash;
    }

    public static function state_key(int $ledger_id, int $prev_id, string $from, string $to): string {
        return 'state:' . $ledger_id . ':' . $prev_id . ':' . $from . '>' . $to;
    }

    /**
     * Compute what must change for one order.
     *
     * @param array $stored   Existing ledger rows for the order, keyed by order_item_id.
     * @param array $snap     Source snapshot (see build_snapshot()).
     * @param array $ctx      now (UTC), installed_at (UTC), source, user_id,
     *                        last_ids ['eta' => int, 'trx' => int], ship_keys [key => true],
     *                        attribution (optional) ['action' => 'eta_set', 'payload' => [...]].
     * @return array{order_cols:array, order_guard:?array, line_updates:array, inserts:array,
     *               removed:int[], confirm:?array, events:array}
     */
    public static function diff(array $stored, array $snap, array $ctx): array {
        $oid    = (int) $snap['order_id'];
        $now    = (string) $ctx['now'];
        $source = (string) ($ctx['source'] ?? 'projector');
        $uid    = (int) ($ctx['user_id'] ?? 0);
        $pv     = ['projector_v' => self::VERSION];

        $out = [
            'order_cols'   => [],
            'order_guard'  => null,
            'line_updates' => [],
            'inserts'      => [],
            'removed'      => [],
            'confirm'      => null,
            'events'       => [],
        ];

        $first = $stored ? reset($stored) : null;

        $order_cols = [
            'order_status'     => (string) $snap['order_status'],
            'trx'              => self::nullable($snap['trx']),
            'po_number'        => self::nullable($snap['po_number']),
            'is_drop_ship'     => (int) !empty($snap['is_drop_ship']),
            'store'            => self::nullable($snap['store']),
            'logistics_status' => (string) $snap['logistics_status'],
            'eta'              => self::nullable($snap['eta']),
        ];

        if ($first) {
            $out['order_guard'] = [
                'trx'              => self::nullable($first['trx'] ?? null),
                'eta'              => self::nullable($first['eta'] ?? null),
                'logistics_status' => (string) ($first['logistics_status'] ?? ''),
            ];
            foreach ($order_cols as $k => $v) {
                if (self::differs($first[$k] ?? null, $v)) { $out['order_cols'] = $order_cols; break; }
            }
        } else {
            $out['order_cols'] = $order_cols;
        }

        // ---- order-scoped events: trx_assigned, eta_changed / eta_set ----
        $old_trx = $first ? self::nullable($first['trx'] ?? null) : null;
        $new_trx = $order_cols['trx'];
        if ($old_trx !== $new_trx && !($first === null && $new_trx === null)) {
            $prev = (int) ($ctx['last_ids']['trx'] ?? 0);
            $out['events'][] = [
                'action'        => 'trx_assigned',
                'order_item_id' => null,
                'user_id'       => $uid,
                'source'        => $source,
                'payload'       => ['previous_trx' => $old_trx, 'new_trx' => $new_trx] + $pv,
                'dedupe_key'    => self::trx_key($oid, $prev, $old_trx, $new_trx),
                'order_level'   => true,
            ];
        }

        $old_eta = $first
            ? ['logistics_status' => (string) ($first['logistics_status'] ?? ''), 'eta' => self::nullable($first['eta'] ?? null)]
            : ['logistics_status' => null, 'eta' => null];
        $new_eta = ['logistics_status' => $order_cols['logistics_status'], 'eta' => $order_cols['eta']];
        $eta_moved = $first
            ? ($old_eta['logistics_status'] !== $new_eta['logistics_status'] || $old_eta['eta'] !== $new_eta['eta'])
            : ($new_eta['eta'] !== null || $new_eta['logistics_status'] !== 'in_stock'); // first sight: baseline only if meaningful
        if ($eta_moved) {
            $prev    = (int) ($ctx['last_ids']['eta'] ?? 0);
            $attr    = $ctx['attribution'] ?? null;
            $is_ui   = is_array($attr) && ($attr['action'] ?? '') === 'eta_set';
            $payload = [
                'previous_status' => $old_eta['logistics_status'],
                'previous_eta'    => $old_eta['eta'],
                'new_status'      => $new_eta['logistics_status'],
                'new_eta'         => $new_eta['eta'],
            ] + ($is_ui ? (array) ($attr['payload'] ?? []) : []) + $pv;
            $out['events'][] = [
                'action'        => $is_ui ? 'eta_set' : 'eta_changed',
                'order_item_id' => null,
                'user_id'       => $uid,
                'source'        => $is_ui ? 'ledger_ui' : $source,
                'payload'       => $payload,
                // Same key namespace for both: a UI change and the projector's view of it collide (§3.3).
                'dedupe_key'    => self::eta_key($oid, $prev, $old_eta, $new_eta),
                'order_level'   => true,
            ];
        }

        // ---- confirmed_at (§4.2) ----
        $stored_confirmed = null;
        foreach ($stored as $r) {
            if (!empty($r['confirmed_at'])) {
                $stored_confirmed = ['confirmed_at' => $r['confirmed_at'], 'confirmed_at_source' => $r['confirmed_at_source']];
                break;
            }
        }
        $confirm = $stored_confirmed;
        if ($new_trx !== null && $stored_confirmed === null) {
            $created = (string) ($snap['date_created'] ?? '');
            if (!$stored && $created !== '' && $created < (string) $ctx['installed_at']) {
                $confirm = ['confirmed_at' => $created, 'confirmed_at_source' => 'backfill_approx'];
            } else {
                $confirm = ['confirmed_at' => $now, 'confirmed_at_source' => 'observed'];
            }
            $out['confirm'] = $confirm;
        }

        // ---- lines ----
        $line_cols = ['product_id', 'part_label', 'qty_ordered', 'qty_shipped', 'tracking_count'];
        foreach ($snap['lines'] as $iid => $line) {
            $iid = (int) $iid;
            if (isset($stored[$iid])) {
                $chg = [];
                foreach ($line_cols as $c) {
                    if (self::differs($stored[$iid][$c] ?? null, $line[$c])) $chg[$c] = $line[$c];
                }
                if ($chg) $out['line_updates'][$iid] = $chg;
            } else {
                $out['inserts'][$iid] = $order_cols + array_intersect_key($line, array_flip($line_cols)) + [
                    'order_id'            => $oid,
                    'order_item_id'       => $iid,
                    'confirmed_at'        => $confirm['confirmed_at'] ?? null,
                    'confirmed_at_source' => $confirm['confirmed_at_source'] ?? null,
                ];
            }
        }
        foreach ($stored as $iid => $r) {
            if (!isset($snap['lines'][$iid]) && ($r['order_status'] ?? '') !== 'line_removed') {
                $out['removed'][] = (int) $iid;
            }
        }

        // ---- shipment_recorded (one per line touched; tracking-only shipments are order-scoped) ----
        $known = (array) ($ctx['ship_keys'] ?? []);
        foreach ($snap['shipments'] as $idx => $n) {
            $hash    = self::shipment_hash($n);
            $targets = $n['items'] ?: [0 => 0];
            foreach ($targets as $iid => $qty) {
                $key = self::ship_key($oid, (int) $iid, (int) $idx, $hash);
                if (isset($known[$key])) continue;
                $out['events'][] = [
                    'action'        => 'shipment_recorded',
                    'order_item_id' => $iid ? (int) $iid : null,
                    'user_id'       => $uid,
                    'source'        => $source,
                    'payload'       => [
                        'shipment_index'   => (int) $idx,
                        'qty'              => (int) $qty,
                        'tracking_numbers' => $n['tracking_numbers'],
                        'carrier'          => $n['carrier'],
                        'other_carrier'    => $n['other_carrier'],
                        'shipped_at'       => $n['date'] !== '' ? $n['date'] : null,
                    ] + $pv,
                    'dedupe_key'    => $key,
                    'order_level'   => false,
                ];
            }
        }

        return $out;
    }

    /**
     * state_changed event for one resolved row, or null when the state did not change (§3.3 layer 1).
     */
    public static function state_event(array $row, array $resolved, int $prev_id, array $ctx = []): ?array {
        $from = (string) ($row['state'] ?? '');
        $to   = (string) $resolved['state'];
        if ($from === $to) return null;
        $payload = ['from' => $from !== '' ? $from : null, 'to' => $to, 'reason' => $resolved['reason'], 'resolver_v' => Ledger_State_Resolver::VERSION];
        if (!empty($resolved['confirmed_at_approx'])) $payload['confirmed_at_approx'] = true;
        if (!empty($ctx['cause'])) $payload += (array) $ctx['cause'];
        return [
            'action'        => 'state_changed',
            'order_item_id' => (int) $row['order_item_id'],
            'user_id'       => 0,
            'source'        => 'resolver',
            'payload'       => $payload,
            'dedupe_key'    => self::state_key((int) $row['id'], $prev_id, $from, $to),
        ];
    }

    private static function nullable($v): ?string {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private static function differs($a, $b): bool {
        if ($a === null || $b === null) return $a !== $b && !($a === null && $b === '') && !($a === '' && $b === null);
        return (string) $a !== (string) $b;
    }

    /* =====================================================================
     * WORDPRESS PART
     * ===================================================================== */

    public static function init(): void {
        add_action('woocommerce_new_order', [__CLASS__, 'queue'], 20, 1);
        add_action('woocommerce_update_order', [__CLASS__, 'queue'], 20, 1);
        add_action('woocommerce_order_status_changed', [__CLASS__, 'queue'], 20, 1);
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $h) {
            add_action($h, [__CLASS__, 'on_post_meta'], 20, 3);
        }
    }

    public static function on_post_meta($meta_id, $object_id, $meta_key): void {
        if (!in_array($meta_key, self::WATCHED_META, true)) return;
        $type = get_post_type((int) $object_id);
        if ($type === 'shop_order' || $type === 'shop_order_placehold') self::queue((int) $object_id);
    }

    public static function queue($order_id): void {
        $order_id = (int) $order_id;
        if ($order_id <= 0) return;
        self::$queue[$order_id] = true;
        if (!self::$shutdown_hooked) {
            self::$shutdown_hooked = true;
            add_action('shutdown', [__CLASS__, 'flush'], 5);
        }
    }

    public static function flush(): void {
        $ids = array_keys(self::$queue);
        self::$queue = [];
        foreach ($ids as $id) {
            self::project($id, ['user_id' => get_current_user_id()]);
        }
    }

    /**
     * Project one order and resolve its rows. Never throws into the caller's request.
     *
     * @param array $opts source ('projector'|'backfill'|'ledger_ui'), user_id, attribution
     */
    public static function project(int $order_id, array $opts = []): bool {
        try {
            return self::project_inner($order_id, $opts);
        } catch (Throwable $e) {
            error_log('MuseSys Ledger: project(' . $order_id . ') failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function project_inner(int $order_id, array $opts): bool {
        global $wpdb;
        $order = wc_get_order($order_id);
        if (!$order || !($order instanceof WC_Order) || $order instanceof WC_Order_Refund) return false;

        $lt = Ledger_Schema::ledger_table();
        $et = Ledger_Schema::events_table();

        $stored = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM $lt WHERE order_id = %d", $order_id), ARRAY_A) as $r) {
            $stored[(int) $r['order_item_id']] = $r;
        }

        $is_open = in_array($order->get_status(), Ledger_State_Resolver::OPEN_STATUSES, true);
        if (!$stored && !$is_open) return false; // never start tracking a closed order

        $snap = self::build_snapshot($order);
        $now  = gmdate('Y-m-d H:i:s');

        $last_ids = ['eta' => 0, 'trx' => 0];
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT action, MAX(id) AS id FROM $et WHERE order_id = %d AND action IN ('eta_changed','eta_set','trx_assigned') GROUP BY action",
            $order_id
        ), ARRAY_A) as $r) {
            $kind = $r['action'] === 'trx_assigned' ? 'trx' : 'eta';
            $last_ids[$kind] = max($last_ids[$kind], (int) $r['id']);
        }
        $ship_keys = array_fill_keys((array) $wpdb->get_col($wpdb->prepare(
            "SELECT dedupe_key FROM $et WHERE order_id = %d AND action = 'shipment_recorded'", $order_id
        )), true);

        $d = self::diff($stored, $snap, [
            'now'          => $now,
            'installed_at' => Ledger_Schema::installed_at(),
            'source'       => $opts['source'] ?? 'projector',
            'user_id'      => (int) ($opts['user_id'] ?? 0),
            'last_ids'     => $last_ids,
            'ship_keys'    => $ship_keys,
            'attribution'  => $opts['attribution'] ?? null,
        ]);

        $wpdb->query('START TRANSACTION');
        try {
            $order_level_ok = true;

            // Guarded order-level update (§3.3 layer 1): if another pass already moved these values, emit nothing.
            if ($stored && $d['order_cols']) {
                $g    = $d['order_guard'];
                $set  = [];
                $args = [];
                foreach ($d['order_cols'] as $k => $v) {
                    $set[]  = $v === null ? "`$k` = NULL" : "`$k` = %s";
                    if ($v !== null) $args[] = $v;
                }
                $set[]  = '`projected_at` = %s';
                $args[] = $now;
                $args[] = $order_id;
                $args[] = $g['trx'];
                $args[] = $g['eta'];
                $args[] = $g['logistics_status'];
                $sql = "UPDATE $lt SET " . implode(', ', $set)
                     . " WHERE order_id = %d AND trx <=> %s AND eta <=> %s AND logistics_status = %s";
                $sql = self::prepare_nullable($sql, $args);
                $affected = $wpdb->query($sql);
                $order_level_ok = $affected > 0;
            }

            foreach ($d['line_updates'] as $iid => $cols) {
                $wpdb->update($lt, $cols + ['projected_at' => $now], ['order_item_id' => $iid]);
            }
            foreach ($d['removed'] as $iid) {
                $wpdb->update($lt, ['order_status' => 'line_removed', 'projected_at' => $now], ['order_item_id' => $iid]);
            }
            if ($d['confirm'] && $stored) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $lt SET confirmed_at = %s, confirmed_at_source = %s WHERE order_id = %d AND confirmed_at IS NULL",
                    $d['confirm']['confirmed_at'], $d['confirm']['confirmed_at_source'], $order_id
                ));
            }
            foreach ($d['inserts'] as $iid => $row) {
                self::insert_ignore($lt, $row + [
                    'state'        => '',
                    'projected_at' => $now,
                    'resolved_at'  => $now,
                ]);
            }

            $ids_by_item = [];
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, order_item_id FROM $lt WHERE order_id = %d", $order_id), ARRAY_A) as $r) {
                $ids_by_item[(int) $r['order_item_id']] = (int) $r['id'];
            }

            foreach ($d['events'] as $ev) {
                if (!empty($ev['order_level']) && !$order_level_ok) continue;
                $iid = $ev['order_item_id'];
                Ledger_Events::append_generated([
                    'order_id'      => $order_id,
                    'ledger_id'     => $iid ? ($ids_by_item[$iid] ?? null) : null,
                    'order_item_id' => $iid,
                    'user_id'       => $ev['user_id'],
                    'source'        => $ev['source'],
                    'action'        => $ev['action'],
                    'payload'       => $ev['payload'],
                    'dedupe_key'    => $ev['dedupe_key'],
                ]);
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        self::resolve_orders([$order_id]);
        return true;
    }

    /**
     * Read everything the ledger needs from the order. Uses $order->get_meta() so it works
     * under both legacy and HPOS storage.
     */
    public static function build_snapshot(WC_Order $order): array {
        $shipments_raw = json_decode((string) $order->get_meta('_muse_shipments', true), true);
        $shipments = [];
        if (is_array($shipments_raw)) {
            foreach (array_values($shipments_raw) as $i => $s) {
                if (is_array($s)) $shipments[$i] = self::normalize_shipment($s);
            }
        }

        $lines = [];
        foreach ($order->get_items('line_item') as $item_id => $item) {
            $item_id  = (int) $item_id;
            $ordered  = (int) $item->get_quantity();
            $shipped  = 0;
            $tracking = [];
            foreach ($shipments as $s) {
                if (!isset($s['items'][$item_id])) continue;
                $shipped += $s['items'][$item_id];
                foreach ($s['tracking_numbers'] as $tn) $tracking[$tn] = true;
            }
            $pid  = (int) $item->get_product_id();
            $part = $pid ? trim((string) get_post_meta($pid, 'muse_part_number', true)) : '';
            $name = (string) $item->get_name();
            $lines[$item_id] = [
                'product_id'     => $pid ?: null,
                'part_label'     => mb_substr($part !== '' ? $part . ' — ' . $name : $name, 0, 255),
                'qty_ordered'    => $ordered,
                'qty_shipped'    => min($ordered, $shipped),
                'tracking_count' => count($tracking),
            ];
        }

        $ls = trim((string) $order->get_meta('_muse_logistics_status', true));
        $eta = trim((string) $order->get_meta('_muse_logistics_date', true));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eta)) $eta = '';

        $store = '';
        foreach (['_muse_store_global', 'additional_install_store_number', '_install_stores', '_musesys_install_store', '_muse_global_install_store'] as $k) {
            $v = $order->get_meta($k, true);
            if (is_array($v)) $v = reset($v);
            $v = trim((string) $v);
            if ($v !== '') { $store = mb_substr($v, 0, 16); break; }
        }

        $created = $order->get_date_created();

        return [
            'order_id'         => $order->get_id(),
            'order_status'     => $order->get_status(),
            'trx'              => trim((string) $order->get_meta('_trx_order_number', true)),
            'po_number'        => trim((string) $order->get_meta('_purchase_order_number', true)),
            'is_drop_ship'     => $order->get_meta('_is_drop_ship', true) === 'yes',
            'store'            => $store,
            'logistics_status' => $ls !== '' ? $ls : 'in_stock',
            'eta'              => $eta,
            'date_created'     => $created ? gmdate('Y-m-d H:i:s', $created->getTimestamp()) : '',
            'lines'            => $lines,
            'shipments'        => $shipments,
        ];
    }

    /* -------------------- resolve (SQL + resolver, no order loads) -------------------- */

    public static function resolve_orders(array $order_ids, array $ctx = []): void {
        global $wpdb;
        $order_ids = array_values(array_filter(array_map('intval', $order_ids)));
        if (!$order_ids) return;
        $lt = Ledger_Schema::ledger_table();
        $in = implode(',', $order_ids);
        $rows = (array) $wpdb->get_results("SELECT * FROM $lt WHERE order_id IN ($in)", ARRAY_A);
        self::resolve_rows($rows, $ctx);
    }

    /** Resolve every row (or every non-closed row) in batches of 500. */
    public static function resolve_all(bool $include_closed, array $ctx = []): int {
        global $wpdb;
        $lt    = Ledger_Schema::ledger_table();
        $last  = 0;
        $count = 0;
        $where = $include_closed ? '1=1' : "state <> 'CLOSED'";
        do {
            $rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $lt WHERE $where AND id > %d ORDER BY id ASC LIMIT 500", $last
            ), ARRAY_A);
            if (!$rows) break;
            self::resolve_rows($rows, $ctx);
            $last   = (int) end($rows)['id'];
            $count += count($rows);
        } while (count($rows) === 500);
        return $count;
    }

    private static function resolve_rows(array $rows, array $ctx): void {
        global $wpdb;
        if (!$rows) return;
        $lt = Ledger_Schema::ledger_table();
        $et = Ledger_Schema::events_table();

        $order_ids  = array_unique(array_map(fn($r) => (int) $r['order_id'], $rows));
        $ledger_ids = array_map(fn($r) => (int) $r['id'], $rows);
        $oin = implode(',', $order_ids);
        $lin = implode(',', $ledger_ids);

        $contacts = [];
        $last_ui  = [];
        foreach ((array) $wpdb->get_results(
            "SELECT order_id, action, created_at FROM $et WHERE order_id IN ($oin) AND source = 'ledger_ui' ORDER BY id ASC", ARRAY_A
        ) as $e) {
            $oid = (int) $e['order_id'];
            if ($e['action'] === 'email_logged' || $e['action'] === 'call_logged') $contacts[$oid][] = $e;
            $last_ui[$oid][$e['action']] = $e['created_at'];
            $last_ui[$oid]['_any'] = $e['created_at'];
        }

        $prev_state = [];
        foreach ((array) $wpdb->get_results(
            "SELECT ledger_id, MAX(id) AS id FROM $et WHERE ledger_id IN ($lin) AND action = 'state_changed' GROUP BY ledger_id", ARRAY_A
        ) as $e) {
            $prev_state[(int) $e['ledger_id']] = (int) $e['id'];
        }

        $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $now_sql = $now->format('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $oid = (int) $row['order_id'];
            $res = Ledger_State_Resolver::resolve($row, $contacts[$oid] ?? [], $now);

            $cols = [
                'state'          => $res['state'],
                'state_reason'   => $res['reason'],
                'due_at'         => $res['due_at'],
                'overdue_bdays'  => $res['overdue_bdays'],
                'data_flags'     => $res['data_flags'] ? implode(',', $res['data_flags']) : null,
                'last_email_at'  => $last_ui[$oid]['email_logged'] ?? null,
                'last_call_at'   => $last_ui[$oid]['call_logged'] ?? null,
                'last_action_at' => $last_ui[$oid]['_any'] ?? null,
                'actionable'     => $res['actionable'],
            ];

            $changed = false;
            foreach ($cols as $k => $v) {
                if (self::differs($row[$k] ?? null, $v === null ? null : (string) $v)) { $changed = true; break; }
            }
            if (!$changed) continue;

            $wpdb->query('START TRANSACTION');
            $set  = [];
            $args = [];
            foreach ($cols + ['resolved_at' => $now_sql] as $k => $v) {
                $set[] = $v === null ? "`$k` = NULL" : "`$k` = %s";
                if ($v !== null) $args[] = (string) $v;
            }
            $args[] = (int) $row['id'];
            $args[] = (string) $row['state'];
            $affected = $wpdb->query(self::prepare_nullable(
                "UPDATE $lt SET " . implode(', ', $set) . " WHERE id = %d AND state = %s", $args
            ));
            if ($affected > 0) {
                $ev = self::state_event($row, $res, $prev_state[(int) $row['id']] ?? 0, $ctx);
                if ($ev) {
                    Ledger_Events::append_generated($ev + ['order_id' => $oid, 'ledger_id' => (int) $row['id']]);
                }
            }
            $wpdb->query('COMMIT');
        }
    }

    /* -------------------- SQL helpers -------------------- */

    /** $wpdb->prepare() with a mixed placeholder list where NULL guards are passed as <=> NULL. */
    private static function prepare_nullable(string $sql, array $args): string {
        global $wpdb;
        // Replace "<=> %s" with "<=> NULL" for null args, keeping positional order.
        $parts = preg_split('/(%[sd])/', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out   = '';
        $vals  = [];
        $i     = 0;
        foreach ($parts as $p) {
            if ($p === '%s' || $p === '%d') {
                $v = $args[$i++] ?? null;
                if ($v === null) {
                    $out .= 'NULL';
                } else {
                    $out   .= $p;
                    $vals[] = $v;
                }
            } else {
                $out .= $p;
            }
        }
        return $vals ? $wpdb->prepare($out, $vals) : $out;
    }

    private static function insert_ignore(string $table, array $row): void {
        global $wpdb;
        $cols = [];
        $vals = [];
        $args = [];
        foreach ($row as $k => $v) {
            $cols[] = "`$k`";
            if ($v === null) {
                $vals[] = 'NULL';
            } else {
                $vals[] = '%s';
                $args[] = (string) $v;
            }
        }
        $sql = "INSERT IGNORE INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')';
        $wpdb->query($args ? $wpdb->prepare($sql, $args) : $sql);
    }
}
