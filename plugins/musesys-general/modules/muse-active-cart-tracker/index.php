<?php
/**
 * Plugin Name: Muse Active Cart Tracker
 * Description: Tracks active carts for logged-in users and displays them in the Admin User list.
 * Version: 1.0.0
 * Author: MuseSystems
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Muse_Active_Cart_Tracker {

    public static function init(): void {
        // Data Capture
        add_action('woocommerce_cart_updated', [__CLASS__, 'save_cart_data']);
        add_action('woocommerce_new_order', [__CLASS__, 'clear_cart_data']);
        
        // Admin Display
        add_filter('manage_users_columns', [__CLASS__, 'add_column']);
        add_filter('manage_users_custom_column', [__CLASS__, 'render_column'], 10, 3);
        
        // Ensure styling for tooltip
        add_action('admin_head', [__CLASS__, 'admin_styles']);
    }

    /**
     * Save cart data to user meta.
     */
    public static function save_cart_data(): void {
        if (!is_user_logged_in() || is_admin()) {
            return;
        }

        if (function_exists('WC') && WC()->cart) {
            $user_id = get_current_user_id();
            $cart = WC()->cart->get_cart();
            
            if (empty($cart)) {
                delete_user_meta($user_id, '_musesys_active_cart_data');
                return;
            }

            $saved_items = [];
            foreach ($cart as $key => $item) {
                $product = $item['data'];
                // We save minimal data to keep meta light
                $saved_items[] = [
                    'id' => $item['product_id'],
                    'name' => $product->get_name(),
                    'qty' => $item['quantity'],
                    'total' => $item['line_total']
                ];
            }

            update_user_meta($user_id, '_musesys_active_cart_data', $saved_items);
            update_user_meta($user_id, '_musesys_active_cart_updated', time());
        }
    }

    /**
     * Clear data on order creation (Checkout).
     */
    public static function clear_cart_data(int $order_id): void {
        $order = wc_get_order($order_id);
        if ($order) {
            $user_id = $order->get_user_id();
            if ($user_id) {
                delete_user_meta($user_id, '_musesys_active_cart_data');
                delete_user_meta($user_id, '_musesys_active_cart_updated');
            }
        }
    }

    /**
     * Add column to Users table.
     */
    public static function add_column(array $columns): array {
        $columns['musesys_cart'] = 'Active Cart';
        return $columns;
    }

    /**
     * Render column content.
     */
    public static function render_column(string $val, string $column_name, int $user_id): string {
        if ($column_name !== 'musesys_cart') {
            return $val; // Filters in WP Users return the value, unlike Actions in Posts
        }

        $items = get_user_meta($user_id, '_musesys_active_cart_data', true);
        if (empty($items) || !is_array($items)) {
            return '—';
        }

        $count = 0;
        $tooltip_lines = [];
        foreach ($items as $item) {
            $count += (int)$item['qty'];
            $tooltip_lines[] = (int)$item['qty'] . 'x ' . esc_html($item['name']);
        }
        
        $updated = get_user_meta($user_id, '_musesys_active_cart_updated', true);
        $time_diff = $updated ? human_time_diff((int)$updated) . ' ago' : '';

        // Simple CSS Tooltip structure
        $html = '<div class="muse-cart-cell">';
        $html .= '<span class="dashicons dashicons-cart" style="color:#d63638;"></span> <strong>' . $count . ' Items</strong>';
        if ($time_diff) {
            $html .= '<br><small style="color:#777;">' . $time_diff . '</small>';
        }
        
        // Tooltip Content
        $html .= '<div class="muse-cart-tooltip">';
        $html .= '<strong>Contents:</strong><ul style="margin:4px 0 0 10px; list-style:disc;">';
        foreach (array_slice($tooltip_lines, 0, 10) as $line) {
            $html .= '<li>' . $line . '</li>';
        }
        if (count($tooltip_lines) > 10) {
            $html .= '<li><em>...and ' . (count($tooltip_lines) - 10) . ' more</em></li>';
        }
        $html .= '</ul></div>';
        $html .= '</div>';

        return $html;
    }

    public static function admin_styles(): void {
        echo '<style>
            .muse-cart-cell { position: relative; cursor: help; }
            .muse-cart-cell .muse-cart-tooltip { 
                display: none; 
                position: absolute; 
                z-index: 999; 
                background: #333; 
                color: #fff; 
                padding: 10px; 
                border-radius: 4px; 
                width: 250px; 
                bottom: 100%; 
                left: 50%; 
                transform: translateX(-50%); 
                font-size: 11px;
                box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            }
            .muse-cart-cell:hover .muse-cart-tooltip { display: block; }
            /* Arrow */
            .muse-cart-cell .muse-cart-tooltip::after {
                content: " "; position: absolute; top: 100%; left: 50%; margin-left: -5px;
                border-width: 5px; border-style: solid; border-color: #333 transparent transparent transparent;
            }
        </style>';
    }
}

Muse_Active_Cart_Tracker::init();
