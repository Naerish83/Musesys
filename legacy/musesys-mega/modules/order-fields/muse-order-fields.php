<?php
/**
 * Plugin Name: Muse Admin Order Tools
 * Plugin URI:  https://muse.systems/
 * Description: Adds TRX/PO fields to admin orders, blocks completion if missing, and makes custom fields searchable across all statuses.
 * Version:     2.0.0
 * Author:      Muse Systems
 * License:     GPL-2.0-or-later
 * Text Domain: muse-admin-fields
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * 1. Add a custom meta box to the admin order page.
 */
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action( 'add_meta_boxes', 'muse_add_custom_order_meta_box' );
function muse_add_custom_order_meta_box() {
    $screen = 'shop_order';
    if (class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') && 
        wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()) {
        $screen = wc_get_page_screen_id('shop-order');
    }

    add_meta_box(
        'muse_custom_order_fields',
        __( 'Muse Custom Order Details', 'muse-admin-fields' ),
        'muse_custom_order_meta_box_content',
        $screen,
        'side',
        'high'
    );
}

/**
 * 2. Display the content (our custom fields) inside the meta box.
 */
function muse_custom_order_meta_box_content( $post_or_order_object ) {
    $order = ($post_or_order_object instanceof WC_Order) ? $post_or_order_object : wc_get_order( $post_or_order_object->ID );
    if ( ! $order ) return;

    wp_nonce_field( 'muse_save_custom_order_fields', 'muse_custom_order_fields_nonce' );

    woocommerce_wp_text_input( array(
        'id'          => '_trx_order_number',
        'label'       => __( 'TRX Order #', 'muse-admin-fields' ),
        'description' => __( 'This field is required before completion.', 'muse-admin-fields' ),
        'desc_tip'    => true,
        'value'       => $order->get_meta( '_trx_order_number' ),
    ) );

    woocommerce_wp_checkbox( array(
        'id'          => '_is_drop_ship',
        'label'       => __( 'Drop-ship', 'muse-admin-fields' ),
        'description' => __( 'Check this if the order is a drop-ship.', 'muse-admin-fields' ),
        'desc_tip'    => true,
        'value'       => $order->get_meta( '_is_drop_ship' ),
        'cbvalue'     => 'yes',
    ) );

    woocommerce_wp_text_input( array(
        'id'          => '_purchase_order_number',
        'label'       => __( 'Purchase Order #', 'muse-admin-fields' ),
        'description' => __( 'Required if order is a drop-ship.', 'muse-admin-fields' ),
        'desc_tip'    => true,
        'value'       => $order->get_meta( '_purchase_order_number' ),
    ) );
}

/**
 * 3. Save custom fields.
 */
add_action( 'woocommerce_process_shop_order_meta', 'muse_save_custom_order_fields' );
function muse_save_custom_order_fields( $post_id ) {
    if ( ! isset( $_POST['muse_custom_order_fields_nonce'] ) || ! wp_verify_nonce( $_POST['muse_custom_order_fields_nonce'], 'muse_save_custom_order_fields' ) ) {
        return;
    }

    $order = wc_get_order( $post_id );
    if ( ! $order ) return;

    if ( isset( $_POST['_trx_order_number'] ) ) {
        $order->update_meta_data( '_trx_order_number', sanitize_text_field( $_POST['_trx_order_number'] ) );
    }

    $is_drop_ship = isset( $_POST['_is_drop_ship'] ) ? 'yes' : 'no';
    $order->update_meta_data( '_is_drop_ship', $is_drop_ship );

    if ( isset( $_POST['_purchase_order_number'] ) ) {
        $order->update_meta_data( '_purchase_order_number', sanitize_text_field( $_POST['_purchase_order_number'] ) );
    }
    
    $order->save();
}

/**
 * 4. Admin notices for validation feedback.
 */
add_action( 'admin_notices', 'muse_display_custom_admin_notices' );
function muse_display_custom_admin_notices() {
    $notices = WC_Admin_Notices::get_notices();
    if ( isset( $notices['muse_field_error'] ) && ! empty( $notices['muse_field_error'] ) ) {
        foreach ( $notices['muse_field_error'] as $message ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
        }
        WC_Admin_Notices::remove_notice( 'muse_field_error' );
    }
}

/**
 * 5. Prevent order completion if custom fields are missing.
 */
add_action( 'woocommerce_before_order_object_save', 'muse_block_order_completion_if_fields_missing', 10, 2 );
function muse_block_order_completion_if_fields_missing( $order, $data_store ) {
    if ( $order->get_status() !== 'completed' ) {
        return;
    }

    $trx_order_number = $order->get_meta( '_trx_order_number' );
    $is_drop_ship     = $order->get_meta( '_is_drop_ship' );
    $po_number        = $order->get_meta( '_purchase_order_number' );

    $errors = [];

    if ( empty( $trx_order_number ) ) {
        $errors[] = 'TRX Order # must be entered before completing the order.';
    }

    if ( $is_drop_ship === 'yes' && empty( $po_number ) ) {
        $errors[] = 'Purchase Order # is required for drop-ship orders.';
    }

    if ( ! empty( $errors ) ) {
        foreach ( $errors as $message ) {
            WC_Admin_Notices::add_custom_notice( 'muse_field_error', $message );
        }

        $order->add_order_note(
            'Failed to mark as complete. Missing required fields: ' . implode( ', ', $errors )
        );

        throw new Exception( implode( ' ', $errors ) );
    }
}

/**
 * 6. Register searchable fields for admin order search.
 */
add_filter( 'woocommerce_shop_order_search_fields', 'muse_add_searchable_order_fields' );
function muse_add_searchable_order_fields( $search_fields ) {
    $search_fields[] = '_trx_order_number';
    $search_fields[] = '_purchase_order_number';
    $search_fields[] = 'muse_global_work_order';
    $search_fields[] = 'WO Number';
    return $search_fields;
}

/**
 * 7. Expand search to include all statuses (completed, trashed, processing, etc.)
 */
add_filter( 'woocommerce_shop_order_search_results', 'muse_force_search_all_statuses', 10, 3 );
function muse_force_search_all_statuses( $order_ids, $term, $search_fields ) {
    global $wpdb;

    $meta_keys = array(
        '_trx_order_number',
        '_purchase_order_number',
        'muse_global_work_order',
        'WO Number',
    );

    $placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
    $prepared     = array_merge( $meta_keys, array( '%' . $wpdb->esc_like( $term ) . '%' ) );

    // HPOS CHECK: If custom tables are enabled, do NOT run this SQL against wp_postmeta
    if (class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') && 
        wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()) {
        return $order_ids;
    }

    $extra_ids = $wpdb->get_col( $wpdb->prepare( "
        SELECT DISTINCT post_id
        FROM $wpdb->postmeta
        WHERE meta_key IN ( $placeholders )
        AND meta_value LIKE %s
    ", $prepared ) );

    if ( ! empty( $extra_ids ) ) {
        $order_ids = array_unique( array_merge( $order_ids, $extra_ids ) );
    }

    return $order_ids;
}

/**
 * 8. (Optional) Placeholder for future dropdown/date/user filters.
 * This can grow without touching the search system above.
 */
add_filter( 'woocommerce_shop_order_query_args', 'muse_custom_order_query_filters', 20, 1 );
function muse_custom_order_query_filters( $query_args ) {
    // Example: $query_args['meta_query'][] = ...
    return $query_args;
}
