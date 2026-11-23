<?php
/**
 * Ledger AJAX endpoints — LEDGER_SPEC.md §6, §7.2, §8.
 * Each handler independently checks nonce, login, capability and (for writes) that the order
 * has open ledger rows. There is no generic edit endpoint.
 */

if (!defined('ABSPATH')) exit;

final class Ledger_Ajax {
    const NONCE = 'musesys_ledger';

    const AGENT_STATES = ['CALL_REQUIRED', 'NEEDS_ETA', 'SHIPPED_NOT_CLOSED'];

    public static function init(): void {
        $map = [
            'musesys_ledger_list'      => 'list_rows',
            'musesys_ledger_history'   => 'history',
            'musesys_ledger_log_email' => 'log_email',
            'musesys_ledger_log_call'  => 'log_call',
            'musesys_ledger_note'      => 'note',
            'musesys_ledger_set_eta'   => 'set_eta',
        ];
        foreach ($map as $action => $method) {
            add_action('wp_ajax_' . $action, [__CLASS__, $method]);
        }
    }

    /* -------------------- guards -------------------- */

    private static function guard(string $cap): void {
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => 'Session expired — reload the page.'], 403);
        }
        if (!is_user_logged_in() || !current_user_can($cap)) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
    }

    /** Write target must be an order with at least one non-closed ledger row. */
    private static function open_order_id(): int {
        global $wpdb;
        $oid = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $lt  = Ledger_Schema::ledger_table();
        $n   = $oid ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $lt WHERE order_id = %d AND state <> 'CLOSED'", $oid)) : 0;
        if ($n === 0) wp_send_json_error(['message' => 'This order is not open in the ledger.'], 404);
        return $oid;
    }

    private static function text(): string {
        return isset($_POST['text']) ? (string) wp_unslash($_POST['text']) : '';
    }

    /* -------------------- reads -------------------- */

    public static function list_rows(): void {
        self::guard(Ledger_Access::CAP_VIEW);
        wp_send_json_success(self::list_payload());
    }

    /** Ledger rows as seen by the agent view or the admin/viewer view. Shared by the page and the digest email. */
    public static function rows_for(bool $agent_view, ?int $only_order = null): array {
        global $wpdb;
        $lt    = Ledger_Schema::ledger_table();
        $where = $agent_view
            ? "state IN ('" . implode("','", self::AGENT_STATES) . "')"
            : "state NOT IN ('CLOSED', '')";
        if ($only_order) $where .= $wpdb->prepare(' AND order_id = %d', $only_order);
        return (array) $wpdb->get_results("SELECT * FROM $lt WHERE $where ORDER BY order_id ASC, order_item_id ASC", ARRAY_A);
    }

    /**
     * Open ON_TRACK rows due within the coming-due window.
     * Order by due_at ASC so the earliest obligations appear first.
     */
    public static function rows_coming_due(?int $only_order = null, ?DateTimeImmutable $now = null): array {
        global $wpdb;
        $now    = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff = Ledger_State_Resolver::coming_due_cutoff($now)->format('Y-m-d H:i:s');
        $lt     = Ledger_Schema::ledger_table();
        $where  = "state = 'ON_TRACK' AND due_at IS NOT NULL AND due_at <= %s";
        $params = [$cutoff];
        if ($only_order) {
            $where   .= ' AND order_id = %d';
            $params[] = $only_order;
        }
        $sql = $wpdb->prepare("SELECT * FROM $lt WHERE $where ORDER BY due_at ASC, order_id ASC, order_item_id ASC", ...$params);
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /** The summary-strip counts for a set of rows (§8). */
    public static function summarize(array $rows): array {
        $summary = ['NEEDS_ETA' => 0, 'CALL_REQUIRED' => 0, 'SHIPPED_NOT_CLOSED' => 0, 'ON_TRACK' => 0,
                    'MISSING_TRX' => 0, 'EXCEPTION' => 0, 'partial' => 0, 'late2' => 0];
        foreach ($rows as $r) {
            if (isset($summary[$r['state']])) $summary[$r['state']]++;
            if ((int) $r['qty_shipped'] > 0 && (int) $r['qty_shipped'] < (int) $r['qty_ordered']) $summary['partial']++;
            if ((int) $r['actionable'] === 1 && (int) $r['overdue_bdays'] >= 2) $summary['late2']++;
        }
        return $summary;
    }

    public static function list_payload(?int $only_order = null): array {
        $agent = Ledger_Access::is_locked_agent();
        $now   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $rows  = self::rows_for($agent, $only_order);

        // Summarize uses ONLY the base rows (coming-due excluded from existing counts).
        $summary = $only_order ? [] : self::summarize($rows);

        if ($agent) {
            $due_rows = self::rows_coming_due($only_order, $now);
            $all_rows = array_merge($rows, $due_rows);
        } else {
            $all_rows = $rows;
        }

        $out_rows = array_map(fn($r) => self::present_row($r, $now), $all_rows);

        if ($only_order) return ['rows' => $out_rows];

        $today = (new DateTimeImmutable('today', new DateTimeZone(Ledger_State_Resolver::TZ)))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $today_counts = Ledger_Events::ui_counts_since($today);
        $summary['actions_today'] = array_sum(array_map(fn($c) => (int) $c['n'], $today_counts));

        $payload = [
            'rows'        => $out_rows,
            'summary'     => $summary,
            'agent_view'  => $agent,
            'can_manage'  => current_user_can(Ledger_Access::CAP_MANAGE),
            'eta_sources' => Ledger_Audit::SOURCES,
        ];

        if (!$agent) {
            $payload['user_stats'] = [
                'today'  => self::user_stats($today_counts),
                'period' => self::user_stats(Ledger_Events::ui_counts_since(Ledger_Schema::installed_at())),
                'since'  => Ledger_Schema::installed_at(),
            ];
            $payload['audit'] = Ledger_Audit::payload();
        }
        return $payload;
    }

    private static function user_stats(array $counts): array {
        $by = [];
        foreach ($counts as $c) {
            $uid = (int) $c['user_id'];
            if (!isset($by[$uid])) {
                $u = get_userdata($uid);
                $by[$uid] = ['name' => $u ? $u->display_name : ('User #' . $uid), 'email_logged' => 0, 'call_logged' => 0, 'eta_set' => 0, 'note' => 0];
            }
            if (isset($by[$uid][$c['action']])) $by[$uid][$c['action']] = (int) $c['n'];
        }
        return array_values($by);
    }

    public static function present_row(array $r, ?DateTimeImmutable $now = null): array {
        $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $edit = '';
        if (!Ledger_Access::is_locked_agent() && current_user_can('edit_shop_orders')) {
            $edit = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
                ? \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url((int) $r['order_id'])
                : admin_url('post.php?post=' . (int) $r['order_id'] . '&action=edit');
        }
        return [
            'id'                  => (int) $r['id'],
            'order_id'            => (int) $r['order_id'],
            'order_item_id'       => (int) $r['order_item_id'],
            'trx'                 => $r['trx'],
            'po_number'           => $r['po_number'],
            'is_drop_ship'        => (int) $r['is_drop_ship'],
            'store'               => $r['store'],
            'part_label'          => $r['part_label'],
            'qty_ordered'         => (int) $r['qty_ordered'],
            'qty_shipped'         => (int) $r['qty_shipped'],
            'tracking_count'      => (int) $r['tracking_count'],
            'logistics_status'    => $r['logistics_status'],
            'eta'                 => $r['eta'],
            'confirmed_at'        => self::iso($r['confirmed_at']),
            'confirmed_at_source' => $r['confirmed_at_source'],
            'state'               => $r['state'],
            'state_reason'        => $r['state_reason'],
            'due_at'              => self::iso($r['due_at']),
            'overdue_bdays'       => (int) $r['overdue_bdays'],
            'data_flags'          => $r['data_flags'] ? (is_array($r['data_flags']) ? $r['data_flags'] : explode(',', $r['data_flags'])) : [],
            'last_email_at'       => self::iso($r['last_email_at']),
            'last_call_at'        => self::iso($r['last_call_at']),
            'last_action_at'      => self::iso($r['last_action_at']),
            'actionable'          => (int) $r['actionable'],
            'coming_due'          => Ledger_State_Resolver::is_coming_due($r, $now) ? 1 : 0,
            'edit_url'            => $edit,
        ];
    }

    private static function iso($utc): ?string {
        return $utc ? str_replace(' ', 'T', $utc) . 'Z' : null;
    }

    public static function history(): void {
        global $wpdb;
        self::guard(Ledger_Access::CAP_VIEW);
        $lid = isset($_POST['ledger_id']) ? absint($_POST['ledger_id']) : 0;
        $lt  = Ledger_Schema::ledger_table();
        $row = $lid ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $lt WHERE id = %d", $lid), ARRAY_A) : null;
        if (!$row) wp_send_json_error(['message' => 'Not found'], 404);
        if (Ledger_Access::is_locked_agent() && !in_array($row['state'], self::AGENT_STATES, true)) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if (!Ledger_State_Resolver::is_coming_due($row, $now)) {
                wp_send_json_error(['message' => 'Not found'], 404);
            }
        }
        $oid = (int) $row['order_id'];

        $events = [];
        $names  = [];
        foreach (Ledger_Events::for_row($lid, $oid) as $e) {
            $uid = (int) $e['user_id'];
            if (!isset($names[$uid])) {
                $u = $uid ? get_userdata($uid) : null;
                $names[$uid] = $uid === 0 ? 'System' : ($u ? $u->display_name : 'User #' . $uid);
            }
            $events[] = [
                'id'         => (int) $e['id'],
                'action'     => $e['action'],
                'source'     => $e['source'],
                'actor'      => $names[$uid],
                'scope'      => $e['ledger_id'] ? 'line' : 'order',
                'payload'    => json_decode((string) $e['payload_json'], true) ?: [],
                'created_at' => self::iso($e['created_at']),
            ];
        }

        // Shipments touching this line, read from the partial-ship source of truth.
        $shipments = [];
        $sc        = ['has_sc_work_order' => false, 'sc_hash' => '', 'logistics_status' => '', 'logistics_date' => ''];
        $order     = wc_get_order($oid);
        if ($order) {
            $raw = json_decode((string) $order->get_meta('_muse_shipments', true), true);
            foreach ((array) $raw as $i => $s) {
                if (!is_array($s)) continue;
                $qty = (int) ($s['items'][$row['order_item_id']] ?? 0);
                if ($qty <= 0) continue;
                $shipments[] = [
                    'index'            => (int) $i,
                    'qty'              => $qty,
                    'carrier'          => (string) ($s['carrier'] ?? ''),
                    'other_carrier'    => (string) ($s['other_carrier'] ?? ''),
                    'tracking_numbers' => array_values(array_filter(array_map('strval', (array) ($s['tracking_numbers'] ?? [])))),
                    'date'             => (string) ($s['date'] ?? ''),
                ];
            }
            $sc = self::sc_info($order);
        }

        $open_lines = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $lt WHERE order_id = %d AND state NOT IN ('CLOSED','')", $oid
        ));

        wp_send_json_success([
            'events'     => $events,
            'shipments'  => $shipments,
            'sc'         => $sc,
            'open_lines' => $open_lines,
        ]);
    }

    /**
     * Mirrors the conditions under which the existing logistics + Service Channel automation
     * sends (muse-logistics-manager::apply_logistics, muse-email-manager::send_sc_automation).
     * Read-only: used to tell the user what will happen, never to decide whether it happens.
     */
    public static function sc_info(WC_Order $order): array {
        $has_sc = false;
        $wos = function_exists('muse_get_all_work_orders') ? (array) muse_get_all_work_orders($order->get_id()) : [];
        foreach ($wos as $wo) {
            if (is_array($wo) && ($wo['type'] ?? '') === 'sc') { $has_sc = true; break; }
        }
        if (!$wos) {
            $has_sc = $order->get_meta('_muse_track_primary', true) && $order->get_meta('_muse_track_status', true) === 'sc';
        }
        return [
            'has_sc_work_order' => (bool) $has_sc,
            'sc_hash'           => (string) $order->get_meta('_muse_sc_last_logistics_hash', true),
            'logistics_status'  => (string) ($order->get_meta('_muse_logistics_status', true) ?: 'in_stock'),
            'logistics_date'    => (string) $order->get_meta('_muse_logistics_date', true),
        ];
    }

    private static function sc_expected(array $sc, string $new_date): bool {
        $changed = $sc['logistics_status'] !== 'backordered' || $sc['logistics_date'] !== $new_date;
        return $sc['has_sc_work_order'] && $changed && $sc['sc_hash'] !== 'backordered|' . $new_date;
    }

    /* -------------------- writes -------------------- */

    public static function log_email(): void { self::human('email_logged', false); }
    public static function log_call(): void  { self::human('call_logged', false); }
    public static function note(): void      { self::human('note', true); }

    private static function human(string $action, bool $text_required): void {
        global $wpdb;
        self::guard(Ledger_Access::CAP_MANAGE);
        $oid  = self::open_order_id();
        $text = trim(self::text());
        if ($text_required && $text === '') wp_send_json_error(['message' => 'Note text is required.'], 400);

        // Notes may target one line; email/call are always order-scoped (D7).
        $lid = null;
        if ($action === 'note' && !empty($_POST['ledger_id'])) {
            $lid = absint($_POST['ledger_id']);
            $lt  = Ledger_Schema::ledger_table();
            $ok  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $lt WHERE id = %d AND order_id = %d", $lid, $oid));
            if (!$ok) wp_send_json_error(['message' => 'Line not on this order.'], 400);
        }
        $extra = [];
        if ($action === 'call_logged' && !empty($_POST['outcome'])) {
            $extra['outcome'] = mb_substr(sanitize_text_field(wp_unslash($_POST['outcome'])), 0, 100);
        }

        if (!Ledger_Events::append_human($oid, $lid, $action, $text, get_current_user_id(), $extra)) {
            wp_send_json_error(['message' => 'Could not save.'], 500);
        }
        Ledger_Projector::resolve_orders([$oid]);
        wp_send_json_success(self::list_payload($oid));
    }

    public static function set_eta(): void {
        self::guard(Ledger_Access::CAP_MANAGE);
        $oid    = self::open_order_id();
        $date   = isset($_POST['eta']) ? sanitize_text_field(wp_unslash($_POST['eta'])) : '';
        $notify = !empty($_POST['notify_customer']) && $_POST['notify_customer'] === '1';

        // ETA basis (Ledger_Audit): where the date came from. Required; merged into the eta_set payload.
        $basis_src     = isset($_POST['eta_source']) ? sanitize_key(wp_unslash($_POST['eta_source'])) : '';
        $basis_contact = isset($_POST['eta_contact']) ? mb_substr(sanitize_text_field(wp_unslash($_POST['eta_contact'])), 0, 100) : '';
        $basis_note    = isset($_POST['eta_note']) ? mb_substr(sanitize_textarea_field(wp_unslash($_POST['eta_note'])), 0, 500) : '';
        $basis_err     = Ledger_Audit::validate_eta_basis($basis_src, $basis_contact, $basis_note);
        if ($basis_err) wp_send_json_error(['message' => $basis_err], 400);

        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(Ledger_State_Resolver::TZ));
        if (!$d || $d->format('Y-m-d') !== $date) wp_send_json_error(['message' => 'Enter a valid date.'], 400);
        $today = (new DateTimeImmutable('now', new DateTimeZone(Ledger_State_Resolver::TZ)))->format('Y-m-d');
        if ($date < $today) wp_send_json_error(['message' => 'The ETA must be today or later.'], 400);

        if (!class_exists('Muse_Logistics_Manager') || !method_exists('Muse_Logistics_Manager', 'apply_logistics')) {
            wp_send_json_error(['message' => 'Logistics module unavailable.'], 500);
        }
        $order = wc_get_order($oid);
        if (!$order) wp_send_json_error(['message' => 'Order not found.'], 404);

        $sc = self::sc_info($order);
        if ($sc['logistics_status'] === 'backordered' && $sc['logistics_date'] === $date) {
            wp_send_json_error(['message' => 'That is already the ETA for this order.'], 400);
        }
        $sc_expected = self::sc_expected($sc, $date);

        // The existing domain mutation — the ledger never writes ETA itself (§6.1).
        Muse_Logistics_Manager::apply_logistics($order, 'backordered', $date, $notify);

        Ledger_Projector::project($oid, [
            'source'      => 'ledger_ui',
            'user_id'     => get_current_user_id(),
            'attribution' => ['action' => 'eta_set', 'payload' => array_filter([
                'notified_customer' => $notify,
                'sc_expected'       => $sc_expected,
                'eta_source'        => $basis_src,
                'eta_contact'       => $basis_contact !== '' ? $basis_contact : null,
                'eta_note'          => $basis_note !== '' ? $basis_note : null,
                'audit_v'           => Ledger_Audit::VERSION,
            ], fn($v) => $v !== null)],
        ]);
        wp_send_json_success(self::list_payload($oid));
    }
}
