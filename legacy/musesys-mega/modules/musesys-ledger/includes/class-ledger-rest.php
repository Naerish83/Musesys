<?php
/**
 * REST surface for an outside system (e.g. Cairn) — LEDGER_SPEC.md §15.3.
 *
 * The outside system can read what is open, file proposals, read their outcomes and withdraw its
 * own pending ones. It cannot approve anything and cannot write order data: that stays with a
 * person on the ledger. Every route needs the musesys_ledger_propose capability.
 */

if (!defined('ABSPATH')) exit;

final class Ledger_Rest {
    const NS = 'musesys/v1';

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function can_propose(): bool {
        return is_user_logged_in() && current_user_can(Ledger_Access::CAP_PROPOSE);
    }

    public static function routes(): void {
        $perm = [__CLASS__, 'can_propose'];

        register_rest_route(self::NS, '/ledger/open-orders', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'open_orders'], 'permission_callback' => $perm,
        ]);
        register_rest_route(self::NS, '/ledger/proposals', [
            ['methods' => 'GET',  'callback' => [__CLASS__, 'index'],  'permission_callback' => $perm],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'create'], 'permission_callback' => $perm],
        ]);
        register_rest_route(self::NS, '/ledger/proposals/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'show'], 'permission_callback' => $perm,
        ]);
        register_rest_route(self::NS, '/ledger/proposals/(?P<id>\d+)/withdraw', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'withdraw'], 'permission_callback' => $perm,
        ]);
    }

    /**
     * Every open order as the ledger sees it, with the tracking already on it, so the outside
     * system proposes against live state instead of its own stale copy.
     */
    public static function open_orders($req) {
        global $wpdb;
        $lt   = Ledger_Schema::ledger_table();
        $rows = (array) $wpdb->get_results(
            "SELECT * FROM $lt WHERE state NOT IN ('CLOSED','') ORDER BY order_id ASC, order_item_id ASC", ARRAY_A
        );
        $pending = [];
        foreach (Ledger_Proposals::query(['status' => [Ledger_Proposals::PENDING, Ledger_Proposals::APPLYING], 'limit' => 500]) as $p) {
            $pending[(int) $p['order_id']][] = $p['source'] . ':' . $p['source_ref'];
        }

        $orders = [];
        foreach ($rows as $r) {
            $oid = (int) $r['order_id'];
            if (!isset($orders[$oid])) {
                $order    = wc_get_order($oid);
                $tracking = [];
                $refs     = [];
                if ($order) {
                    $raw = json_decode((string) $order->get_meta('_muse_shipments', true), true);
                    foreach ((array) $raw as $s) {
                        if (!is_array($s)) continue;
                        foreach ((array) ($s['tracking_numbers'] ?? []) as $t) {
                            $t = trim((string) $t);
                            if ($t !== '') $tracking[] = ['carrier' => (string) ($s['carrier'] ?? ''), 'number' => $t];
                        }
                    }
                    // Tenant seam (docs/hook-ownership.md): e.g. the Wegmans Work Order #.
                    $refs = array_values(array_filter(array_map('strval', (array) apply_filters('musesys_order_references', [], $order))));
                }
                $orders[$oid] = [
                    'order_id'          => $oid,
                    'order_status'      => $r['order_status'],
                    'trx'               => (string) $r['trx'],
                    'po_number'         => (string) $r['po_number'],
                    'is_drop_ship'      => (int) $r['is_drop_ship'] === 1,
                    'store'             => (string) $r['store'],
                    'references'        => $refs,
                    'logistics_status'  => $r['logistics_status'],
                    'eta'               => $r['eta'],
                    'tracking'          => $tracking,
                    'pending_proposals' => $pending[$oid] ?? [],
                    'lines'             => [],
                ];
            }
            $orders[$oid]['lines'][] = [
                'order_item_id' => (int) $r['order_item_id'],
                'part_label'    => (string) $r['part_label'],
                'qty_ordered'   => (int) $r['qty_ordered'],
                'qty_shipped'   => (int) $r['qty_shipped'],
                'state'         => $r['state'],
            ];
        }
        return rest_ensure_response(['generated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'orders' => array_values($orders)]);
    }

    public static function create($req) {
        $in = $req->get_json_params();
        if (!is_array($in)) $in = (array) $req->get_body_params();
        $p = Ledger_Proposals::normalize($in);
        if (is_string($p)) return new WP_Error('musesys_ledger_bad_proposal', $p, ['status' => 400]);

        $res = Ledger_Proposals::create($p, get_current_user_id());
        if (isset($res['error'])) return new WP_Error('musesys_ledger_proposal_refused', $res['error'], ['status' => $res['status']]);

        $out = rest_ensure_response(Ledger_Proposals::present($res['proposal']) + ['created' => $res['created']]);
        $out->set_status($res['created'] ? 201 : 200);
        return $out;
    }

    public static function index($req) {
        Ledger_Proposals::settle();
        $f = ['limit' => (int) ($req['limit'] ?: 100)];
        if ($req['status'])   $f['status']   = array_map('trim', explode(',', (string) $req['status']));
        if ($req['source'])   $f['source']   = sanitize_key((string) $req['source']);
        if ($req['order_id']) $f['order_id'] = (int) $req['order_id'];
        if ($req['updated_since']) {
            $ts = strtotime((string) $req['updated_since']);
            if ($ts === false) return new WP_Error('musesys_ledger_bad_since', 'updated_since is not a date.', ['status' => 400]);
            $f['updated_since'] = gmdate('Y-m-d H:i:s', $ts);
        }
        return rest_ensure_response(['proposals' => array_map(['Ledger_Proposals', 'present'], Ledger_Proposals::query($f))]);
    }

    public static function show($req) {
        $row = Ledger_Proposals::get((int) $req['id']);
        if (!$row) return new WP_Error('musesys_ledger_not_found', 'Proposal not found.', ['status' => 404]);
        return rest_ensure_response(Ledger_Proposals::present($row));
    }

    public static function withdraw($req) {
        $id  = (int) $req['id'];
        $row = Ledger_Proposals::get($id);
        if (!$row) return new WP_Error('musesys_ledger_not_found', 'Proposal not found.', ['status' => 404]);
        $in     = (array) $req->get_json_params();
        $reason = mb_substr(sanitize_text_field((string) ($in['reason'] ?? '')), 0, Ledger_Proposals::MAX_NOTE);
        if (!Ledger_Proposals::withdraw($id, $reason, get_current_user_id())) {
            $now = Ledger_Proposals::get($id);
            return new WP_Error('musesys_ledger_not_pending', 'Only a pending proposal can be withdrawn (this one is ' . $now['status'] . ').', ['status' => 409]);
        }
        return rest_ensure_response(Ledger_Proposals::present(Ledger_Proposals::get($id)));
    }
}
