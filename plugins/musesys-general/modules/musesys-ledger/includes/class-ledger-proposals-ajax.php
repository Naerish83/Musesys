<?php
/**
 * Ledger screen handlers for proposed updates — LEDGER_SPEC.md §15.4.
 *
 * Listing needs the view capability. Approve and reject need musesys_ledger_approve, and approve
 * also needs whatever the existing ship-lines endpoint demands (it runs as the approver).
 */

if (!defined('ABSPATH')) exit;

final class Ledger_Proposals_Ajax {
    const RECENT_DAYS = 7;

    public static function init(): void {
        add_action('wp_ajax_musesys_ledger_proposals', [__CLASS__, 'list_proposals']);
        add_action('wp_ajax_musesys_ledger_proposal_approve', [__CLASS__, 'approve']);
        add_action('wp_ajax_musesys_ledger_proposal_reject', [__CLASS__, 'reject']);
    }

    private static function guard(string $cap): void {
        if (!check_ajax_referer(Ledger_Ajax::NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => 'Session expired — reload the page.'], 403);
        }
        if (!is_user_logged_in() || !current_user_can($cap)) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
    }

    public static function list_proposals(): void {
        self::guard(Ledger_Access::CAP_VIEW);
        wp_send_json_success(self::payload());
    }

    public static function payload(): array {
        Ledger_Proposals::settle();
        $since   = gmdate('Y-m-d H:i:s', time() - self::RECENT_DAYS * DAY_IN_SECONDS);
        $waiting = Ledger_Proposals::query(['status' => [Ledger_Proposals::PENDING, Ledger_Proposals::APPLYING], 'limit' => 200]);
        $recent  = array_filter(
            Ledger_Proposals::query(['status' => Ledger_Proposals::FINAL, 'limit' => 100]),
            fn($r) => $r['updated_at'] >= $since
        );
        return [
            'pending'     => array_map([__CLASS__, 'present'], array_reverse($waiting)),   // oldest first
            'recent'      => array_map([__CLASS__, 'present'], array_values($recent)),
            'can_approve' => current_user_can(Ledger_Access::CAP_APPROVE),
            'carriers'    => Ledger_Proposals::CARRIERS,
        ];
    }

    /** A proposal plus what the reviewer needs to decide: the order's open lines and what approving will do. */
    private static function present(array $r): array {
        $out  = Ledger_Proposals::present($r);
        $oid  = (int) $r['order_id'];
        $rows = Ledger_Proposals::open_rows($oid);
        $head = $rows[0] ?? [];

        $out['order'] = [
            'trx'          => (string) ($head['trx'] ?? ''),
            'po_number'    => (string) ($head['po_number'] ?? ''),
            'store'        => (string) ($head['store'] ?? ''),
            'is_drop_ship' => (int) ($head['is_drop_ship'] ?? 0),
            'edit_url'     => '',
        ];
        if (!Ledger_Access::is_locked_agent() && current_user_can('edit_shop_orders')) {
            $out['order']['edit_url'] = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
                ? \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url($oid)
                : admin_url('post.php?post=' . $oid . '&action=edit');
        }
        $out['lines'] = Ledger_Proposals::suggest_lines($rows, (array) ($out['payload']['lines'] ?? []));

        $out['has_sc_work_order'] = false;
        if ($r['status'] === Ledger_Proposals::PENDING && ($order = wc_get_order($oid))) {
            $out['has_sc_work_order'] = (bool) Ledger_Ajax::sc_info($order)['has_sc_work_order'];
        }

        $uid = (int) $r['decided_by'];
        $u   = $uid ? get_userdata($uid) : null;
        $out['decided_by'] = $uid ? ($u ? $u->display_name : 'User #' . $uid) : '';
        return $out;
    }

    public static function approve(): void {
        self::guard(Ledger_Access::CAP_APPROVE);
        $id     = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $notify = !empty($_POST['notify_customer']) && $_POST['notify_customer'] === '1';
        $lines  = json_decode(isset($_POST['lines']) ? (string) wp_unslash($_POST['lines']) : '', true);
        if (!is_array($lines)) wp_send_json_error(['message' => 'Choose at least one line and a quantity to ship.'], 400);

        $requested = [];
        foreach ($lines as $item => $qty) $requested[absint($item)] = absint($qty);

        $res = Ledger_Proposals::approve($id, $requested, $notify, get_current_user_id(), [__CLASS__, 'ship']);
        if (!$res['ok']) wp_send_json_error(['message' => $res['message'], 'proposals' => self::payload()], $res['status']);
        wp_send_json_success(['message' => $res['message'], 'proposals' => self::payload()]);
    }

    /**
     * The existing shipment mutation, called exactly as UACP calls it: the partial-ship module's
     * ship-lines endpoint, dispatched in-process as the approving user. Nothing about recording a
     * shipment is reimplemented here (§6.2, §15.5).
     *
     * @return array|string the endpoint's response, or an error message
     */
    public static function ship(int $order_id, array $body) {
        $req = new WP_REST_Request('POST', '/musesys/v1/orders/' . $order_id . '/ship-lines');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode($body));

        $res = rest_do_request($req);
        if ($res->is_error()) {
            $err = $res->as_error();
            return $err->get_error_message() !== '' ? $err->get_error_message() : 'The shipment endpoint refused the request.';
        }
        $data = $res->get_data();
        return is_array($data) ? $data : 'The shipment endpoint returned no result.';
    }

    public static function reject(): void {
        self::guard(Ledger_Access::CAP_APPROVE);
        $id     = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $reason = isset($_POST['reason']) ? mb_substr(sanitize_textarea_field(wp_unslash($_POST['reason'])), 0, Ledger_Proposals::MAX_NOTE) : '';
        if (trim($reason) === '') wp_send_json_error(['message' => 'Say why this is being rejected.'], 400);
        if (!Ledger_Proposals::get($id)) wp_send_json_error(['message' => 'Proposal not found.'], 404);

        if (!Ledger_Proposals::reject($id, $reason, get_current_user_id())) {
            wp_send_json_error(['message' => 'This proposal is no longer waiting.', 'proposals' => self::payload()], 409);
        }
        wp_send_json_success(['message' => 'Proposal rejected.', 'proposals' => self::payload()]);
    }
}
