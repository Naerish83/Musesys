<?php
/**
 * Plugin Name: Wegmans Members-Only Front Door
 * Description: Locks the entire site behind login while preserving password reset, activation flows, and securing REST access.
 * Version: 1.3
 * Author: MuseSys
 * Author URI: https://musesys.com
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main gate: Redirect non-logged-in users to login page
 */
add_action('template_redirect', 'wegmans_members_only_gate', 1);

function wegmans_members_only_gate() {

    // Allow logged-in users
    if (is_user_logged_in()) {
        return;
    }

    // Allow core auth pages
    if (isset($GLOBALS['pagenow']) && in_array($GLOBALS['pagenow'], [
        'wp-login.php',
        'wp-register.php'
    ], true)) {
        return;
    }

    // Allow password reset / recovery / activation actions
    if (
        isset($_GET['action']) &&
        in_array($_GET['action'], [
            'lostpassword',
            'rp',
            'resetpass',
            'enter_recovery_mode',
            'newaccount'
        ], true)
    ) {
        return;
    }

    // Allow WooCommerce lost password endpoint (if used)
    if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('lost-password')) {
        return;
    }

    // Allow AJAX requests
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return;
    }

    // Allow REST bootstrap (actual auth handled below)
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }

    // Allow wp-cron
    if (defined('DOING_CRON') && DOING_CRON) {
        return;
    }

    // Redirect to login, preserving intended destination
    $redirect_to = $_SERVER['REQUEST_URI'] ?? home_url();
    wp_redirect(wp_login_url($redirect_to));
    exit;
}

/**
 * Disable XML-RPC (common bot vector)
 */
add_filter('xmlrpc_enabled', '__return_false');

/**
 * Hide login errors to prevent username enumeration
 */
add_filter('login_errors', function () {
    return 'Invalid credentials. Please try again.';
});

/**
 * Block REST access for non-logged-in users (corrected logic)
 */
add_filter('rest_authentication_errors', function ($result) {

    // If another authentication error already exists, respect it
    if (!empty($result)) {
        return $result;
    }

    // --- OPTIONAL ESCAPE HATCH (commented out) ---
    // Allow specific REST namespaces if ever required
    /*
    $request = rest_get_server()->get_current_request();
    $route   = $request ? $request->get_route() : '';

    // Example: Allow Woo Store API
    if (str_starts_with($route, '/wc/store/')) {
        return $result;
    }
    */
    // --- END OPTIONAL ESCAPE HATCH ---

    // Block all other unauthenticated REST access
    if (!is_user_logged_in()) {
        return new WP_Error(
            'rest_not_logged_in',
            'You must be logged in to access this endpoint.',
            ['status' => 401]
        );
    }

    return $result;
});
