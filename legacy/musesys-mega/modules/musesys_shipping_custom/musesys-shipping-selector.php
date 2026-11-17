<?php
/**
 * Plugin Name: MuseSys — Wegmans Shipping Selector
 * Version: 1.3.1
 * Author: MuseSystems
 * Description: Admin UI for managing store and warehouse addresses. Deprecated shortcode [muse_address_selector]—use [wegmans_store_selector] or [musesys_favorite_selector] instead.
 */

defined('ABSPATH') || exit;
define('MUSESYS_ADDR_STORES','musesys_addresses_stores');
define('MUSESYS_ADDR_WAREHOUSES','musesys_addresses_warehouses');
define('MUSESYS_ADDR_OTHER','musesys_addresses_other');

add_action('admin_menu', function () {
    add_submenu_page('musesys','Shipping Manager','Shipping Manager','manage_options','musesys-shipping','musesys_shipping_selector_page');
});

function musesys_addr_sets() {
    return [
        'stores'      => ['label'=>'Stores','option'=>MUSESYS_ADDR_STORES],
        'warehouses' => ['label'=>'Warehouses','option'=>MUSESYS_ADDR_WAREHOUSES],
        'other'      => ['label'=>'Other','option'=>MUSESYS_ADDR_OTHER],
    ];
}

function musesys_shipping_selector_page() {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions','musesys'));
    $sets = musesys_addr_sets();
    $active = (isset($_GET['set']) && isset($sets[$_GET['set']])) ? sanitize_text_field($_GET['set']) : 'stores';
    $opt_key = $sets[$active]['option'];

    echo '<div class="wrap"><h1>Shipping Manager</h1>';
    echo '<p><em>Import applies to Stores and Warehouses. Favorites are managed by users under My Account ? Address Favorites.</em></p>';
    echo '<h2 class="nav-tab-wrapper">';
    foreach ($sets as $k=>$def){
        $class = ($k===$active)?' nav-tab nav-tab-active':' nav-tab';
        $url = admin_url('admin.php?page=musesys-shipping&set='.$k);
        printf('<a href="%s" class="%s">%s</a>', esc_url($url), esc_attr($class), esc_html($def['label']));
    }
    echo '</h2>';

    if (!empty($_POST['musesys_shipping_save']) && check_admin_referer('musesys_shipping_save_nonce')) {
        $raw = isset($_POST['musesys_addresses_json']) ? wp_unslash($_POST['musesys_addresses_json']) : '';
        $list = json_decode($raw, true);
        if (!is_array($list)) $list = [];
        update_option($opt_key, array_values($list));
        echo '<div class="updated notice is-dismissible"><p>Saved '.$sets[$active]['label'].' addresses.</p></div>';
    }

    $current = get_option($opt_key, []);
    $display = esc_textarea(wp_json_encode($current, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

    echo '<p>Paste a JSON array of address objects for the active set.</p>';
    echo '<form method="post">';
    wp_nonce_field('musesys_shipping_save_nonce');
    echo '<textarea name="musesys_addresses_json" rows="18" style="width:100%;">'.$display.'</textarea>';
    echo '<p><button type="submit" class="button button-primary" name="musesys_shipping_save" value="1">Save '.$sets[$active]['label'].'</button></p>';
    echo '</form></div>';
}

function musesys_get_store_addresses(){ return get_option(MUSESYS_ADDR_STORES, []); }
function musesys_get_warehouse_addresses(){ return get_option(MUSESYS_ADDR_WAREHOUSES, []); }

add_shortcode('muse_address_selector', function () {
    if (!function_exists('WC')) return '<p>WooCommerce required.</p>';

    wc_add_notice(__('The [muse_address_selector] shortcode is deprecated. Use [musesys_favorite_selector] or [wegmans_store_selector] for a better experience, Sir.', 'musesys'), 'notice');

    $all_addresses = musesys_get_all_addresses_normalized();
    $favorites = musesys_current_user_favorites();
    
    if (empty($all_addresses['stores']) && empty($all_addresses['warehouses']) && empty($favorites)) {
        return '<div class="notice notice-error">No addresses found.</div>';
    }

    ob_start(); ?>
    <div class="muse-address-selector-container">
        <form method="post" id="muse-address-selector-form">
            <?php wp_nonce_field('musesys_set_address_nonce', 'musesys_set_address_nonce'); ?>
            
            <p><strong>Choose a Shipping Address:</strong></p>
            <input type="text" id="muse-address-search" placeholder="Search by store name or number..." style="width:100%; margin-bottom: 10px;">
            <select name="musesys_shipping_address" id="muse-shipping-address" required size="20" style="width:100%;">
                <option value="">— Select an address —</option>

                <?php
                $all_available_addresses = array_merge($all_addresses['stores'], $all_addresses['warehouses']);

                /* SYLVA FIX: Mapping updated based on Image 1 (JSON) Evidence */
                $format_label = function($addr) {
                    // JSON Evidence: first_name = "1" (Number), last_name = "John Glenn" (Name)
                    $num  = isset($addr['first_name']) && is_scalar($addr['first_name']) ? trim((string) $addr['first_name']) : ''; 
                    $name = isset($addr['last_name']) && is_scalar($addr['last_name']) ? trim((string) $addr['last_name']) : '';
                    
                    // Display: Name (#Number) — Address
                    // Example: John Glenn (#1)
                    return esc_html($name . ' (#' . $num . ') — ' . ($addr['address_1'] ?? '') . ', ' . ($addr['city'] ?? ''));
                };

                if (!empty($favorites) && is_user_logged_in()) :
                    echo '<optgroup label="Favorites">';
                    foreach ($favorites as $fav_id) :
                        foreach ($all_available_addresses as $addr) :
                            if ($addr['_id'] === $fav_id) :
                                $addr_label = $format_label($addr);
                                $addr_search = esc_attr(strtolower($addr_label . ' ' . json_encode($addr)));
                                $addr_json = esc_attr(json_encode($addr));
                                echo '<option value="' . $addr_json . '" data-search="' . $addr_search . '">' . $addr_label . '</option>';
                                break;
                            endif;
                        endforeach;
                    endforeach;
                    echo '</optgroup>';
                endif;

                if (!empty($all_addresses['stores'])) :
                    echo '<optgroup label="Store Addresses">';
                    foreach ($all_addresses['stores'] as $addr) :
                        $addr_label = $format_label($addr);
                        $addr_search = esc_attr(strtolower($addr_label . ' ' . json_encode($addr)));
                        $addr_json = esc_attr(json_encode($addr));
                        echo '<option value="' . $addr_json . '" data-search="' . $addr_search . '">' . $addr_label . '</option>';
                    endforeach;
                    echo '</optgroup>';
                endif;
                
                if (!empty($all_addresses['warehouses'])) :
                    echo '<optgroup label="Warehouse Addresses">';
                    foreach ($all_addresses['warehouses'] as $addr) :
                        $addr_label = $format_label($addr);
                        $addr_search = esc_attr(strtolower($addr_label . ' ' . json_encode($addr)));
                        $addr_json = esc_attr(json_encode($addr));
                        echo '<option value="' . $addr_json . '" data-search="' . $addr_search . '">' . $addr_label . '</option>';
                    endforeach;
                    echo '</optgroup>';
                endif;
                ?>
            </select>
            
            <br>
            <button type="submit" name="musesys_set_address" class="button button_blue wc-backward" style="margin-top:10px;">Confirm Address</button>
        </form>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('muse-address-search');
        const selectElement = document.getElementById('muse-shipping-address');
        const form = document.getElementById('muse-address-selector-form');

        form.action = "<?php echo esc_url(wc_get_checkout_url()); ?>";

        searchInput.addEventListener('input', function() {
            const searchTerm = searchInput.value.toLowerCase();
            const options = selectElement.querySelectorAll('option');

            options.forEach(function(option) {
                const searchContent = option.getAttribute('data-search');
                if (option.value === "" || (searchContent && searchContent.includes(searchTerm))) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            });

            const optgroups = selectElement.querySelectorAll('optgroup');
            optgroups.forEach(function(optgroup) {
                let hasVisibleOption = false;
                const optgroupOptions = optgroup.querySelectorAll('option');
                optgroupOptions.forEach(function(option) {
                    if (option.style.display !== 'none') {
                        hasVisibleOption = true;
                    }
                });
                optgroup.style.display = hasVisibleOption ? '' : 'none';
            });
        });

        form.addEventListener('submit', function(e) {
            const select = document.getElementById('muse-shipping-address');
            if (select.value === '') {
                e.preventDefault();
                alert('Please select an address.');
            }
        });
    });
    </script>
    <?php
    return ob_get_clean();
});

add_action('template_redirect', function() {
    if (is_page() && has_shortcode(get_the_content(), 'muse_address_selector')) {
        wc_add_notice(__('The [muse_address_selector] shortcode is deprecated. Use [musesys_favorite_selector] or [wegmans_store_selector] for a better experience, Sir.', 'musesys'), 'notice');
        wp_safe_redirect(apply_filters('musesys_selector_page_url', site_url('/167249-2/')));
        exit;
    }
});

add_action('init', function () {
    if (is_admin() || !function_exists('WC')) return;
    
    if (
        isset($_SERVER['REQUEST_METHOD'])
        && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['musesys_set_address'], $_POST['musesys_shipping_address'])
    ) {
        $nonce = isset($_POST['musesys_set_address_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['musesys_set_address_nonce']))
            : '';
        if ($nonce && wp_verify_nonce($nonce, 'musesys_set_address_nonce')) {
            $raw_data = wp_unslash($_POST['musesys_shipping_address']);
            $picked = is_string($raw_data) ? json_decode($raw_data, true) : null;

            if (is_array($picked)) {
                // Decoded JSON is untrusted: only scalars become strings, anything else is blank.
                $str = fn($key) => isset($picked[$key]) && is_scalar($picked[$key]) ? (string) $picked[$key] : '';
                
                // SYLVA FIX: Correct logic based on JSON evidence
                // JSON: first_name = Number ("1"), last_name = Name ("John Glenn")
                
                $num_raw  = trim($str('first_name')); // Grab "1"
                $name_raw = trim($str('last_name'));  // Grab "John Glenn"
                
                $session_data = [
                    // WE SWAP THEM HERE FOR THE CHECKOUT
                    'first_name' => $name_raw,                  // Set Checkout First Name = "John Glenn"
                    'last_name'  => 'Store #' . $num_raw,       // Set Checkout Last Name = "Store #1"
                    
                    'address_1'  => $str('address_1'),
                    'address_2'  => $str('address_2'),
                    'city'       => $str('city'),
                    'state'      => $str('state'),
                    'postcode'   => $str('zip'),
                    'phone'      => $str('phone'),
                    'company'    => $str('company'),
                    'store_number' => $num_raw, 
                ];

                // Save to Session
                WC()->session->set('musesys_shipping', $session_data);
                wp_safe_redirect(wc_get_checkout_url());
                exit;
            } else {
                wc_add_notice(__('Invalid address selected.', 'musesys'), 'error');
                wp_safe_redirect(wp_get_referer() ?: home_url());
                exit;
            }
        }
    }
});
