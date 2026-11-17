<?php
/**
 * Plugin Name: Muse Order Tracking
 * Description: Compatibility bridge for legacy order tracking hooks after the standards refactor.
 * Version: 2.0.0
 * Author: MuseSys
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Keep only the non-conflicting automation hook from the legacy module.
 * Order tracking, shipment UI, and work-order display now live in the
 * dedicated standards-aligned modules and MU plugins.
 */
add_action( 'woocommerce_order_status_changed', 'muse_sc_status_notification', 10, 4 );
function muse_sc_status_notification( $order_id, $old_status, $new_status, $order ) {
    do_action( 'muse_send_sc_automation', $order_id, $new_status );
}
