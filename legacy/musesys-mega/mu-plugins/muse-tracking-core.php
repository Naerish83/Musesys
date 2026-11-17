<?php
/**
 * Plugin Name: Muse Tracking Core
 * Description: Core tracking number functionality - loads before all plugins
 * Version: 1.1.0
 */

if (!defined('ABSPATH')) exit;

function muse_tracking_get_first_meta($post_id, $keys, $default = '') {
    foreach ((array) $keys as $key) {
        $value = get_post_meta($post_id, $key, true);
        if ($value !== '' && $value !== null && $value !== array()) {
            return $value;
        }
    }
    return $default;
}

/**
 * Universal tracking display function
 * 
 * @param int    $post_id Post ID (PR or Order)
 * @param string $format  'primary' (default), 'all', 'count', 'badge'
 * @return mixed String for primary/badge, array for all, int for count
 */
function gerhardt_get_tracking_display($post_id, $format = 'primary') {
    $post_type = get_post_type($post_id);

    if ($post_type === 'musesys_pr') {
        $pr_id = get_post_meta($post_id, '_muse_pr_id', true);
        if (!empty($pr_id)) {
            return preg_match('/^PR-/i', (string) $pr_id) ? (string) $pr_id : 'PR-' . $pr_id;
        }
    }
    
    // For orders: Use new work_orders structure
    if ($post_type === 'shop_order') {
        $primary = get_post_meta($post_id, '_primary_work_order', true);
        $count = get_post_meta($post_id, '_work_order_count', true);
        $work_orders = get_post_meta($post_id, '_work_orders', true);
        
        // If we have new data, use it
        if ($primary) {
            switch ($format) {
                case 'primary':
                    return $primary;
                    
                case 'badge':
                    // Primary + badge if multiple
                    if ($count > 1) {
                        return $primary . ' <span class="wo-badge" title="' . ($count - 1) . ' additional work orders">[+' . ($count - 1) . ']</span>';
                    }
                    return $primary;
                    
                case 'all':
                    return $work_orders ?: array();
                    
                case 'count':
                    return $count ?: 1;
                    
                default:
                    return $primary;
            }
        }

        // NOTE (2026-07-24): _trx_order_number is intentionally NOT read here.
        // TRX # is a standalone Gerharz-internal field (see CORE_META_STANDARDS.md,
        // "TRX #" section) and must never surface via gerhardt_get_tracking_display()
        // / muse_override_order_number() as a public-facing order number. Previously
        // this function fell back to _trx_order_number when no _primary_work_order
        // existed yet — that fallback is removed. Orders with only a TRX # set (no
        // work-order data) now fall through to the legacy tracking logic below,
        // same as any order with neither field populated.
        // If no new work-order data, fall through to legacy logic below.
    }
    
    // Fallback logic for legacy/compatibility
    // Try to get formatted display first
    $tracking_display = muse_tracking_get_first_meta($post_id, array('_tracking_display', '_muse_track_status'));
    if (!empty($tracking_display)) {
        return $tracking_display;
    }
    
    // Fallback: build from components
    $tracking_number = muse_tracking_get_first_meta($post_id, array('_muse_track_primary', '_tracking_number', '_muse_wo_global'));
    $tracking_type = muse_tracking_get_first_meta($post_id, array('_tracking_type'));
    
    if (!empty($tracking_number)) {
        // Auto-detect type if missing
        if (empty($tracking_type)) {
             $tracking_type = (strlen($tracking_number) === 9) ? 'sc' : 'eam';
        }
        $prefix = ($tracking_type === 'sc') ? 'SC' : 'EAM';
        return "{$prefix}-{$tracking_number}";
    }
    
    // Final fallback: Post ID
    return "Order-{$post_id}";
}

/**
 * Check if order has multiple work orders
 */
function muse_has_multiple_work_orders($order_id) {
    if (!$order_id) return false;
    $count = get_post_meta($order_id, '_work_order_count', true);
    return ($count && $count > 1);
}

/**
 * Get all work orders for an order
 */
function muse_get_all_work_orders($order_id) {
    if (!$order_id) return array();
    return get_post_meta($order_id, '_work_orders', true) ?: array();
}

/**
 * Get raw tracking number (no formatting)
 */
function gerhardt_get_tracking_number($post_id) {
    return muse_tracking_get_first_meta($post_id, array('_muse_track_primary', '_tracking_number', '_muse_wo_global'));
}

/**
 * Get tracking type (sc|eam|legacy)
 */
function gerhardt_get_tracking_type($post_id) {
    $tracking_number = gerhardt_get_tracking_number($post_id);
    if (!empty($tracking_number)) {
        return (strlen(preg_replace('/\D/', '', (string) $tracking_number)) === 9) ? 'sc' : 'eam';
    }
    return muse_tracking_get_first_meta($post_id, array('_tracking_type'));
}

/**
 * Check if post has Service Channel tracking
 */
function gerhardt_is_sc_tracked($post_id) {
    return gerhardt_get_tracking_type($post_id) === 'sc';
}

/**
 * Meta field documentation
 */
function muse_tracking_meta_fields() {
    return array(
        '_muse_track_primary' => 'Core primary tracking/work order number',
        '_muse_track_status'  => 'Core shipping status / tracking display logic',
        '_tracking_number'    => 'Legacy raw tracking number (7 or 9 digits)',
        '_tracking_type'      => 'Legacy type: sc|eam|legacy',
        '_tracking_display'   => 'Legacy formatted display string (SC-123456789)',
        '_muse_wo_global'     => 'Core global work order number',
        '_muse_store_global'  => 'Core global install store number',
        '_work_orders'        => 'Array of all work orders with metadata',
        '_primary_work_order' => 'Formatted display of primary work order',
        '_work_order_count'   => 'Integer count of work orders',
        '_searchable_wo'      => 'Searchable work order numbers (multiple)',
        // Deliberately standalone — NOT a work order / tracking field, and must
        // never be read by the tracking-display fallback chain above. See
        // CORE_META_STANDARDS.md, "TRX #" section.
        '_trx_order_number'   => 'Gerharz-internal TRX #. Independent of Work Order #. Enforced non-empty before order completion by order-fields/muse-order-fields.php.',
    );
}

/**
 * Build work orders array on order save/update
 */
add_action('woocommerce_update_order', 'muse_build_work_orders_array', 10, 1);
add_action('woocommerce_new_order', 'muse_build_work_orders_array', 10, 1);

function muse_build_work_orders_array($order_id) {
    if (!$order_id) return;
    if (!function_exists('wc_get_order')) return;
    
    $order = wc_get_order($order_id);
    if (!$order) return;
    
    // --- WORK ORDERS ---
    $work_orders = array();
    
    // 1. Global Work Orders - Explicit Check for confirmed key
    $global_wo = muse_tracking_get_first_meta($order_id, array('_muse_wo_global', '_muse_global_work_order', '_work_order_number', '_muse_track_primary', '_tracking_number'));
    
    if (!empty($global_wo)) {
        $global_wo = preg_replace('/\D/', '', (string) $global_wo);
        $work_orders[] = array(
            'number'  => $global_wo,
            'type'    => (strlen($global_wo) === 9) ? 'sc' : 'eam',
            'display' => ((strlen($global_wo) === 9) ? 'SC-' : 'EAM-') . $global_wo,
            'source'  => 'global',
            'line_item_id' => 0
        );
    }
    
    // 2. Line Item Work Orders
    foreach ($order->get_items() as $item_id => $item) {
        // Check for human-readable key "WO Number" used by existing system
        $line_wo = $item->get_meta('_muse_wo_line');
        
        // Fallbacks
        if (empty($line_wo)) $line_wo = $item->get_meta('WO Number');
        if (empty($line_wo)) $line_wo = $item->get_meta('_line_work_order');
        if (empty($line_wo)) $line_wo = $item->get_meta('_work_order'); 
        
        if ($line_wo) {
            $line_wo = preg_replace('/\D/', '', (string) $line_wo);
            $work_orders[] = array(
                'number'       => $line_wo,
                'type'         => (strlen($line_wo) === 9) ? 'sc' : 'eam',
                'display'      => ((strlen($line_wo) === 9) ? 'SC-' : 'EAM-') . $line_wo,
                'line_item_id' => $item_id,
                'source'       => 'line_item'
            );
        }
    }
    
    // 3. De-duplicate
    $unique_wo = array();
    $seen = array();
    foreach ($work_orders as $wo) {
        if (!in_array($wo['number'], $seen)) {
            $unique_wo[] = $wo;
            $seen[] = $wo['number'];
        }
    }
    
    // 4. Save Work Order Data
    update_post_meta($order_id, '_work_orders', $unique_wo);
    
    if (!empty($unique_wo)) {
        update_post_meta($order_id, '_primary_work_order', $unique_wo[0]['display']);
        update_post_meta($order_id, '_work_order_count', count($unique_wo));
        update_post_meta($order_id, '_muse_wo_global', $unique_wo[0]['number']);
        
        // Searchable meta
        delete_post_meta($order_id, '_searchable_wo');
        foreach ($unique_wo as $wo) {
            add_post_meta($order_id, '_searchable_wo', $wo['number'], false);
        }
    } else {
        delete_post_meta($order_id, '_primary_work_order');
        update_post_meta($order_id, '_work_order_count', 0);
        delete_post_meta($order_id, '_searchable_wo');
        delete_post_meta($order_id, '_muse_wo_global');
    }

    // --- INSTALL STORES ---
    $install_stores = array();

    // 1. Global Install Store - Explicit Check for confirmed key
    $global_store = muse_tracking_get_first_meta($order_id, array('_muse_store_global', '_muse_global_install_store', '_install_store_number', '_store_number'));

    if (!empty($global_store)) {
        $install_stores[] = array(
            'number' => $global_store,
            'source' => 'global',
            'line_item_id' => 0
        );
    }

    // 2. Line Item Install Stores
    foreach ($order->get_items() as $item_id => $item) {
        // Check for human-readable key "Install Store #"
        $line_store = $item->get_meta('_muse_store_global');
        
        // Fallbacks
        if (empty($line_store)) $line_store = $item->get_meta('Install Store #');
        if (empty($line_store)) $line_store = $item->get_meta('_line_install_store');
        if (empty($line_store)) $line_store = $item->get_meta('_install_store');
        
        if ($line_store) {
            $install_stores[] = array(
                'number' => $line_store,
                'source' => 'line_item',
                'line_item_id' => $item_id
            );
        }
    }

    // 3. De-duplicate Stores
    $unique_stores = array();
    $seen_stores = array();
    foreach ($install_stores as $store) {
        if (!in_array($store['number'], $seen_stores)) {
            $unique_stores[] = $store;
            $seen_stores[] = $store['number'];
        }
    }

    // 4. Save Install Store Data
    update_post_meta($order_id, '_install_stores', $unique_stores);
    
    if (!empty($unique_stores)) {
        update_post_meta($order_id, '_primary_install_store', $unique_stores[0]['number']);
        update_post_meta($order_id, '_install_store_count', count($unique_stores));
        update_post_meta($order_id, '_muse_store_global', $unique_stores[0]['number']);
    } else {
        delete_post_meta($order_id, '_primary_install_store');
        update_post_meta($order_id, '_install_store_count', 0);
        delete_post_meta($order_id, '_muse_store_global');
    }
}

/**
 * Search orders by any work order number
 */
add_filter('posts_search', 'muse_search_by_work_order', 10, 2);
function muse_search_by_work_order($search, $query) {
    global $wpdb;
    
    // Only apply to shop_order searches
    if (!$query->is_search() || !in_array('shop_order', (array)$query->get('post_type'))) {
        return $search;
    }
    
    $search_term = $query->get('s');
    
    // Check if search term looks like a work order (7 or 9 digits)
    if (preg_match('/\d{7,9}/', $search_term, $matches)) {
        $wo_number = $matches[0];
        
        // Search in _searchable_wo meta
        $meta_search = " OR {$wpdb->posts}.ID IN (
            SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_searchable_wo' 
            AND meta_value = %s
        )";
        
        $search .= $wpdb->prepare($meta_search, $wo_number);
    }
    
    return $search;
}

/**
 * Override WooCommerce Order Number with Work Order Display
 * 
 * Replaces the standard order number (ID) with the custom Work Order string (e.g., SC-123456789)
 * everywhere in WooCommerce (emails, admin list, my account, etc).
 * 
 * @param string $order_number The current order number (usually the post ID)
 * @param WC_Order $order The order object
 * @return string The modified order number or original if no custom WO exists
 */
add_filter( 'woocommerce_order_number', 'muse_override_order_number', 10, 2 );

function muse_override_order_number( $order_number, $order ) {
    // Safety check
    if ( ! $order instanceof WC_Order ) {
        return $order_number;
    }

    if ( function_exists( 'gerhardt_get_tracking_display' ) ) {
        $custom_display = gerhardt_get_tracking_display( $order->get_id() );
        
        // gerhardt_get_tracking_display returns "Order-{ID}" as a fallback if no specific WO exists.
        // We do NOT want to return "Order-123" here, because WooCommerce often prefixes with "Order #".
        // Instead, if we detect the fallback format, we return the original order number (ID).
        $fallback_check = "Order-" . $order->get_id();
        
        if ( $custom_display && $custom_display !== $fallback_check ) {
            return $custom_display; 
        }
    }

    return $order_number;
}
