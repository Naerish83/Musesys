<?php
/**
 * Proposed updates from an outside system (e.g. Cairn) — LEDGER_SPEC.md §15.
 *
 * An outside system never writes order data. It files a PROPOSAL; a person approves or rejects it
 * on the ledger; approval runs the existing domain mutation (the ship-lines endpoint), exactly as
 * UACP does. The ledger still stores no canonical business data: a proposal is a request, not state.
 *
 * The first half of this class is pure (no WordPress calls) so it runs under the PHP CLI tests.
 */

if (!defined('ABSPATH')) exit;

final class Ledger_Proposals {
    const KIND_SHIPMENT = 'shipment';
    const KINDS         = [self::KIND_SHIPMENT];

    const PENDING    = 'pending';
    const APPLYING   = 'applying';   // claimed by an approval in flight
    const APPLIED    = 'applied';
    const REJECTED   = 'rejected';
    const SUPERSEDED = 'superseded'; // already recorded, order closed, or withdrawn by its source
    const STATUSES   = [self::PENDING, self::APPLYING, self::APPLIED, self::REJECTED, self::SUPERSEDED];
    const FINAL      = [self::APPLIED, self::REJECTED, self::SUPERSEDED];

    /** Carrier codes accepted by the ship-lines endpoint (musesys-partial-ship). */
    const CARRIERS = ['UPS', 'FEDEX', 'USPS', 'DHL', 'LTL', 'OTHER'];

    const MAX_TRACKING     = 10;
    const MAX_TRACKING_LEN = 64;
    const MAX_REF          = 150;
    const MAX_SOURCE       = 32;
    const MAX_SUMMARY      = 300;
    const MAX_NOTE         = 500;
    const MAX_EVIDENCE     = 8000;   // bytes of JSON
    const STALE_APPLYING   = 600;    // seconds before a claimed proposal is re-examined

    /* ==================== pure ==================== */

    /** Comparison form of a tracking number: uppercase letters and digits only. */
    public static function norm_tracking($t): string {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $t));
    }

    /**
     * Validate and normalise an incoming proposal.
     *
     * @return array|string the normalised proposal, or an error message
     */
    public static function normalize(array $in) {
        $source = strtolower(trim((string) ($in['source'] ?? '')));
        if ($source === '' || strlen($source) > self::MAX_SOURCE || !preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $source)) {
            return 'source is required (letters, digits, dot, dash, underscore; 32 characters at most).';
        }
        $ref = trim((string) ($in['source_ref'] ?? ''));
        if ($ref === '' || strlen($ref) > self::MAX_REF || !preg_match('/^[\x21-\x7E]+$/', $ref)) {
            return 'source_ref is required (printable ASCII without spaces; 150 characters at most).';
        }
        $order_id = (int) ($in['order_id'] ?? 0);
        if ($order_id <= 0) return 'order_id is required.';

        $kind = (string) ($in['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            return 'kind must be one of: ' . implode(', ', self::KINDS) . '.';
        }

        $carrier = strtoupper(trim((string) ($in['carrier'] ?? '')));
        if (!in_array($carrier, self::CARRIERS, true)) {
            return 'carrier must be one of: ' . implode(', ', self::CARRIERS) . '.';
        }
        $other = trim((string) ($in['other_carrier'] ?? ''));
        if ($carrier === 'OTHER' && $other === '') return 'other_carrier is required when carrier is OTHER.';
        if (strlen($other) > 60) return 'other_carrier is too long (60 characters at most).';

        $raw = $in['tracking_numbers'] ?? null;
        if (!is_array($raw)) return 'tracking_numbers must be a list.';
        $tracking = [];
        $seen     = [];
        foreach ($raw as $t) {
            if (!is_scalar($t)) return 'tracking_numbers must contain text values only.';
            $t = trim((string) $t);
            if ($t === '') continue;
            if (strlen($t) > self::MAX_TRACKING_LEN) return 'A tracking number is too long (64 characters at most).';
            $n = self::norm_tracking($t);
            if ($n === '') return 'A tracking number has no letters or digits.';
            if (isset($seen[$n])) continue;
            $seen[$n]   = true;
            $tracking[] = $t;
        }
        if (!$tracking) return 'At least one tracking number is required.';
        if (count($tracking) > self::MAX_TRACKING) return 'Too many tracking numbers (10 at most).';

        // Lines are optional: a vendor notice rarely says which order lines it covers. The reviewer
        // confirms lines and quantities at approval either way.
        $lines = [];
        if (isset($in['lines'])) {
            if (!is_array($in['lines'])) return 'lines must be a list.';
            foreach ($in['lines'] as $ln) {
                if (!is_array($ln)) return 'Each line must be an object.';
                $item = (int) ($ln['order_item_id'] ?? ($ln['line_item_id'] ?? 0));
                $qty  = (int) ($ln['qty'] ?? ($ln['ship_qty'] ?? 0));
                if ($item <= 0 || $qty <= 0) return 'Each line needs order_item_id and a quantity above zero.';
                $lines[$item] = ($lines[$item] ?? 0) + $qty;
            }
        }

        $summary = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($in['summary'] ?? ''))));
        if (strlen($summary) > self::MAX_SUMMARY) $summary = substr($summary, 0, self::MAX_SUMMARY);

        $evidence = $in['evidence'] ?? [];
        if (!is_array($evidence)) return 'evidence must be an object.';
        $evidence = self::clean_evidence($evidence, 0);
        if (strlen((string) json_encode($evidence)) > self::MAX_EVIDENCE) return 'evidence is too large (8,000 bytes of JSON at most).';

        $out_lines = [];
        foreach ($lines as $item => $qty) $out_lines[] = ['order_item_id' => $item, 'qty' => $qty];

        return [
            'source'     => $source,
            'source_ref' => $ref,
            'order_id'   => $order_id,
            'kind'       => $kind,
            'payload'    => [
                'carrier'          => $carrier,
                'other_carrier'    => $carrier === 'OTHER' ? $other : '',
                'tracking_numbers' => $tracking,
                'lines'            => $out_lines,
            ],
            'summary'    => $summary,
            'evidence'   => $evidence,
        ];
    }

    /** Evidence is shown to a reviewer, never executed: keep scalars and shallow nesting only. */
    private static function clean_evidence(array $a, int $depth): array {
        $out = [];
        foreach ($a as $k => $v) {
            $k = substr(preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $k), 0, 60);
            if ($k === '') continue;
            if (is_array($v)) {
                if ($depth < 2) $out[$k] = self::clean_evidence($v, $depth + 1);
            } elseif (is_bool($v) || is_int($v) || is_float($v) || $v === null) {
                $out[$k] = $v;
            } elseif (is_string($v)) {
                $out[$k] = substr(trim(strip_tags($v)), 0, 500);
            }
        }
        return $out;
    }

    /**
     * Which of the given tracking numbers already sit on the order's shipments.
     *
     * @param array $shipments decoded _muse_shipments
     * @return string[] the matching numbers, as given
     */
    public static function already_recorded(array $shipments, array $tracking): array {
        $have = [];
        foreach ($shipments as $s) {
            if (!is_array($s)) continue;
            foreach ((array) ($s['tracking_numbers'] ?? []) as $t) $have[self::norm_tracking($t)] = true;
        }
        unset($have['']);
        return array_values(array_filter($tracking, fn($t) => isset($have[self::norm_tracking($t)])));
    }

    /**
     * Lines offered in the approval dialog: every open line with quantity still to ship, pre-filled
     * with the proposed quantity (clamped), or with everything remaining when the proposal names no lines.
     *
     * @param array $rows  ledger rows of the order (order_item_id, part_label, qty_ordered, qty_shipped)
     * @param array $lines proposal lines [['order_item_id' => n, 'qty' => n], ...]
     */
    public static function suggest_lines(array $rows, array $lines): array {
        $want = [];
        foreach ($lines as $ln) $want[(int) $ln['order_item_id']] = (int) $ln['qty'];
        $out = [];
        foreach ($rows as $r) {
            $remaining = max(0, (int) $r['qty_ordered'] - (int) $r['qty_shipped']);
            if ($remaining <= 0) continue;
            $item  = (int) $r['order_item_id'];
            $out[] = [
                'order_item_id' => $item,
                'part_label'    => (string) ($r['part_label'] ?? ''),
                'qty_ordered'   => (int) $r['qty_ordered'],
                'qty_shipped'   => (int) $r['qty_shipped'],
                'remaining'     => $remaining,
                'suggested'     => $want ? min($remaining, $want[$item] ?? 0) : $remaining,
            ];
        }
        return $out;
    }

    /**
     * Check the reviewer's confirmed lines against the order's open lines.
     *
     * @param array $rows      ledger rows of the order
     * @param array $requested [order_item_id => qty]
     * @return array|string    ship-lines payload lines, or an error message
     */
    public static function confirm_lines(array $rows, array $requested) {
        $remaining = [];
        foreach ($rows as $r) {
            $remaining[(int) $r['order_item_id']] = max(0, (int) $r['qty_ordered'] - (int) $r['qty_shipped']);
        }
        $out = [];
        foreach ($requested as $item => $qty) {
            $item = (int) $item;
            $qty  = (int) $qty;
            if ($qty <= 0) continue;
            if (!isset($remaining[$item])) return 'A line is not on this order.';
            if ($qty > $remaining[$item]) return 'A quantity is more than what is left to ship on its line.';
            $out[] = ['line_item_id' => $item, 'ship_qty' => $qty];
        }
        if (!$out) return 'Choose at least one line and a quantity to ship.';
        return $out;
    }

    /** Would these confirmed lines ship everything still open on the order? */
    public static function ships_everything(array $rows, array $confirmed): bool {
        $by = [];
        foreach ($confirmed as $ln) $by[(int) $ln['line_item_id']] = (int) $ln['ship_qty'];
        foreach ($rows as $r) {
            $remaining = max(0, (int) $r['qty_ordered'] - (int) $r['qty_shipped']);
            if ($remaining > ($by[(int) $r['order_item_id']] ?? 0)) return false;
        }
        return true;
    }

    /**
     * Shipping everything left completes the order, and an order with no TRX # (or a drop-ship order
     * with no PO #) refuses to complete: its whole save is thrown away, shipment included, while the
     * ship-lines endpoint still answers success. So that approval is refused up front.
     * Mirrors muse_block_order_completion_if_fields_missing (order-fields), read from the ledger row.
     *
     * @return string|null the reason, or null when the approval may go ahead
     */
    public static function completion_blocker(array $rows, array $confirmed): ?string {
        if (!$rows || !self::ships_everything($rows, $confirmed)) return null;
        $head = $rows[0];
        if (trim((string) ($head['trx'] ?? '')) === '') {
            return 'This would ship everything left and complete the order, but the order has no TRX #. Enter the TRX # on the order first, or ship less than everything.';
        }
        if ((int) ($head['is_drop_ship'] ?? 0) === 1 && trim((string) ($head['po_number'] ?? '')) === '') {
            return 'This would ship everything left and complete the order, but this drop-ship order has no PO #. Enter the PO # on the order first, or ship less than everything.';
        }
        return null;
    }

    /* ==================== storage ==================== */

    private static function now(): string {
        return gmdate('Y-m-d H:i:s');
    }

    public static function get(int $id): ?array {
        global $wpdb;
        $t = Ledger_Schema::proposals_table();
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $id), ARRAY_A);
        return $r ?: null;
    }

    public static function find(string $source, string $ref): ?array {
        global $wpdb;
        $t = Ledger_Schema::proposals_table();
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE source = %s AND source_ref = %s", $source, $ref), ARRAY_A);
        return $r ?: null;
    }

    /** Open (non-closed) ledger rows of an order. */
    public static function open_rows(int $order_id): array {
        global $wpdb;
        $lt = Ledger_Schema::ledger_table();
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $lt WHERE order_id = %d AND state NOT IN ('CLOSED','') ORDER BY order_item_id ASC", $order_id
        ), ARRAY_A);
    }

    private static function shipments_of($order): array {
        $raw = json_decode((string) $order->get_meta('_muse_shipments', true), true);
        return is_array($raw) ? $raw : [];
    }

    /**
     * File a proposal. Idempotent on (source, source_ref): a repeat returns the stored row untouched.
     *
     * @param array $p normalize() output
     * @return array ['proposal' => row, 'created' => bool] or ['error' => message, 'status' => http]
     */
    public static function create(array $p, int $user_id): array {
        global $wpdb;
        $existing = self::find($p['source'], $p['source_ref']);
        if ($existing) return ['proposal' => $existing, 'created' => false];

        $order = wc_get_order($p['order_id']);
        if (!$order || !($order instanceof WC_Order) || $order instanceof WC_Order_Refund) {
            return ['error' => 'Order not found.', 'status' => 404];
        }
        $rows = self::open_rows($p['order_id']);
        if (!$rows) return ['error' => 'This order is not open in the ledger.', 'status' => 409];

        $by_item = [];
        foreach ($rows as $r) $by_item[(int) $r['order_item_id']] = true;
        foreach ($p['payload']['lines'] as $ln) {
            if (!isset($by_item[$ln['order_item_id']])) return ['error' => 'A proposed line is not an open line of this order.', 'status' => 422];
        }

        // Nothing to review when the order already carries every proposed number.
        $dupes  = self::already_recorded(self::shipments_of($order), $p['payload']['tracking_numbers']);
        $status = count($dupes) === count($p['payload']['tracking_numbers']) ? self::SUPERSEDED : self::PENDING;
        $note   = $status === self::SUPERSEDED ? 'Already recorded on the order.' : null;

        $t   = Ledger_Schema::proposals_table();
        $now = self::now();
        $sql = "INSERT IGNORE INTO $t (source, source_ref, order_id, kind, payload_json, evidence_json, summary, status, status_note, created_by, created_at, updated_at)"
             . ' VALUES (%s, %s, %d, %s, %s, %s, %s, %s, ' . ($note === null ? 'NULL' : '%s') . ', %d, %s, %s)';
        $args = [$p['source'], $p['source_ref'], $p['order_id'], $p['kind'], wp_json_encode($p['payload']),
                 wp_json_encode((object) $p['evidence']), $p['summary'], $status];
        if ($note !== null) $args[] = $note;
        array_push($args, $user_id, $now, $now);
        $inserted = (int) $wpdb->query($wpdb->prepare($sql, $args));

        $row = self::find($p['source'], $p['source_ref']);
        if (!$row) return ['error' => 'Could not save the proposal.', 'status' => 500];
        if ($inserted) {
            // A racing duplicate request loses the INSERT IGNORE and files no second event.
            self::event($row, 'proposal_received', substr($p['source'], 0, 16), $user_id, ['status' => $status] + ($note ? ['note' => $note] : []));
        }
        return ['proposal' => $row, 'created' => (bool) $inserted];
    }

    /**
     * Move a proposal from one status to another. Guarded: only succeeds from the expected status,
     * so two people (or a person and a withdraw call) cannot both decide it.
     */
    public static function transition(int $id, string $from, string $to, array $set = []): bool {
        global $wpdb;
        $t     = Ledger_Schema::proposals_table();
        $cols  = ['status = %s', 'updated_at = %s'];
        $args  = [$to, self::now()];
        foreach (['status_note' => '%s', 'decided_by' => '%d', 'decided_at' => '%s', 'result_json' => '%s'] as $k => $f) {
            if (!array_key_exists($k, $set)) continue;
            if ($set[$k] === null) { $cols[] = "$k = NULL"; continue; }
            $cols[] = "$k = $f";
            $args[] = $k === 'status_note' ? substr((string) $set[$k], 0, self::MAX_NOTE) : $set[$k];
        }
        $args[] = $id;
        $args[] = $from;
        return (int) $wpdb->query($wpdb->prepare("UPDATE $t SET " . implode(', ', $cols) . ' WHERE id = %d AND status = %s', $args)) === 1;
    }

    /** One event per proposal per step; the dedupe key collapses replays. */
    public static function event(array $row, string $action, string $source, int $user_id, array $extra = []): void {
        $payload = json_decode((string) $row['payload_json'], true) ?: [];
        Ledger_Events::append_generated([
            'order_id'      => (int) $row['order_id'],
            'ledger_id'     => null,
            'order_item_id' => null,
            'user_id'       => $user_id,
            'source'        => $source,
            'action'        => $action,
            'payload'       => [
                'proposal_id'      => (int) $row['id'],
                'from'             => (string) $row['source'],
                'carrier'          => (string) ($payload['carrier'] ?? ''),
                'tracking_numbers' => (array) ($payload['tracking_numbers'] ?? []),
            ] + $extra,
            'dedupe_key'    => 'prop:' . (int) $row['id'] . ':' . $action . (isset($extra['attempt']) ? ':' . (int) $extra['attempt'] : ''),
        ]);
    }

    /** Withdrawn by the system that filed it. Only a pending proposal can be withdrawn. */
    public static function withdraw(int $id, string $reason, int $user_id): bool {
        $row = self::get($id);
        if (!$row) return false;
        $reason = trim($reason) !== '' ? trim($reason) : 'Withdrawn by ' . $row['source'] . '.';
        if (!self::transition($id, self::PENDING, self::SUPERSEDED, ['status_note' => $reason])) return false;
        self::event($row, 'proposal_withdrawn', substr((string) $row['source'], 0, 16), $user_id, ['note' => $reason]);
        return true;
    }

    public static function reject(int $id, string $reason, int $user_id): bool {
        $row = self::get($id);
        if (!$row) return false;
        if (!self::transition($id, self::PENDING, self::REJECTED, [
            'status_note' => $reason, 'decided_by' => $user_id, 'decided_at' => self::now(),
        ])) return false;
        self::event($row, 'proposal_rejected', 'ledger_ui', $user_id, ['note' => substr($reason, 0, self::MAX_NOTE)]);
        return true;
    }

    /**
     * Approve: claim the proposal, run the existing shipment mutation, record the outcome.
     *
     * @param array    $requested [order_item_id => qty] confirmed by the reviewer
     * @param callable $ship      fn(int $order_id, array $body): array|string — runs the mutation and
     *                            returns its response, or an error message. Injected so the SQL-level
     *                            tests can run without WordPress REST.
     * @return array ['ok' => bool, 'message' => string, 'status' => http]
     */
    public static function approve(int $id, array $requested, bool $notify, int $user_id, callable $ship): array {
        $row = self::get($id);
        if (!$row) return ['ok' => false, 'message' => 'Proposal not found.', 'status' => 404];
        if ($row['status'] !== self::PENDING) {
            return ['ok' => false, 'message' => 'This proposal is no longer waiting (' . $row['status'] . ').', 'status' => 409];
        }
        $oid     = (int) $row['order_id'];
        $payload = json_decode((string) $row['payload_json'], true) ?: [];
        $numbers = (array) ($payload['tracking_numbers'] ?? []);

        $order = wc_get_order($oid);
        $rows  = $order ? self::open_rows($oid) : [];
        if (!$order || !$rows) {
            if (self::transition($id, self::PENDING, self::SUPERSEDED, ['status_note' => 'The order is no longer open.'])) {
                self::event($row, 'proposal_superseded', 'projector', 0, ['note' => 'The order is no longer open.']);
            }
            return ['ok' => false, 'message' => 'The order is no longer open. The proposal was closed.', 'status' => 409];
        }
        if (self::already_recorded(self::shipments_of($order), $numbers)) {
            if (self::transition($id, self::PENDING, self::SUPERSEDED, ['status_note' => 'Already recorded on the order.'])) {
                self::event($row, 'proposal_superseded', 'projector', 0, ['note' => 'Already recorded on the order.']);
            }
            return ['ok' => false, 'message' => 'That tracking is already on the order. The proposal was closed.', 'status' => 409];
        }
        $lines = self::confirm_lines($rows, $requested);
        if (is_string($lines)) return ['ok' => false, 'message' => $lines, 'status' => 400];
        $blocker = self::completion_blocker($rows, $lines);
        if ($blocker !== null) return ['ok' => false, 'message' => $blocker, 'status' => 409];

        // Claim. A second approver, a reject or a withdraw now fails its own guarded transition.
        if (!self::transition($id, self::PENDING, self::APPLYING, ['decided_by' => $user_id, 'decided_at' => self::now()])) {
            return ['ok' => false, 'message' => 'Someone else just decided this proposal.', 'status' => 409];
        }

        $body = [
            'carrier'          => (string) ($payload['carrier'] ?? ''),
            'other_carrier'    => (string) ($payload['other_carrier'] ?? ''),
            'tracking_numbers' => array_values(array_map('strval', $numbers)),
            'lines'            => $lines,
            'notify'           => $notify,
            'closeout'         => false,   // completion stays with the partial-ship rule: all quantity shipped
        ];
        try {
            $res = $ship($oid, $body);
        } catch (Throwable $e) {
            $res = $e->getMessage() !== '' ? $e->getMessage() : 'The shipment could not be recorded.';
        }

        // Believe the order, never the response. Seen on staging 2026-11-23: when the order refuses to
        // save (WooCommerce swallows the exception), ship-lines still answers success with nothing stored.
        // The reverse also happens: an error after the shipment was saved.
        $fresh    = wc_get_order($oid);
        $recorded = $fresh ? self::already_recorded(self::shipments_of($fresh), $numbers) : [];
        if (count($recorded) !== count($numbers)) {
            $why = is_string($res) ? $res : 'The order did not keep the shipment: its save was refused. Check the order notes.';
            $attempt = self::failed_attempts($id) + 1;
            self::transition($id, self::APPLYING, self::PENDING, [
                'status_note' => 'Last attempt failed: ' . $why, 'decided_by' => 0, 'decided_at' => null,
            ]);
            self::event($row, 'proposal_failed', 'ledger_ui', $user_id, ['note' => substr($why, 0, self::MAX_NOTE), 'attempt' => $attempt]);
            return ['ok' => false, 'message' => 'Not recorded: ' . $why, 'status' => 502];
        }
        if (is_string($res)) $res = ['warning' => $res];

        self::transition($id, self::APPLYING, self::APPLIED, [
            'status_note' => isset($res['warning']) ? 'Recorded, with a warning: ' . $res['warning'] : null,
            'result_json' => wp_json_encode([
                'order_status' => $fresh->get_status(),
                'shipped'      => $res['shipped'] ?? null,
                'total'        => $res['total'] ?? null,
                'lines'        => $lines,
                'notified'     => $notify,
            ]),
        ]);
        self::event($row, 'proposal_approved', 'ledger_ui', $user_id, ['lines' => $lines, 'notified_customer' => $notify]);
        Ledger_Projector::project($oid, ['source' => 'ledger_ui', 'user_id' => $user_id]);

        return ['ok' => true, 'status' => 200, 'message' => isset($res['warning'])
            ? 'Recorded, but the order reported: ' . $res['warning']
            : 'Shipment recorded.'];
    }

    private static function failed_attempts(int $id): int {
        global $wpdb;
        $et = Ledger_Schema::events_table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $et WHERE action = 'proposal_failed' AND dedupe_key LIKE %s", 'prop:' . $id . ':proposal_failed:%'
        ));
    }

    /**
     * Housekeeping, run before every list:
     *  - a proposal stuck in 'applying' (the approving request died) is settled from the order itself;
     *  - a pending proposal whose order closed, or whose tracking a person has since typed in, is closed.
     *
     * @return int proposals changed
     */
    public static function settle(?int $now_ts = null): int {
        global $wpdb;
        $t       = Ledger_Schema::proposals_table();
        $now_ts  = $now_ts ?? time();
        $cutoff  = gmdate('Y-m-d H:i:s', $now_ts - self::STALE_APPLYING);
        $changed = 0;

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE status = %s OR (status = %s AND updated_at < %s) ORDER BY id ASC LIMIT 500",
            self::PENDING, self::APPLYING, $cutoff
        ), ARRAY_A);

        foreach ($rows as $row) {
            $id      = (int) $row['id'];
            $payload = json_decode((string) $row['payload_json'], true) ?: [];
            $numbers = (array) ($payload['tracking_numbers'] ?? []);
            $order   = wc_get_order((int) $row['order_id']);
            $on_order = $order ? self::already_recorded(self::shipments_of($order), $numbers) : [];

            if ($row['status'] === self::APPLYING) {
                if ($on_order) {
                    $ok = self::transition($id, self::APPLYING, self::APPLIED, ['status_note' => 'Recorded; the approving request did not finish.']);
                    if ($ok) self::event($row, 'proposal_approved', 'ledger_ui', (int) $row['decided_by'], ['note' => 'Settled from the order.']);
                } else {
                    $ok = self::transition($id, self::APPLYING, self::PENDING, [
                        'status_note' => 'An approval did not finish; nothing was recorded.', 'decided_by' => 0, 'decided_at' => null,
                    ]);
                }
                $changed += $ok ? 1 : 0;
                continue;
            }

            $note = null;
            if ($numbers && count($on_order) === count($numbers)) {
                $note = 'Already recorded on the order.';
            } elseif (!$order || !self::open_rows((int) $row['order_id'])) {
                $note = 'The order is no longer open.';
            }
            if ($note !== null && self::transition($id, self::PENDING, self::SUPERSEDED, ['status_note' => $note])) {
                self::event($row, 'proposal_superseded', 'projector', 0, ['note' => $note]);
                $changed++;
            }
        }
        return $changed;
    }

    /**
     * Proposals for the ledger screen or for the system that filed them.
     *
     * @param array $f status (string|string[]), source, updated_since (UTC 'Y-m-d H:i:s'), order_id, limit
     */
    public static function query(array $f = []): array {
        global $wpdb;
        $t     = Ledger_Schema::proposals_table();
        $where = ['1=1'];
        $args  = [];
        if (!empty($f['status'])) {
            $st      = array_values(array_intersect((array) $f['status'], self::STATUSES));
            if (!$st) return [];
            $where[] = 'status IN (' . implode(',', array_fill(0, count($st), '%s')) . ')';
            array_push($args, ...$st);
        }
        if (!empty($f['source']))        { $where[] = 'source = %s';      $args[] = (string) $f['source']; }
        if (!empty($f['updated_since'])) { $where[] = 'updated_at >= %s'; $args[] = (string) $f['updated_since']; }
        if (!empty($f['order_id']))      { $where[] = 'order_id = %d';    $args[] = (int) $f['order_id']; }
        $limit  = max(1, min(500, (int) ($f['limit'] ?? 100)));
        $args[] = $limit;
        $order  = !empty($f['updated_since']) ? 'updated_at ASC, id ASC' : 'id DESC';
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE " . implode(' AND ', $where) . " ORDER BY $order LIMIT %d", $args
        ), ARRAY_A);
    }

    public static function pending_count(): int {
        global $wpdb;
        $t = Ledger_Schema::proposals_table();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE status = %s", self::PENDING));
    }

    /** The shape handed back to the filing system. No internal user ids beyond the decider's display name. */
    public static function present(array $r): array {
        $iso = fn($utc) => $utc ? str_replace(' ', 'T', $utc) . 'Z' : null;
        return [
            'id'          => (int) $r['id'],
            'source'      => $r['source'],
            'source_ref'  => $r['source_ref'],
            'order_id'    => (int) $r['order_id'],
            'kind'        => $r['kind'],
            'status'      => $r['status'],
            'status_note' => $r['status_note'],
            'payload'     => json_decode((string) $r['payload_json'], true) ?: [],
            'summary'     => (string) $r['summary'],
            'evidence'    => json_decode((string) $r['evidence_json'], true) ?: [],
            'result'      => $r['result_json'] ? (json_decode((string) $r['result_json'], true) ?: null) : null,
            'decided_at'  => $iso($r['decided_at']),
            'created_at'  => $iso($r['created_at']),
            'updated_at'  => $iso($r['updated_at']),
        ];
    }
}
