<?php
/**
 * Plugin Name: Muse Order History
 * Description: Legacy order history tab for Wegmans account page — sourced from imported JSON archive.
 * Version:     1.0.0
 * Author:      MuseSys
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MUSE_OH_VERSION',   '1.0.0' );
define( 'MUSE_OH_DIR',       plugin_dir_path( __FILE__ ) );
define( 'MUSE_OH_URL',       plugin_dir_url( __FILE__ ) );

// The archive holds customer PII, so it must live outside the web root.
// nginx serves static files directly on Cloudways; an .htaccess deny does nothing.
// Default: <app>/private_html/muse-order-history/ (sibling of public_html).
// Override with define( 'MUSE_OH_DATA_FILE', '/abs/path.json' ) in wp-config.php.
if ( ! defined( 'MUSE_OH_DATA_FILE' ) ) {
    define( 'MUSE_OH_DATA_FILE', dirname( untrailingslashit( ABSPATH ) ) . '/private_html/muse-order-history/wegmans_order_history.json' );
}

// ─── Account Tab Registration ──────────────────────────────────────────────

add_filter( 'woocommerce_account_menu_items', 'muse_oh_register_tab', 20 );
function muse_oh_register_tab( $items ) {
    $new = array();
    foreach ( $items as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'orders' ) {
            $new['order-history'] = __( 'Original Order History', 'muse-oh' );
        }
    }
    return $new;
}

add_action( 'init', 'muse_oh_add_endpoint' );
function muse_oh_add_endpoint() {
    add_rewrite_endpoint( 'order-history', EP_ROOT | EP_PAGES );
}

add_action( 'woocommerce_account_order-history_endpoint', 'muse_oh_render_tab' );
function muse_oh_render_tab() {
    $user     = wp_get_current_user();
    $email    = strtolower( trim( $user->user_email ) );
    $orders   = muse_oh_get_orders_for_email( $email );

    wp_enqueue_style(  'muse-oh-style',  MUSE_OH_URL . 'assets/muse-oh.css',  array(), MUSE_OH_VERSION );
    wp_enqueue_script( 'muse-oh-script', MUSE_OH_URL . 'assets/muse-oh.js',   array('jquery'), MUSE_OH_VERSION, true );

    include MUSE_OH_DIR . 'templates/order-history.php';
}

// ─── AJAX Endpoint (optional future use / filter requests) ─────────────────

add_action( 'wp_ajax_muse_oh_get_orders', 'muse_oh_ajax_get_orders' );
function muse_oh_ajax_get_orders() {
    check_ajax_referer( 'muse_oh_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( null, 401 );
    }
    $user   = wp_get_current_user();
    $email  = strtolower( trim( $user->user_email ) );
    // Only the caller's own rows, matched on account email.
    $orders = muse_oh_get_orders_for_email( $email );
    wp_send_json_success( $orders );
}

// ─── Data Layer ────────────────────────────────────────────────────────────

// Refuse to read the archive from anywhere the web server can hand out directly.
function muse_oh_data_file_is_private( $path ) {
    $file = realpath( $path );
    if ( $file === false ) return false;
    $file = wp_normalize_path( $file );
    foreach ( array( ABSPATH, WP_CONTENT_DIR ) as $web_root ) {
        $root = realpath( $web_root );
        if ( $root !== false && strpos( $file, trailingslashit( wp_normalize_path( $root ) ) ) === 0 ) {
            return false;
        }
    }
    return true;
}

function muse_oh_get_all_orders() {
    static $cache = null;
    if ( $cache !== null ) return $cache;
    $cache = array();
    if ( ! file_exists( MUSE_OH_DATA_FILE ) ) return $cache;
    if ( ! muse_oh_data_file_is_private( MUSE_OH_DATA_FILE ) ) {
        error_log( 'muse-order-history: refusing to read MUSE_OH_DATA_FILE inside the web root: ' . MUSE_OH_DATA_FILE );
        return $cache;
    }
    $raw     = file_get_contents( MUSE_OH_DATA_FILE );
    $decoded = json_decode( (string) $raw, true );
    $cache   = is_array( $decoded ) ? $decoded : array();
    return $cache;
}

function muse_oh_get_orders_for_email( $email ) {
    $email = strtolower( trim( (string) $email ) );
    if ( $email === '' || ! is_email( $email ) ) return array();
    $all = muse_oh_get_all_orders();
    return array_values( array_filter( $all, function( $o ) use ( $email ) {
        return is_array( $o ) && isset( $o['email'] ) && is_string( $o['email'] )
            && strtolower( trim( $o['email'] ) ) === $email;
    }));
}

// ─── Flush rewrite on activate/deactivate ──────────────────────────────────

register_activation_hook(   __FILE__, 'muse_oh_activate' );
register_deactivation_hook( __FILE__, 'muse_oh_deactivate' );

function muse_oh_activate() {
    muse_oh_add_endpoint();
    flush_rewrite_rules();
}

function muse_oh_deactivate() {
    flush_rewrite_rules();
}
