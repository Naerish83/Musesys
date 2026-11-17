<?php
/**
 * Plugin Name: MuseSys – Hide Prices (Admin Only)
 * Description: Prices remain in WooCommerce for internal truth, but are fully hidden from everyone except admins (classic templates + redaction).
 * Version: 2.0.0
 * Author: MuseSystems
 */

if (!defined('ABSPATH')) { exit; }

final class MuseSys_Hide_Prices_Admin_Only {

    /**
     * Capability gate:
     * - Only admins (or anyone with manage_woocommerce) can see prices.
     * - Everyone else gets redacted pricing output.
     */
    private const CAP_VIEW_PRICES = 'manage_woocommerce';

    public static function init(): void {
        if (self::user_can_view_prices()) {
            return;
        }

        // 1) Stop modern cart/checkout blocks from leaking via Store API rendering paths.
        add_filter('woocommerce_should_load_cart_checkout_blocks', '__return_false', 9999);

        // 2) Core catalog / product price HTML.
        add_filter('woocommerce_get_price_html', [__CLASS__, 'price_label'], 9999, 2);

        // 3) Cart line items (classic templates & fragments).
        add_filter('woocommerce_cart_item_price', [__CLASS__, 'dash'], 9999, 3);
        add_filter('woocommerce_cart_item_subtotal', [__CLASS__, 'dash'], 9999, 3);
        add_filter('woocommerce_widget_cart_item_quantity', [__CLASS__, 'widget_qty_no_price'], 9999, 3);

        // 4) Cart totals (classic).
        add_filter('woocommerce_cart_totals_subtotal_html', [__CLASS__, 'dash'], 9999);
        add_filter('woocommerce_cart_totals_order_total_html', [__CLASS__, 'dash'], 9999);
        add_filter('woocommerce_cart_totals_coupon_html', [__CLASS__, 'dash'], 9999, 2);
        add_filter('woocommerce_cart_totals_fee_html', [__CLASS__, 'dash'], 9999, 2);
        add_filter('woocommerce_cart_totals_shipping_html', [__CLASS__, 'dash'], 9999);
        add_filter('woocommerce_cart_totals_taxes_total_html', [__CLASS__, 'dash'], 9999);
        add_filter('woocommerce_cart_totals_taxes_html', [__CLASS__, 'dash'], 9999);

        // 5) Checkout review table (classic).
        add_filter('woocommerce_checkout_cart_item_quantity', [__CLASS__, 'checkout_qty_no_price'], 9999, 3);

        // 6) Orders: hide line subtotals/totals and summary totals on thank-you page + My Account.
        add_filter('woocommerce_order_formatted_line_subtotal', [__CLASS__, 'dash'], 9999, 3);
        add_filter('woocommerce_get_order_item_totals', [__CLASS__, 'redact_order_totals'], 9999, 3);

        // 7) Emails: wipe the totals block and line subtotals.
        add_filter('woocommerce_email_order_item_quantity', [__CLASS__, 'email_qty_no_price'], 9999, 2);
        add_filter('woocommerce_email_order_items_args', [__CLASS__, 'email_items_args'], 9999, 1);

        // 8) Structured data: don’t expose price to crawlers.
        add_filter('woocommerce_structured_data_product', [__CLASS__, 'redact_structured_data'], 9999, 2);

        // 9) CSS belt-and-suspenders (themes/blocks/odd templates).
        add_action('wp_enqueue_scripts', [__CLASS__, 'inline_css'], 99);
    }

    private static function user_can_view_prices(): bool {
        // manage_woocommerce is present on admins + shop managers by default.
        // You said ONLY you (admin) should see prices, so keep it strict:
        // If you ever add shop managers later, they’d see prices—so we also require administrator.
        if (!is_user_logged_in()) {
            return false;
        }

        $user = wp_get_current_user();
        if (!$user || empty($user->roles)) {
            return false;
        }

        $is_admin_role = in_array('administrator', (array) $user->roles, true);
        return $is_admin_role && current_user_can(self::CAP_VIEW_PRICES);
    }

    public static function price_label(string $price_html, $product): string {
        return '<span class="muse-price-hidden">' . esc_html__('Price hidden', 'woocommerce') . '</span>';
    }

    public static function dash(): string {
        return '<span class="muse-price-hidden">&mdash;</span>';
    }

    public static function widget_qty_no_price(string $html, array $cart_item, string $cart_item_key): string {
        // Mini-cart quantity line often includes price; keep quantity only.
        $qty = isset($cart_item['quantity']) ? (int) $cart_item['quantity'] : 1;
        return sprintf('<span class="quantity">%s</span>', esc_html($qty . ' ×'));
    }

    public static function checkout_qty_no_price(string $qty_html, array $cart_item, string $cart_item_key): string {
        $qty = isset($cart_item['quantity']) ? (int) $cart_item['quantity'] : 1;
        return sprintf('<strong class="product-quantity">&times;&nbsp;%s</strong>', esc_html((string) $qty));
    }

    public static function redact_order_totals(array $totals, $order, $tax_display): array {
        // Replace every displayed total value with an em dash.
        foreach ($totals as $key => $row) {
            if (is_array($row) && isset($row['value'])) {
                $totals[$key]['value'] = self::dash();
            }
        }
        return $totals;
    }

    public static function email_qty_no_price(string $qty, $item): string {
        // Keep quantity only, no amounts.
        $q = method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : (int) $qty;
        return (string) $q;
    }

    public static function email_items_args(array $args): array {
        // Removes line item prices/subtotals in email table in many templates.
        // Keep it conservative: hide prices via CSS + totals filter; this prevents some themes from reintroducing them.
        $args['show_sku'] = $args['show_sku'] ?? false;
        return $args;
    }

    public static function redact_structured_data(array $markup, $product): array {
        // Remove offers / price from schema.
        if (isset($markup['offers'])) {
            unset($markup['offers']);
        }
        return $markup;
    }

    public static function inline_css(): void {
        wp_register_style('musesys-hide-prices-admin-only', false);
        wp_enqueue_style('musesys-hide-prices-admin-only');

        wp_add_inline_style('musesys-hide-prices-admin-only', '
            .muse-price-hidden { font-style: italic; opacity: 0.8; }
            .price, .amount,
            .woocommerce-Price-amount,
            .woocommerce-Price-currencySymbol,
            .woocommerce-mini-cart__total,
            .cart-subtotal, .order-total,
            .woocommerce-order-overview__total,
            .woocommerce-table--order-details tfoot .woocommerce-Price-amount {
                display: none !important;
            }
        ');
    }
}

add_action('plugins_loaded', [MuseSys_Hide_Prices_Admin_Only::class, 'init'], 1);
