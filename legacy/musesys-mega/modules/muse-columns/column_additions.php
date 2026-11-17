<?php
/**
 * Module: Custom Order Screen Columns (HPOS & Legacy Aware)
 * Target: musesys-mega plugin
 * Version: 1.0.1
 * Description: Registers, renders, and handles sorting for custom order listing columns (TRX#, PO#, Logistics, ESD).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 1. Register the new columns for the Orders list table
 */
function muse_register_custom_order_columns( $columns ) {
    // Injecting our new items right before the 'wc_actions' or 'actions' column
    $new_columns = [];

    foreach ( $columns as $key => $column_name ) {
        if ( in_array( $key, [ 'wc_actions', 'actions' ], true ) ) {
            $new_columns['muse_trx_num']         = __( 'TRX #', 'musesys-mega' );
            $new_columns['muse_po_num']          = __( 'PO #', 'musesys-mega' );
            $new_columns['muse_logistics_status'] = __( 'Logistics Status', 'musesys-mega' );
            $new_columns['muse_esd_date']        = __( 'ESD', 'musesys-mega' );
        }
        $new_columns[$key] = $column_name;
    }

    // Fallback if actions column wasn't found
    if ( ! isset( $new_columns['muse_trx_num'] ) ) {
        $new_columns['muse_trx_num']         = __( 'TRX #', 'musesys-mega' );
        $new_columns['muse_po_num']          = __( 'PO #', 'musesys-mega' );
        $new_columns['muse_logistics_status'] = __( 'Logistics Status', 'musesys-mega' );
        $new_columns['muse_esd_date']        = __( 'ESD', 'musesys-mega' );
    }

    return $new_columns;
}
// Legacy/CPT Hook
add_filter( 'manage_edit-shop_order_columns', 'muse_register_custom_order_columns', 20 );
// HPOS Hook
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'muse_register_custom_order_columns', 20 );


/**
 * 2. Render the column data (HPOS & Legacy Compatible)
 */
function muse_render_custom_order_columns_content( $column, $order_or_post = null ) {
    // Resolve the Order Object regardless of the engine running backend
    $order = null;
    if ( $order_or_post instanceof WC_Order ) {
        $order = $order_or_post;
    } elseif ( is_numeric( $order_or_post ) ) {
        $order = wc_get_order( $order_or_post );
    } elseif ( $order_or_post instanceof WP_Post ) {
        $order = wc_get_order( $order_or_post->ID );
    } else {
        global $post;
        if ( $post ) {
            $order = wc_get_order( $post->ID );
        }
    }

    if ( ! $order ) {
        return;
    }

    // Output the requested data based on the key
    switch ( $column ) {
        case 'muse_trx_num':
            $trx = $order->get_meta( '_trx_order_number' );
            echo $trx ? esc_html( $trx ) : '<span class="na">&ndash;</span>';
            break;

        case 'muse_po_num':
            $po = $order->get_meta( '_purchase_order_number' );
            echo $po ? esc_html( $po ) : '<span class="na">&ndash;</span>';
            break;

        case 'muse_logistics_status':
            $log_status = $order->get_meta( '_muse_logistics_status' );
            if ( $log_status ) {
                // Quick styling adjustment to fit beautifully inside standard WooCommerce rows
                $status_class = sanitize_title( $log_status );
                echo sprintf(
                    '<span class="order-status status-%s" style="padding: 2px 6px; border-radius: 4px; font-weight: 600; font-size: 11px; background: #e5e5e5; color: #3c434a;">%s</span>',
                    esc_attr( $status_class ),
                    esc_html( ucfirst( $log_status ) )
                );
            } else {
                echo '<span class="na">&ndash;</span>';
            }
            break;

        case 'muse_esd_date':
            $esd = $order->get_meta( '_muse_logistics_date' );
            if ( is_string( $esd ) && '' !== $esd ) {
                $time = strtotime( $esd );
                if ( $time ) {
                    $date_format = get_option( 'date_format' );
                    // Modern wp_date() replaces legacy date_i18n() in WP 5.3+
                    $formatted_date = wp_date( $date_format, $time );
                    echo esc_html( $formatted_date );
                } else {
                    echo esc_html( $esd );
                }
            } else {
                echo '<span class="na">&ndash;</span>';
            }
            break;
    }
}
// Legacy / CPT Action Hook
add_action( 'manage_shop_order_posts_custom_column', 'muse_render_custom_order_columns_content', 20, 2 );
// HPOS Action Hook
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'muse_render_custom_order_columns_content', 20, 2 );


/**
 * 3. Ensure the columns are registered to the Screen Options sorting list
 */
function muse_make_columns_sortable( $sortable_columns ) {
    $sortable_columns['muse_trx_num']  = 'muse_trx_num';
    $sortable_columns['muse_po_num']   = 'muse_po_num';
    $sortable_columns['muse_esd_date'] = 'muse_esd_date';
    return $sortable_columns;
}
add_filter( 'manage_edit-shop_order_sortable_columns', 'muse_make_columns_sortable' );
add_filter( 'manage_woocommerce_page_wc-orders_sortable_columns', 'muse_make_columns_sortable' );


/**
 * 4. Handle sorting queries for custom columns
 */
// HPOS Sorting
add_filter( 'woocommerce_order_query_args', 'muse_orders_sorting_hpos_args' );
function muse_orders_sorting_hpos_args( $query_vars ) {
    if ( ! is_admin() ) {
        return $query_vars;
    }
    
    $orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : '';
    if ( empty( $orderby ) ) {
        return $query_vars;
    }

    if ( in_array( $orderby, [ 'muse_trx_num', 'muse_po_num', 'muse_esd_date' ], true ) ) {
        $meta_keys = [
            'muse_trx_num'  => '_trx_order_number',
            'muse_po_num'   => '_purchase_order_number',
            'muse_esd_date' => '_muse_logistics_date',
        ];
        $query_vars['meta_key'] = $meta_keys[ $orderby ];
        $query_vars['orderby']  = 'meta_value';
    }
    return $query_vars;
}

// Legacy CPT Sorting
add_filter( 'request', 'muse_orders_sorting_legacy_request' );
function muse_orders_sorting_legacy_request( $vars ) {
    if ( ! is_admin() ) {
        return $vars;
    }

    if ( isset( $vars['orderby'] ) && in_array( $vars['orderby'], [ 'muse_trx_num', 'muse_po_num', 'muse_esd_date' ], true ) ) {
        $meta_keys = [
            'muse_trx_num'  => '_trx_order_number',
            'muse_po_num'   => '_purchase_order_number',
            'muse_esd_date' => '_muse_logistics_date',
        ];
        $vars = array_merge( $vars, [
            'meta_key' => $meta_keys[ $vars['orderby'] ],
            'orderby'  => 'meta_value',
        ] );
    }
    return $vars;
}