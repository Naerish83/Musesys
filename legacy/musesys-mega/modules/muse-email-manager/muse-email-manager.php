<?php
/**
 * Plugin Name: Muse Email Manager
 * Description: Centralized email template management for MuseSys
 * Version: 1.0.1
 */

/**
 * Retrieve all work orders from an order, classified by type.
 * Returns array of [ 'number' => '123456789', 'type' => 'sc'|'eam' ]
 */
if ( ! function_exists( 'muse_get_all_work_orders' ) ) {
    function muse_get_all_work_orders( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return array();

        $found = array();
        $seen  = array();

        // Helper: classify and collect a WO number
        $collect = function( $raw ) use ( &$found, &$seen ) {
            // Strip any prefix garbage (WO#-, WO-, etc)
            $cleaned = preg_replace( '/^[A-Z#\-\s]+/i', '', trim( $raw ) );
            $cleaned = preg_replace( '/[^0-9]/', '', $cleaned );
            $len = strlen( $cleaned );
            if ( ( $len === 7 || $len === 9 ) && ! isset( $seen[ $cleaned ] ) ) {
                $seen[ $cleaned ] = true;
                $found[] = array(
                    'number' => $cleaned,
                    'type'   => ( $len === 9 ) ? 'sc' : 'eam',
                );
            }
        };

        // 1. Structured work orders from tracking core
        $work_orders = $order->get_meta( '_work_orders', true );
        if ( is_array( $work_orders ) ) {
            foreach ( $work_orders as $wo ) {
                if ( ! empty( $wo['number'] ) ) {
                    $collect( $wo['number'] );
                }
            }
        }

        // 2. Global WO from core standard / legacy fallbacks
        $global = $order->get_meta( '_muse_wo_global', true );
        if ( empty( $global ) ) $global = $order->get_meta( 'muse_global_work_order' );
        if ( empty( $global ) ) $global = $order->get_meta( '_tracking_number', true );
        if ( ! empty( $global ) ) $collect( $global );

        // 3. Per-line WO numbers - prefer core key first
        foreach ( $order->get_items() as $item ) {
            $line_wo = $item->get_meta( '_muse_wo_line' );
            if ( empty( $line_wo ) ) $line_wo = $item->get_meta( 'WO Number' );
            if ( ! empty( $line_wo ) ) $collect( $line_wo );
        }

        return $found;
    }
}

/**
 * Get a human-readable tracking display string for a PR or order.
 * Replaces the missing gerhardt_get_muse_track_status() function.
 */
if ( ! function_exists( 'muse_get_tracking_display' ) ) {
    function muse_get_tracking_display( $post_id ) {
        $post_type = get_post_type( $post_id );

        if ( $post_type === 'musesys_pr' ) {
            $pr_id = get_post_meta( $post_id, '_muse_pr_id', true );
            if ( $pr_id ) {
                return preg_match( '/^PR-/i', $pr_id ) ? $pr_id : 'PR-' . $pr_id;
            }
        }

        // Try structured work orders array first
        $wos = get_post_meta( $post_id, '_work_orders', true );
        if ( ! empty( $wos ) && is_array( $wos ) && isset( $wos[0]['display'] ) ) {
            return $wos[0]['display'];
        }
        // Fall back to legacy flat meta
        $display = get_post_meta( $post_id, '_muse_track_status', true );
        if ( $display ) return $display;
        $num = get_post_meta( $post_id, '_muse_track_primary', true );
        if ( $num ) return $num;
        $trx_order_number = get_post_meta( $post_id, '_trx_order_number', true );
        if ( $trx_order_number ) return preg_replace( '/^(?:PR-)+/i', '', (string) $trx_order_number );
        $pr_id = get_post_meta( $post_id, '_muse_pr_id', true );
        if ( $pr_id ) return preg_match( '/^PR-/i', $pr_id ) ? $pr_id : 'PR-' . $pr_id;
        return 'PR-' . $post_id;
    }
}

// Main email handler class
class Muse_Email_Manager {
    
    public function __construct() {
        // Register hooks
        add_action('muse_send_pr_confirmation', array($this, 'send_pr_confirmation'), 10, 2);
        add_action('muse_send_pr_update', array($this, 'send_pr_update'), 10, 2);
        add_action('muse_send_pr_order_link', array($this, 'send_pr_order_link'), 10, 2);
        add_action('muse_send_order_confirmation', array($this, 'send_order_confirmation'), 10, 2);
        add_action('muse_send_sc_automation', array($this, 'send_sc_automation'), 10, 3);
    }
    
    /**
     * Send Product Request confirmation email
     * @param int $pr_id Product Request ID
     * @param array $data Additional data
     */
    public function send_pr_confirmation($pr_id, $data = array()) {
        $tracking_display = muse_get_tracking_display($pr_id);
        
        $to = $data['email'] ?? get_post_meta($pr_id, '_musesys_customer_email', true);
        $subject = "Product Request {$tracking_display} Received";
        
        ob_start();
        include plugin_dir_path(__FILE__) . 'templates/pr-confirmation.php';
        $message = ob_get_clean();
        
        $this->send_email($to, $subject, $message);
    }

    public function send_pr_update($pr_id, $data = array()) {
        $tracking_display = muse_get_tracking_display($pr_id);
        $new_status = $data['status'] ?? 'Updated';
        
        $to = $data['email'] ?? get_post_meta($pr_id, '_musesys_customer_email', true);
        $subject = "Product Request {$tracking_display} Update: " . ucfirst($new_status);
        
        $message = "<p>Your request <strong>{$tracking_display}</strong> status has changed to: <strong>" . ucfirst($new_status) . "</strong>.</p>";
        $message .= "<p><a href='" . ($data['link'] ?? '#') . "'>View Request</a></p>";
        
        $this->send_email($to, $subject, $message);
    }

    public function send_pr_order_link($pr_id, $data = array()) {
        $to = $data['email'] ?? '';
        $link = $data['link'] ?? '#';
        $display_id = muse_get_tracking_display($pr_id);
        $subject = "Action Required: Confirm Order {$display_id}";
        
        $message = "<p>Please click below to confirm your order:</p>";
        $message .= "<p><a href='{$link}'>Confirm & Place Order</a></p>";
        
        $this->send_email($to, $subject, $message);
    }
    
    /**
     * Send Order confirmation email
     */
    public function send_order_confirmation($order_id, $data = array()) {
        $tracking_display = muse_get_tracking_display($order_id);
        
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $to = $order->get_billing_email();
        $subject = "Order {$tracking_display} Confirmed";
        
        ob_start();
        include plugin_dir_path(__FILE__) . 'templates/order-confirmation.php';
        $message = ob_get_clean();
        
        $this->send_email($to, $subject, $message);
    }
    
    /**
     * Send Service Channel automation email
     */
    private function get_latest_shipment_data($order): array {
        $shipments = json_decode( $order->get_meta( '_muse_shipments', true ), true );
        if ( ! is_array( $shipments ) || empty( $shipments ) ) {
            return array();
        }

        return array(
            'shipment' => end( $shipments ),
            'index'    => count( $shipments ),
        );
    }

    private function build_sc_event_payload($order, $event, $data = array()) {
        $event = strtolower( (string) $event );

        if ( in_array( $event, array( 'wc-order-placed', 'order-placed', 'processing' ), true ) ) {
            return array(
                'status_label' => 'Parts Ordered',
                'message'      => 'Parts have been ordered and the order is now in process.',
                'dedupe_key'   => '_muse_sc_parts_ordered_sent',
                'dedupe_value' => 'yes',
                'tracking_numbers' => array(),
                'carrier_label' => '',
                'ship_date' => '',
                'logistics_date' => '',
            );
        }

        if ( $event === 'parts_shipped' ) {
            $shipment_data = $data['shipment'] ?? null;
            $shipment_meta = $this->get_latest_shipment_data( $order );
            $shipment      = is_array( $shipment_data ) ? $shipment_data : ( $shipment_meta['shipment'] ?? array() );
            $shipment_idx  = isset( $shipment_meta['index'] ) ? (string) $shipment_meta['index'] : '';
            $track_numbers = ! empty( $shipment['tracking_numbers'] ) && is_array( $shipment['tracking_numbers'] ) ? array_values( array_filter( $shipment['tracking_numbers'] ) ) : array();

            if ( empty( $track_numbers ) ) {
                return null;
            }

            return array(
                'status_label' => 'Parts Shipped',
                'message'      => 'Parts have shipped and tracking information is now available.',
                'dedupe_key'   => '_muse_sc_last_shipment_sent',
                'dedupe_value' => $shipment_idx,
                'tracking_numbers' => $track_numbers,
                'carrier_label' => $shipment['carrier'] ?? '',
                'ship_date' => $shipment['date'] ?? '',
                'logistics_date' => '',
            );
        }

        if ( $event === 'backordered' ) {
            $logistics_status = strtolower( (string) ( $data['logistics_status'] ?? $order->get_meta( '_muse_logistics_status' ) ) );
            $logistics_date   = (string) ( $data['logistics_date'] ?? $order->get_meta( '_muse_logistics_date' ) );

            if ( $logistics_status !== 'backordered' ) {
                return null;
            }

            return array(
                'status_label' => 'Backordered',
                'message'      => 'Parts are currently backordered.',
                'dedupe_key'   => '_muse_sc_last_logistics_hash',
                'dedupe_value' => 'backordered|' . $logistics_date,
                'tracking_numbers' => array(),
                'carrier_label' => '',
                'ship_date' => '',
                'logistics_date' => $logistics_date,
            );
        }

        return null;
    }

    public function send_sc_automation($order_id, $event, $data = array()) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $work_orders = array();
        
        // Get structured work orders if available
        if (function_exists('muse_get_all_work_orders')) {
            $work_orders = muse_get_all_work_orders($order_id);
        }
        
        // Fallback: Check legacy meta if array is empty
        if (empty($work_orders)) {
            $tracking_number = get_post_meta($order_id, '_muse_track_primary', true);
            $tracking_type = get_post_meta($order_id, '_muse_track_status', true);
            
            if ($tracking_number && $tracking_type === 'sc') {
                $work_orders[] = array(
                    'number' => $tracking_number,
                    'type' => 'sc'
                );
            }
        }
        
        if (empty($work_orders)) return;

        $payload = $this->build_sc_event_payload($order, $event, is_array($data) ? $data : array());
        if (empty($payload)) return;

        if ((string) $order->get_meta($payload['dedupe_key']) === (string) $payload['dedupe_value']) {
            return;
        }
        
        $sent_count = 0;
        foreach ($work_orders as $wo) {
            // Only send to Service Channel work orders
            if ($wo['type'] !== 'sc') continue;
            
            $tracking_number = $wo['number']; // Template expects this variable
            $to = $tracking_number . '@wonote.servicechannel.net';
            $subject = "Status Update: {$tracking_number}";
            $sc_status_label = $payload['status_label'];
            $status_message = $payload['message'];
            $carrier_label = $payload['carrier_label'];
            $track_numbers = $payload['tracking_numbers'];
            $ship_date = $payload['ship_date'];
            $logistics_date = $payload['logistics_date'];
            
            ob_start();
            include plugin_dir_path(__FILE__) . 'templates/sc-automation.php';
            $message = ob_get_clean();
            
            $this->send_email($to, $subject, $message);
            $sent_count++;
        }
        
        if ($sent_count > 0 && function_exists('wc_get_order')) {
            $order->update_meta_data($payload['dedupe_key'], $payload['dedupe_value']);
            $order->add_order_note(
                sprintf('ServiceChannel Automation: %s sent to %d work order(s)', $payload['status_label'], $sent_count),
                false
            );
            $order->save();
        }
    }
    
    /**
     * Central email sender with headers
     */
    private function send_email($to, $subject, $message) {
        if (empty($to)) return;
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: MuseSys <noreply@musesys.com>'
        );
        
        wp_mail($to, $subject, $message, $headers);
    }
}

new Muse_Email_Manager();
