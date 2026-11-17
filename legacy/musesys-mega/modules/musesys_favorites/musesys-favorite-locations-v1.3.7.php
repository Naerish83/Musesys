<?php
/**
 * Plugin Name: MuseSys — Favorite Locations
 * Description: My Account tab for managing store/warehouse favorites. Syncs with Shipping Selector, saves 'bucket|key' strings in user_meta 'musesys_favorites'. Clean dropdowns, no search.
 * Version: 1.4.0
 * Author: MuseSystems
 */
 
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Load selector provider if available
 */
add_action('plugins_loaded', function () {
    if (!function_exists('musesys_selector_load_sources')) {
        $selector_paths = [
            WP_CONTENT_DIR . '/plugins/musesys_selector/index.php',
            WP_CONTENT_DIR . '/plugins/musesys-shipping-selector.php',
            WP_CONTENT_DIR . '/mu-plugins/musesys-shipping-selector.php',
        ];
        foreach ($selector_paths as $path) {
            if (file_exists($path)) {
                include_once $path;
                if (function_exists('musesys_selector_load_sources')) break;
            }
        }
    }
}, 5);

/**
 * Provider - Aligned to store-selector logic
 */
function musesys_favloc_get_addresses() {
    $stores = [];
    $warehouses = [];
    $favorites = [];

    if (function_exists('musesys_selector_load_sources')) {
        list($stores, $warehouses) = musesys_selector_load_sources();
    } else {
        // Fallback if not available
        $both = get_option('musesys_locations_json', []);
        $stores = $both['stores'] ?? [];
        $warehouses = $both['warehouses'] ?? [];
        if (empty($stores)) $stores = get_option('musesys_addresses_stores', []);
        if (empty($warehouses)) $warehouses = get_option('musesys_addresses_warehouses', []);
    }

    $favorites = get_user_meta(get_current_user_id(), 'musesys_favorites', true);
    if (!is_array($stores)) $stores = [];
    if (!is_array($warehouses)) $warehouses = [];
    if (!is_array($favorites)) $favorites = [];

    return ['stores' => $stores, 'warehouses' => $warehouses, 'favorites' => $favorites];
}

/**
 * Endpoint
 */
add_action('init', function () {
    add_rewrite_endpoint('favorite-locations', EP_ROOT | EP_PAGES);
});

add_filter('woocommerce_account_menu_items', function ($items) {
    $new = [];
    $inserted = false;
    foreach ($items as $key => $label) {
        $new[$key] = $label;
        if ($key === 'edit-address') {
            $new['favorite-locations'] = __('Favorite Locations', 'musesys');
            $inserted = true;
        }
    }
    if (!$inserted) $new['favorite-locations'] = __('Favorite Locations', 'musesys');
    return $new;
}, 20);

/**
 * Handle POST (Add to Favorites) - Aligned to 'bucket|key' format
 */
add_action('template_redirect', function () {
    if (!is_user_logged_in() || (!is_account_page() && !is_checkout())) return;
    if (!isset($_POST['musesys_set_address'])) return;
    if (!wp_verify_nonce($_POST['musesys_set_address_nonce'] ?? '', 'musesys_set_address')) {
        wc_add_notice(__('Security check failed .', 'musesys'), 'error');
        return;
    }

    $bucket = sanitize_text_field($_POST['musesys_bucket'] ?? '');
    $key = sanitize_text_field($_POST['musesys_key'] ?? '');
    $sources = musesys_favloc_get_addresses();

    if (!isset($sources[$bucket][$key])) {
        wc_add_notice(__('Invalid address selected .', 'musesys'), 'error');
        return;
    }

    $user_id = get_current_user_id();
    $meta_key = 'musesys_favorites';
    $favorites = get_user_meta($user_id, $meta_key, true);
    if (!is_array($favorites)) $favorites = [];

    $new_entry = $bucket . '|' . $key;

    if (!in_array($new_entry, $favorites)) {
        $favorites[] = $new_entry;
        update_user_meta($user_id, $meta_key, $favorites);
        wc_add_notice(__('Slipped that address into your Favorites .', 'musesys'), 'success');
    } else {
        wc_add_notice(__('That one’s already in your Favorites, you greedy thing.', 'musesys'), 'notice');
    }

    wp_safe_redirect(wc_get_account_endpoint_url('favorite-locations'));
    exit;
});

/**
 * Renderer - Two full dropdowns (stores and warehouses) styled like store-selector, no search
 */
function musesys_favloc_render_selector() {
    if (!is_user_logged_in()) {
        echo '<p>' . esc_html__('You must be logged in to manage favorites .', 'musesys') . '</p>';
        return;
    }

    $sources = musesys_favloc_get_addresses();
    $stores = $sources['stores'];
    $warehouses = $sources['warehouses'];
    $fav_entries = $sources['favorites'];

    // Use store-selector's label function for consistency
    $mk_label = function_exists('musesys_selector_label') ? 'musesys_selector_label' : function($row) {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $addr = $row['address_1'] ?? '';
        $city = $row['city'] ?? '';
        $state = $row['state'] ?? '';
        $zip = $row['zip'] ?? '';
        $left = $name ? $name : $addr;
        $right = trim($city . ($state ? ', ' . $state : '') . ($zip ? ' ' . $zip : ''));
        return trim($left . ($right ? ' — ' . $right : ''));
    };

    echo '<div class="musesys-favorite-manager">';
    echo '<h3>Your Current Favorites</h3>';
    if (!empty($fav_entries)) {
        echo '<ul>';
        foreach ($fav_entries as $f) {
            list($bucket, $key) = explode('|', $f, 2);
            if (isset($sources[$bucket][$key])) {
                $row = $sources[$bucket][$key];
                $label = $mk_label($row);
                echo '<li>' . esc_html($label) . ' (' . esc_html(ucfirst($bucket)) . ')</li>';
            }
        }
        echo '</ul>';
    } else {
        echo '<p>No favorites yet . Let’s fill that up.</p>';
    }

    echo '<div class="musesys-selector-grid">';

    if (!empty($stores)) {
        echo '<div class="muse-bucket">';
        echo '<label for="musesys_key_stores"><strong>Add from Stores</strong></label>';
        echo '<form method="post" class="musesys-favorite-form">';
        wp_nonce_field('musesys_set_address', 'musesys_set_address_nonce');
        echo '<input type="hidden" name="musesys_set_address" value="1">';
        echo '<input type="hidden" name="musesys_bucket" value="stores">';
        echo '<select class="musesys-select" id="musesys_key_stores" name="musesys_key">';
        echo '<option value="">— Choose a store —</option>';
        foreach ($stores as $k => $row) {
            $label = $mk_label($row);
            echo '<option value="' . esc_attr($k) . '">' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p style="margin-top:10px;"><button type="submit" class="button button-primary" onclick="if(!document.getElementById(\'musesys_key_stores\').value){alert(\'Please select a store.\');return false;}">' . esc_html__('Add to Favorites', 'musesys') . '</button></p>';
        echo '</form>';
        echo '</div>';
    }

    if (!empty($warehouses)) {
        echo '<div class="muse-bucket">';
        echo '<label for="musesys_key_warehouses"><strong>Add from Warehouses</strong></label>';
        echo '<form method="post" class="musesys-favorite-form">';
        wp_nonce_field('musesys_set_address', 'musesys_set_address_nonce');
        echo '<input type="hidden" name="musesys_set_address" value="1">';
        echo '<input type="hidden" name="musesys_bucket" value="warehouses">';
        echo '<select class="musesys-select" id="musesys_key_warehouses" name="musesys_key">';
        echo '<option value="">— Choose a warehouse —</option>';
        foreach ($warehouses as $k => $row) {
            $label = $mk_label($row);
            echo '<option value="' . esc_attr($k) . '">' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p style="margin-top:10px;"><button type="submit" class="button button-primary" onclick="if(!document.getElementById(\'musesys_key_warehouses\').value){alert(\'Please select a warehouse.\');return false;}">' . esc_html__('Add to Favorites', 'musesys') . '</button></p>';
        echo '</form>';
        echo '</div>';
    }

    echo '</div>';

    // Enqueue store-selector CSS for consistency
    wp_enqueue_style('musesys-selector', plugins_url('musesys-selector.css', __DIR__ . '/musesys-store-selector.php'));

    echo '</div>';
}

/**
 * Endpoint renderer only
 */
add_action('woocommerce_account_favorite-locations_endpoint', 'musesys_favloc_render_selector');
?>