<?php
/**
 * Plugin Name: Muse Clear Cart
 * Description: Adds an "Empty Cart" button to the cart page.
 * Version: 1.0.0
 * Author: MuseSystems
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class Muse_Clear_Cart {

    public static function init(): void {
        add_action('woocommerce_cart_actions', [__CLASS__, 'render_empty_cart_button']);
        add_action('wp_loaded', [__CLASS__, 'handle_empty_cart_action']);
    }

    /**
     * Render the Empty Cart button.
     */
    public static function render_empty_cart_button(): void {
        ?>
        <button type="submit" 
                class="button" 
                name="muse_empty_cart" 
                value="1"
                onclick="return confirm('Are you sure you want to remove all items from your cart?');"
                style="background-color: #d63638; color: white; border-color: #d63638; margin-left: 10px;">
            <?php esc_html_e('Empty Cart', 'woocommerce'); ?>
        </button>
        <?php wp_nonce_field('muse_empty_cart_action', 'muse_empty_cart_nonce'); ?>
        <?php
    }

    /**
     * Handle the Empty Cart submission.
     */
    public static function handle_empty_cart_action(): void {
        if (isset($_POST['muse_empty_cart']) && $_POST['muse_empty_cart'] === '1') {
            
            if (!isset($_POST['muse_empty_cart_nonce']) || !wp_verify_nonce($_POST['muse_empty_cart_nonce'], 'muse_empty_cart_action')) {
                wc_add_notice('Security check failed. Please try again.', 'error');
                return;
            }

            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
                wc_add_notice('Cart verified empty.', 'success');
                
                // Redirect to avoid resubmission
                wp_safe_redirect(wc_get_cart_url());
                exit;
            }
        }
    }
}

Muse_Clear_Cart::init();
